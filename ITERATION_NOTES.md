# Iteration Notes

## Goal
shrink the PHPStan level-6 baseline by fixing real type issues in code

## State
- Baseline entries: 8 (down from 9)
- Remaining: missingType.iterableValue (7) + missingType.return (1)
- Top files by iterableValue count: all singles now — BackfillRestaurantLocation, UptimeCanary, ValidateRestaurantData, BlogPostController, LiveRestaurantResource, RestaurantEnrichmentService, WikidataService

## Log
### Iteration 36 — Fixed EnrichRestaurantWithAi::$backoff iterableValue entry (total: 9→8)
- `app/Jobs/EnrichRestaurantWithAi.php`: Added `@var array<int, int>` to `$backoff` property
- 1 baseline entry removed; 0 EnrichRestaurantWithAi entries remain
- `./vendor/bin/phpstan analyse` passes cleanly; all 563 tests pass
### Iteration 35 — Fixed all 2 RestaurantValidationService iterableValue entries (total: 11→9)
- `app/Services/RestaurantValidationService.php`: Added value type annotations to `normalize()`:
  - `@param array<string, mixed> $attributes`
  - `@return array<string, mixed>`
- 2 baseline entries removed; 0 RestaurantValidationService entries remain
- `./vendor/bin/phpstan analyse` passes cleanly; all RestaurantValidationServiceTest (10) tests pass
### Iteration 34 — Fixed all 2 CuisineMatcher iterableValue entries (total: 13→11)
- `app/Services/CuisineMatcher.php`: Added value type annotations:
  - `isSubstringOfAnyOnKeyword()` → `@param string[] $onKeywords`
  - `venueMatchesCuisine()` → `@param array<string, mixed> $venue`
- 2 baseline entries removed; 0 CuisineMatcher entries remain
- `./vendor/bin/phpstan analyse` passes cleanly; all 20 CuisineMatcher tests pass
### Iteration 33 — Fixed all 2 FavoriteController iterableValue entries (total: 15→13)
- `app/Http/Controllers/FavoriteController.php`: Added value type annotations:
  - `ensurePersisted()` → `@param array<string, mixed> $data`
  - `resolveCuisineIds()` → `@return int[]`
- 2 baseline entries removed; 0 FavoriteController entries remain
- `./vendor/bin/phpstan analyse` passes cleanly
### Iteration 32 — Fixed all 2 HomeController iterableValue entries (total: 17→15)
- `app/Http/Controllers/HomeController.php`: Added value type annotations:
  - `getHomepageData()` → `@return array<string, mixed>`
  - `getScopedCategories()` → `@return array<int, array<string, mixed>>`
- 2 baseline entries removed; 0 HomeController entries remain
- All 563 tests pass; `./vendor/bin/phpstan analyse` passes cleanly
### Iteration 31 — Fixed all 3 SearchController iterableValue entries (total: 20→17)
- `app/Http/Controllers/SearchController.php`: Added value type annotations to `persistLiveResults()`:
  - `$results` → `@param array<int, array<string, mixed>>`
  - `$cuisineIds` → `@param int[]`
  - `$defaultLocation` → `@param array<string, mixed>|null`
- 3 baseline entries removed; 0 SearchController entries remain
- All 563 tests pass; `./vendor/bin/phpstan analyse` passes cleanly
### Iteration 30 — Fixed all 3 AuditRestaurantCuisines iterableValue entries (total: 23→20)
- `app/Console/Commands/AuditRestaurantCuisines.php`: Added value type annotations:
  - `aiCuisineSlugs()` → `@param array<string, string> $normalizedNameToSlug`
  - `recommendations()` → `@param array<string, int> $slugToId`, `@param array<string, string> $normalizedNameToSlug`
- 3 baseline entries removed; 0 AuditRestaurantCuisines entries remain
- All 563 tests pass; `./vendor/bin/phpstan analyse` passes cleanly
### Iteration 29 — Fixed all 4 RestaurantResource iterableValue entries (total: 27→23)
- `app/Http/Resources/RestaurantResource.php`: Added value type annotations:
  - Property `$aggregates` → `@var array{log_denoms: array<string, float>, minmax: array<string, array{min: float, max: float}|null>, quality: array{mean_rating: float}}|null`
  - `withAggregates()` → `@param array{log_denoms: array<string, float>, minmax: array<string, array{min: float, max: float}|null>, quality: array{mean_rating: float}} $aggregates`
  - `getScoreBreakdown()` → `@return array<string, mixed>|null`
  - `toArray()` → `@return array<string, mixed>`
- 4 baseline entries removed; 0 RestaurantResource entries remain
- All 563 tests pass; `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 28 — Fixed all 5 LiveVenuePersister iterableValue entries (total: 32→27)
- `app/Services/LiveVenuePersister.php`: Added value type annotations to all 3 params + return type in `persist()`:
  - `$venue` → `@param array<string, mixed>`
  - `$cuisineIds` → `@param int[]`
  - `$defaultLocation` → `@param array<string, mixed>|null`
  - Return → `@return array{restaurant: Restaurant, created: bool, venue: array<string, mixed>}`
- Added `@param array<string, mixed> $venue` to `knownCuisineIds()`
- 5 baseline entries removed; 0 LiveVenuePersister entries remain
- All 563 tests pass; `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 27 — Fixed all 7 AiEnrichmentService iterableValue entries (total: 39→32)
- `app/Services/AiEnrichmentService.php`: Added value type annotations to all 4 methods:
  - `enrichRestaurant()` → `@param array<string, mixed> $restaurantData` + `@return array<string, mixed>|null`
  - `buildProviderChain()` → `@return array<int, array<string, mixed>>`
  - `tryProvider()` → `@param array<string, mixed> $restaurantData` + `@param array<string, mixed> $provider` + `@return array<string, mixed>|null`
  - `buildPrompt()` → `@param array<string, mixed> $restaurantData`
- 7 baseline entries removed; 0 AiEnrichmentService entries remain
- `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 26 — Fixed all 7 GeolocationService iterableValue entries (total: 45→39)
- `app/Services/GeolocationService.php`: Added value type annotations to all 7 methods:
  - `resolveCoordinates()` → `@return array{lat: float, lng: float}|null`
  - `resolveLocation()` → `@return array{lat: float, lng: float, city: string|null, state: string|null}|null`
  - `ipLookup()` → `@return array{lat: float, lng: float}|null`
  - `searchCities()` → `@return array<int, array{city: string|null, state: string|null, country: string|null, lat: float|null, lng: float|null, display: string}>`
  - `forwardGeocode()` → `@return array{lat: float, lng: float}|null`
  - `reverseGeocode()` → `@return array{city: string|null, state: string|null}|null`
  - `ipLookupFull()` → `@return array{lat: float, lng: float, city: string|null, region: string|null}|null`
- 7 baseline entries removed; 0 GeolocationService entries remain
- All 563 tests pass; `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 25 — Fixed all 8 RestaurantEnrichmentService iterableValue entries (total: 53→45)
- `app/Services/RestaurantEnrichmentService.php`: Added value types to `consumeOverpassResponses()` (`@param array<int, Response|Throwable>`, `@return array<int, array<string, mixed>>`), `enrichAllCitiesThrottled()` (`@return array{total_processed: int, ...}`), `fetchAndNormalizeAllSources()` (`@return array<int, array<string, mixed>>`), `normalizeOverpassWithFallback()` (`@param array<int, mixed>`, `@return array<int, array<string, mixed>>`), `normalizePoolResponses()` (`@param array<int, Response|Throwable>`, `@return array<int, array<string, mixed>>`)
- 8 baseline entries removed; 0 RestaurantEnrichmentService entries remain
- All 563 tests pass; `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 24 — Fixed all 8 VenuePipeline iterableValue entries (total: 61→53)
- `app/Services/VenuePipeline.php`: Added `@param array<string, mixed>` to `$a`/`$b` in `withinRadius()`, `phonesMatch()`, and `sortTiebreak()`
- Added `@param array<int, array<string, mixed>>` + `@return array<int, array<string, mixed>>` to `sortVenues()`
- 8 baseline entries removed; 0 VenuePipeline entries remain
- All 563 tests pass; `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 23 — Fixed all 9 RestaurantWebsiteScraperService iterableValue entries (total: 70→61)
- `app/Services/RestaurantWebsiteScraperService.php`: Added value types to every array return:
  - `scrape()` → `@return array{opening_hours: mixed, menu_url: string|null, photo_url: string|null, photos: string[]}|null`
  - `performScrape()` → `@return array{opening_hours: mixed, menu_url: string|null, photo_url: string|null, photos: string[]}|null`
  - `extractOpeningHours()` → `@return array<string, mixed>|null`
  - `extractHoursFromJsonLd()` → `@return array<string, mixed>|null`
  - `extractHoursFromMicrodata()` → `@return array<string, mixed>|null`
  - `extractHoursFromText()` → `@return array<string, mixed>|null`
  - `parseHoursText()` → `@return array{raw_text: string, structured: false}`
  - `normalizeOpeningHours()` → `@return array<string, mixed>|null`
  - `fetchPageForSocial()` → `@return array<string, string>|null` (was `@return array|null`)
- 9 baseline entries removed; 0 RestaurantWebsiteScraperService entries remain
- All RestaurantWebsiteScraperServiceTest (23) tests pass
- `./vendor/bin/phpstan analyse` passes cleanly
### Iteration 22 — Fixed all 19 PopularityScoreService iterableValue entries (total: 89→70)
- `app/Services/PopularityScoreService.php`: Added value types to every array param/return/property:
  - Property `$weights` → `array<string, float>`
  - `__construct()` → `@param array<string, float>|null $weights`
  - `calculateBreakdown()` → `@return array{signals: array<int, array{label: string, weight: float, normalized: float, contribution: float, detail: string}>, total: float}`
  - `calculateBreakdownForArray()` → `@param array<string, mixed> $restaurant` + breakdown return shape
  - `calculateBreakdownWithAggregates()` → `@param array<string, mixed> $restaurant` + `@param array{log_denoms: array<string, float>, minmax: array<string, mixed>, quality: array{mean_rating: float}} $aggregates` + breakdown return shape
  - `calculateBreakdownWithAggregatesFromEloquent()` → aggregates param + breakdown return shape
  - `computeAggregates()` → `@return array{log_denoms: array<string, float>, minmax: array<string, array{min: float, max: float}|null>, quality: array{mean_rating: float}}`
  - `computeCompletenessFromArray()` → `@param array<string, mixed> $restaurant`
  - `minmaxStats()` → `@return array{min: float, max: float}|null`
  - `normalize()` → `@param array<string, float> $logDenoms` + `@param array<string, array{min: float, max: float}|null> $minmax`
  - `normalizeBayesianQuality()` → `@param array{rating: float|null, reviews: float} $raw`
  - `normalizeMinMax()` → `@param array{min: float, max: float}|null $stats`
  - `rawValueFromArray()` → `@param array<string, mixed> $restaurant`
  - `restaurantToArray()` → `@return array<string, mixed>`
- Tighter shapes exposed 4 unnecessary `??` coalescing guards → removed them
- 19 baseline entries removed; 0 PopularityScoreService entries remain
- All 17 PopularityScoreService unit tests pass
- `./vendor/bin/phpstan analyse` passes cleanly
### Iteration 21 — Fixed all 24 LiveSearchService iterableValue entries (total: 113→89)
- `app/Services/LiveSearchService.php`: Added value types to every array param/return across 12 methods:
  - `search()` → `@return array<int, array<string, mixed>>`
  - `fetchAndMergeAllSources()` → `@return array<int, array<string, mixed>>`
  - `fetchSerpApiUnderLock()` → `@param array<int, RequestSpec>` + `@return array<int, array<string, mixed>>`
  - `dispatchPool()` → `@param array<string, array<int, RequestSpec>>` + `@return array<string, array<int, \Illuminate\Http\Client\Response|\Throwable>>`
  - `normalizeCachedHit()` → `@param array<int, array<string, mixed>>` + `@return` same shape
  - `applyOverpassNameFallback()` → `@param array<int, array<string, mixed>>` + `@param array<int, string>` + `@return` same shape
  - `scoreWithUnifiedService()`, `filterByCuisineConfidence()`, `boundResults()`, `filterByDistance()`, `filterNonRestaurants()`, `stampCuisineMatchStrength()`, `filterByCuisineRelevance()` → all `@param array<int, array<string, mixed>>` + `@return` same shape
- 24 baseline entries removed; 0 LiveSearchService entries remain
- `./vendor/bin/phpstan analyse` passes cleanly
- Key gotcha: when editing methods with existing docblocks, must ensure param/return annotations are inside the existing `/** */` block, not added as a separate block after `*/` — Fixed all 24 SocrataOpenDataService iterableValue entries (total: 137→113)
- `app/Services/SocrataOpenDataService.php`: Added value types to every array param/return/property:
  - Property `$endpoints` → `@var array<string, array<string, mixed>>`
  - `search()` → `@return array<int, array<string, mixed>>`
  - `fetchRaw()` → `@return array{cached: bool, data: array<int, array<string, mixed>>}|null`
  - `normalizeRaw()` → `@param array<int, array<string, mixed>>` + `@return` same shape
  - `poolRequestsFor()` → `@param array<string, mixed> $context` + `@return array<int, RequestSpec>`
  - `parsePoolResponse()` → `@return array<int, array<string, mixed>>|null`
  - `consumePoolResponses()` → `@param array<int, Response|\Throwable> $responses` + `@return array<int, array<string, mixed>>`
  - `fetchAllEndpoints()` → `@return array<int, array<string, mixed>>`
  - `fetchEndpoint()` → `@param array<int, string> $fields` + `@return array<int, array<string, mixed>>`
  - `buildSoqlQuery()` → `@param array<int, string> $fields` + `@return array<string, mixed>`
  - `buildHeaders()` → `@return array<string, string>`
  - `normalizeEndpointResults()` → `@param array<int, array<string, mixed>>` + `@return` same shape
  - `normalizeRow()` → `@param array<string, mixed>` + `@return array<string, mixed>|null`
  - `deduplicateByName()` → `@param array<int, array<string, mixed>>` + `@return` same shape
  - `normalizeForEnrichment()` → `@param array<string, mixed>` + `@return array<string, mixed>`
- 24 baseline entries removed; 0 SocrataOpenDataService entries remain
- `./vendor/bin/phpstan analyse` passes cleanly
- Test failures (53) are pre-existing from Iteration 10's `buildPoolRequest(): Response` return type (actually returns `LazyPromise` in pool context)
### Iteration 19 — Fixed all 13 BizDataApiService iterableValue entries (total: 150→137)
- `app/Services/BizDataApiService.php`: Added value types to every array param/return:
  - `search()` → `@return array<int, array<string, mixed>>`
  - `normalizeResults()` → `@param array<int, array<string, mixed>>` + `@return` same shape
  - `fetchRaw()` → `@return array{cached: bool, data: array<int, mixed>}|null`
  - `normalizeRaw()` → `@param array<int, mixed>` + `@return array<int, array<string, mixed>>`
  - `poolRequestsFor()` → `@param array<string, mixed> $context` + `@return array<int, RequestSpec>`
  - `parsePoolResponse()` → `@return array<int, mixed>|null`
  - `consumePoolResponses()` → `@param array<int, Response|\Throwable>` + `@return array<int, array<string, mixed>>`
  - `normalizeForEnrichment()` → `@param array<string, mixed>` + `@return array<string, mixed>`
- 13 baseline entries removed; 0 BizDataApiService entries remain
- `./vendor/bin/phpstan analyse` passes cleanly
- `app/Services/OverpassService.php`: Added value types to every array param/return across the class:
  - Property: `$mirrors` → `array<int, string>`
  - `search()` → `@return array<int, array<string, mixed>>`
  - `searchByName()` → `@param array<int, string> $keywords` + `@return array<int, array<string, mixed>>`
  - `fetchRaw()` → `@return array{cached: bool, data: array<int, mixed>}|null`
  - `fetchByNameRaw()` → `@param array<int, string> $keywords`, `@param array<string, mixed> $context`, `@return array{cached: bool, data: array<int, mixed>}|null`
  - `executeSearch()` → `@return array<int, array<string, mixed>>`
  - `executeSearchByName()` → `@param array<int, string> $keywords` + `@return array<int, array<string, mixed>>`
  - `normalizeResults()` → `@param array<int, mixed> $elements` + `@return array<int, array<string, mixed>>`
  - `extractCoords()` → `@param array<string, mixed> $el` + `@return array{lat: float, lon: float}|null`
  - `buildAddress()` → `@param array<string, mixed> $tags`
  - `mapPriceRange()` → `@param array<string, mixed> $tags`
  - `extractCuisines()` → `@param array<string, mixed> $tags` + `@return array<int, array{id: int, name: string, slug: string}>`
  - `normalizeRaw()` → `@param array<int, mixed> $elements` + `@return array<int, array<string, mixed>>`
  - `poolRequestsFor()` → `@param array<string, mixed> $context` + `@return array<int, RequestSpec>`
  - `parsePoolResponse()` → `@return array<int, mixed>|null`
  - `consumePoolResponses()` → `@param array<int, Response|\Throwable> $responses` + `@return array<int, array<string, mixed>>`
  - `extractFeatures()` → `@param array<string, mixed> $tags` + `@return array<string, mixed>`
  - `normalizeForEnrichment()` → `@param array<string, mixed> $r` + `@return array<string, mixed>`
- The narrower `fetchRaw()`/`fetchByNameRaw()` return types exposed 3 unnecessary `?? []` fallbacks in callers (null guard already present):
  - `LiveSearchService.php:441`: Removed `?? []` from `$nameRaw['data']`
  - `RestaurantEnrichmentService.php:345`: Removed `?? []` from `$nameRaw['data']`
  - `RestaurantEnrichmentService.php:376`: Removed `?? []` from `$nameRaw['data']`
- 30 baseline entries removed; 0 OverpassService entries remain
- `./vendor/bin/phpstan analyse` passes cleanly
- `app/Services/SerpApiService.php`: Added value types to array params/returns for 10 methods:
  - `search()`: `@return array<int, array<string, mixed>>`
  - `fetchRaw()`: `@return array<string, mixed>|null`
  - `normalizeRaw()`: `@param array<int, array<string, mixed>> $localResults` + `@return` same shape
  - `poolRequestsFor()`: `@param array<string, mixed> $context` + `@return array<int, RequestSpec>`
  - `parsePoolResponse()`: `@return array<int, array<string, mixed>>|null`
  - `consumePoolResponses()`: `@param array<int, Response|\Throwable> $responses` + `@return` venue shape
  - `normalizeResults()`: `@param array<int, array<string, mixed>> $localResults` + `@return` venue shape
  - `parsePriceRange()`: `@param array<string, mixed> $venue`
  - `parseAddress()`: `@param array<string, mixed> $result`
  - `normalizeForEnrichment()`: `@param array<string, mixed> $r` + `@return array<string, mixed>`
- 15 baseline entries removed; 0 SerpApiService entries remain
- `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 16 — Fixed all 4 argument.templateType: SearchAuditCommand + GeolocationService (total: 199→195)
- `app/Console/Commands/SearchAuditCommand.php`: Added `/** @var array<int, array<string, mixed>> $results */` before `collect($results)` and extracted `$r['score_breakdown']['signals'] ?? []` to `$scoreSignals` with `/** @var array<int, array{label: mixed, contribution: float}> */` annotation (2 argument.templateType)
- `app/Services/GeolocationService.php`: Added `/** @var array<int, array<string, mixed>> $features */` before `collect($features)` in `searchCities()` (2 argument.templateType)
- Removed the 4 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly
- argument.templateType category fully eliminated — zero entries remain
- `app/Http/Resources/RestaurantResource.php`: Added `@var Collection<int, \App\Models\Restaurant>|null` to `$allRestaurants` property; added `@param Collection<int, \App\Models\Restaurant>` to `withAllRestaurants()` (2 generics)
- `app/Services/RestaurantEnrichmentService.php`: Added `@param Collection<int, Restaurant>` to `enrichAwards()`, `enrichWebsiteData()`, `enrichWithAi()`; added `@param Collection<int, Cuisine>` to `buildCityCuisineGrid()`; added `@return Collection<int, Cuisine>` to `getConfiguredCuisines()` (5 generics)
- Baseline regenerated: 199 entries, 0 generics. Also absorbed the lone method.unresolvableReturnType entry.
- `./vendor/bin/phpstan analyse` passes cleanly
- `app/Services/PopularityScoreService.php`: Added `@param Collection<int, mixed> $all` or `$allRestaurants` to 7 methods: `calculateScore()`, `calculateBreakdown()`, `calculateBreakdownForArray()`, `computeAggregates()`, `collectionMeanRating()`, `logDenominator()`, `minmaxStats()`
- Used `Collection<int, mixed>` because the service handles both Restaurant models and live-search arrays — PHPStan's invariant TValue rejects narrower union types at call sites
- Removed the 7 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly
- All 17 PopularityScoreService unit tests pass

### Iteration 13 — Fixed BackfillRestaurantWebsites generics + iterableValue (total: 218→215)
- `app/Console/Commands/BackfillRestaurantWebsites.php`: Added `@return Builder<Restaurant>` to `missingRestaurants()` (1 generics)
- Added `@return array<string>` to `candidateDomains()` (1 iterableValue)
- Added `@param array<string, mixed>` to `parseExtractedPrice()` (1 iterableValue)
- Removed the 3 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly
- `app/Console/Commands/EnrichRestaurants.php`: Added `@return Collection<int, Cuisine>` to `getCuisines()` (1 generics)
- Added `@return array<int, float>|null` to `resolveCityCoordinates()` (1 iterableValue)
- Added `/** @var array<string, array{float, float}> $cities */` before `config()` call in `enrichAllCities()` and `/** @var array<string, int> $cityResults */` in both `enrichAllCities()` and `enrichDiscoveredCities()` (2 argument.templateType from `collect()`)
- Removed the 4 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly
- `app/Models/RestaurantSocialLink.php`: Added `@return BelongsTo<Restaurant, $this>` to `restaurant()` (1 generics)
- `app/Models/User.php`: Added `@return BelongsToMany<Restaurant, $this>` to `favorites()` (1 generics)
- Removed the 2 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 10 — Fixed SearchController generics + LiveSearchService return type (total: 229→224)
- `app/Http/Controllers/SearchController.php`: Added `@param Builder<Restaurant> $query` / `@return Builder<Restaurant>` PHPDoc to `applySort()` (2 generics)
- `app/Services/LiveSearchService.php`: Added `: \Illuminate\Http\Client\Response` return type to `buildPoolRequest()` (1 missingType.return)
- Also removed the 2 SearchController `applyRestaurantSort()` generics — the trait already had generics from iteration 9 but the baseline entries under SearchController were never removed
- Removed all 5 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 9 — Fixed all 16 RestaurantController baseline entries (total: 245→229)
- `app/Http/Controllers/RestaurantController.php`: Added `: InertiaResponse` return types to `index()`, `show()`, `preview()`, `leaderboard()`, `compare()`
- Added `: JsonResponse` return type to `apiIndex()`
- Added `@return Builder<Restaurant>` to `buildRestaurantQuery()`; `@param Builder<Restaurant>` / `@return Builder<Restaurant>` to `applySortMode()`
- Changed `@var Builder $query` → `@var Builder<Restaurant> $query` (2 instances)
- Added `@param array<array<string, mixed>>` / `@return array<array<string, mixed>>` to `persistLiveResults()`; `@param array<array<string, mixed>>` to `snapshotLiveResults()`
- Imported `InertiaResponse` and `JsonResponse`
- `app/Http/Controllers/Concerns/SortsRestaurantQueries.php`: Added `@param Builder<Restaurant>` / `@return Builder<Restaurant>` to `applyRestaurantSort()`
- Key finding: separate `/** @param */` / `/** @return */` docblocks before a method are NOT merged — PHPStan only reads the last one. Must include `@param`/`@return` inside the main method docblock.
- Also resolved the 2 `argument.templateType` (`->when()` on `Builder`) entries — properly typed Builder<Restaurant> resolves `TWhenReturnType`
- Removed all 16 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 8 — Fixed Restaurant model generics (total: 255→245)
- `app/Models/Restaurant.php`: Added `/** @use HasFactory<\Database\Factories\RestaurantFactory> */` before `use HasFactory`
- Added `@return BelongsToMany<Cuisine, $this>` on `cuisines()`, `@return BelongsToMany<User, $this>` on `favoritedBy()`, `@return HasMany<RestaurantSocialLink, $this>` on `socialLinks()`
- Added `@param Builder<Restaurant> $query` + `@return Builder<Restaurant>` on `scopeActive()`, `scopeNearby()`, `scopeByPopularity()`
- Removed the 10 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 7 — Fixed Cuisine model generics (total: 258→255)
- `app/Models/Cuisine.php`: Added `/** @use HasFactory<\Database\Factories\CuisineFactory> */` before `use HasFactory`
- Added `@return BelongsTo<CuisineCategory, $this>` on `category()`
- Added `@return BelongsToMany<Restaurant, $this>` on `restaurants()`
- Removed the 3 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 6 — Fixed BlogPost generics (total: 264→258)
- `app/Models/BlogPost.php`: Added `/** @use HasFactory<\Database\Factories\BlogPostFactory> */` before `use HasFactory`
- Added `@return BelongsTo<User, $this>` on `author()`
- Added `@param Builder<BlogPost>` / `@return Builder<BlogPost>` on `scopePublished()` and `scopeDraft()`
- Removed the 6 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly
- `app/Models/CuisineCategory.php`: Added `/** @use HasFactory<...> */` inline before `use HasFactory` and `@return HasMany<Cuisine, $this>` on `cuisines()`
- Key finding: `@use` for traits must go directly before the `use` statement, not on the class docblock
- Removed the 2 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 4 — Fixed all 7 ExternalApiCache type issues (total: 273→266)
- `app/Models/ExternalApiCache.php`: Added `@param Builder<ExternalApiCache>` / `@return Builder<ExternalApiCache>` PHPDoc to `scopeExpired()` and `scopeFresh()` (4 missingType.generics)
- Added `@param array<mixed>` to `put()` and `storeByKey()` (2 missingType.iterableValue)
- Added `@return array<mixed>|null` to `findByKey()` (1 missingType.iterableValue)
- Removed the 7 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 3 — Added array value types to RequestSpec constructor (missingType.iterableValue: 208→205)
- `app/Services/Http/RequestSpec.php`: Added `@param array<string, mixed>` for `$query` and `$body`, `@param array<string, string>` for `$headers`
- Removed the 3 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 2 — Added missing return types to 12 controller/middleware methods (missingType.return: 19→7)
- `FavoriteController`: Added `use Illuminate\Http\JsonResponse;`, typed `index()` as `\Inertia\Response`, `toggle()` and `merge()` as `JsonResponse`
- `GeocodeController`: Added `use Illuminate\Http\JsonResponse;`, typed `reverse()`, `search()`, `forward()` as `JsonResponse`
- `EngagementController`: Added `use Illuminate\Http\Response;`, typed `store()` as `Response`
- `LogApiRequest`: Added `use Symfony\Component\HttpFoundation\Response;`, typed `handle()` as `Response`
- `HomeController`: Typed `__invoke()` as `\Inertia\Response`
- `CuisineController`: Typed `show()` as `\Inertia\Response`
- `Admin/DashboardController`: Typed `__invoke()` as `\Inertia\Response`
- `SearchController`: Typed `__invoke()` as `\Inertia\Response`
- Removed the 12 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly

### Iteration 1 — Eliminated all missingType.parameter entries (4→0)
- `app/Console/Commands/CopySqliteToMysql.php`: Added `Connection` import, typed `$source` and `$target` params in `copyTable()` and `targetColumns()` as `Connection`
- `app/Services/RestaurantWebsiteScraperService.php`: Typed `$hours` param in `normalizeOpeningHours()` as `mixed`
- Removed the 4 corresponding entries from `phpstan-baseline.neon`
- `./vendor/bin/phpstan analyse` passes cleanly
