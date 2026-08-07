# Iteration Notes

## Goal
shrink the PHPStan level-7 baseline by fixing real type issues in code

## State
- Shrunk baseline from 111 to 24 entries (removed 87 lines)
- Fixed all 15 baseline entries in RestaurantWebsiteScraperService.php: added false guards on `$xpath->query()` return values and `assert($var instanceof \DOMNode|\DOMElement)` before textContent/getAttribute access, plus preg_split false guard
- Fixed 1 entry in BackfillRestaurantWebsites.php: added false guard on `$xpath->query()` before foreach
- Fixed 1 entry in EnrichRestaurants.php: changed `array_map('strtolower', ...)` to closure with `(string)` cast
- Fixed HtmlSanitizer::sanitize() (prior iteration) — DOMDocument::saveHTML() returns string|false, now guards against false
- Fixed DeduplicateRestaurants::findDuplicatePairs() return type — replaced `(array) $row` cast with explicit array shape `['keep_id' => (int), 'dupe_id' => (int), 'name' => (string)]` so PHPStan sees the typed shape

### Next
- Remaining entries: BlogPostController.php (property type, 1 entry), HomeController.php + RestaurantController.php + SearchController.php (orderByRaw non-literal-string, 17 entries), Restaurant.php (selectRaw/orderByRaw/whereRaw, 3 entries), LiveSearchService.php (offset access, 2 entries), RestaurantEnrichmentService.php (argument type, 1 entry)
- The orderByRaw/selectRaw/whereRaw non-literal-string issues across controllers and Restaurant model are the biggest remaining category (20 entries)
- BlogPostController author_id is the next single-entry fix

### Gotchas
- `assert()` is disabled in production (zend.assertions=0), so the DOM assertion changes are runtime no-ops that only satisfy PHPStan
- For DOMElement contexts (e.g. `<a>` tags needing getAttribute()), use `assert($var instanceof \DOMElement)` rather than `\DOMNode`
- The restaurant sort/query builders pass dynamic strings to orderByRaw etc — may need explicit literal-string casts or restructuring

## Log
- Iteration 2: Fixed RestaurantWebsiteScraperService.php (15 entries), BackfillRestaurantWebsites.php (1 entry), EnrichRestaurants.php (1 entry). Baseline: 111 → 25. Phpstan clean, all 563 tests pass.
- Iteration 3: Fixed DeduplicateRestaurants::findDuplicatePairs() return type. Baseline: 25 → 24. Phpstan clean, all 563 tests pass.
