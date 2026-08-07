# Iteration Notes

## Goal
shrink the PHPStan level-7 baseline for tests/ by fixing real type issues in test code

## State
Fixed `method.nonObject` on `PendingCommand|int` in `AiEnrichRestaurantsTest.php` (4 artisan calls, count: 4).

The fix uses `/** @var PendingCommand $command */` with an explicit `$command->run()` call after `assertExitCode()`. The explicit `run()` is mandatory because `PendingCommand` uses `__destruct()` for lazy execution — extracting to a variable prevents the destructor from firing until the variable goes out of scope, which is too late for subsequent assertions like `Queue::assertPushed()`.

Baseline: 728 → 722 lines.

Previous iteration fixes:
- `method.notFound` (Model::cuisines) and `argument.type` (Model vs Restaurant) across 4 files
- `BatchedScoringTest.php`: removed 6 of 7 entries
- `HomeControllerTest.php`: removed all 5 `method.notFound` entries
- `RestaurantControllerTest.php`: removed 3 `method.notFound` + 3 `missingType.iterableValue` = 6 entries
- `SearchControllerTest.php`: removed 1 `method.notFound` entry

### What is next
- `method.nonObject` on `PendingCommand|int` (5 files remaining: BackupDatabaseCommandTest, QuotaStatusCommandTest, RefreshAwardsTest, RestoreDatabaseCommandTest, SerpApiExhaustionTest) — same `@var PendingCommand` + explicit `run()` pattern
- `method.nonObject` on `PDOStatement|false` (BackupDatabaseCommandTest, RestoreDatabaseCommandTest)
- `missingType.iterableValue` (simple `@param`/`@return` annotations in ~10 remaining files)
- `argument.type` for Mockery mocks passed to service constructors (LiveSearchScoringTest, RestaurantEnrichment*Test — requires stub file work)

### Gotchas
- For `PendingCommand|int` union type issues, extracting to a variable with `@var PendingCommand` annotation AND calling `$command->run()` explicitly is required. The `__destruct()` pattern in PendingCommand means the variable must not outlive the assertions that depend on the command having executed.
- The remaining `method.notFound` on `Mockery\ExpectationInterface::once()` in BackfillRestaurantPhotosTest is a Mockery stub file limitation that can't be fixed in test code
- `method.alreadyNarrowedType` entries (assertIsArray on known array types) are low-value — assertions are still intentional even if PHPStan can see the type

## Log
1. Fixed all 5 PHPStan baseline entries for `AuditRestaurantCuisinesTest.php`
2. Fixed 4 PHPStan baseline entries for `BackfillRestaurantPhotosTest.php` (return.type, missingType.iterableValue, method.nonObject ×2)
3. Fixed 3 PHPStan baseline entries for `DeduplicateRestaurantsTest.php` (return.type, missingType.iterableValue, method.nonObject)
4. Fixed 18 baseline entries across 4 files (BatchedScoringTest, HomeControllerTest, RestaurantControllerTest, SearchControllerTest) — all `Model::cuisines()` and related `argument.type` issues
5. Fixed 1 entry (count 4) `method.nonObject` on `PendingCommand|int` in AiEnrichRestaurantsTest.php — learned that PendingCommand's `__destruct()` requires explicit `run()` when extracting to variable
