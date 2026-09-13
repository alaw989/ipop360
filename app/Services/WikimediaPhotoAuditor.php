<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Classifies a stored Wikimedia photo as a real picture of the venue or a
 * name-only match (the "Lost & Found" bin, "Ela" the person).
 *
 * A photo passes when either:
 *  - its Commons file description page is geotagged within ~150 m of the pin
 *    (Commons `prop=coordinates`), or
 *  - a Wikidata item within ~150 m carries the same file as its P18 image.
 *
 * Report-only by design: the auditor never writes. Removal is a separate,
 * operator-approved decision (backlog goal 19).
 */
class WikimediaPhotoAuditor
{
    public const VERDICT_COMMONS = 'verified_commons';

    public const VERDICT_WIKIDATA = 'verified_wikidata';

    public const VERDICT_UNVERIFIED = 'unverified';

    public const VERDICT_UNCHECKABLE = 'uncheckable';

    private const COMMONS_ENDPOINT = 'https://commons.wikimedia.org/w/api.php';

    private const USER_AGENT = 'iPop360/1.0';

    /** Pass distance: ~150 m (the Moose's Tooth verification radius). */
    private const MAX_DISTANCE_KM = 0.15;

    /** Wikidata proximity box (±degrees), matching WikidataService. */
    private const BOX_PADDING = 0.01;

    /** Commons accepts up to 50 titles per query. */
    private const BATCH_SIZE = 50;

    /** Commons file coordinate lookups are stable; cache them for 30 days. */
    private const CACHE_TTL_DAYS = 30;

    /**
     * filename => lookup, for the current run. Populated by preloadCommons()
     * (batched + cached) and consulted by commonsLookup().
     *
     * @var array<string, array{status: string, lat?: float, lng?: float}>
     */
    private array $commonsCache = [];

    public function __construct(private WikidataService $wikidata) {}

    /**
     * Resolve many file coordinate lookups at once: reuse the 30-day cache,
     * then fetch the rest in batches of 50 titles. On a 429 (Wikimedia's rate
     * limit) it stops issuing further requests and marks the remaining titles
     * failed — never "none", so an --apply run cannot quarantine on a throttle.
     *
     * @param  array<int, string>  $filenames
     * @return array{requested: int, cached: int, fetched: int, failed: int}
     */
    public function preloadCommons(array $filenames): array
    {
        $filenames = array_values(array_unique(array_filter($filenames, fn (string $name): bool => $name !== '')));
        $stats = ['requested' => count($filenames), 'cached' => 0, 'fetched' => 0, 'failed' => 0];
        $missing = [];

        foreach ($filenames as $filename) {
            $cached = Cache::get(self::cacheKey($filename));
            if (is_array($cached) && isset($cached['status']) && is_string($cached['status'])) {
                $lookup = ['status' => $cached['status']];
                if (isset($cached['lat'], $cached['lng']) && is_numeric($cached['lat']) && is_numeric($cached['lng'])) {
                    $lookup['lat'] = (float) $cached['lat'];
                    $lookup['lng'] = (float) $cached['lng'];
                }
                $this->commonsCache[$filename] = $lookup;
                $stats['cached']++;
            } else {
                $missing[] = $filename;
            }
        }

        foreach (array_chunk($missing, self::BATCH_SIZE) as $chunk) {
            $fetched = $this->fetchCommonsBatch($chunk);

            if ($fetched === null) {
                foreach ($chunk as $filename) {
                    $this->commonsCache[$filename] = ['status' => 'failed'];
                    $stats['failed']++;
                }

                break; // rate limited / API down: stop instead of hammering
            }

            foreach ($chunk as $filename) {
                $lookup = $fetched[self::normalizeTitle($filename)] ?? ['status' => 'none'];
                $this->commonsCache[$filename] = $lookup;
                Cache::put(self::cacheKey($filename), $lookup, now()->addDays(self::CACHE_TTL_DAYS));
                $stats['fetched']++;
            }
        }

        return $stats;
    }

    /**
     * @return array{verdict: string, distance_m: int|null, title: string|null}
     */
    public function audit(Restaurant $restaurant): array
    {
        $photo = trim((string) $restaurant->photo_url);
        $filename = $photo === '' ? null : $this->fileNameFromUrl($photo);

        if ($filename === null || $restaurant->latitude === null || $restaurant->longitude === null) {
            return $this->result(self::VERDICT_UNCHECKABLE, null, $filename);
        }

        $lat = (float) $restaurant->latitude;
        $lng = (float) $restaurant->longitude;

        $lookup = $this->commonsLookup($filename);
        if ($lookup['status'] === 'failed') {
            // Couldn't check, so the photo is neither verified nor provably
            // wrong — never "unverified". An --apply run would otherwise
            // quarantine a genuinely good photo on a transient API failure.
            return $this->result(self::VERDICT_UNCHECKABLE, null, $filename);
        }
        if ($lookup['status'] === 'coords' && isset($lookup['lat'], $lookup['lng'])) {
            $km = $this->haversineKm($lat, $lng, $lookup['lat'], $lookup['lng']);
            if ($km <= self::MAX_DISTANCE_KM) {
                return $this->result(self::VERDICT_COMMONS, $this->meters($km), $filename);
            }
        }

        $venues = $this->wikidata->findRestaurantImagesInBox(
            $lat - self::BOX_PADDING,
            $lng - self::BOX_PADDING,
            $lat + self::BOX_PADDING,
            $lng + self::BOX_PADDING,
        );

        foreach ($venues as $venue) {
            $image = $venue['image'] ?? null;
            if (! is_string($image) || $image === '' || ! $this->sameFile($image, $photo)) {
                continue;
            }
            $km = $this->haversineKm($lat, $lng, (float) $venue['lat'], (float) $venue['lng']);
            if ($km <= self::MAX_DISTANCE_KM) {
                return $this->result(self::VERDICT_WIKIDATA, $this->meters($km), $filename);
            }
        }

        return $this->result(self::VERDICT_UNVERIFIED, null, $filename);
    }

    /**
     * The Commons file's geotag lookup. `status` distinguishes "has coords"
     * from "checked, no coords" from "couldn't check" — the last must not be
     * read as unverified.
     *
     * @return array{status: string, lat?: float, lng?: float}
     */
    public function commonsLookup(string $filename): array
    {
        if (array_key_exists($filename, $this->commonsCache)) {
            return $this->commonsCache[$filename];
        }

        try {
            $response = Http::timeout(8)
                ->withUserAgent(self::USER_AGENT)
                ->get(self::COMMONS_ENDPOINT, [
                    'action' => 'query',
                    'titles' => 'File:'.$filename,
                    'prop' => 'coordinates',
                    'format' => 'json',
                    'origin' => '*',
                ]);

            if (! $response->successful()) {
                return ['status' => 'failed'];
            }

            $pages = $response->json()['query']['pages'] ?? [];
            foreach (is_array($pages) ? $pages : [] as $page) {
                $coords = is_array($page) ? ($page['coordinates'][0] ?? null) : null;
                if (is_array($coords) && isset($coords['lat'], $coords['lon'])) {
                    return ['status' => 'coords', 'lat' => (float) $coords['lat'], 'lng' => (float) $coords['lon']];
                }
            }

            return ['status' => 'none'];
        } catch (\Throwable $e) {
            Log::debug('Commons file coordinates lookup failed', [
                'title' => 'File:'.$filename,
                'message' => $e->getMessage(),
            ]);

            return ['status' => 'failed'];
        }
    }

    /**
     * One batched Commons query for up to BATCH_SIZE file titles. Returns
     * normalized-title => lookup, or null when the request failed / was
     * rate-limited (the caller marks those titles failed, never "none").
     *
     * @param  list<string>  $filenames
     * @return array<string, array{status: string, lat?: float, lng?: float}>|null
     */
    private function fetchCommonsBatch(array $filenames): ?array
    {
        $titles = implode('|', array_map(fn (string $name): string => 'File:'.$name, $filenames));

        try {
            $response = Http::timeout(15)
                ->withUserAgent(self::USER_AGENT)
                ->get(self::COMMONS_ENDPOINT, [
                    'action' => 'query',
                    'titles' => $titles,
                    'prop' => 'coordinates',
                    'format' => 'json',
                    'origin' => '*',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $pages = $response->json()['query']['pages'] ?? [];
            $lookups = [];
            foreach (is_array($pages) ? $pages : [] as $page) {
                if (! is_array($page)) {
                    continue;
                }
                $title = (string) ($page['title'] ?? '');
                if ($title === '') {
                    continue;
                }
                $coords = $page['coordinates'][0] ?? null;
                $lookups[self::normalizeTitle($title)] = is_array($coords) && isset($coords['lat'], $coords['lon'])
                    ? ['status' => 'coords', 'lat' => (float) $coords['lat'], 'lng' => (float) $coords['lon']]
                    : ['status' => 'none'];
            }

            return $lookups;
        } catch (\Throwable $e) {
            Log::debug('Commons batch coordinates lookup failed', [
                'count' => count($filenames),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private static function normalizeTitle(string $title): string
    {
        $title = preg_replace('/^file:/i', '', trim($title)) ?? $title;

        return mb_strtolower(str_replace('_', ' ', $title));
    }

    private static function cacheKey(string $filename): string
    {
        return 'commons-coords:'.sha1(self::normalizeTitle($filename));
    }

    /**
     * The Commons file name a Wikimedia URL points at, or null when the URL is
     * not a Wikimedia file. Handles both thumb
     * (`…/thumb/a/a3/Name.jpg/800px-Name.jpg`) and original
     * (`…/a/a3/Name.jpg`) layouts.
     */
    public function fileNameFromUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || (! str_ends_with($host, 'wikimedia.org') && ! str_ends_with($host, 'wikipedia.org'))) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', (string) parse_url($url, PHP_URL_PATH)),
            fn (string $segment): bool => $segment !== '',
        ));
        if ($segments === []) {
            return null;
        }

        $candidates = [end($segments)];
        if (count($segments) >= 2) {
            $candidates[] = $segments[count($segments) - 2];
        }

        foreach ($candidates as $candidate) {
            $name = (string) preg_replace('/^\d+px-/', '', rawurldecode((string) $candidate));
            if (preg_match('/\.(jpe?g|png|gif|webp|tiff?|svg)$/i', $name) === 1) {
                return str_replace('_', ' ', $name);
            }
        }

        return null;
    }

    private function sameFile(string $a, string $b): bool
    {
        $nameA = $this->fileNameFromUrl($a);
        $nameB = $this->fileNameFromUrl($b);

        return $nameA !== null && $nameB !== null && mb_strtolower($nameA) === mb_strtolower($nameB);
    }

    /**
     * @return array{verdict: string, distance_m: int|null, title: string|null}
     */
    private function result(string $verdict, ?int $distanceM, ?string $filename): array
    {
        return [
            'verdict' => $verdict,
            'distance_m' => $distanceM,
            'title' => $filename === null ? null : 'File:'.$filename,
        ];
    }

    private function meters(float $km): int
    {
        return (int) round($km * 1000);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthKm * 2 * asin(min(1.0, sqrt($a)));
    }
}
