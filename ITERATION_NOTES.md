# Iteration Notes

## Goal
add a free Photon venue source to the live search and remove the broken Overpass name-regex fallback

## State
Done this iteration: removed the ENRICHMENT name-regex fallback — deleted
`RestaurantEnrichmentService::consumeOverpassResponses` (folded its call site in
`fetchAndNormalizeAllSources` into a direct `$this->overpass->consumePoolResponses`
call) and the already-dead `normalizeOverpassWithFallback`. Then deleted the three
now-unreferenced name-regex methods from `OverpassService` — `fetchByNameRaw`,
`searchByName`, `executeSearchByName` — plus the three `OverpassServiceTest` cases
that exercised them. Photon remains the name-based recall path in both live search
and enrichment. `composer test` 677 passed, pint + phpstan clean.

Next: consider adding Photon to `UptimeCanary`/`QuotaStatusCommand` source lists for
observability parity (Photon is a live source now but isn't in the canary/quota
health surface). Also `OverpassService::fetchRaw()` (the cuisine raw fetch, NOT the
name path) appears unused since the pool refactor — candidate for a follow-up
cleanup, but out of scope for the name-regex goal.

Gotchas:
- `OverpassService::fetchRaw` still exists and is cuisine-based (not name-regex);
  grep shows no callers, so it may be dead code to remove later.
- Photon API: no `radius` param (geofence via `bbox`); `q` is REQUIRED and
  text-matches names (unscoped falls back to `q=restaurant`); repeated
  `osm_tag=amenity:*` params (OR union, not comma-separated); no phone/website/
  cuisine tags exposed.

## Log
- [1] Added PhotonService + wired into live search (config + tests). `composer test`
  681 passed, pint + phpstan clean. Verified live: `q=mexican` bbox'd to Mobile, AL
  returns real restaurants.
- [2] Removed the live-search Overpass name-regex fallback (`applyOverpassNameFallback`
  + call site + test + stub). `composer test` 680 passed, pint + phpstan clean.
  Enrichment's name-regex fallback (`fetchByNameRaw`) remains — next decision.
- [3] Removed the enrichment name-regex fallback (`consumeOverpassResponses` +
  dead `normalizeOverpassWithFallback`) and deleted `OverpassService`'s
  `fetchByNameRaw`/`searchByName`/`executeSearchByName` + their three tests.
  `composer test` 677 passed, pint + phpstan clean.
