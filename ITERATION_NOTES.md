# Iteration Notes

## Goal
add unit coverage for the 7 scheduled artisan commands (UpdateEngagement, GenerateSitemap, ScoreRestaurants, UptimeCanary, ScrapeRestaurantSocialLinks, GarbageCollectApiCache, VerifyRestaurantWebsites)

## State
- ✅ UpdateEngagement — 4 tests (correct counters, empty table, multiple restaurants, restaurants without engagement)
- Next: GarbageCollectApiCache (depends on ExternalApiCache model + expired() scope)
- Gotchas: restaurant_engagement table has no Eloquent model — use DB::table() directly in tests

## Log
- Iter 1: Added UpdateEngagementCommandTest (tests/Feature/) — 4 passing
