# Iteration Notes

## Goal
shrink the PHPStan level-7 baseline for tests/ by fixing real type issues in test code

## State
Fixed `method.nonObject` on `PendingCommand|int` in `RefreshAwardsTest.php` and `RestoreDatabaseCommandTest.php`, and `method.nonObject` on `PDOStatement|false` in `RestoreDatabaseCommandTest.php`. The PDOStatement fix uses `@var PDOStatement` annotation on the extracted `$stmt` variable from `PDO::query()` before calling `fetchColumn()`.

Fixed `argument.type` and `offsetAccess.nonOffsetAccessible` for `glob()` returning `list<string>|false` in `BackupDatabaseCommandTest.php` and `RestoreDatabaseCommandTest.php`. Added `?: []` null-coalesce after `glob()` calls that didn't already have it, consistent with line 88 of RestoreDatabaseCommandTest which already used this pattern.

Fixed `method.nonObject` on `PDOStatement|false` (`fetchColumn()`) in `BackupDatabaseCommandTest.php` — same `@var PDOStatement` pattern already used in RestoreDatabaseCommandTest.

Fixed all 42 `missingType.iterableValue` entries across 18 test files. Added `@param` and `@return` PHPDoc annotations with value types (e.g., `array<string, mixed>`, `array<string, string>`, `array<int, string>`) to private test helper methods. Note: generics syntax (`array<K, V>`) is only valid in PHPDoc comments, NOT in PHP native type declarations.

Fixed remaining 13 `missingType.iterableValue` entries across 11 test files (BizDataApiServiceTest, EnrichCuisineTaggingTest, EnrichFreeOnlyTest, EnrichSearchResultsTest, OverpassServiceTest, RatingFirstComboOrderingTest, AiEnrichmentServiceTest, CuisineScopeTest, SocrataOpenDataServiceTest, RestaurantEnrichmentProcessFreeVenueTest). Root cause: multiple separate `/** @param */` and `/** @return */` docblocks before a method — PHPStan only reads the last one. Fix: merged into single combined docblocks.

Fixed all 10 `argument.templateType` entries on `collect()` calls in `AiEnrichRestaurantsTest.php` (2) and `LiveSearchScoringTest.php` (8). In AiEnrichRestaurantsTest, the fix extracts `Queue::pushed()` to a `@var array<int, EnrichRestaurantWithAi>` variable before passing to `collect()`. In LiveSearchScoringTest, `$scored` from `ReflectionMethod::invoke()` was annotated with `@var array<int, array<string, mixed>>`. For the inner `collect($mystery['score_breakdown']['signals'])` calls (lines 143/170), the chained mixed array access prevented template resolution — fixed by extracting `$signals` to a variable with a full array-shape `@var` annotation (`array<int, array{label: string, weight: float, normalized: float, contribution: float, detail: string}>`) matching the `@return` PHPDoc of `PopularityScoreService::calculateBreakdownForArray()`.

Baseline: 410 → 386 lines (68 → 64 entries, 117 → 113 errors).

### What is next
- `argument.type` for Mockery mocks passed to service constructors (LiveSearchScoringTest, RestaurantEnrichment*Test). A PHPStan stub file was attempted but did not override Mockery's existing `@return` annotations. Likely requires a `DynamicMethodReturnTypeExtension` or `@phpstan-` comments.
- `method.notFound` on `Mockery\ExpectationInterface::andReturn()` etc. (RefreshAwardsTest, LiveSearchScoringTest, BackfillRestaurantPhotosTest, EnrichSearchResultsTest — Mockery `shouldReceive()` returns `ExpectationInterface|HigherOrderMessage`, where `HigherOrderMessage` lacks `andReturn`/`once`/`andReturnNull`). Same stub/extension challenge.
- Individual issues: SerpApiQueryConstructionTest (return.type, argument.type for parse_str), EngagementApiTest (assign.propertyType), RestaurantResourceAggregatesTest (argument.type ×2), WebsiteScraperSsrfGuardTest (arguments.count), EnrichSearchResultsTest (argument.type for handle()), AiEnrichmentServiceTest (argument.type ×4).

### Gotchas
- `assertSuccessful()` on `PendingCommand` is an expectation-setter, not a runner. After extracting `$this->artisan(...)` to a `@var PendingCommand` variable, you must call `$command->run()` explicitly — otherwise the command never executes and side effects (like file creation) won't happen before your assertions.
- When fixing `PendingCommand|int`, remember that `assertFailed()` and `assertExitCode()` are also expectation-setters that need explicit `run()`.
- `method.alreadyNarrowedType` entries (assertIsArray on known array types) are low-value — assertions are still intentional even if PHPStan can see the type
- `glob()` in PHP 8.3 returns `array<int, string>|false`. Adding `?: []` converts false to an empty array, eliminating the union type without hiding real errors — a false return from glob() would already cause a test failure downstream.
- `array<K, V>` generics syntax is NOT valid in PHP native type declarations — it only works inside `/** @param */` / `/** @return */` PHPDoc annotations. Using it inline (e.g., `function foo(array<string, mixed> $x)`) will cause a parse error. Use docblock annotations instead.
- PHPStan reads only the LAST consecutive `/** */` docblock before a function/method. Multiple separate docblocks (a common pattern in this codebase) cause earlier `@param` annotations to be invisible to PHPStan. Always merge into a single combined docblock.
- PHPStan stub files cannot override existing `@return` annotations from vendor code — they are additive, not replacement. Mockery's `shouldReceive()` returning `ExpectationInterface|HigherOrderMessage` cannot be narrowed via a stub file.

## Log
1. Fixed all 5 PHPStan baseline entries for `AuditRestaurantCuisinesTest.php`
2. Fixed 4 PHPStan baseline entries for `BackfillRestaurantPhotosTest.php` (return.type, missingType.iterableValue, method.nonObject ×2)
3. Fixed 3 PHPStan baseline entries for `DeduplicateRestaurantsTest.php` (return.type, missingType.iterableValue, method.nonObject)
4. Fixed 18 baseline entries across 4 files (BatchedScoringTest, HomeControllerTest, RestaurantControllerTest, SearchControllerTest) — all `Model::cuisines()` and related `argument.type` issues
5. Fixed 1 entry (count 4) `method.nonObject` on `PendingCommand|int` in AiEnrichRestaurantsTest.php — learned that PendingCommand's `__destruct()` requires explicit `run()` when extracting to variable
6. Fixed 1 entry (count 1) `method.nonObject` on `PendingCommand|int` in SerpApiExhaustionTest.php — same pattern, baseline 722→716
7. Fixed 1 entry (count 10) `method.nonObject` on `PendingCommand|int` in QuotaStatusCommandTest.php — same pattern, baseline 716→710
9. Fixed 3 baseline entries (count 8) `method.nonObject` on `PendingCommand|int` in RefreshAwardsTest.php and RestoreDatabaseCommandTest.php — same `@var PendingCommand` + explicit `run()` pattern, baseline 704→686
10. Fixed 1 baseline entry (count 2) `method.nonObject` on `PDOStatement|false` in RestoreDatabaseCommandTest.php — `@var PDOStatement` on extracted `$stmt = $pdo->query(...)` before `fetchColumn()`, baseline 686→680
11. Fixed 5 baseline entries (count 7) `argument.type` and `offsetAccess.nonOffsetAccessible` on `glob()` return type in BackupDatabaseCommandTest.php and RestoreDatabaseCommandTest.php — added `?: []` after `glob()` calls, baseline 680→650
12. Fixed 1 baseline entry (count 1) `method.nonObject` on `PDOStatement|false` (`fetchColumn()`) in BackupDatabaseCommandTest.php — extracted `$stmt` variable with `@var PDOStatement` annotation before calling `fetchColumn()`, baseline 650→644
13. Fixed all 42 `missingType.iterableValue` entries across 18 test files — added `@param`/`@return` PHPDoc annotations with array value types, baseline 644→488
14. Fixed remaining 13 `missingType.iterableValue` entries across 11 test files — merged multiple separate `/** */` docblocks into single combined docblocks so PHPStan reads all annotations, baseline 488→410 (81→69→68 entries)
15. Fixed all 10 `argument.templateType` entries on `collect()` calls in AiEnrichRestaurantsTest.php (2) and LiveSearchScoringTest.php (8) — added `@var` annotations on input variables so PHPStan can resolve `collect()` template types. For nested collect() calls on chained array access (e.g., `collect($mystery['score_breakdown']['signals'])`), extracting to a variable with a full array-shape `@var` was needed because intermediate `mixed` from array access on `array<string, mixed>` still blocked template resolution. Baseline 410→386 (68→64 entries, 117→113 errors).

(End of file - total 53 lines)
