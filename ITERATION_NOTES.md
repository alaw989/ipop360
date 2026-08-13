# Iteration Notes

## Goal
stop passing BizData's ignored query param and add a bounded live retry for its flaky upstream

## State
Done both halves of the goal in one pass:

1. **Stop sending the ignored `query` param** — removed `'query' => $cuisine` from all
   three BizData request builders in `app/Services/BizDataApiService.php`:
   `search()`, `fetchRaw()`, and `poolRequestsFor()` (the `query` param is ignored
   by the upstream and can itself trigger the 502).
2. **Bounded live retry** — `poolRequestsFor()` now fans out to N identical GETs on
   the live read path (`read_path` context) and `consumePoolResponses()` takes the
   first success, mirroring the Overpass multi-mirror idiom. N is bounded by the new
   `live_search.bizdata_attempts` config (env `LIVE_SEARCH_BIZDATA_ATTEMPTS`, default
   2; set 1 to disable). Enrichment (`read_path=false`) keeps a single attempt — its
   next scheduled run retries naturally.

Tests: added `test_does_not_send_ignored_query_param` (Feature) and
`test_pool_requests_never_send_the_ignored_query_param` +
`test_pool_requests_fan_out_on_the_live_read_path_only` (Unit); updated
`test_sends_correct_query_parameters` to drop the `query=japanese` assertion.
`composer test` 680 passed, Pint clean, PHPStan 0 errors.

**Next (possible follow-ups, not required by the goal):**
- The cache key (`cacheKeyFor`) still includes `$cuisine`, so scoped searches cache
  byte-identical BizData responses under separate keys (harmless but redundant). Could
  drop `$cuisine` from the key now that the upstream ignores it — but that changes the
  key contract shared with `LiveSearchService`/`RestaurantEnrichmentService`, so left
  for a future iteration.

**Gotchas:**
- The retry is concurrent fan-out (not sequential backoff), because the codebase
  reserves blocking sequential retries for the enrichment path (see Socrata's "live
  path drops the 3x exponential-backoff retry" comment) to bound live wall time.

## Log
