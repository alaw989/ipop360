# Iteration Notes

## Goal
increase test coverage

## State
Coverage loop in progress. Tests at 526 passing (started at 475). Services covered so far:
- `App\Services\HtmlSanitizer` — `tests/Unit/HtmlSanitizerTest.php` (11 tests / 30 assertions)
- `App\Services\LiveVenuePersister` — `tests/Unit/LiveVenuePersisterTest.php` (8 tests / 24 assertions)
- `App\Services\AiEnrichmentService` — `tests/Unit/AiEnrichmentServiceTest.php` (7 tests / 17 assertions)
- `App\Services\SerpApiService` normalization/cache-key logic — `tests/Unit/SerpApiServiceTest.php` (8 tests / 35 assertions). Note: the existing Feature tests cover query construction, exhaustion, quota guard; this new unit test covers the pure venue-normalization methods (`normalizeRaw`, `normalizeForEnrichment`, `cacheKeyFor`) that those never touch.

Still untested: `RestaurantEnrichmentService`, `LiveSearchService`, `CuisineMatcher` internals (`AugmentedVenue`, `RestaurantContext`, `resolveScope` edge cases).

Gotchas:
- HtmlSanitizer: Latin accented chars round-trip as HTML *named* entities (`Café` → `Caf&eacute;`) because `encodeNonAscii` writes numeric entities but `DOMDocument->saveHTML` re-serializes as named. Assert with `html_entity_decode`.
- LiveVenuePersister: `normalize()` runs first, so tests see normalized output (https:// prefix, digit-stripped phone). `has_award` is unset before update so live sources never overwrite a real award.
- AiEnrichment: no-key → no-op; 429 → fallback provider chain.
- RestaurantValidationService.normalize(): the leading whitespace-trim loop means empty/whitespace-only URL values stay as `''` (guard is `!empty`), they are NOT dropped or nulled.

## Next
Pick the next uncovered service. `CuisineMatcher` internals beyond `resolveScope`/`matchesEvidence` (its DTOs `AugmentedVenue`/`RestaurantContext`, `venueMatchesRival`-style logic) are a good next target with no likely collaborators. `RestaurantEnrichmentService` is the largest gap but requires stubbing/mocking many collaborators — consider testing small behaviors indirectly. `LiveSearchService` is partially covered via `LiveSearchScoringTest` but its search() orchestration is untested.

## Log
- Iter 1: Added `tests/Unit/HtmlSanitizerTest.php` (11 tests / 30 assertions).
- Iter 2: Added `tests/Unit/LiveVenuePersisterTest.php` (8 tests / 24 assertions).
- Iter 3: Added `tests/Unit/AiEnrichmentServiceTest.php` (7 tests / 17 assertions).
- Iter 4: Added `tests/Unit/SerpApiServiceTest.php` (8 tests / 35 assertions) covering `normalizeRaw`, `normalizeForEnrichment`, `cacheKeyFor` — the pure normalization/cache logic Feature tests skip.
- Iter 5: Added `tests/Unit/RestaurantValidationServiceTest.php` (10 tests / 41 assertions) covering `normalize`, `normalizeUrl`, `clampRating`, `clampLatitude`, `clampLongitude`, `normalizePhone`, `normalizePriceRange`.
- Iter 6: Added `tests/Unit/CuisineScopeTest.php` (5 tests / 18 assertions) covering the three state predicates (`isUnscoped`, `isScoped`, `isInvalid`) plus the mutual-exclusivity truth table and taxonomy-field exposure.
