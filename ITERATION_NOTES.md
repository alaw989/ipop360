# Iteration Notes

## Goal
shrink the PHPStan level-6 baseline by fixing real type issues in code

## State
- Baseline entries: 264 (down from 292)
- Remaining by category: missingType.iterableValue (202), missingType.generics (47), missingType.return (7), argument.templateType (7), method.unresolvableReturnType (1)
- missingType.parameter category fully eliminated (4→0)
- missingType.return down to 7 (all in RestaurantController + LiveSearchService)
- missingType.generics down to 47 (2 removed from CuisineCategory)
- Next: another model with generics (BlogPost: 6 entries, Restaurant: 10, Cuisine: 3) or BlogPost scopes + relations would be a good high-return target

## Log
### Iteration 5 — Fixed CuisineCategory generics (total: 266→264)
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
