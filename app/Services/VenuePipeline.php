<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Shared venue-processing pipeline.
 *
 * Extracted from LiveSearchService and RestaurantEnrichmentService to
 * eliminate ~250 LOC of duplicated dedup/filter/merge logic. Both services
 * delegate to this collaborator rather than maintaining their own copies.
 *
 * All methods are stateless pure functions operating on venue arrays.
 */
class VenuePipeline
{
    /** Haversine match threshold (km) for cross-source dedup/matching. */
    private const MATCH_RADIUS_KM = 0.2;

    /**
     * Google place_type substrings that signal a food establishment (matched
     * case-insensitively anywhere in the type string). "restaurant" is the primary
     * signal (covers "Ethiopian restaurant", "Takeout Restaurant", "Fast food
     * restaurant"); the rest cover drink/light-meal venues Google doesn't type
     * "restaurant" — bars, cafes, breweries, delis, caterers, buffets, food courts,
     * steak houses, fast food, etc. Google sends ASCII ("cafe", never "café"), so no
     * accented entry is needed. Verified disjoint from RETAIL_TYPE_PATTERNS below
     * (no food type contains store/market/grocery/wholesale/supplier).
     */
    private const FOOD_TYPE_PATTERNS = [
        'restaurant', 'cafe', 'coffee', 'bistro', 'diner', 'brasserie', 'gastropub',
        'brewpub', 'trattoria', 'osteria', 'eatery', 'brewery', 'distillery', 'winery',
        'taphouse', 'pizzeria', 'steakhouse', 'steak house', 'barbecue', 'takeaway',
        'takeout', 'fast food', 'food court', 'buffet', 'ice cream', 'creamery',
        'tea room', 'tea house', 'juice bar', 'juicery', 'brunch', 'sandwich', 'donut',
        'waffle', 'caterer', 'canteen', 'dhaba', 'deli',
    ];

    /**
     * Retail/wholesale place_type substrings. If ANY of a row's place_types matches
     * one of these, the row is a store/market/grocery — NOT a restaurant — and is
     * dropped even if it also carries a weak food type like "Deli"/"Bakery" (a grocery
     * with a deli counter is still a grocery). Checked BEFORE the food signal so a
     * weak type on a retail row can't rescue it. (Adversarial review: without this,
     * adding "deli" to keep standalone delis re-leaks "Greer's Downtown Market".)
     */
    private const RETAIL_TYPE_PATTERNS = ['store', 'grocery', 'market', 'wholesale', 'supplier'];

    /**
     * Ambiguous short drink-establishment words matched only as the LAST word of a
     * place_type (drinking bars are head-initial + bar-final: "Cocktail bar", "Wine
     * bar", "Bar") — so "bar"≠"barber", "wine bar" survives while "wine store" drops
     * (retail guard), and "bar association" (bar-first) is not a false-keep.
     */
    private const FOOD_TYPE_TAIL_WORDS = ['bar', 'pub', 'tavern'];

    /**
     * Non-restaurant place_type substrings (spec-046, extended for the
     * "Southern Bail Bonds" leak). A POSITIVE match here drops a row even if it also
     * carries a weak/ambiguous food type — e.g. a waxing salon tagged "Waxing hair
     * removal service" + a stray "Cafe" still drops on "wax"/"salon". Matched
     * case-insensitively as a substring against BOTH SerpApi's human phrases
     * ("Hair salon") and Google's snake_case enums (hair_care→"hair care"→matches
     * "hair") — see the _→space normalization in isFoodEstablishment(). Safe to be
     * broad here (unlike NAME_NON_RESTAURANT_PATTERNS below): this is matched against
     * Google's own type taxonomy, not free-text venue names, so there's no risk of a
     * pun restaurant name colliding with it.
     *
     * Recall caveat: 'spa' is deliberately ABSENT — it is a substring of 'spanish', and
     * 'spanish' is a registered cuisine, so matching it would drop every typed Spanish
     * restaurant (caught by an adversarial review). The other entries were verified
     * disjoint from every cuisine adjective and every FOOD_TYPE_PATTERN. A typed 'Spa' /
     * 'Day spa' still drops via the no-food-signal fallthrough. Lodging (hotel/motel) is
     * also excluded — hotels host real restaurants Google tags restaurant/bar.
     */
    private const NON_RESTAURANT_PATTERNS = [
        // Personal care / beauty (the "Brazilian wax" salon leak this targets)
        'salon', 'beauty', 'hair', 'barber', 'wax', 'nail', 'tanning',
        // Brow/lash studios typed "... bar" — without these, FOOD_TYPE_TAIL_WORDS 'bar'
        // would rescue "Eyebrows bar"/"Brow bar"/"Lash bar" as a drink venue.
        'brow', 'lash', 'eyebrow',
        // Worship / civic / education
        'church', 'mosque', 'temple', 'synagogue', 'school', 'university', 'museum',
        // Health (non-food) / fitness
        'gym', 'fitness', 'clinic', 'pharmacy', 'hospital', 'dentist', 'doctor',
        // Transit / infrastructure / civic
        'bridge', 'parking', 'gas station', 'fuel', 'association', 'library',
        // Financial / legal / professional services (the "Southern Bail Bonds" leak)
        'lawyer', 'attorney', 'notary', 'insurance', 'finance', 'accounting',
        'bank', 'atm', 'real estate', 'bail bond',
    ];

    /**
     * Non-restaurant NAME substrings (spec-046, extended for the "Southern Bail
     * Bonds" leak), applied to any venue with NO place_types at all — regardless of
     * source. Originally SerpApi-only (SerpApi matched the name but returned no
     * type — e.g. "European Wax Center" on a "brazilian" search); broadened because
     * BizData is provably an "unfiltered source" (it ignores its own `category`
     * query param and never carries a type signal into the app either — see
     * BizDataApiService::normalizeResults()), so an untyped BizData row needs the
     * same name-based fallback SerpApi rows get. Intentionally MINIMAL: broader
     * words (salon/spa/gym/pharmacy/hospital/bank/loan/tax/pawn/...) were TRIED and
     * removed — as NAME substrings they collide with real venue names ('spa'→Spain/
     * Spaghetti/Spartan, 'salon'→"Salon de thé" tea room, 'gym'→Gymkhana, 'bank'→"The
     * Bank" steakhouse, a real converted-bank-building restaurant pattern). Typed
     * non-restaurants are caught by NON_RESTAURANT_PATTERNS (place_types); this NAME
     * fallback covers only the rare untyped row, so recall safety wins over breadth.
     * See nameLooksNonRestaurant().
     */
    private const NAME_NON_RESTAURANT_PATTERNS = [
        'wax', 'waxing', 'bail bond', 'bail bonds', 'attorney', 'notary', 'insurance agency',
    ];

    public function __construct(
        private PriceLevelNormalizer $priceLevelNormalizer,
    ) {}

    /**
     * Filter garbage names from OSM-derived sources.
     * Rejects: numeric-only, generic cuisine words, quote-wrapped, price-leading.
     *
     * @param  array<array<string,mixed>>  $venues
     * @return array<array<string,mixed>>
     */
    public function filterGarbageNames(array $venues): array
    {
        $genericWords = config('restaurant-finder.filters.garbage_generic_words', []);
        $genericWordsLower = array_map(fn ($w) => strtolower(trim($w)), $genericWords);
        $genericWordsSet = array_flip($genericWordsLower);

        return array_values(array_filter($venues, function ($v) use ($genericWordsSet) {
            $name = $v['name'] ?? '';

            $trimmed = trim($name);
            $lower = strtolower($trimmed);

            if (empty($trimmed)) {
                return false;
            }

            // Numeric-only (e.g., "1803")
            if (preg_match('/^\d+$/', $trimmed)) {
                return false;
            }

            // Generic word as the entire name (e.g., "diner", "restaurant")
            if (isset($genericWordsSet[$lower])) {
                return false;
            }

            // Wrapped in stray/escaped quotes (e.g., "\"diner\"")
            if (preg_match('/^(["\']).+\1$/u', $trimmed)) {
                return false;
            }

            // Price-leading fragment (e.g., "$1.50 Fresh Pizza", "€5 Menu")
            if (preg_match('/^[\$£€]\d+/u', $trimmed)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Cross-source deduplication using fuzzy name similarity AND haversine proximity.
     * Collapses duplicates within the match radius, preferring the row with more data.
     *
     * @param  array<array<string,mixed>>  $venues
     * @return array<array<string,mixed>>
     */
    public function crossSourceDedup(array $venues): array
    {
        if (empty($venues)) {
            return [];
        }

        $matchRadius = config('restaurant-finder.dedup.match_radius_km', self::MATCH_RADIUS_KM);
        $similarityThreshold = config('restaurant-finder.dedup.name_similarity_threshold', 85.0);

        $deduped = [];
        $consumed = [];

        foreach ($venues as $i => $a) {
            if (isset($consumed[$i])) {
                continue;
            }

            $merged = $a;

            foreach ($venues as $j => $b) {
                if ($i === $j || isset($consumed[$j])) {
                    continue;
                }

                if ($this->venuesMatch($a, $b, $matchRadius, $similarityThreshold)) {
                    // Merge non-empty fields from b into a (prefer more complete data)
                    $merged = $this->mergeVenues($merged, $b);
                    $consumed[$j] = true;
                }
            }

            $deduped[] = $merged;
        }

        return $deduped;
    }

    /**
     * Determine if two venues represent the same physical place.
     * Matches on EITHER (a) a normalized phone match within radius (spec-069 4A —
     * catches name variants >15% apart so a rating attaches to its counterpart),
     * OR (b) the classic fuzzy-name + haversine-proximity match.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public function venuesMatch(array $a, array $b, float $radius, float $similarityThreshold): bool
    {
        // 4A phone fast-path: same last-10-digits phone + within radius. Bypasses
        // the name check (the whole point — names diverge), but keeps proximity so
        // two distinct same-phone venues (chain central booking) don't false-merge.
        if ($this->phonesMatch($a, $b) && $this->withinRadius($a, $b, $radius)) {
            return true;
        }

        $nameA = strtolower(trim($a['name'] ?? ''));
        $nameB = strtolower(trim($b['name'] ?? ''));

        if ($nameA === '' || $nameB === '') {
            return false;
        }

        // Name similarity check (exact or fuzzy)
        if ($nameA === $nameB) {
            $nameSimilarity = 100.0;
        } else {
            similar_text($nameA, $nameB, $nameSimilarity);
        }

        if ($nameSimilarity < $similarityThreshold) {
            return false;
        }

        return $this->withinRadius($a, $b, $radius);
    }

    /**
     * Haversine proximity check with a null-island guard (0,0 coords = unknown).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function withinRadius(array $a, array $b, float $radius): bool
    {
        $latA = (float) ($a['lat'] ?? $a['latitude'] ?? 0);
        $lngA = (float) ($a['lng'] ?? $a['longitude'] ?? 0);
        $latB = (float) ($b['lat'] ?? $b['latitude'] ?? 0);
        $lngB = (float) ($b['lng'] ?? $b['longitude'] ?? 0);

        if ($latA === 0.0 || $lngA === 0.0 || $latB === 0.0 || $lngB === 0.0) {
            return false;
        }

        return $this->haversineKm($latA, $lngA, $latB, $lngB) <= $radius;
    }

    /**
     * spec-069 4A: two venues match by phone when both carry a phone whose last
     * 10 digits are equal (the unique-enough tail; the area/country prefix is
     * noisy across sources). Requires ≥10 digits so a shared short reservation
     * line can't false-merge. Gated by dedup.phone_match.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function phonesMatch(array $a, array $b): bool
    {
        if (! filter_var(config('restaurant-finder.dedup.phone_match', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $pa = $this->normalizePhone($a['phone'] ?? null);
        $pb = $this->normalizePhone($b['phone'] ?? null);

        return $pa !== null && $pb !== null && $pa === $pb;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (! is_string($digits) || strlen($digits) < 10) {
            return null;
        }

        return substr($digits, -10);
    }

    /**
     * Merge non-empty fields from source venue into target.
     * Prefers the target unless the source has more complete data (e.g., has rating).
     *
     * @param  array<string,mixed>  $target
     * @param  array<string,mixed>  $source
     * @return array<string,mixed>
     */
    public function mergeVenues(array $target, array $source): array
    {
        $fields = [
            'name', 'lat', 'lng', 'latitude', 'longitude',
            'address', 'city', 'state', 'postal_code', 'country',
            'phone', 'website_url', 'price_range', 'photo_url',
            'opening_hours', 'description', 'features',
            'yelp_rating', 'yelp_review_count', 'google_rating', 'google_review_count',
            'yelp_business_id', 'google_place_id',
            'source', 'distance', 'cuisine',
        ];

        $merged = $target;

        foreach ($fields as $field) {
            $sourceValue = $source[$field] ?? null;
            $targetValue = $target[$field] ?? null;

            // If target has no usable value, take from source. "No usable
            // value" is broader than === null: several normalizers build
            // their output with `$raw['field'] ?? null` chains, and raw
            // upstream APIs sometimes return the key as "" or [] instead of
            // omitting it (e.g. BizData's raw `phone: ""`) — `??` doesn't
            // fall through on those, so a strict null check left the source's
            // real value stranded (spec-105). Numeric 0/false are NOT
            // treated as blank — a literal 0 price_range or review_count is
            // real data.
            if ($this->isBlank($targetValue) && ! $this->isBlank($sourceValue)) {
                $merged[$field] = $sourceValue;
            }
        }

        // Rating families (google/yelp) are handled separately from the
        // null-gate loop above: `google_review_count`/`yelp_review_count`
        // default to 0 (not null) on every normalizer, so the null-gate never
        // fires for them — a target with review_count=0 would silently keep
        // that 0 even when the source carries a real count, which then
        // shrinks PopularityScoreService's Bayesian quality fully to the mean
        // (spec-094). Fixed per-family (not a single "has any rating" flag,
        // which let an existing yelp_rating block an update to google_rating/
        // google_review_count from a source that only has google data).
        foreach ([
            'google' => ['google_rating', 'google_review_count'],
            'yelp' => ['yelp_rating', 'yelp_review_count'],
        ] as [$ratingField, $reviewField]) {
            $sourceHasRating = ! empty($source[$ratingField]);
            $targetHasRating = ! empty($target[$ratingField]);

            if ($sourceHasRating && ! $targetHasRating) {
                $merged[$ratingField] = $source[$ratingField];

                if (($source[$reviewField] ?? null) !== null) {
                    $merged[$reviewField] = $source[$reviewField];
                }
            }
        }

        // Merge source tags (e.g., cuisines, categories) if present (from LiveSearchService)
        if (! empty($source['cuisines']) && empty($merged['cuisines'])) {
            $merged['cuisines'] = $source['cuisines'];
        }

        // Union gallery photos across sources (dedup by URL, cap 6).
        if (! empty($source['photos'])) {
            $unioned = array_values(array_unique(array_merge(
                $merged['photos'] ?? [],
                $source['photos'],
            )));
            $merged['photos'] = array_slice($unioned, 0, 6);
        }

        // spec-079: carry place_types + description across the merge. Previously
        // these were dropped, so when a rich SerpApi row ("Thai restaurant" type
        // + description) folded into a name-only OSM/BizData target, the merged
        // row lost exactly the fields stampCuisineMatchStrength (spec-071) and
        // the cuisine-relevance filter read → genuine cuisine matches stamped 0.0
        // and got demoted. Union place_types (dedup); prefer a non-empty
        // description (SerpApi's is the cuisine signal).
        if (! empty($source['place_types'])) {
            $merged['place_types'] = array_values(array_unique(array_merge(
                $merged['place_types'] ?? [],
                $source['place_types'],
            )));
        }
        if (! empty($source['description']) && empty($merged['description'])) {
            $merged['description'] = $source['description'];
        }

        return $merged;
    }

    /**
     * "No usable value" for merge-fold purposes: null, empty string, or an
     * empty array. Deliberately NOT true for 0/0.0/false — a literal numeric
     * 0 (e.g. price_range, or a review_count in an already-active rating
     * family) is real data, not absence (spec-105).
     */
    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * Re-sort the live-search venue array by the user's sort mode (spec-069 4B).
     * Called by LiveSearchService BEFORE boundResults() so the cap/page-slice
     * applies to the user-sorted set, not a score-pre-selected one (the old
     * bound-then-sort made ?sort=nearest miss the true nearest past #N).
     *
     * NULLS LAST in both directions; tiebreak = popularity_score DESC then name
     * ASC. The explicit null guards are LOAD-BEARING (PHP 8 TypeError on
     * `null <=> int`).
     *
     * spec-069 4C: the `rating` mode is credibility-aware — venues with fewer
     * than rating_sort_min_reviews sink below credible ones so a 5.0/3-review
     * venue can't beat 4.8/5000. Kill-switch ranking.rating_sort_credibility.
     *
     * @param  array<int, array<string, mixed>>  $venues
     * @return array<int, array<string, mixed>>
     */
    public function sortVenues(array $venues, string $sort, bool $hasCoords): array
    {
        if (count($venues) <= 1) {
            return $venues;
        }

        // nearest without coords falls back to best_match (parity with applySortMode).
        $effective = ($sort === 'nearest' && ! $hasCoords) ? 'best_match' : $sort;

        if ($effective === 'best_match') {
            return $venues; // already popularity_score desc from scoring
        }

        $minReviews = (int) config('restaurant-finder.ranking.rating_sort_min_reviews', 20);
        $credibility = filter_var(
            config('restaurant-finder.ranking.rating_sort_credibility', true), FILTER_VALIDATE_BOOL
        );

        $ratingKey = function (array $r) use ($minReviews, $credibility): ?float {
            $rating = $r['google_rating'] ?? $r['yelp_rating'] ?? null;
            if ($rating === null || ! is_numeric($rating)) {
                return null;
            }
            $rating = (float) $rating;
            $reviews = (float) ($r['google_review_count'] ?? $r['yelp_review_count'] ?? 0);

            // Sink non-credible ratings below all credible ones (ratings are 0-5,
            // so -10 guarantees the bucket). Among non-credible they still sort by
            // rating desc. With credibility off, the raw rating is the key.
            return ($credibility && $reviews < $minReviews) ? $rating - 10.0 : $rating;
        };

        usort($venues, function (array $a, array $b) use ($effective, $ratingKey): int {
            [$va, $vb, $desc] = match ($effective) {
                'nearest' => [$a['distance'] ?? null, $b['distance'] ?? null, false], // ASC: closest first
                'rating' => [$ratingKey($a), $ratingKey($b), true],                   // DESC: highest first
                'reviews' => [
                    $a['google_review_count'] ?? $a['yelp_review_count'] ?? null,
                    $b['google_review_count'] ?? $b['yelp_review_count'] ?? null,
                    true,
                ],
                'price' => [
                    $this->priceLevelNormalizer->normalize($a['price_range'] ?? null),
                    $this->priceLevelNormalizer->normalize($b['price_range'] ?? null),
                    false, // ASC: cheapest first
                ],
                'social_presence' => [
                    (int) (((int) ($a['social_links_count'] ?? 0)) > 0),
                    (int) (((int) ($b['social_links_count'] ?? 0)) > 0),
                    true, // DESC: venues with social links first
                ],
                'website_traffic' => [
                    $a['website_clicks_count'] ?? null,
                    $b['website_clicks_count'] ?? null,
                    true, // DESC: most clicks first
                ],
                default => [$a['popularity_score'] ?? null, $b['popularity_score'] ?? null, true],
            };

            // NULLS LAST in BOTH directions (null always sinks, regardless of $desc).
            if ($va === null && $vb === null) {
                return $this->sortTiebreak($a, $b);
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }

            $cmp = $desc ? ($vb <=> $va) : ($va <=> $vb);

            return $cmp !== 0 ? $cmp : $this->sortTiebreak($a, $b);
        });

        return $venues;
    }

    /**
     * spec-081: on a cuisine-scoped search, when enough confident matches exist
     * (cuisine_match >= confidence_threshold), drop the rest so wrong-cuisine
     * venues don't pollute the result list. When there aren't enough confident
     * venues, keep everything (padding mode) — this prevents returning too few
     * results for obscure cuisines or small towns.
     *
     * Unscoped searches pass through unchanged (no cuisine_match to judge).
     *
     * @param  array<int, array<string, mixed>>  $venues
     * @return array<int, array<string, mixed>>
     */
    public function filterByCuisineConfidence(array $venues, CuisineScope $scope): array
    {
        if (! $scope->isScoped()) {
            return $venues;
        }

        $threshold = (float) config('restaurant-finder.ranking.cuisine_confidence.confidence_threshold', 0.3);
        $minResults = (int) config('restaurant-finder.ranking.cuisine_confidence.min_confident_results', 2);

        $unfiltered = config('restaurant-finder.filters.cuisine_unfiltered_sources', ['bizdata']);
        $unfilteredSet = array_flip(array_map('strtolower', $unfiltered));

        $confident = [];
        foreach ($venues as $venue) {
            $cuisineMatch = (float) ($venue['cuisine_match'] ?? 0.0);
            if ($cuisineMatch >= $threshold) {
                $confident[] = $venue;
            }
        }

        if (count($confident) >= $minResults) {
            return $confident;
        }

        // Padding mode (fewer confident matches than min_results): keep the
        // confident rows plus ambiguous rows from trusted sources (a real venue
        // with no name keyword — e.g. "Olive Garden" for Italian — still shows,
        // ranked below matches). Drop zero-evidence rows from UNFILTERED sources
        // (BizData returns EVERY nearby restaurant regardless of the cuisine
        // query): they are pure noise, and without this a low-coverage city
        // floods its scoped results with unrelated venues like Applebee's.
        return array_values(array_filter($venues, function ($v) use ($threshold, $unfilteredSet) {
            $cuisineMatch = (float) ($v['cuisine_match'] ?? 0.0);
            if ($cuisineMatch >= $threshold) {
                return true;
            }

            $source = strtolower((string) ($v['source'] ?? ''));

            return ! isset($unfilteredSet[$source]);
        }));
    }

    /**
     * Deterministic tiebreak for live rows whose primary sort key is equal:
     * popularity_score DESC, then name ASC.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function sortTiebreak(array $a, array $b): int
    {
        $pa = (float) ($a['popularity_score'] ?? 0);
        $pb = (float) ($b['popularity_score'] ?? 0);
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }

        return ($a['name'] ?? '') <=> ($b['name'] ?? '');
    }

    /**
     * Calculate haversine distance between two coordinates in kilometers.
     * Unified implementation for both LiveSearchService and RestaurantEnrichmentService.
     */
    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Exact (case-insensitive) match or a high name-similarity ratio.
     * Used for matching names when proximity is already satisfied.
     * Replaces bare str_contains, which false-matched distinct venues whose names
     * are substrings of one another (e.g. "Pizza" vs "Pizza Express").
     */
    public function namesMatch(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        similar_text($a, $b, $percent);

        return $percent >= 85.0;
    }

    /**
     * Drop rows whose Google `place_types` indicate a NON-restaurant (spec-042).
     *
     * SerpApi's google_maps engine returns any place whose NAME matches the query,
     * so a generic category search (q="african near me") surfaced churches, bridges,
     * hair salons, grocery stores and museums that merely have the category word in
     * their name (SerpApi tags every row cuisine=Restaurant, so the type is the only
     * real discriminator). A row survives iff at least one place_type signals a food
     * establishment.
     *
     * Rows WITHOUT place_types: sources whose own query is already restaurant-scoped
     * (overpass/socrata) pass through untouched (recall-protective). A row with no
     * place_types AND no scoping guarantee (serpapi — name-match-scoped, not
     * restaurant-scoped; bizdata — provably ignores its own `category` query param,
     * see BizDataApiService::normalizeResults()) is dropped only if its NAME carries a
     * high-confidence non-restaurant word (spec-046, extended for the "Southern Bail
     * Bonds" leak): an untyped row whose name lacks those words is still kept, since
     * real restaurants are sometimes untyped too. Gated by
     * `filters.scrutinize_place_types` (default true). Runs before dedup (reads
     * per-source place_types before mergeVenues() can fold rows together).
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    public function filterNonRestaurants(array $results): array
    {
        if (! (bool) config('restaurant-finder.filters.scrutinize_place_types', true)) {
            return $results;
        }

        $kept = [];
        $dropped = [];
        foreach ($results as $r) {
            $placeTypes = $r['place_types'] ?? null;
            $source = (string) ($r['source'] ?? '');
            // No structured type. Sources that are restaurant-scoped by their own
            // query (overpass/socrata) are trusted (recall-protective). serpapi
            // (name-match-scoped) and bizdata (ignores its own category param) get a
            // conservative NAME check instead, since either can return a non-food
            // business that merely matched on name/proximity.
            if (! is_array($placeTypes) || empty($placeTypes)) {
                if (in_array($source, ['serpapi', 'bizdata'], true) && $this->nameLooksNonRestaurant($r['name'] ?? '')) {
                    $dropped[] = ['name' => $r['name'] ?? '', 'place_types' => $placeTypes, 'source' => $source, 'reason' => 'untyped non-restaurant name'];

                    continue;
                }
                $kept[] = $r;

                continue;
            }
            if ($this->isFoodEstablishment($placeTypes)) {
                $kept[] = $r;
            } else {
                $dropped[] = ['name' => $r['name'] ?? '', 'place_types' => $placeTypes];
            }
        }

        if (! empty($dropped)) {
            Log::info('Non-restaurant place_types filter dropped rows', [
                'count' => count($dropped),
                'dropped' => array_slice($dropped, 0, 20),
            ]);
        }

        return $kept;
    }

    /**
     * Does any of a row's Google place_types signal a food establishment? Google
     * returns human-readable type phrases ("African restaurant", "Cocktail bar",
     * "Coffee shop"); matched case-insensitively.
     *
     * @param  string[]  $placeTypes
     */
    private function isFoodEstablishment(array $placeTypes): bool
    {
        $types = [];
        foreach ($placeTypes as $type) {
            // Normalize to lowercase with underscores→spaces so the same patterns match
            // BOTH SerpApi's human phrases ("Cocktail bar", "Hair salon") AND Google's
            // snake_case enums ("cocktail_bar", "hair_care"). The tail-word check splits
            // on spaces, so "cocktail_bar" must become "cocktail bar" to surface its "bar".
            $t = strtolower(str_replace('_', ' ', (string) $type));
            if ($t !== '') {
                $types[] = $t;
            }
        }

        // Retail guard: any store/market/grocery/wholesale type → not a restaurant
        // (a grocery with a deli/bakery counter is still retail). Checked first so a
        // weak food type on a retail row cannot rescue it.
        foreach ($types as $t) {
            foreach (self::RETAIL_TYPE_PATTERNS as $retail) {
                if (str_contains($t, $retail)) {
                    return false;
                }
            }
        }

        // Non-restaurant guard (spec-046): a POSITIVE salon/spa/wax/church/gym/... type
        // → not a restaurant, dropped even if a weak food type is also present (a waxing
        // salon with a stray "Cafe" tag is still a salon). Recall-protective: a real
        // restaurant's types never contain these. Lodging is excluded (hotels host
        // restaurants) — see NON_RESTAURANT_PATTERNS.
        foreach ($types as $t) {
            foreach (self::NON_RESTAURANT_PATTERNS as $non) {
                if (str_contains($t, $non)) {
                    return false;
                }
            }
        }

        // Food signal: any restaurant/drink type, or a drink word as the last token.
        foreach ($types as $t) {
            foreach (self::FOOD_TYPE_PATTERNS as $pattern) {
                if (str_contains($t, $pattern)) {
                    return true;
                }
            }
            $tokens = preg_split('/[\s\/\-]+/u', $t) ?: [];
            $last = end($tokens) ?: '';
            if ($last !== '' && in_array($last, self::FOOD_TYPE_TAIL_WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * For an UNTYPED serpapi/bizdata row, does its name signal an obvious
     * non-restaurant? (spec-046 fallback for the waxing-salon leak, extended for the
     * "Southern Bail Bonds" leak — both sources can return a name-matched business
     * with no place type to classify by.) See NAME_NON_RESTAURANT_PATTERNS.
     */
    private function nameLooksNonRestaurant(string $name): bool
    {
        $name = strtolower($name);
        if ($name === '') {
            return false;
        }
        foreach (self::NAME_NON_RESTAURANT_PATTERNS as $pattern) {
            if (str_contains($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify a single ALREADY-PERSISTED venue as a likely non-restaurant, for
     * retroactive auditing (restaurants:audit-classification). Unlike
     * filterNonRestaurants() — used on live/enrichment batches, where the untyped
     * NAME fallback is source-gated to serpapi/bizdata because other sources are
     * already query-scoped — this checks ANY row with no place_types by name. A
     * persisted row's original source isn't always known (most existing rows
     * predate the source column), and this is a dry-run flag for human review, not
     * a silent filter, so the broader check is safe here.
     *
     * @param  array<string, mixed>  $venue
     * @return string|null a human-readable reason, or null if the venue looks fine
     */
    public function looksNonRestaurant(array $venue): ?string
    {
        $placeTypes = $venue['place_types'] ?? null;
        if (is_array($placeTypes) && ! empty($placeTypes)) {
            return $this->isFoodEstablishment($placeTypes)
                ? null
                : 'place_types: '.implode(', ', $placeTypes);
        }

        $text = trim(($venue['name'] ?? '').' '.($venue['description'] ?? ''));

        return $this->nameLooksNonRestaurant($text) ? 'name/description pattern match' : null;
    }
}
