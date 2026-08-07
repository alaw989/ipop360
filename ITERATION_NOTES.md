# Iteration Notes

## Goal
shrink the PHPStan level-7 baseline for tests/ by fixing real type issues in test code

## State
Fixed `method.notFound` (Model::cuisines) and `argument.type` (Model vs Restaurant) across 4 files by replacing `Restaurant::factory()->create()` with the `whereKey()->firstOrFail()` / `whereKey()->get()` reload pattern:

- `BatchedScoringTest.php`: removed 6 of 7 entries (2 `method.notFound` + 1 `argument.type`, 3 `method.alreadyNarrowedType` remain)
- `HomeControllerTest.php`: removed all 5 `method.notFound` entries
- `RestaurantControllerTest.php`: removed 3 `method.notFound` + 3 `missingType.iterableValue` = 6 entries, now fully clean
- `SearchControllerTest.php`: removed 1 `method.notFound` entry, now fully clean

Baseline: 776 → 728 lines (129 → 121 entries).

### What is next
- `method.nonObject` on `PendingCommand|int` (6 files: AiEnrichRestaurantsTest, BackupDatabaseCommandTest, QuotaStatusCommandTest, RefreshAwardsTest, RestoreDatabaseCommandTest, SerpApiExhaustionTest)
- `missingType.iterableValue` (simple `@param`/`@return` annotations in ~10 remaining files)
- `argument.type` for Mockery mocks passed to service constructors (LiveSearchScoringTest, RestaurantEnrichment*Test — requires stub file work)

### Gotchas
- For `PendingCommand|int` union type issues, extracting to a variable with `@var PendingCommand` annotation is clean and avoids changing test assertions
- The remaining `method.notFound` on `Mockery\ExpectationInterface::once()` in BackfillRestaurantPhotosTest is a Mockery stub file limitation that can't be fixed in test code
- `method.alreadyNarrowedType` entries (assertIsArray on known array types) are low-value — assertions are still intentional even if PHPStan can see the type

## Log
1. Fixed all 5 PHPStan baseline entries for `AuditRestaurantCuisinesTest.php`
2. Fixed 4 PHPStan baseline entries for `BackfillRestaurantPhotosTest.php` (return.type, missingType.iterableValue, method.nonObject ×2)
3. Fixed 3 PHPStan baseline entries for `DeduplicateRestaurantsTest.php` (return.type, missingType.iterableValue, method.nonObject)
4. Fixed 18 baseline entries across 4 files (BatchedScoringTest, HomeControllerTest, RestaurantControllerTest, SearchControllerTest) — all `Model::cuisines()` and related `argument.type` issues
