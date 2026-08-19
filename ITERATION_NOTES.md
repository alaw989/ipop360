# Iteration Notes

## Goal
make the app feel mobile-native while staying in the browser: give Search a mobile filter sheet (reuse shadcn Sheet, side=bottom like CuisinePicker/LocationPicker) + a mobile map toggle; upgrade TopNav mobile menu to a Sheet/drawer; add a sticky action bar (call/directions/website) to the restaurant detail page; add viewport-fit=cover + safe-area padding; tune Leaflet for touch; verify in a 375px viewport and desktop that nothing regresses

## State
Done: (1) sticky action bar; (2) Search mobile filter sheet; (3) Search mobile map toggle; (4) TopNav mobile menu → side=right Sheet drawer; (5) `viewport-fit=cover` meta + safe-area padding on bottom sheets; (6) Leaflet touch tuning — `dragging: !Browser.mobile`, `tapHold: Browser.mobile`, `scrollWheelZoom: false`; (7) accessible `SheetTitle`/`SheetDescription` on the two new sheets (new `SheetTitle.vue`/`SheetDescription.vue` UI components) — clears reka-ui DialogTitle/Description console warnings; (8) verified in 375px + desktop: filter sheet, map toggle, drawer, sticky action bar (fixed bottom:0, Call/Directions/Website), viewport meta; mobile toggles hidden on desktop, no regressions.
Next: post-loop hardening gate (pint → composer test → npm run build → phpstan → coverage) — run after loop.
Gotchas: Leaflet `Browser.mobile` is a named export (SearchMap uses `leaflet.Browser`, DetailMap uses `L.Browser`); CuisinePicker/LocationPicker sheets still lack a11y titles (pre-existing, out of scope).

## Log
- [7] Added SheetTitle/SheetDescription UI components and wired them into the Search filter sheet + TopNav drawer; added tests; cleared reka-ui a11y warnings; ran full 375px + desktop browser verification (no regressions) and `npm run build`.
- [6] Leaflet touch tuning: dragging disabled on mobile (page scroll wins), tapHold panning, scrollWheelZoom off; added map-option tests to SearchMap/DetailMap specs.
- [5] viewport-fit=cover meta + safe-area bottom padding on CuisinePicker/Search/LocationPicker sheets; added HomeControllerTest viewport assertion.
- [4] TopNav mobile menu → side=right Sheet drawer with header/close button; dropped manual Escape/outside-click handlers (reka-ui native).
- [3] Search mobile map toggle: Map/List button swaps main column between list and inline SearchMap (xl:hidden).
- [2] Search mobile filter sheet: `Filters` toggle (lg:hidden) + side=bottom Sheet wrapping SearchFilters; fixed banner-dismiss test button targeting.
- [1] Added sticky action bar (Call/Directions/Website) to restaurant detail page — new component, tests, Show.vue integration, safe-area padding.
