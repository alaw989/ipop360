# Iteration Notes

## Goal
shrink the PHPStan level-7 baseline for tests/ by fixing real type issues in test code

## State
Fixed `method.nonObject` on `PendingCommand|int` in `BackupDatabaseCommandTest.php` (3 artisan calls, count: 3). Applied the `@var PendingCommand` + explicit `run()` pattern across all 3 test methods. Note: `assertSuccessful()` is an expectation-setter (like `assertExitCode`), not a runner — the explicit `run()` call is required after setting expectations.

Baseline: 710 → 704 lines.

### What is next
- `method.nonObject` on `PendingCommand|int` (2 files remaining: RefreshAwardsTest, RestoreDatabaseCommandTest) — same `@var PendingCommand` + explicit `run()` pattern
- `method.nonObject` on `PDOStatement|false` (BackupDatabaseCommandTest, RestoreDatabaseCommandTest)
- `missingType.iterableValue` (simple `@param`/`@return` annotations in ~10 remaining files)
- `argument.type` for Mockery mocks passed to service constructors (LiveSearchScoringTest, RestaurantEnrichment*Test — requires stub file work)

### Gotchas
- `assertSuccessful()` on `PendingCommand` is an expectation-setter, not a runner. After extracting `$this->artisan(...)` to a `@var PendingCommand` variable, you must call `$command->run()` explicitly — otherwise the command never executes and side effects (like file creation) won't happen before your assertions.
- For `PendingCommand|int` union type issues, extracting to a variable with `@var PendingCommand` annotation AND calling `$command->run()` explicitly is required. The `__destruct()` pattern in PendingCommand means the variable must not outlive the assertions that depend on the command having executed.
- The remaining `method.notFound` on `Mockery\ExpectationInterface::once()` in BackfillRestaurantPhotosTest is a Mockery stub file limitation that can't be fixed in test code
- `method.alreadyNarrowedType` entries (assertIsArray on known array types) are low-value — assertions are still intentional even if PHPStan can see the type

## Log
1. Fixed all 5 PHPStan baseline entries for `AuditRestaurantCuisinesTest.php`
2. Fixed 4 PHPStan baseline entries for `BackfillRestaurantPhotosTest.php` (return.type, missingType.iterableValue, method.nonObject ×2)
3. Fixed 3 PHPStan baseline entries for `DeduplicateRestaurantsTest.php` (return.type, missingType.iterableValue, method.nonObject)
4. Fixed 18 baseline entries across 4 files (BatchedScoringTest, HomeControllerTest, RestaurantControllerTest, SearchControllerTest) — all `Model::cuisines()` and related `argument.type` issues
5. Fixed 1 entry (count 4) `method.nonObject` on `PendingCommand|int` in AiEnrichRestaurantsTest.php — learned that PendingCommand's `__destruct()` requires explicit `run()` when extracting to variable
6. Fixed 1 entry (count 1) `method.nonObject` on `PendingCommand|int` in SerpApiExhaustionTest.php — same pattern, baseline 722→716
7. Fixed 1 entry (count 10) `method.nonObject` on `PendingCommand|int` in QuotaStatusCommandTest.php — same pattern, baseline 716→710
8. Fixed 1 entry (count 3) `method.nonObject` on `PendingCommand|int` in BackupDatabaseCommandTest.php — same pattern, baseline 710→704
