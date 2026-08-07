# Iteration Notes

## Goal
shrink the PHPStan level-6 baseline by fixing real type issues in code

## State
- Baseline entries: 137 (down from 150)
- Remaining: missingType.iterableValue (137)
- Top files by iterableValue count: SocrataOpenDataService.php (24), LiveSearchService.php (24), PopularityScoreService.php (19), RestaurantWebsiteScraperService.php (9), RestaurantEnrichmentService.php (8), VenuePipeline.php (8)

## Log
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
