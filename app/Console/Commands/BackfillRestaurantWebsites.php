<?php

namespace App\Console\Commands;

use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\CuisineTagMapper;
use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BackfillRestaurantWebsites extends Command
{
    protected $signature = 'restaurants:backfill-websites
        {--dry-run : Show what would be updated without making changes}
        {--limit=0 : Max restaurants to process (0 = unlimited)}
        {--skip-cache : Skip the cache lookup phase}
        {--skip-search : Skip the web search phase}';

    protected $description = 'Backfill missing website URLs from cache and web search, then scrape social links';

    private int $found = 0;

    private const CACHE_SOURCES = ['serpapi', 'preview', 'bizdata'];

    /**
     * Max restaurants whose menu URL + opening hours are scraped per run.
     * Bounded so a single daily sweep never hammers every website at once;
     * the scrape result is cached per-domain (7d TTL) so the next run picks up
     * where this one left off cheaply.
     */
    private const MENU_SCRAPE_DAILY_LIMIT = 200;

    /**
     * Max restaurants whose social links are scraped per run. Without this
     * cap the phase queries ALL active restaurants with a website but no
     * social links yet — a backlog that grows daily as the cache phase fills
     * in more website_urls, and had reached 17,639 rows in production,
     * ballooning a single run to 15-18 hours (vs. the 240-minute
     * withoutOverlapping mutex this command is scheduled under). Bounded +
     * ordered by id so each run advances through a different slice; a row
     * that fails to yield links keeps social_links_count=0 and is retried on
     * a later run.
     */
    private const SOCIAL_SCRAPE_DAILY_LIMIT = 400;

    private const DOMAIN_SKIP_PATTERNS = [
        '/facebook\.com/i', '/instagram\.com/i', '/twitter\.com/i', '/x\.com/i',
        '/yelp\.com/i', '/tripadvisor\.com/i', '/youtube\.com/i', '/tiktok\.com/i',
        '/linkedin\.com/i', '/pinterest\.com/i',
        '/google\.com/i', '/bing\.com/i', '/microsoft\.com/i',
        '/wikipedia\.org/i', '/menupix\.com/i', '/allmenus\.com/i',
        '/restaurantguru\.com/i', '/opentable\.com/i', '/seamless\.com/i',
        '/toasttab\.com/i', '/toast\.site/i', '/uorder\.io/i', '/bentoobox\.net/i',
    ];

    /**
     * Trailing qualifiers stripped from a cached venue's Google place type
     * before it is matched to a seeded cuisine ("Chinese restaurant" →
     * "Chinese"). Multi-word types ("Noodle house" → "Noodle") survive the
     * strip and simply fail to match a seeded cuisine, which is the safe
     * outcome. A type that IS a qualifier alone ("Bar") is left intact so it
     * can never collapse to an empty string.
     *
     * @var string[]
     */
    private const CUISINE_TYPE_QUALIFIERS = [
        'restaurant', 'bar', 'grill', 'kitchen', 'house', 'place', 'shop',
        'store', 'cafe', 'café', 'eatery', 'deli', 'joint', 'stand', 'catering',
        'caterer', 'lounge', 'diner', 'takeaway', 'takeout', 'bakery', 'bistro',
        'pub', 'buffet', 'food', 'cuisine', 'supplier', 'suppliers', 'parlor',
    ];

    public function handle(RestaurantWebsiteScraperService $scraper, CuisineTagMapper $cuisineTagMapper): int
    {
        $dryRun = $this->option('dry-run');
        $skipCache = $this->option('skip-cache');
        $skipSearch = $this->option('skip-search');

        if (! $skipCache) {
            $this->matchFromCache($dryRun, $cuisineTagMapper);
        }

        $remaining = $this->countMissing();
        if ($remaining > 0 && ! $skipSearch) {
            $this->info("Web searching for {$remaining} restaurant(s)...");
            $this->webSearch($dryRun);
        }

        $remaining = $this->countMissing();
        if ($remaining > 0 && ! $skipSearch) {
            $this->info("Guessing domains for {$remaining} restaurant(s)...");
            $this->guessFromTitle($dryRun);
        }

        $newWebsites = $this->countNewWebsites();
        if ($newWebsites > 0 && ! $dryRun) {
            $this->info("Scraping social links for {$newWebsites} new website(s)...");
            $this->scrapeSocialLinks($scraper);
        }

        $this->scrapeMenuData($scraper, $dryRun);

        $this->newLine();
        $this->info("Done. {$this->found} website(s) found.");
        $left = $this->countMissing();
        if ($left > 0) {
            $this->warn("  {$left} restaurant(s) still missing website URLs.");
        }

        return self::SUCCESS;
    }

    private function matchFromCache(bool $dryRun, CuisineTagMapper $cuisineTagMapper): void
    {
        $this->line('Building cache index...');
        $phoneIndex = [];
        $nameIndex = [];

        foreach (self::CACHE_SOURCES as $source) {
            $entries = ExternalApiCache::where('source', $source)->get();
            foreach ($entries as $entry) {
                // Some sources (preview) persist ONE restaurant object per
                // cache row rather than a list of venues; treat a non-list
                // payload as a single-venue list so its scalar fields are not
                // iterated as venues (which crashed parseExtractedPrice).
                $venues = $entry->data;
                if (! array_is_list($venues)) {
                    $venues = [$venues];
                }
                foreach ($venues as $venue) {
                    $website = $venue['website'] ?? $venue['website_url'] ?? null;
                    $parsedPrice = $this->parseExtractedPrice($venue);
                    $entryData = [
                        '_website' => $website,
                        '_price_range' => $parsedPrice,
                        '_phone' => $venue['phone'] ?? null,
                        '_description' => $this->sanitizeDescription($venue['description'] ?? null),
                        '_address' => $this->sanitizeAddress($venue['address'] ?? null),
                        '_opening_hours' => $this->normalizeCacheHours(
                            $venue['opening_hours'] ?? $venue['operating_hours'] ?? null
                        ),
                        '_photo' => $this->sanitizeCachePhoto($venue['thumbnail'] ?? null),
                        '_rating' => $this->sanitizeCacheRating($venue['rating'] ?? null),
                        '_review_count' => $this->sanitizeCacheReviewCount($venue['reviews'] ?? null),
                        '_cuisine_ids' => $this->cacheCuisineIds($venue, $cuisineTagMapper),
                    ];
                    // Index the venue only if it carries at least one field the
                    // cache phase can backfill. Website-less cached venues (which
                    // may still hold an address/phone/price/description) must not
                    // be skipped entirely, or those gaps go unserved.
                    if ($this->entryHasNothingUseful($entryData)) {
                        continue;
                    }
                    $name = $this->normalize($venue['title'] ?? $venue['name'] ?? '');
                    if ($name !== '') {
                        $nameIndex[$name] = $entryData;
                    }
                    $phone = $venue['phone'] ?? null;
                    if (! empty($phone)) {
                        $digits = substr(preg_replace('/\D+/', '', (string) $phone) ?? '', -10);
                        if (strlen($digits) === 10) {
                            $phoneIndex[$digits] = $entryData;
                        }
                    }
                }
            }
        }

        $this->line('  Name index: '.count($nameIndex).', Phone index: '.count($phoneIndex));

        $missing = $this->cacheCandidates()->get();
        $hits = 0;
        $phonesBackfilled = 0;
        $pricesBackfilled = 0;
        $descriptionsBackfilled = 0;
        $addressesBackfilled = 0;
        $hoursBackfilled = 0;
        $photosBackfilled = 0;
        $ratingsBackfilled = 0;
        $cuisinesBackfilled = 0;

        foreach ($missing as $restaurant) {
            $entryData = null;

            // Try name match
            $nameKey = $this->normalize($restaurant->name);
            if (isset($nameIndex[$nameKey])) {
                $entryData = $nameIndex[$nameKey];
            }

            // Try phone match
            if ($entryData === null) {
                $phoneDigits = substr((string) preg_replace('/\D+/', '', $restaurant->phone ?? ''), -10);
                if (strlen($phoneDigits) === 10 && isset($phoneIndex[$phoneDigits])) {
                    $entryData = $phoneIndex[$phoneDigits];
                }
            }

            if ($entryData !== null) {
                $updates = [];
                if (empty($restaurant->website_url) && is_string($entryData['_website'])) {
                    if (! $this->isSkipDomainUrl($entryData['_website'])) {
                        $updates['website_url'] = $entryData['_website'];
                    } else {
                        $this->line("  Skipped cache URL (non-restaurant domain): {$entryData['_website']}");
                    }
                }
                if (empty($restaurant->price_range) && is_string($entryData['_price_range'])) {
                    $updates['price_range'] = $entryData['_price_range'];
                }
                $phone = $this->normalizeCachePhone($entryData['_phone'] ?? null);
                if (empty($restaurant->phone) && $phone !== null) {
                    $updates['phone'] = $phone;
                }
                if (empty($restaurant->description) && is_string($entryData['_description'])) {
                    $updates['description'] = $entryData['_description'];
                }
                if (empty($restaurant->address) && is_string($entryData['_address'])) {
                    $updates['address'] = $entryData['_address'];
                }
                if (empty($restaurant->opening_hours) && is_array($entryData['_opening_hours'])) {
                    $updates['opening_hours'] = $entryData['_opening_hours'];
                }
                if (empty($restaurant->photo_url) && is_string($entryData['_photo'])) {
                    $updates['photo_url'] = $entryData['_photo'];
                    $updates['photo_source'] = 'website';
                }
                if (($restaurant->google_rating === null || $restaurant->google_rating <= 0) && is_float($entryData['_rating'])) {
                    $updates['google_rating'] = $entryData['_rating'];
                    $updates['google_review_count'] = $entryData['_review_count'] ?? 0;
                }
                if (! empty($updates) && ! $dryRun) {
                    $restaurant->update($updates);
                    Log::channel('enrichment')->info('Cache backfill from cached search data', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'website_url' => $updates['website_url'] ?? null,
                        'price_range' => $updates['price_range'] ?? null,
                        'phone' => $updates['phone'] ?? null,
                        'description' => $updates['description'] ?? null,
                        'address' => $updates['address'] ?? null,
                        'opening_hours' => $updates['opening_hours'] ?? null,
                        'photo_url' => $updates['photo_url'] ?? null,
                        'google_rating' => $updates['google_rating'] ?? null,
                        'google_review_count' => $updates['google_review_count'] ?? null,
                    ]);
                }
                if (isset($updates['phone'])) {
                    $phonesBackfilled++;
                }
                if (isset($updates['price_range'])) {
                    $pricesBackfilled++;
                }
                if (isset($updates['description'])) {
                    $descriptionsBackfilled++;
                }
                if (isset($updates['address'])) {
                    $addressesBackfilled++;
                }
                if (isset($updates['opening_hours'])) {
                    $hoursBackfilled++;
                }
                if (isset($updates['photo_url'])) {
                    $photosBackfilled++;
                }
                if (isset($updates['google_rating'])) {
                    $ratingsBackfilled++;
                }
                // Attach cached Google place types as cuisine tags — fill-empty
                // only: a restaurant that already carries evidence-backed tags
                // is never touched (the audit sweep owns tag hygiene), and an
                // untagged row becomes cuisine-searchable for free.
                if ($entryData['_cuisine_ids'] !== [] && $restaurant->cuisines()->doesntExist()) {
                    if (! $dryRun) {
                        $restaurant->cuisines()->attach($entryData['_cuisine_ids']);
                        Log::channel('enrichment')->info('Cuisine tags backfilled from cached search data', [
                            'restaurant_id' => $restaurant->id,
                            'restaurant_name' => $restaurant->name,
                            'cuisine_ids' => $entryData['_cuisine_ids'],
                        ]);
                    }
                    $cuisinesBackfilled++;
                }
                $hits++;
                if (isset($updates['website_url'])) {
                    $this->found++;
                }
            }
        }

        $this->line("  Cache matched {$hits} restaurant(s).");
        $this->line("  Phone backfilled for {$phonesBackfilled} restaurant(s).");
        $this->line("  Price backfilled for {$pricesBackfilled} restaurant(s).");
        $this->line("  Description backfilled for {$descriptionsBackfilled} restaurant(s).");
        $this->line("  Address backfilled for {$addressesBackfilled} restaurant(s).");
        $this->line("  Opening hours backfilled for {$hoursBackfilled} restaurant(s).");
        $this->line("  Photos backfilled for {$photosBackfilled} restaurant(s).");
        $this->line("  Ratings backfilled for {$ratingsBackfilled} restaurant(s).");
        $this->line("  Cuisine tags backfilled for {$cuisinesBackfilled} restaurant(s).");
    }

    /**
     * Restaurants the cache phase may enrich: those missing a website URL, a
     * phone number, a price range, a description, an address, opening hours, a
     * photo or a google rating (fill-empty all from the cached live-search
     * venue data, free of charge — no web search, no AI quota).
     *
     * @return Builder<Restaurant>
     */
    private function cacheCandidates(): Builder
    {
        return Restaurant::query()
            ->active()
            ->where(function ($q) {
                $q->where(function ($website) {
                    $website->whereNull('website_url')->orWhere('website_url', '');
                })->orWhere(function ($phone) {
                    $phone->whereNull('phone')->orWhere('phone', '');
                })->orWhere(function ($price) {
                    $price->whereNull('price_range')->orWhere('price_range', '');
                })->orWhere(function ($description) {
                    $description->whereNull('description')->orWhere('description', '');
                })->orWhere(function ($address) {
                    $address->whereNull('address')->orWhere('address', '');
                })->orWhere(function ($hours) {
                    $hours->whereNull('opening_hours')->orWhere('opening_hours', '')->orWhere('opening_hours', '[]');
                })->orWhere(function ($photo) {
                    $photo->whereNull('photo_url')->orWhere('photo_url', '');
                })->orWhere(function ($rating) {
                    $rating->whereNull('google_rating')->orWhere('google_rating', '<=', 0);
                })->orWhereDoesntHave('cuisines');
            });
    }

    /**
     * Whether a cached venue's entry data has nothing the cache phase can
     * backfill (all fields empty). Used to skip vacuous cache entries instead
     * of indexing them for nothing.
     *
     * @param  array<string, mixed>  $entryData
     */
    private function entryHasNothingUseful(array $entryData): bool
    {
        foreach (['_website', '_price_range', '_phone', '_description', '_address', '_opening_hours', '_photo', '_rating', '_review_count', '_cuisine_ids'] as $key) {
            if (! empty($entryData[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize a cached venue's opening_hours into the app's stored shape, or
     * null when it cannot be trusted. Two cache shapes are understood:
     *   - a raw OSM-style hours string (one that actually contains a HH:MM time
     *     marker) → raw-text form; junk (e.g. "closed") is rejected;
     *   - a SerpApi per-day operating_hours map ("monday" => "11 AM–9 PM") →
     *     structured form, keeping only single-range days with a usable time
     *     pair; closed / multi-range / junk days are skipped.
     * Anything else is rejected so nothing bogus is persisted. Matches
     * LiveVenuePersister::normalizeOpeningHours' raw-text convention and the
     * OpeningHours component's structured shape, which the website scraper also
     * emits.
     *
     * @return array{structured: false, raw_text: string}|array{structured: true, hours: list<array{day: string, open: string, close: string}>}|null
     */
    private function normalizeCacheHours(mixed $hours): ?array
    {
        if (is_array($hours)) {
            return $this->normalizeCacheHoursMap($hours);
        }

        if (! is_string($hours)) {
            return null;
        }

        $trimmed = trim($hours);
        if ($trimmed === '') {
            return null;
        }

        // Raw OSM hours always carry a 24h HH:MM marker ("Mo-Su 11:00-21:00").
        if (preg_match('/\b\d{1,2}:\d{2}\b/', $trimmed) !== 1) {
            return null;
        }

        return ['structured' => false, 'raw_text' => $trimmed];
    }

    /**
     * Convert a SerpApi operating_hours per-day map into the app's structured
     * shape, or null when no day carries a usable single time range. Days that
     * are closed, multi-range ("11 AM–1 PM, 3–8 PM") or unparseable are
     * skipped; a map with nothing usable yields null so nothing bogus is
     * persisted.
     *
     * @param  array<int|string, mixed>  $map
     * @return array{structured: true, hours: list<array{day: string, open: string, close: string}>}|null
     */
    private function normalizeCacheHoursMap(array $map): ?array
    {
        $structured = [];

        foreach ($map as $day => $range) {
            if (! is_string($day) || ! is_string($range)) {
                continue;
            }

            $dayName = $this->normalizeCacheDayName($day);
            if ($dayName === null) {
                continue;
            }

            $parsed = $this->parseCacheHoursRange($range);
            if ($parsed === null) {
                continue;
            }

            $structured[] = [
                'day' => $dayName,
                'open' => $parsed[0],
                'close' => $parsed[1],
            ];
        }

        return $structured === [] ? null : ['structured' => true, 'hours' => $structured];
    }

    /**
     * Map a SerpApi operating_hours day key (lowercase full name) to the
     * title-cased name the OpeningHours component renders, or null for a key
     * that is not a day.
     */
    private function normalizeCacheDayName(string $day): ?string
    {
        $map = [
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday',
            'sunday' => 'Sunday',
        ];

        return $map[strtolower(trim($day))] ?? null;
    }

    /**
     * Split a SerpApi day's hours value ("11 AM–9 PM", "12 PM–1 AM") into an
     * [open, close] time pair, or null when it cannot be trusted. Closed days,
     * multi-range days (which the structured shape cannot express) and
     * digit-less junk are rejected.
     *
     * @return array{string, string}|null
     */
    private function parseCacheHoursRange(string $range): ?array
    {
        $trimmed = trim($range);
        if ($trimmed === '' || strtolower($trimmed) === 'closed' || str_contains($trimmed, ',')) {
            return null;
        }

        if (preg_match('/^(.+?)\s*[–—-]\s*(.+)$/u', $trimmed, $m) !== 1) {
            return null;
        }

        $open = trim($m[1]);
        $close = trim($m[2]);

        if ($open === '' || $close === '' || preg_match('/\d/', $open) !== 1 || preg_match('/\d/', $close) !== 1) {
            return null;
        }

        // "4–9 PM" means 4 PM–9 PM: propagate the closer's meridiem onto an
        // opener that lacks one so the rendered row reads cleanly.
        if (preg_match('/(AM|PM)$/i', $open) !== 1 && preg_match('/(AM|PM)$/i', $close, $mm) === 1) {
            $open .= ' '.strtoupper($mm[1]);
        }

        return [$open, $close];
    }

    /**
     * A cached venue address worth storing, or null. Rejects missing,
     * non-string and implausibly short values so no junk string is persisted.
     */
    private function sanitizeAddress(mixed $address): ?string
    {
        if (! is_string($address)) {
            return null;
        }

        $trimmed = trim($address);

        // A real street address carries at least a number + street name; a
        // one-or-two token string ("x", "Main St") is junk, not a usable address.
        return strlen($trimmed) >= 8 && str_contains($trimmed, ' ') ? $trimmed : null;
    }

    /**
     * A cached venue description worth storing, or null. Rejects missing,
     * non-string and implausibly short values so no junk blurb is persisted.
     */
    private function sanitizeDescription(mixed $description): ?string
    {
        if (! is_string($description)) {
            return null;
        }

        $trimmed = trim($description);

        return strlen($trimmed) >= 20 ? $trimmed : null;
    }

    /**
     * A cached venue photo worth storing, or null. Accepts a http(s) image URL
     * (SerpApi venue thumbnails), rejecting non-strings and junk so nothing
     * bogus is persisted as the card photo.
     */
    private function sanitizeCachePhoto(mixed $photo): ?string
    {
        if (! is_string($photo)) {
            return null;
        }

        $trimmed = trim($photo);

        if (! str_starts_with($trimmed, 'http://') && ! str_starts_with($trimmed, 'https://')) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Normalize a cached venue phone to the corpus's 10-digit convention
     * (digits only, last 10). Returns null for missing or implausibly short
     * values so nothing bogus is stored.
     */
    private function normalizeCachePhone(mixed $phone): ?string
    {
        if (! is_string($phone) && ! is_numeric($phone)) {
            return null;
        }

        $digits = substr((string) preg_replace('/\D+/', '', (string) $phone), -10);

        return strlen($digits) === 10 ? $digits : null;
    }

    /**
     * A cached venue Google rating worth storing, or null. Accepts only a
     * numeric value within Google's 0-5 scale (rounded to one decimal, matching
     * RestaurantValidationService::clampRating), rejecting non-numeric and
     * out-of-range values so nothing bogus is persisted as a quality signal.
     */
    private function sanitizeCacheRating(mixed $rating): ?float
    {
        if (! is_numeric($rating)) {
            return null;
        }

        $value = (float) $rating;

        return $value > 0 && $value <= 5 ? round($value, 1) : null;
    }

    /**
     * A cached venue review count worth storing, or null. Accepts only a
     * positive integer; rejects missing, non-numeric and zero/negative values
     * so a review count is never persisted without a usable rating behind it.
     */
    private function sanitizeCacheReviewCount(mixed $reviews): ?int
    {
        if (! is_numeric($reviews)) {
            return null;
        }

        $value = (int) $reviews;

        return $value > 0 ? $value : null;
    }

    /** @param array<string, mixed> $venue */
    private function parseExtractedPrice(array $venue): ?string
    {
        $raw = $venue['extracted_price'] ?? null;
        if (is_numeric($raw)) {
            $val = (int) $raw;

            return match (true) {
                $val < 15 => '$',
                $val < 30 => '$$',
                $val < 50 => '$$$',
                default => '$$$$',
            };
        }

        $price = $venue['price'] ?? null;
        if ($price !== null && preg_match('/(\d+)/', $price, $m)) {
            $val = (int) $m[1];

            return match (true) {
                $val < 15 => '$',
                $val < 30 => '$$',
                $val < 50 => '$$$',
                default => '$$$$',
            };
        }

        return null;
    }

    /**
     * Map a cached venue's Google place types ("Chinese restaurant") to seeded
     * cuisine ids. Trailing non-cuisine qualifiers are stripped ("Chinese
     * restaurant" → "Chinese") before CuisineTagMapper matches against the
     * seeded names, so a venue tagged "Thai restaurant" yields the thai tag.
     * Types that don't normalize to a seeded cuisine ("Pizza restaurant",
     * "Bar", "Caterer") are skipped; an empty list is returned when nothing
     * maps. Uses the same conservative normalization as the AI enrichment job,
     * so unseeded names never create phantom pivot rows.
     *
     * @param  array<string, mixed>  $venue
     * @return list<int>
     */
    private function cacheCuisineIds(array $venue, CuisineTagMapper $cuisineTagMapper): array
    {
        $types = $venue['types'] ?? $venue['type'] ?? null;
        if (is_string($types)) {
            $types = [$types];
        }
        if (! is_array($types)) {
            return [];
        }

        $names = [];
        foreach ($types as $type) {
            $name = $this->stripCuisineQualifier((string) $type);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names === [] ? [] : $cuisineTagMapper->idsForNames($names);
    }

    /**
     * Strip a trailing non-cuisine qualifier from a Google place type, leaving
     * the cuisine core intact. A type that IS a qualifier on its own ("Bar") is
     * left as-is so it can never collapse to an empty string.
     */
    private function stripCuisineQualifier(string $type): string
    {
        $trimmed = trim($type);
        if ($trimmed === '') {
            return '';
        }

        foreach (self::CUISINE_TYPE_QUALIFIERS as $qualifier) {
            $suffix = ' '.$qualifier;
            if (str_ends_with(strtolower($trimmed), $suffix)) {
                $stripped = trim(substr($trimmed, 0, -strlen($suffix)));
                if ($stripped !== '') {
                    return $stripped;
                }

                return $trimmed;
            }
        }

        return $trimmed;
    }

    private function webSearch(bool $dryRun): void
    {
        $limit = (int) $this->option('limit');
        $restaurants = $this->missingRestaurants($limit)->get();
        $total = $restaurants->count();

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $found = 0;

        foreach ($restaurants as $restaurant) {
            $url = $this->searchWeb($restaurant->name, $restaurant->city, $restaurant->state);

            if ($url !== null) {
                if (! $dryRun) {
                    $restaurant->update(['website_url' => $url]);
                    Log::channel('enrichment')->info('Website backfilled from web search', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'website_url' => $url,
                    ]);
                }
                $found++;
                $this->found++;
            }

            // Small delay to avoid rate limiting
            if (! $dryRun) {
                usleep(200_000);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  Web search found {$found} website(s).");
    }

    /**
     * Try Bing first, fall back to DuckDuckGo if Bing fails or returns nothing.
     */
    private function searchWeb(string $name, ?string $city, ?string $state): ?string
    {
        $url = $this->searchBing($name, $city, $state);

        if ($url !== null) {
            return $url;
        }

        return $this->searchDuckDuckGoHtml($name, $city, $state);
    }

    /**
     * Search Bing HTML results for the restaurant website.
     * Parses base64-encoded redirect URLs from Bing's search result links.
     */
    private function searchBing(string $name, ?string $city, ?string $state): ?string
    {
        $query = trim("{$name} {$city} {$state} official website");
        $query = substr($query, 0, 200);

        try {
            $response = Http::timeout(8)
                ->withUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                ->withOptions(['allow_redirects' => true])
                ->get('https://www.bing.com/search', ['q' => $query]);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // Bing stores the real URL in the u= parameter of ck/a redirect links
            preg_match_all('~u=a1([a-zA-Z0-9+/=]+)~i', $html, $matches);

            if (empty($matches[1])) {
                return null;
            }

            foreach ($matches[1] as $encoded) {
                $decoded = base64_decode($encoded, true);
                if ($decoded === false || empty($decoded)) {
                    continue;
                }

                $url = urldecode($decoded);

                if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                    continue;
                }

                if (! $this->isSkipDomainUrl($url)) {
                    return $url;
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Fallback search using DuckDuckGo HTML.
     * Parses redirect URLs from DuckDuckGo's search result links (uddg parameter).
     */
    private function searchDuckDuckGoHtml(string $name, ?string $city, ?string $state): ?string
    {
        $query = trim("{$name} {$city} {$state} official website");
        $query = substr($query, 0, 200);

        try {
            $response = Http::timeout(8)
                ->withUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                ->withOptions(['allow_redirects' => true])
                ->get('https://html.duckduckgo.com/html/', ['q' => $query]);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // DuckDuckGo wraps result links in <a> with class result__a
            // They redirect via the uddg parameter (base64-encoded URL)
            preg_match_all('#uddg=([a-zA-Z0-9+/=%]+)#i', $html, $matches);

            if (empty($matches[1])) {
                // Fallback: try scraping direct href from result links
                libxml_use_internal_errors(true);
                $dom = new \DOMDocument;
                $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();

                $xpath = new \DOMXPath($dom);
                $links = $xpath->query("//a[contains(@class, 'result__a')]");

                if ($links === false) {
                    return null;
                }

                foreach ($links as $link) {
                    $href = $link->getAttribute('href');
                    if (empty($href)) {
                        continue;
                    }
                    // Direct URL (no DDG redirect wrapper)
                    if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                        if (! $this->isSkipDomainUrl($href)) {
                            return $href;
                        }

                        continue;
                    }
                    // Maybe a DDG-style redirect URL
                    $url = $this->extractUrlFromDdgRedirect($href);
                    if ($url !== null && ! $this->isSkipDomainUrl($url)) {
                        return $url;
                    }
                }

                return null;
            }

            foreach ($matches[1] as $encoded) {
                $url = $this->extractUrlFromDdgRedirect($encoded);
                if ($url !== null && ! $this->isSkipDomainUrl($url)) {
                    return $url;
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract a real URL from a DuckDuckGo redirect parameter.
     */
    private function extractUrlFromDdgRedirect(string $encoded): ?string
    {
        $decoded = urldecode($encoded);
        $decoded = base64_decode($decoded, true);
        if ($decoded === false || empty($decoded)) {
            return null;
        }

        if (! str_starts_with($decoded, 'http://') && ! str_starts_with($decoded, 'https://')) {
            return null;
        }

        return $decoded;
    }

    private function normalize(string $name): string
    {
        $name = preg_replace('/[^a-z0-9\s]/i', '', $name) ?? '';
        $name = preg_replace('/\s+/', ' ', $name) ?? '';

        return strtolower(trim($name));
    }

    /**
     * Check if a URL belongs to a known non-restaurant domain via skip patterns.
     */
    private function isSkipDomainUrl(string $url): bool
    {
        foreach (self::DOMAIN_SKIP_PATTERNS as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    private function countMissing(): int
    {
        return Restaurant::query()
            ->active()
            ->where(function ($q) {
                $q->whereNull('website_url')->orWhere('website_url', '');
            })
            ->count();
    }

    private function countNewWebsites(): int
    {
        return Restaurant::query()
            ->active()
            ->whereNotNull('website_url')
            ->where('website_url', '!=', '')
            ->where('social_links_count', 0)
            ->count();
    }

    /** @return Builder<Restaurant> */
    private function missingRestaurants(int $limit): Builder
    {
        $q = Restaurant::query()
            ->active()
            ->where(function ($q) {
                $q->whereNull('website_url')->orWhere('website_url', '');
            });

        if ($limit > 0) {
            $q->limit($limit);
        }

        return $q;
    }

    private function scrapeSocialLinks(RestaurantWebsiteScraperService $scraper): void
    {
        $restaurants = Restaurant::query()
            ->active()
            ->whereNotNull('website_url')
            ->where('website_url', '!=', '')
            ->where('social_links_count', 0)
            ->orderBy('id')
            ->limit(self::SOCIAL_SCRAPE_DAILY_LIMIT)
            ->get();

        $total = $restaurants->count();
        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($restaurants as $restaurant) {
            try {
                if ($restaurant->website_url === null || $restaurant->website_url === '') {
                    $bar->advance();

                    continue;
                }
                $links = $scraper->scrapeSocial($restaurant->website_url);

                if ($links !== null) {
                    RestaurantSocialLink::where('restaurant_id', $restaurant->id)->delete();
                    $now = now();
                    foreach ($links as $platform => $url) {
                        $verified = $scraper->verifyProfileUrl($url);
                        RestaurantSocialLink::create([
                            'restaurant_id' => $restaurant->id,
                            'platform' => $platform,
                            'url' => $url,
                            'verified_at' => $verified ? $now : null,
                            'last_check_failed_at' => $verified ? null : $now,
                        ]);
                    }
                    $restaurant->update(['social_links_count' => $restaurant->countScoredSocialLinks()]);
                }
            } catch (\Throwable $e) {
                Log::channel('enrichment')->warning('Social scrape failed during website backfill', [
                    'restaurant_id' => $restaurant->id,
                    'website_url' => $restaurant->website_url ?? null,
                    'message' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Scrape menu URLs + opening hours + description + price range from the
     * websites of restaurants that have a website_url but are missing menu_url,
     * opening_hours, description or price_range (description and price_range are
     * the corpus's biggest gaps — 96% and 94% of active rows — previously served
     * only by cache + quota-bound AI). Fill-empty only — existing values are
     * never clobbered, and rows missing only some fields are revisited until
     * every field is filled. Bounded per run by
     * {@see self::MENU_SCRAPE_DAILY_LIMIT} and driven by the same per-domain
     * cache as the rest of the scraper.
     */
    private function scrapeMenuData(RestaurantWebsiteScraperService $scraper, bool $dryRun): void
    {
        $query = Restaurant::query()
            ->active()
            ->whereNotNull('website_url')
            ->where('website_url', '!=', '')
            ->where(function ($q) {
                $q->whereNull('menu_url')->orWhere('menu_url', '')
                    ->orWhereNull('opening_hours')->orWhere('opening_hours', '')
                    ->orWhereNull('description')->orWhere('description', '')
                    ->orWhereNull('price_range')->orWhere('price_range', '');
            })
            ->orderByRaw('COALESCE(popularity_score, 0) DESC')
            ->orderBy('id')
            ->limit(self::MENU_SCRAPE_DAILY_LIMIT);

        $restaurants = $query->get();
        $total = $restaurants->count();

        if ($total === 0) {
            return;
        }

        $this->info("Scraping menu URLs + hours + description + price for {$total} restaurant(s)...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $found = 0;

        foreach ($restaurants as $restaurant) {
            try {
                if ($restaurant->website_url === null || $restaurant->website_url === '') {
                    $bar->advance();

                    continue;
                }

                $scraped = $scraper->scrape($restaurant->website_url);

                if ($scraped !== null && (! empty($scraped['menu_url']) || ! empty($scraped['opening_hours']) || ! empty($scraped['description']) || ! empty($scraped['price_range']))) {
                    $updates = [];

                    if (! empty($scraped['menu_url']) && empty($restaurant->menu_url)) {
                        $updates['menu_url'] = $scraped['menu_url'];
                    }
                    if (! empty($scraped['opening_hours']) && empty($restaurant->opening_hours)) {
                        $updates['opening_hours'] = $scraped['opening_hours'];
                    }
                    if (! empty($scraped['description']) && empty($restaurant->description)) {
                        $updates['description'] = $scraped['description'];
                    }
                    if (! empty($scraped['price_range']) && empty($restaurant->price_range)) {
                        $updates['price_range'] = $scraped['price_range'];
                    }

                    if (! $dryRun && ! empty($updates)) {
                        $restaurant->update($updates);
                        Log::channel('enrichment')->info('Menu URL + hours + description + price backfilled from website scrape', [
                            'restaurant_id' => $restaurant->id,
                            'restaurant_name' => $restaurant->name,
                            'website_url' => $restaurant->website_url,
                            'menu_url' => $scraped['menu_url'] ?? null,
                            'opening_hours' => $scraped['opening_hours'] ?? null,
                            'description' => $scraped['description'] ?? null,
                            'price_range' => $scraped['price_range'] ?? null,
                        ]);
                    }

                    $found++;
                }
            } catch (\Throwable $e) {
                Log::channel('enrichment')->warning('Menu scrape failed during website backfill', [
                    'restaurant_id' => $restaurant->id,
                    'website_url' => $restaurant->website_url ?? null,
                    'message' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  Menu/hours/description/price scraped for {$found} restaurant(s).");
    }

    private function guessFromTitle(bool $dryRun): void
    {
        $limit = (int) $this->option('limit');
        $restaurants = $this->missingRestaurants($limit)->get();
        $total = $restaurants->count();

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $found = 0;

        foreach ($restaurants as $restaurant) {
            $candidates = $this->candidateDomains($restaurant->name, $restaurant->city);
            $url = null;

            foreach ($candidates as $domain) {
                try {
                    $response = Http::timeout(5)
                        ->withUserAgent('Mozilla/5.0')
                        ->head($domain);

                    if ($response->successful()) {
                        $url = $domain;
                        break;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            if ($url !== null) {
                if ($this->isSkipDomainUrl($url)) {
                    $this->line("  Skipped guessed URL (non-restaurant domain): {$url}");
                    $bar->advance();

                    continue;
                }
                if (! $dryRun) {
                    $restaurant->update(['website_url' => $url]);
                    Log::channel('enrichment')->info('Website backfilled from domain guess', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'website_url' => $url,
                    ]);
                }
                $found++;
                $this->found++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("  Domain guessing found {$found} website(s).");
    }

    /** @return array<string> */
    private function candidateDomains(string $name, ?string $city): array
    {
        $slug = $this->toSlug($name);
        $citySlug = $city ? $this->toSlug($city) : '';

        $domains = [
            "https://www.{$slug}.com",
            "https://{$slug}.com",
        ];

        if ($citySlug !== '') {
            $domains[] = "https://www.{$slug}{$citySlug}.com";
            $domains[] = "https://{$slug}{$citySlug}.com";
        }

        // Try with hyphens between words if multi-word
        $words = explode('-', $slug);
        if (count($words) > 1) {
            $joined = implode('', $words);
            $domains[] = "https://www.{$joined}.com";
            $domains[] = "https://{$joined}.com";
        }

        return array_unique($domains);
    }

    private function toSlug(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text) ?? '';
        $text = preg_replace('/\s+/', '-', $text) ?? '';
        $text = preg_replace('/-+/', '-', $text) ?? '';

        return trim($text, '-');
    }
}
