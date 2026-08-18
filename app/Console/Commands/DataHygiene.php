<?php

namespace App\Console\Commands;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Restaurant;
use App\Services\AiEnrichmentService;
use App\Services\RestaurantDeduplicationService;
use App\Services\RestaurantValidationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Continuous restaurant data-hygiene pass.
 *
 * Runs daily and deterministically cleans the corpus in-place:
 *   1. normalizes state (full names → abbreviations, lowercase → uppercase,
 *      foreign/junk → null), city (title-case with small-word exceptions),
 *      name/address whitespace (collapse double spaces, strip trailing), and
 *      phone (digits-only);
 *   2. merges true duplicates (exact name+city+coords, and same-phone+city
 *      groups) reusing {@see RestaurantDeduplicationService::mergePair} —
 *      chain locations with the same name but different city/coords are never
 *      merged;
 *   3. AI-re-derives junk rows (one-char names, empty shells) and, when the
 *      model cannot identify the restaurant, hard-deletes them;
 *   4. AI-enriches still-missing fields (description, price_range, phone,
 *      website_url) on the neediest rows first, bounded per run;
 *   5. logs a per-run summary to the enrichment channel.
 *
 * Default is a read-only dry run; pass --apply to persist.
 */
class DataHygiene extends Command
{
    protected $signature = 'restaurants:data-hygiene
        {--apply : Persist changes (default is a read-only dry run)}
        {--limit= : Bound the run: merge at most N duplicate pairs and enrich at most N rows (enrich is also capped by the daily AI limit)}';

    protected $description = 'Normalize restaurant fields and merge true duplicates (continuous hygiene pass)';

    /**
     * Full US state/territory names keyed by their uppercase form.
     *
     * @var array<string, string>
     */
    private const STATE_ABBREVIATIONS = [
        'ALABAMA' => 'AL',
        'ALASKA' => 'AK',
        'ARIZONA' => 'AZ',
        'ARKANSAS' => 'AR',
        'CALIFORNIA' => 'CA',
        'COLORADO' => 'CO',
        'CONNECTICUT' => 'CT',
        'DELAWARE' => 'DE',
        'FLORIDA' => 'FL',
        'GEORGIA' => 'GA',
        'HAWAII' => 'HI',
        'IDAHO' => 'ID',
        'ILLINOIS' => 'IL',
        'INDIANA' => 'IN',
        'IOWA' => 'IA',
        'KANSAS' => 'KS',
        'KENTUCKY' => 'KY',
        'LOUISIANA' => 'LA',
        'MAINE' => 'ME',
        'MARYLAND' => 'MD',
        'MASSACHUSETTS' => 'MA',
        'MICHIGAN' => 'MI',
        'MINNESOTA' => 'MN',
        'MISSISSIPPI' => 'MS',
        'MISSOURI' => 'MO',
        'MONTANA' => 'MT',
        'NEBRASKA' => 'NE',
        'NEVADA' => 'NV',
        'NEW HAMPSHIRE' => 'NH',
        'NEW JERSEY' => 'NJ',
        'NEW MEXICO' => 'NM',
        'NEW YORK' => 'NY',
        'NORTH CAROLINA' => 'NC',
        'NORTH DAKOTA' => 'ND',
        'OHIO' => 'OH',
        'OKLAHOMA' => 'OK',
        'OREGON' => 'OR',
        'PENNSYLVANIA' => 'PA',
        'RHODE ISLAND' => 'RI',
        'SOUTH CAROLINA' => 'SC',
        'SOUTH DAKOTA' => 'SD',
        'TENNESSEE' => 'TN',
        'TEXAS' => 'TX',
        'UTAH' => 'UT',
        'VERMONT' => 'VT',
        'VIRGINIA' => 'VA',
        'WASHINGTON' => 'WA',
        'WEST VIRGINIA' => 'WV',
        'WISCONSIN' => 'WI',
        'WYOMING' => 'WY',
        'DISTRICT OF COLUMBIA' => 'DC',
    ];

    /**
     * Words kept lowercase in city title-casing (unless the first word).
     *
     * @var list<string>
     */
    private const SMALL_WORDS = [
        'a', 'an', 'and', 'at', 'but', 'by', 'for', 'in', 'of', 'on', 'or',
        'the', 'to', 'with', 'de', 'du', 'des', 'la', 'le', 'van', 'von', 'der', 'den',
    ];

    /**
     * Max AI-enrichment jobs dispatched per run. Keeps the daily AI quota
     * (Groq free tier) from being exhausted by an unbounded sweep; the next
     * day's run picks up where this one left off.
     */
    private const ENRICH_DAILY_LIMIT = 200;

    public function __construct(
        private readonly RestaurantDeduplicationService $dedupe,
        private readonly RestaurantValidationService $validation,
        private readonly AiEnrichmentService $aiEnrichment,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = $this->parseLimit();

        $restaurants = Restaurant::query()
            ->get(['id', 'name', 'address', 'city', 'state', 'phone', 'latitude', 'longitude']);

        $normalized = $this->normalizeFields($restaurants, $apply);

        $mergeStats = $this->mergeDuplicates($normalized, $apply, $limit);

        $junkStats = $this->rederiveOrDeleteJunk($apply);

        $enrichStats = $this->enrichMissingFields($apply, $limit);

        $this->renderSummary($apply, $limit, $this->normalizationCounts, $mergeStats, $junkStats, $enrichStats);
        $this->logSummary($apply, $limit, $this->normalizationCounts, $mergeStats, $junkStats, $enrichStats);

        return self::SUCCESS;
    }

    /**
     * Parse the --limit option into a positive-int bound, or null when unset
     * (or set to a non-positive/non-numeric value) meaning "unbounded" (merge)
     * / "use the default cap" (enrich).
     */
    private function parseLimit(): ?int
    {
        $limit = $this->option('limit');

        return ($limit !== null && (int) $limit > 0) ? (int) $limit : null;
    }

    /**
     * @var array{
     *     state: int,
     *     city: int,
     *     name: int,
     *     address: int,
     *     phone: int,
     * }
     */
    private array $normalizationCounts = ['state' => 0, 'city' => 0, 'name' => 0, 'address' => 0, 'phone' => 0];

    /**
     * @var array{detected: int, rederived: int, deleted: int, skipped: int}
     */
    private array $junkStats = ['detected' => 0, 'rederived' => 0, 'deleted' => 0, 'skipped' => 0];

    /**
     * @var array{eligible: int, dispatched: int}
     */
    private array $enrichStats = ['eligible' => 0, 'dispatched' => 0];

    /**
     * Normalize state/city/name/address/phone on every row, persisting when
     * applying. Returns a map of id → normalized fields used by the merge pass
     * so detection is identical in dry-run and apply mode.
     *
     * @param  Collection<int, Restaurant>  $restaurants
     * @return array<int, array{name: ?string, city: ?string, state: ?string, phone: ?string, lat: ?float, lng: ?float}>
     */
    private function normalizeFields($restaurants, bool $apply): array
    {
        $normalized = [];

        foreach ($restaurants as $restaurant) {
            $updates = [];

            $state = $this->normalizeState($restaurant->state);
            if ($state !== $restaurant->state) {
                $updates['state'] = $state;
                $this->normalizationCounts['state']++;
            }

            $city = $this->titleCaseCity($restaurant->city);
            if ($city !== $restaurant->city) {
                $updates['city'] = $city;
                $this->normalizationCounts['city']++;
            }

            $name = $this->collapseWhitespace($restaurant->name);
            if ($name !== null && $name !== $restaurant->name) {
                $updates['name'] = $name;
                $this->normalizationCounts['name']++;
            }

            $address = $this->collapseWhitespace($restaurant->address);
            if ($address !== $restaurant->address) {
                $updates['address'] = $address;
                $this->normalizationCounts['address']++;
            }

            $phone = $this->validation->normalizePhone($restaurant->phone);
            if ($phone !== $restaurant->phone) {
                $updates['phone'] = $phone;
                $this->normalizationCounts['phone']++;
            }

            if ($apply && $updates !== []) {
                $restaurant->update($updates);
            }

            $normalized[$restaurant->id] = [
                'name' => $name ?? $restaurant->name,
                'city' => $city ?? $restaurant->city,
                'state' => $state ?? $restaurant->state,
                'phone' => $phone,
                'lat' => $restaurant->latitude,
                'lng' => $restaurant->longitude,
            ];
        }

        return $normalized;
    }

    /**
     * Merge true duplicates: exact name+city+coords, then same-phone+city
     * groups, then same name+city+state rows whose coords are within a tight
     * proximity (snapshot copies persisted with slightly different coords).
     * Chain locations (same name, different city/coords) are left alone.
     * {@see $limit} bounds the total number of merge pairs performed per run
     * (lowest-id groups first), so a bounded pass works through the corpus over
     * successive runs rather than merging everything at once.
     *
     * @param  array<int, array{name: ?string, city: ?string, state: ?string, phone: ?string, lat: ?float, lng: ?float}>  $normalized
     * @return array{
     *     exact_pairs: int,
     *     phone_pairs: int,
     *     proximity_pairs: int,
     *     rows_deleted: int,
     *     links_repointed: int,
     *     links_dropped: int,
     *     engagement_merged: int,
     *     favorites_repointed: int,
     *     pivot_unions: int,
     * }
     */
    private function mergeDuplicates(array $normalized, bool $apply, ?int $limit): array
    {
        $totals = [
            'exact_pairs' => 0,
            'phone_pairs' => 0,
            'proximity_pairs' => 0,
            'rows_deleted' => 0,
            'links_repointed' => 0,
            'links_dropped' => 0,
            'engagement_merged' => 0,
            'favorites_repointed' => 0,
            'pivot_unions' => 0,
        ];

        ksort($normalized, SORT_NUMERIC);

        $removed = [];
        $budget = $limit;

        // Exact name + city + coords.
        $exactGroups = [];
        foreach ($normalized as $id => $row) {
            if ($row['lat'] === null || $row['lng'] === null || $row['name'] === null || $row['city'] === null) {
                continue;
            }

            $key = $row['name']."\0".$row['city']."\0".$row['lat']."\0".$row['lng'];
            $exactGroups[$key][] = $id;
        }

        $result = $this->mergeGroups($exactGroups, $apply, $removed, $budget);
        $removed = $result['removed'];
        $totals['exact_pairs'] = $result['pairs'];
        $totals = $this->accumulateMergeStats($totals, $result['stats']);

        if ($budget !== null && $budget <= 0) {
            return $totals;
        }

        // Same normalized phone + city.
        $phoneGroups = [];
        foreach ($normalized as $id => $row) {
            if (isset($removed[$id]) || empty($row['phone']) || empty($row['city'])) {
                continue;
            }

            $key = $row['phone']."\0".$row['city'];
            $phoneGroups[$key][] = $id;
        }

        $result = $this->mergeGroups($phoneGroups, $apply, $removed, $budget);
        $totals['phone_pairs'] = $result['pairs'];
        $totals = $this->accumulateMergeStats($totals, $result['stats']);

        if ($budget !== null && $budget <= 0) {
            return $totals;
        }

        // Same normalized name+city+state whose coords sit within a tight
        // radius. These are the audit's name+city+state dupe groups: the
        // live-search snapshot persistence path created a second row with
        // slightly different coords (and often no phone), so neither the exact
        // coords nor the phone passes catch them. Chains keep distinct
        // coords far apart, so proximity never merges them.
        $proximityGroups = $this->groupByProximity($normalized, $removed);

        $result = $this->mergeProximityGroups($proximityGroups, $normalized, $apply, $removed, $budget);
        $totals['proximity_pairs'] = $result['pairs'];
        $totals = $this->accumulateMergeStats($totals, $result['stats']);

        return $totals;
    }

    /**
     * The maximum distance (km) between two same-name rows that still count as
     * the same venue. Tight enough that genuine chain locations (which sit
     * kilometres apart even in the same city) are never merged, yet loose
     * enough to catch live-search snapshot copies whose coords differ by a few
     * metres to a few hundred metres.
     */
    private const PROXIMITY_RADIUS_KM = 0.15;

    /**
     * Group rows sharing a normalized name+city+state whose coords are within
     * {@see self::PROXIMITY_RADIUS_KM} of the group's lowest-id row. Rows that
     * fall outside every existing group's radius start their own group, so
     * distinct chain locations in the same city are never merged together.
     *
     * @param  array<int, array{name: ?string, city: ?string, state: ?string, phone: ?string, lat: ?float, lng: ?float}>  $normalized
     * @param  array<int, true>  $removed
     * @return array<int, array<int, int>>
     */
    private function groupByProximity(array $normalized, array $removed): array
    {
        // $groups[$key] is a list of groups; each group is a list of ids whose
        // anchor (first id) is the lowest-id row for that cluster.
        $groups = [];

        ksort($normalized, SORT_NUMERIC);

        foreach ($normalized as $id => $row) {
            if (isset($removed[$id]) || $row['lat'] === null || $row['lng'] === null
                || $row['name'] === null || $row['city'] === null) {
                continue;
            }

            $key = $row['name']."\0".$row['city']."\0".($row['state'] ?? '');
            $assigned = false;

            foreach ($groups[$key] ?? [] as $groupIndex => $groupIds) {
                $anchorId = $groupIds[0];
                $anchor = $normalized[$anchorId] ?? null;
                if ($anchor === null || $anchor['lat'] === null || $anchor['lng'] === null) {
                    continue;
                }

                if ($this->haversineKm($row['lat'], $row['lng'], $anchor['lat'], $anchor['lng']) <= self::PROXIMITY_RADIUS_KM) {
                    $groups[$key][$groupIndex][] = $id;
                    $assigned = true;

                    break;
                }
            }

            if (! $assigned) {
                $groups[$key][] = [$id];
            }
        }

        $result = [];
        foreach ($groups as $key => $keyGroups) {
            foreach ($keyGroups as $ids) {
                if (count($ids) > 1) {
                    $result[] = $ids;
                }
            }
        }

        return $result;
    }

    /**
     * Merge every proximity group: keep its lowest-id row, merge each sibling
     * within the radius into it, decrementing the shared pair {@see $budget}.
     *
     * @param  array<int, array<int, int>>  $groups
     * @param  array<int, array{name: ?string, city: ?string, state: ?string, phone: ?string, lat: ?float, lng: ?float}>  $normalized
     * @param  array<int, true>  $removed
     * @return array{
     *     pairs: int,
     *     stats: array{
     *         rows_deleted: int,
     *         links_repointed: int,
     *         links_dropped: int,
     *         engagement_merged: int,
     *         favorites_repointed: int,
     *         pivot_unions: int,
     *     },
     * }
     */
    private function mergeProximityGroups(array $groups, array $normalized, bool $apply, array $removed, ?int &$budget): array
    {
        $stats = [
            'rows_deleted' => 0,
            'links_repointed' => 0,
            'links_dropped' => 0,
            'engagement_merged' => 0,
            'favorites_repointed' => 0,
            'pivot_unions' => 0,
        ];

        $pairs = 0;

        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }

            sort($ids, SORT_NUMERIC);
            $keepId = array_shift($ids);

            foreach ($ids as $dupeId) {
                if (isset($removed[$dupeId])) {
                    continue;
                }

                if ($budget !== null && $budget <= 0) {
                    break 2;
                }

                $pair = $this->dedupe->mergePair($keepId, $dupeId, $apply);

                $pairs++;
                $stats['rows_deleted'] += $pair['rows_deleted'];
                $stats['links_repointed'] += $pair['links_repointed'];
                $stats['links_dropped'] += $pair['links_dropped'];
                $stats['engagement_merged'] += $pair['engagement_merged'];
                $stats['favorites_repointed'] += $pair['favorites_repointed'];
                $stats['pivot_unions'] += $pair['pivot_unions'];

                $removed[$dupeId] = true;

                if ($budget !== null) {
                    $budget--;
                }
            }
        }

        return ['pairs' => $pairs, 'stats' => $stats];
    }

    /**
     * Haversine distance between two coordinate pairs in kilometres.
     */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Merge every group with >1 member into its earliest (lowest-id) row,
     * decrementing the shared pair {@see $budget} until it is exhausted.
     *
     * @param  array<string, array<int, int>>  $groups
     * @param  array<int, true>  $removed
     * @return array{
     *     removed: array<int, true>,
     *     pairs: int,
     *     stats: array{
     *         rows_deleted: int,
     *         links_repointed: int,
     *         links_dropped: int,
     *         engagement_merged: int,
     *         favorites_repointed: int,
     *         pivot_unions: int,
     *     },
     * }
     */
    private function mergeGroups(array $groups, bool $apply, array $removed, ?int &$budget): array
    {
        $stats = [
            'rows_deleted' => 0,
            'links_repointed' => 0,
            'links_dropped' => 0,
            'engagement_merged' => 0,
            'favorites_repointed' => 0,
            'pivot_unions' => 0,
        ];

        $pairs = 0;

        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }

            sort($ids, SORT_NUMERIC);
            $keepId = array_shift($ids);

            foreach ($ids as $dupeId) {
                if (isset($removed[$dupeId])) {
                    continue;
                }

                if ($budget !== null && $budget <= 0) {
                    break 2;
                }

                $pair = $this->dedupe->mergePair($keepId, $dupeId, $apply);

                $pairs++;
                $stats['rows_deleted'] += $pair['rows_deleted'];
                $stats['links_repointed'] += $pair['links_repointed'];
                $stats['links_dropped'] += $pair['links_dropped'];
                $stats['engagement_merged'] += $pair['engagement_merged'];
                $stats['favorites_repointed'] += $pair['favorites_repointed'];
                $stats['pivot_unions'] += $pair['pivot_unions'];

                $removed[$dupeId] = true;

                if ($budget !== null) {
                    $budget--;
                }
            }
        }

        return ['removed' => $removed, 'pairs' => $pairs, 'stats' => $stats];
    }

    /**
     * @param  array{
     *     exact_pairs: int,
     *     phone_pairs: int,
     *     proximity_pairs: int,
     *     rows_deleted: int,
     *     links_repointed: int,
     *     links_dropped: int,
     *     engagement_merged: int,
     *     favorites_repointed: int,
     *     pivot_unions: int,
     * }  $totals
     * @param  array{
     *     rows_deleted: int,
     *     links_repointed: int,
     *     links_dropped: int,
     *     engagement_merged: int,
     *     favorites_repointed: int,
     *     pivot_unions: int,
     * }  $stats
     * @return array{
     *     exact_pairs: int,
     *     phone_pairs: int,
     *     proximity_pairs: int,
     *     rows_deleted: int,
     *     links_repointed: int,
     *     links_dropped: int,
     *     engagement_merged: int,
     *     favorites_repointed: int,
     *     pivot_unions: int,
     * }
     */
    private function accumulateMergeStats(array $totals, array $stats): array
    {
        foreach ($stats as $key => $value) {
            $totals[$key] += $value;
        }

        return $totals;
    }

    /**
     * Detect junk rows (one-char names, empty shells), attempt an AI name
     * re-derivation, and hard-delete when the model cannot identify the
     * restaurant. Requires --apply to mutate; without an AI key the rows are
     * detected but left untouched (never deleted blind).
     *
     * @return array{detected: int, rederived: int, deleted: int, skipped: int}
     */
    private function rederiveOrDeleteJunk(bool $apply): array
    {
        $junk = $this->detectJunkRows();
        $this->junkStats['detected'] = $junk->count();

        if (! $apply || $junk->isEmpty()) {
            return $this->junkStats;
        }

        $hasAiKey = ! empty(config('services.ai.api_key'));

        foreach ($junk as $restaurant) {
            if (! $hasAiKey) {
                $this->junkStats['skipped']++;

                continue;
            }

            try {
                $name = $this->aiEnrichment->rederiveName($restaurant->toArray());
            } catch (\Throwable $e) {
                Log::channel('enrichment')->warning('Junk re-derivation failed (leaving row for next run)', [
                    'restaurant_id' => $restaurant->id,
                    'message' => $e->getMessage(),
                ]);
                $this->junkStats['skipped']++;

                continue;
            }

            if ($name !== null) {
                $oldName = $restaurant->name;
                $restaurant->update(['name' => $name]);
                $this->junkStats['rederived']++;
                Log::channel('enrichment')->info('Junk row re-derived name', [
                    'restaurant_id' => $restaurant->id,
                    'old_name' => $oldName,
                    'new_name' => $name,
                ]);
            } else {
                $this->hardDeleteJunk($restaurant);
                $this->junkStats['deleted']++;
            }
        }

        return $this->junkStats;
    }

    /**
     * @return Collection<int, Restaurant>
     */
    private function detectJunkRows(): Collection
    {
        return Restaurant::query()
            ->where(function ($q) {
                $q->whereRaw('length(trim(name)) = 1')
                    ->orWhere(function ($emptyShell) {
                        $emptyShell->where(function ($name) {
                            $name->whereNull('name')->orWhereRaw("trim(name) = ''");
                        })
                            ->where(function ($address) {
                                $address->whereNull('address')->orWhereRaw("trim(address) = ''");
                            })
                            ->where(function ($phone) {
                                $phone->whereNull('phone')->orWhereRaw("trim(phone) = ''");
                            })
                            ->where(function ($website) {
                                $website->whereNull('website_url')->orWhereRaw("trim(website_url) = ''");
                            });
                    });
            })
            ->get();
    }

    /**
     * Permanently remove a junk row and its relational rows. Mirrors the
     * dedupe service's cleanup: social links, engagement, favorites and the
     * cuisine pivot are removed before the restaurant row itself.
     */
    private function hardDeleteJunk(Restaurant $restaurant): void
    {
        DB::table('restaurant_social_links')->where('restaurant_id', $restaurant->id)->delete();
        DB::table('restaurant_engagement')->where('restaurant_id', $restaurant->id)->delete();
        DB::table('favorite_restaurant_user')->where('restaurant_id', $restaurant->id)->delete();
        DB::table('cuisine_restaurant')->where('restaurant_id', $restaurant->id)->delete();

        $restaurant->delete();

        Log::channel('enrichment')->info('Junk row hard-deleted', [
            'restaurant_id' => $restaurant->id,
            'name' => $restaurant->name,
        ]);
    }

    /**
     * Dispatch {@see EnrichRestaurantWithAi} jobs for rows still missing
     * description/price_range/phone/website_url, highest popularity score
     * first, bounded to the --limit option per run but never above
     * {@see self::ENRICH_DAILY_LIMIT}. Requires --apply and an AI key;
     * otherwise only the eligible count is reported.
     *
     * @return array{eligible: int, dispatched: int}
     */
    private function enrichMissingFields(bool $apply, ?int $limit): array
    {
        $cap = $limit === null ? self::ENRICH_DAILY_LIMIT : min($limit, self::ENRICH_DAILY_LIMIT);

        $eligible = Restaurant::query()
            ->whereNotNull('name')
            ->whereRaw("trim(name) <> ''")
            ->where(function ($missing) {
                $missing->whereNull('description')->orWhereRaw("trim(description) = ''")
                    ->orWhereNull('price_range')->orWhereRaw("trim(price_range) = ''")
                    ->orWhereNull('phone')->orWhereRaw("trim(phone) = ''")
                    ->orWhereNull('website_url')->orWhereRaw("trim(website_url) = ''");
            })
            ->orderByRaw('COALESCE(popularity_score, 0) DESC')
            ->orderBy('id')
            ->limit($cap)
            ->get(['id', 'name']);

        $this->enrichStats['eligible'] = $eligible->count();

        if (! $apply || empty(config('services.ai.api_key'))) {
            return $this->enrichStats;
        }

        foreach ($eligible as $restaurant) {
            EnrichRestaurantWithAi::dispatch($restaurant->id);
            $this->enrichStats['dispatched']++;
        }

        return $this->enrichStats;
    }

    private function normalizeState(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        $state = $this->collapseWhitespace($state);
        if ($state === null) {
            return null;
        }

        $upper = strtoupper($state);

        if (isset(self::STATE_ABBREVIATIONS[$upper])) {
            return self::STATE_ABBREVIATIONS[$upper];
        }

        if (in_array($upper, self::STATE_ABBREVIATIONS, true)) {
            return $upper;
        }

        return null;
    }

    private function titleCaseCity(?string $city): ?string
    {
        if ($city === null) {
            return null;
        }

        $city = $this->collapseWhitespace($city);
        if ($city === null) {
            return null;
        }

        $words = preg_split('/\s+/', $city);
        $words = $words === false ? [] : $words;

        $out = [];
        foreach ($words as $i => $word) {
            $lower = mb_strtolower($word);
            if ($i > 0 && in_array($lower, self::SMALL_WORDS, true)) {
                $out[] = $lower;
            } else {
                $out[] = mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return implode(' ', $out);
    }

    private function collapseWhitespace(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $collapsed = trim((string) preg_replace('/\s+/', ' ', $value));

        return $collapsed === '' ? null : $collapsed;
    }

    /**
     * @param  array{state: int, city: int, name: int, address: int, phone: int}  $normalizationCounts
     * @param  array{
     *     exact_pairs: int,
     *     phone_pairs: int,
     *     proximity_pairs: int,
     *     rows_deleted: int,
     *     links_repointed: int,
     *     links_dropped: int,
     *     engagement_merged: int,
     *     favorites_repointed: int,
     *     pivot_unions: int,
     * }  $mergeStats
     * @param  array{detected: int, rederived: int, deleted: int, skipped: int}  $junkStats
     * @param  array{eligible: int, dispatched: int}  $enrichStats
     */
    private function renderSummary(bool $apply, ?int $limit, array $normalizationCounts, array $mergeStats, array $junkStats, array $enrichStats): void
    {
        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (changes persisted)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));
        $this->line('Pass bound (--limit): '.($limit === null ? 'unbounded' : (string) $limit));
        $this->line('State normalized: '.$normalizationCounts['state']);
        $this->line('City normalized: '.$normalizationCounts['city']);
        $this->line('Name normalized: '.$normalizationCounts['name']);
        $this->line('Address normalized: '.$normalizationCounts['address']);
        $this->line('Phone normalized: '.$normalizationCounts['phone']);
        $this->line('Exact duplicate pairs merged: '.$mergeStats['exact_pairs']);
        $this->line('Same-phone/city pairs merged: '.$mergeStats['phone_pairs']);
        $this->line('Proximity duplicate pairs merged: '.$mergeStats['proximity_pairs']);
        $this->line('Duplicate rows deleted: '.$mergeStats['rows_deleted']);
        $this->line('Social links repointed: '.$mergeStats['links_repointed']);
        $this->line('Social links dropped (platform collision): '.$mergeStats['links_dropped']);
        $this->line('Engagement rows repointed: '.$mergeStats['engagement_merged']);
        $this->line('Favorites repointed: '.$mergeStats['favorites_repointed']);
        $this->line('Cuisine pivot unions: '.$mergeStats['pivot_unions']);
        $this->line('Junk rows detected: '.$junkStats['detected']);
        $this->line('Junk rows re-derived: '.$junkStats['rederived']);
        $this->line('Junk rows hard-deleted: '.$junkStats['deleted']);
        $this->line('Junk rows skipped (no AI key / error): '.$junkStats['skipped']);
        $this->line('AI-enrich eligible: '.$enrichStats['eligible']);
        $this->line('AI-enrich jobs dispatched: '.$enrichStats['dispatched']);
    }

    /**
     * @param  array{state: int, city: int, name: int, address: int, phone: int}  $normalizationCounts
     * @param  array{
     *     exact_pairs: int,
     *     phone_pairs: int,
     *     proximity_pairs: int,
     *     rows_deleted: int,
     *     links_repointed: int,
     *     links_dropped: int,
     *     engagement_merged: int,
     *     favorites_repointed: int,
     *     pivot_unions: int,
     * }  $mergeStats
     * @param  array{detected: int, rederived: int, deleted: int, skipped: int}  $junkStats
     * @param  array{eligible: int, dispatched: int}  $enrichStats
     */
    private function logSummary(bool $apply, ?int $limit, array $normalizationCounts, array $mergeStats, array $junkStats, array $enrichStats): void
    {
        Log::channel('enrichment')->info('Data hygiene run complete', [
            'applied' => $apply,
            'pass_limit' => $limit,
            'state_normalized' => $normalizationCounts['state'],
            'city_normalized' => $normalizationCounts['city'],
            'name_normalized' => $normalizationCounts['name'],
            'address_normalized' => $normalizationCounts['address'],
            'phone_normalized' => $normalizationCounts['phone'],
            'exact_pairs_merged' => $mergeStats['exact_pairs'],
            'phone_pairs_merged' => $mergeStats['phone_pairs'],
            'proximity_pairs_merged' => $mergeStats['proximity_pairs'],
            'rows_deleted' => $mergeStats['rows_deleted'],
            'links_repointed' => $mergeStats['links_repointed'],
            'links_dropped' => $mergeStats['links_dropped'],
            'engagement_merged' => $mergeStats['engagement_merged'],
            'favorites_repointed' => $mergeStats['favorites_repointed'],
            'pivot_unions' => $mergeStats['pivot_unions'],
            'junk_detected' => $junkStats['detected'],
            'junk_rederived' => $junkStats['rederived'],
            'junk_deleted' => $junkStats['deleted'],
            'junk_skipped' => $junkStats['skipped'],
            'ai_enrich_eligible' => $enrichStats['eligible'],
            'ai_enrich_dispatched' => $enrichStats['dispatched'],
        ]);
    }
}
