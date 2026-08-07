# Iteration Notes

## Goal
increase test coverage

## State
Coverage loop in progress. Tests at 536 passing (started at 475). Services covered so far:
- `App\Services\HtmlSanitizer` — `tests/Unit/HtmlSanitizerTest.php` (11 tests / 30 assertions)
- `App\Services\LiveVenuePersister` — `tests/Unit/LiveVenuePersisterTest.php` (8 tests / 24 assertions)
- `App\Services\AiEnrichmentService` — `tests/Unit/AiEnrichmentServiceTest.php` (7 tests / 17 assertions)
- `App\Services\SerpApiService` normalization/cache-key logic — `tests/Unit/SerpApiServiceTest.php` (8 tests / 35 assertions). Note: the existing Feature tests cover query construction, key extraction, quota guard; this new unit test covers the pure venue-normalization methods (`normalizeRaw`, `normalizeForEnrichment`, `cacheKeyFor`) that those never touch.
- `App\Services\OverpassService` — `tests/Unit/OverpassServiceTest.php` (10 tests / 54 assertions) covering node/way/relation coord extraction, skip-missing-coords-or-name, haversine distance + ascending sort, stable negative id/slug, address/website fallbacks, `cuisine`+feature tag extraction, `normalizeForEnrichment`, and deterministic/input-sensitive `cacheKeyFor`.

Still uncovered: `RestaurantEnrichmentService`, `LiveSearchService.search()` orchestration (LiveSearchScoringTest covers scoring only).

Gotchas:
- HtmlSanitizer: Latin accented chars round-trip as HTML *named* entities (`Café` → `Caf&eacute;`) because `encodeNonAscii` writes numeric entities but `DOMDocument->saveHTML` re-serializes as named. Assert with `html_entity_decode`.
- LiveVenuePersister: `normalize()` runs first, so tests see normalized output (https:// prefix, digit-stripped phone). `has_award` is unset before update so live sources never overwrite a real award.
- AiEnrichment: no-key → no-op; 429 → fallback provider chain.
- RestaurantValidationService.normalize(): the leading whitespace-trim loop means empty/whitespace-only URL values stay as `''` (guard is `!empty`), they are NOT dropped or nulled.
- BizData: `normalizeRaw` reads `lon` (not `lng`) for coords, computes haversine `distance` rounded to 0.1, emits a negative `id` and a `{slug}-{md5₆}` slug; a row with no `name` is skipped. The slug suffix is md5-derived, so assert with `assertStringStartsWith('diner-')` rather than an exact literal.

## Next
All four free/keyless live-search sources (BizData, Socrata, SerpApi, Overpass) now have normalize/cache-key unit tests. Next steps: `LiveSearchService.search()` orchestration (involves the VenuePipeline + scoring + the four sources; partially covered via `LiveSearchScoringTest`), or the largest gap `RestaurantEnrichmentService` — which needs many collaborators stubbed/mocked, so consider testing small behaviors indirectly (e.g. its dispatch/should-run logic) rather than the full enrichment path.

## Log
- Iter 1: Added `tests/Unit/HtmlSanitizerTest.php` (11 tests / 30 assertions).
- Iter 2: Added `tests/Unit/LiveVenuePersisterTest.php` (8 tests / 24 assertions).
- Iter 3: Added `tests/Unit/AiEnrichmentServiceTest.php` (7 tests / 17 assertions).
- Iter 4: Added `tests/Unit/SerpApiServiceTest.php` (8 tests / 35 assertions) covering `normalizeRaw`, `normalizeForEnrichment`, `cacheKeyFor` — the pure normalization/cache logic Feature tests skip.
- Iter 5: Added `tests/Unit/RestaurantValidationServiceTest.php` (10 tests / 41 assertions) covering `normalize`, `normalizeUrl`, `clampRating`, `clampLatitude`, `clampLongitude`, `normalizePhone`, `normalizePriceRange`.
- Iter 6: Added `tests/Unit/CuisineScopeTest.php` (5 tests / 18 assertions) covering the three state predicates (`isUnscoped`, `isScoped`, `isInvalid`) plus the mutual-exclusivity truth table and taxonomy-field exposure.
- Iter 7: Added `tests/Unit/BizDataApiServiceTest.php` (8 tests / 43 assertions) covering the free/keyless live-search source's pure logic: `normalizeRaw` field mapping (incl. address/city/state/zip/country/website fallbacks), haversine distance, skip-no-name, stable negative id/slug, plus `normalizeForEnrichment` and `cacheKeyFor`.
- Iter 8: Added `tests/Unit/OverpassServiceTest.php` (10 tests / 54 assertions) covering the last free/keyless live-search source's pure logic: node/way/relation coord extraction, skip-missing-coords-or-name, haversine + sort, stable negative id/slug (`-abs(crc32('osm:{id}'))`), `addr:*`/`url` fallbacks, `cuisine` (semicolon-split, ucwords) + FEATURE_TAGS extraction, `normalizeForEnrichment`, and `cacheKeyFor`. Gotcha: `addr:housenumber`+`addr:street` combine into `address` only when both present; `postal_code` falls back `addr:postcode` → `postcode`; website `website` → `url`.
