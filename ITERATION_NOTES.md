# Iteration Notes

## Goal
shrink the PHPStan level-7 baseline by fixing real type issues in code

## State
- Shrunk baseline from 117 to 111 lines (removed 6 lines)
- Fixed HtmlSanitizer::sanitize() — DOMDocument::saveHTML() returns string|false, now guards against false before passing to decodeNonAscii()

### Next
- RestaurantWebsiteScraperService.php has the most baseline entries (15+), all DOM-related. Fixing those would be the biggest single-file win.
- The orderByRaw/selectRaw/whereRaw non-literal-string issues across controllers and Restaurant model are the next biggest category.
- EnrichRestaurants.php strtolower callback is another easy one-liner.

### Gotchas
- DOM-related fixes in RestaurantWebsiteScraperService.php may require adding explicit type checks on getElementsByTagName() and getElementById() return values (DOMNodeList|false).
- The restaurant sort/query builders pass dynamic strings to orderByRaw etc — may need explicit literal-string casts or restructuring.

## Log
