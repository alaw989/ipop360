<?php

namespace App\Services\Overture;

use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\FieldQuarantineService;
use App\Services\RestaurantWebsiteScraperService;
use App\Services\SocialLinkRecorder;
use App\Services\VenuePipeline;
use App\Services\WebsiteIdentityVerifier;
use App\Support\AreaCodeStates;
use App\Support\SocialProfileUrl;
use App\Support\StateAbbreviations;
use App\Support\ZipLocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Overture Maps corroboration + gap fill (data-integrity phase 3).
 *
 * 1. extract(): one DuckDB pass over the release's public S3 GeoParquet keeps
 *    only US food places (a few columns) in a local, lat-sorted Parquet file
 *    (~1.8M rows). Re-running for the same release reuses the file.
 * 2. run(): restaurants are grouped into cell_size_deg blocks; each block's
 *    places are read from the local file and every restaurant is matched to
 *    the place at the same location — VenuePipeline::venuesMatch (same phone,
 *    or ≥85% similar name, within match_radius_km), the rule live search uses.
 * 3. applyMatch(): records the corroboration (overture_id/confidence/sources/
 *    status) and fills ONLY empty fields — phone (when its area code fits the
 *    restaurant's state), address, website (never a blocked/reference host;
 *    identity-checked later by restaurants:verify-websites) and social
 *    profiles (validated + reachability-checked). Each fill is recorded in
 *    field_sources. An address is never filled with a ZIP far from the pin,
 *    and a stored address whose ZIP is far from the pin (copied from another
 *    location) is replaced by the place's when the place's ZIP is near it —
 *    the old one goes to field_quarantine (address_far_from_location). A
 *    place Overture marks permanently_closed deactivates the restaurant
 *    through field_quarantine (reversible).
 */
class OvertureImporter
{
    private const BUCKET = 'https://overturemaps-us-west-2.s3.amazonaws.com';

    /** Continental US + AK + HI + PR, as an extraction envelope. */
    private const US_BBOX = ['xmin' => -179.5, 'xmax' => -65.0, 'ymin' => 17.5, 'ymax' => 71.5];

    public function __construct(
        private DuckDb $duckDb,
        private VenuePipeline $pipeline,
        private FieldQuarantineService $quarantine,
        private WebsiteIdentityVerifier $verifier,
        private RestaurantWebsiteScraperService $scraper,
        private SocialLinkRecorder $recorder,
    ) {}

    /**
     * The newest release prefix in the public bucket, e.g. "2026-08-19.0".
     */
    public function latestRelease(): string
    {
        $response = Http::timeout(30)->get(self::BUCKET.'/', ['list-type' => 2, 'prefix' => 'release/', 'delimiter' => '/']);
        preg_match_all('#<Prefix>release/(\d{4}-\d{2}-\d{2}\.\d+)/</Prefix>#', $response->body(), $m);
        if ($m[1] === []) {
            throw new RuntimeException('Could not list Overture releases (HTTP '.$response->status().')');
        }
        $releases = $m[1];
        // "2026-08-19.0" / "2026-08-19.10": natural order handles both the
        // zero-padded date and a multi-digit revision suffix.
        usort($releases, fn (string $a, string $b): int => strnatcmp($a, $b));

        return (string) end($releases);
    }

    /**
     * Local Parquet of the release's US food places (created once).
     */
    public function extract(string $release): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}\.\d+$/', $release) !== 1) {
            throw new RuntimeException("Invalid Overture release '{$release}'");
        }

        $dir = storage_path('app/overture/'.$release);
        $path = $dir.'/places.parquet';
        if (is_file($path)) {
            return $path;
        }
        if (! is_dir($dir.'/tmp') && ! mkdir($dir.'/tmp', 0755, true) && ! is_dir($dir.'/tmp')) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        $categories = implode(', ', array_map(
            fn (string $c) => "'".str_replace("'", '', $c)."'",
            (array) config('restaurant-finder.overture.categories', [])
        ));
        $b = self::US_BBOX;
        $memory = max(256, (int) config('restaurant-finder.overture.duckdb_memory_limit_mb', 1024));
        $threads = max(1, (int) config('restaurant-finder.overture.duckdb_max_threads', 2));
        $tmp = $path.'.partial';

        $this->duckDb->run(<<<SQL
            SET s3_region='us-west-2';
            SET memory_limit='{$memory}MB';
            SET threads={$threads};
            SET temp_directory='{$dir}/tmp';
            COPY (
                SELECT id,
                       names.primary AS name,
                       basic_category AS category,
                       confidence,
                       operating_status,
                       websites,
                       phones,
                       socials,
                       addresses[1].freeform AS street,
                       addresses[1].locality AS city,
                       addresses[1].region AS region,
                       addresses[1].postcode AS postcode,
                       bbox.ymin AS lat,
                       bbox.xmin AS lng,
                       list_distinct([s.dataset FOR s IN sources]) AS datasets
                FROM read_parquet('s3://overturemaps-us-west-2/release/{$release}/theme=places/type=place/*', hive_partitioning=1)
                WHERE bbox.xmin BETWEEN {$b['xmin']} AND {$b['xmax']}
                  AND bbox.ymin BETWEEN {$b['ymin']} AND {$b['ymax']}
                  AND basic_category IN ({$categories})
                ORDER BY lat, lng
            ) TO '{$tmp}' (FORMAT parquet, COMPRESSION zstd);
            SQL, 7200);

        rename($tmp, $path);

        return $path;
    }

    /**
     * Match every active restaurant against the extract.
     *
     * @return array<string, int> run statistics
     */
    public function run(string $parquet, string $release, bool $apply, ?callable $progress = null, bool $socials = true): array
    {
        $size = max(0.05, (float) config('restaurant-finder.overture.cell_size_deg', 0.5));

        $blocks = [];
        Restaurant::query()->active()->whereNotNull('latitude')->whereNotNull('longitude')
            ->select(['id', 'latitude', 'longitude'])
            ->chunkById(5000, function (Collection $rows) use (&$blocks, $size): void {
                foreach ($rows as $r) {
                    $blocks[(int) floor((float) $r->latitude / $size).':'.(int) floor((float) $r->longitude / $size)][] = (int) $r->id;
                }
            });

        $stats = $this->emptyStats();
        $stats['blocks'] = count($blocks);
        $margin = 0.01; // places just across a block edge can still be the same venue

        foreach ($blocks as $key => $ids) {
            [$by, $bx] = array_map('intval', explode(':', (string) $key));
            $ymin = $by * $size - $margin;
            $ymax = ($by + 1) * $size + $margin;
            $xmin = $bx * $size - $margin;
            $xmax = ($bx + 1) * $size + $margin;

            $places = $this->duckDb->select(
                "SELECT * FROM read_parquet('{$parquet}') WHERE lat BETWEEN {$ymin} AND {$ymax} AND lng BETWEEN {$xmin} AND {$xmax}"
            );

            foreach (array_chunk($ids, 1000) as $chunk) {
                $restaurants = Restaurant::query()->whereIn('id', $chunk)->get();
                $blockStats = $this->matchBlock($restaurants, $places, $release, $apply, $socials);
                foreach ($blockStats as $k => $v) {
                    $stats[$k] += $v;
                }
            }

            if ($progress !== null) {
                $progress($key, count($ids), count($places));
            }
        }

        Log::channel('enrichment')->info('Overture import complete', ['release' => $release, 'apply' => $apply] + $stats);

        return $stats;
    }

    /**
     * Match one block's restaurants to its places (and apply when asked).
     *
     * @param  Collection<int, Restaurant>  $restaurants
     * @param  list<array<string, mixed>>  $places
     * @return array<string, int>
     */
    public function matchBlock(Collection $restaurants, array $places, string $release, bool $apply, bool $socials = true): array
    {
        $stats = $this->emptyStats();
        $grid = [];
        foreach ($places as $i => $place) {
            $grid[$this->gridKey((float) $place['lat'], (float) $place['lng'])][] = $i;
        }

        foreach ($restaurants as $restaurant) {
            $stats['restaurants']++;
            $place = $this->bestMatch($restaurant, $places, $grid);
            if ($place === null) {
                continue;
            }

            $stats['matched']++;
            foreach ($this->applyMatch($restaurant, $place, $release, $apply, $socials) as $k => $v) {
                $stats[$k] += $v;
            }
        }

        return $stats;
    }

    /**
     * @param  list<array<string, mixed>>  $places
     * @param  array<string, list<int>>  $grid
     * @return array<string, mixed>|null
     */
    private function bestMatch(Restaurant $restaurant, array $places, array $grid): ?array
    {
        $lat = (float) $restaurant->latitude;
        $lng = (float) $restaurant->longitude;
        $self = ['name' => (string) $restaurant->name, 'phone' => $restaurant->phone, 'lat' => $lat, 'lng' => $lng];
        $radius = (float) config('restaurant-finder.overture.match_radius_km', 0.2);
        $ownPhone = substr((string) preg_replace('/\D+/', '', (string) $restaurant->phone), -10);

        [$gy, $gx] = array_map('intval', explode(':', $this->gridKey($lat, $lng)));
        $best = null;
        $bestRank = null;
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                foreach ($grid[($gy + $dy).':'.($gx + $dx)] ?? [] as $i) {
                    $place = $places[$i];
                    $phone = $this->firstPhone($place);
                    $candidate = ['name' => (string) ($place['name'] ?? ''), 'phone' => $phone, 'lat' => (float) $place['lat'], 'lng' => (float) $place['lng']];
                    if (! $this->pipeline->venuesMatch($self, $candidate, $radius, 85.0)) {
                        continue;
                    }
                    // Prefer a phone match, then the nearest place.
                    $rank = [
                        $phone !== null && $ownPhone !== '' && $phone === $ownPhone ? 0 : 1,
                        abs($candidate['lat'] - $lat) + abs($candidate['lng'] - $lng),
                    ];
                    if ($bestRank === null || $rank < $bestRank) {
                        $best = $place;
                        $bestRank = $rank;
                    }
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array<string, int>
     */
    private function applyMatch(Restaurant $restaurant, array $place, string $release, bool $apply, bool $socials = true): array
    {
        $stats = ['phone_filled' => 0, 'address_filled' => 0, 'address_corrected' => 0, 'postal_corrected' => 0, 'website_filled' => 0, 'socials_added' => 0, 'closed' => 0, 'phone_conflicts' => 0];
        $confidence = (float) ($place['confidence'] ?? 0);
        $fillable = $confidence >= (float) config('restaurant-finder.overture.fill_min_confidence', 0.5);
        $datasets = is_array($place['datasets'] ?? null) ? $place['datasets'] : [];

        $updates = [
            'overture_id' => (string) $place['id'],
            'overture_confidence' => round($confidence, 3),
            'overture_sources' => min(255, count($datasets)),
            'overture_status' => is_string($place['operating_status'] ?? null) ? $place['operating_status'] : null,
            'overture_checked_at' => now(),
        ];
        $sources = is_array($restaurant->field_sources) ? $restaurant->field_sources : [];
        $tag = 'overture:'.$release;

        $phone = $this->firstPhone($place);
        $ownPhone = substr((string) preg_replace('/\D+/', '', (string) $restaurant->phone), -10);
        if ($phone !== null && $ownPhone !== '' && $phone !== $ownPhone) {
            $stats['phone_conflicts']++;
        }
        if ($fillable && $phone !== null && $ownPhone === '') {
            $phoneState = AreaCodeStates::stateForPhone($phone);
            $rowState = StateAbbreviations::toAbbreviation($restaurant->state);
            if ($phoneState === null || $rowState === null || $phoneState === $rowState) {
                $updates['phone'] = $phone;
                $sources['phone'] = $tag;
                $stats['phone_filled']++;
            }
        }

        $street = trim((string) ($place['street'] ?? ''));
        $placeAddress = implode(', ', array_filter([
            $street, trim((string) ($place['city'] ?? '')), trim(($place['region'] ?? '').' '.($place['postcode'] ?? '')),
        ]));
        $placeZip = ZipLocation::zipOf(null, (string) ($place['postcode'] ?? ''));
        $lat = (float) $restaurant->latitude;
        $lng = (float) $restaurant->longitude;
        // Never write an address whose ZIP is somewhere else than the pin.
        $placeUsable = $fillable && $street !== ''
            && ($placeZip === null || ZipLocation::isFarFrom($placeZip, $lat, $lng) !== true)
            && ! $this->quarantine->isQuarantined((int) $restaurant->id, 'address', $placeAddress);
        $copiedZip = null;
        if ($placeUsable && trim((string) $restaurant->address) === '') {
            $updates['address'] = $placeAddress;
            $sources['address'] = $tag;
            $stats['address_filled']++;
        } elseif ($placeUsable && $placeZip !== null) {
            // A stored address whose ZIP is far from the pin was copied from
            // another location (the old name-only backfill). The place at the
            // pin, in a ZIP near it, has the right one.
            // The address's own ZIP: a far postal_code alone doesn't prove the
            // street wrong.
            $storedZip = ZipLocation::zipOf($restaurant->address);
            if ($storedZip !== null && $storedZip !== $placeZip && ZipLocation::isFarFrom($storedZip, $lat, $lng) === true) {
                $copiedZip = $storedZip;
                $updates['address'] = $placeAddress;
                $sources['address'] = $tag;
                // The copy usually carried its ZIP into postal_code, which the
                // page prints after the address: correct it with the address.
                if (ZipLocation::zipOf(null, $restaurant->postal_code) === $storedZip) {
                    $updates['postal_code'] = $placeZip;
                    $sources['postal_code'] = $tag;
                }
                $stats['address_corrected']++;
            }
        }

        // A postal_code from somewhere else, on a row whose address has no ZIP
        // of its own or one at the pin: the place's postcode, when it's here.
        $storedPostal = ZipLocation::zipOf(null, $restaurant->postal_code);
        $addressZip = ZipLocation::zipOf($restaurant->address);
        $postalFix = null;
        if (! array_key_exists('postal_code', $updates) && $fillable && $placeZip !== null && $storedPostal !== null
            && $storedPostal !== $placeZip
            && ($addressZip === null || ZipLocation::isFarFrom($addressZip, $lat, $lng) === false)
            && ZipLocation::isFarFrom($storedPostal, $lat, $lng) === true
            && ZipLocation::isFarFrom($placeZip, $lat, $lng) === false) {
            $postalFix = ['from' => $storedPostal, 'to' => $placeZip];
            $sources['postal_code'] = $tag;
            $stats['postal_corrected']++;
        }

        if ($fillable && trim((string) $restaurant->website_url) === '') {
            foreach ((array) ($place['websites'] ?? []) as $website) {
                $website = trim((string) $website);
                if ($website === '' || $this->verifier->isBlockedUrl($website) || $this->verifier->isReferenceUrl($website)
                    || $this->quarantine->isQuarantined((int) $restaurant->id, 'website_url', $website)) {
                    continue;
                }
                // Identity-checked later by the daily restaurants:verify-websites
                // run, which takes never-checked rows first. Clearing
                // website_verified_at queues this URL: a row whose previous
                // website was quarantined still carries that check's timestamp,
                // which would hide the new URL until the re-check window lapses.
                $updates['website_url'] = $website;
                $updates['website_identity'] = null;
                $updates['website_verified_at'] = null;
                $sources['website_url'] = $tag;
                $stats['website_filled']++;
                break;
            }
        }

        if ($sources !== (is_array($restaurant->field_sources) ? $restaurant->field_sources : [])) {
            $updates['field_sources'] = $sources;
        }

        $closed = ($place['operating_status'] ?? null) === 'permanently_closed';
        if ($closed) {
            $stats['closed']++;
        }

        if (! $apply) {
            $stats['socials_added'] += $socials ? count($this->missingSocials($restaurant, $place)) : 0;

            return $stats;
        }

        if ($copiedZip !== null) {
            // Keep the copied values restorable. Both columns are refilled
            // just below, so a restore can't put back half of the old address.
            $fields = array_key_exists('postal_code', $updates) ? ['address', 'postal_code'] : ['address'];
            $this->quarantine->quarantineFields($restaurant, $fields, 'address_far_from_location', 'overture:import', [
                'zip' => $copiedZip, 'overture_id' => $place['id'], 'release' => $release,
            ]);
        }

        if ($postalFix !== null) {
            // Restorable while the column still holds the place's postcode.
            $this->quarantine->replaceFields($restaurant, ['postal_code' => $postalFix['to']], 'postal_far_from_location', 'overture:import', [
                'zip' => $postalFix['from'], 'overture_id' => $place['id'], 'release' => $release,
            ]);
        }

        $restaurant->update($updates);

        $added = [];
        foreach ($socials ? $this->missingSocials($restaurant, $place) : [] as $platform => $url) {
            $verified = $this->scraper->verifyProfileUrl($url);
            $restaurant->socialLinks()->create([
                'platform' => $platform,
                'url' => $url,
                'scope' => RestaurantSocialLink::SCOPE_LOCATION,
                'verified_at' => $verified ? now() : null,
                'last_check_failed_at' => $verified ? null : now(),
            ]);
            $added[] = $url;
        }
        if ($added !== []) {
            $stats['socials_added'] += count($added);
            $this->recorder->classifyBrandScope($added);
            $this->recorder->recount([(int) $restaurant->id]);
        }

        if ($closed && $restaurant->is_active) {
            $this->quarantine->quarantineFields($restaurant, ['is_active'], 'closed_per_overture', 'overture:import', [
                'overture_id' => $place['id'], 'release' => $release,
            ]);
        }

        return $stats;
    }

    /**
     * Valid social profiles from the place for platforms the restaurant lacks.
     *
     * @param  array<string, mixed>  $place
     * @return array<string, string> platform => canonical url
     */
    private function missingSocials(Restaurant $restaurant, array $place): array
    {
        $have = $restaurant->socialLinks()->pluck('platform')->map(fn ($p) => (string) $p)->all();
        $missing = [];
        foreach ((array) ($place['socials'] ?? []) as $url) {
            $profile = SocialProfileUrl::canonicalize((string) $url);
            if ($profile !== null && ! in_array($profile['platform'], $have, true) && ! isset($missing[$profile['platform']])) {
                $missing[$profile['platform']] = $profile['url'];
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function firstPhone(array $place): ?string
    {
        foreach ((array) ($place['phones'] ?? []) as $phone) {
            $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
            if (strlen($digits) >= 10) {
                return substr($digits, -10);
            }
        }

        return null;
    }

    private function gridKey(float $lat, float $lng): string
    {
        // ~550 m cells: a 200 m match radius never spans more than one ring.
        return (int) floor($lat / 0.005).':'.(int) floor($lng / 0.005);
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return [
            'blocks' => 0, 'restaurants' => 0, 'matched' => 0, 'phone_filled' => 0, 'address_filled' => 0,
            'address_corrected' => 0, 'postal_corrected' => 0, 'website_filled' => 0, 'socials_added' => 0, 'closed' => 0, 'phone_conflicts' => 0,
        ];
    }
}
