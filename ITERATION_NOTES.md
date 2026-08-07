# Iteration Notes

## Goal
shrink the PHPStan level-7 baseline for tests/ by fixing real type issues in test code

## State
Removed 5 baseline entries from `tests/Feature/AuditRestaurantCuisinesTest.php`:
- `method.notFound`: `cuisines()` on `Model` (from `fresh()` in both helpers)
- `missingType.iterableValue`: `$cuisineSlugs`, `$extra`, `tags()` return
- `return.type`: `restaurant()` returning `Model|null` instead of `Restaurant`

Fix approach: replaced `fresh()` with `->whereKey($id)->firstOrFail()` which PHPStan resolves correctly to `Restaurant`. Added `@param` type annotations. Also replaced `! empty($cuisineSlugs)` with `$cuisineSlugs !== []` since the type is now `array<int, string>`.

### What is next
Many more test files have similar patterns. High-impact targets:
- Other files with `fresh()` returning `Model|null` on restaurant helpers (same pattern in BackfillRestaurantPhotosTest, DeduplicateRestaurantsTest, BatchedScoringTest, etc.)
- `missingType.iterableValue` errors (simple `@param` annotations needed in ~15 files)
- `method.nonObject` on artisan command union types (`PendingCommand|int`) — will need different approach

### Gotchas
- `Restaurant::factory()->create()` returns `Model` at PHPStan level 7, not `Restaurant`. Workaround: reload via `Restaurant::query()->whereKey($id)->firstOrFail()`
- `findOrFail()` on Builder resolves to `Model|Collection`, so use `whereKey()->firstOrFail()` instead
- The `$extra` parameter (used as `array_merge` second arg with `array<string, mixed>` type annotation) doesn't affect `create()` return type

## Log
1. Fixed all 5 PHPStan baseline entries for `AuditRestaurantCuisinesTest.php`
