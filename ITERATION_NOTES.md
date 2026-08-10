# Iteration Notes

## Goal
add unit coverage for the 7 scheduled artisan commands (UpdateEngagement, GenerateSitemap, ScoreRestaurants, UptimeCanary, ScrapeRestaurantSocialLinks, GarbageCollectApiCache, VerifyRestaurantWebsites)

## State
- ✅ UpdateEngagement — 4 tests (correct counters, empty table, multiple restaurants, restaurants without engagement)
- ✅ GarbageCollectApiCache — 5 tests (no expired, dry-run preserves rows, dry-run >10, live deletes expired, idempotent)
- Next: GenerateSitemap (needs sitemap:generate signature; check how it produces output)
- Gotchas: restaurant_engagement table has no Eloquent model — use DB::table() directly in tests

## Log
- Iter 1: Added UpdateEngagementCommandTest (tests/Feature/) — 4 passing
- Iter 2: Added GarbageCollectApiCacheCommandTest (tests/Feature/) — 5 passing
