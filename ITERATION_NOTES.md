# Iteration Notes

## Goal
add unit coverage for the 7 scheduled artisan commands (UpdateEngagement, GenerateSitemap, ScoreRestaurants, UptimeCanary, ScrapeRestaurantSocialLinks, GarbageCollectApiCache, VerifyRestaurantWebsites)

## State
- ✅ UpdateEngagement — 4 tests (correct counters, empty table, multiple restaurants, restaurants without engagement)
- ✅ GarbageCollectApiCache — 5 tests (no expired, dry-run preserves rows, dry-run >10, live deletes expired, idempotent)
- ✅ GenerateSitemap — 6 tests (valid XML, static pages, cuisine pages, active/inactive restaurants, published/draft posts, lastmod presence)
- ✅ ScoreRestaurants — 5 tests (no active restaurants, scores active with breakdown/rank_change, excludes inactive, city filter, rank change reordering)
- ✅ UptimeCanary — 6 tests (all healthy, no social links, stale scrape, serpapi exhausted, serpapi near circuit breaker, degraded cache count increments)
- Next: ScrapeRestaurantSocialLinks (social:scrape command)
- Gotchas: Carbon 3 diffInHours() is signed by default — fixed bug in UptimeCanary where now()->diffInHours($lastScrape) returned negative for past dates, never triggering the >48h stale check. Use explicit diffInHours($date, true) for absolute diff.
- Gotchas: services.bizdata.url + services.overpass.url config keys don't exist — API health checks silently skip in tests (no outbound HTTP). Configure them with Config::set() if you want to test HTTP-faking flows.

## Log
- Iter 1: Added UpdateEngagementCommandTest (tests/Feature/) — 4 passing
- Iter 2: Added GarbageCollectApiCacheCommandTest (tests/Feature/) — 5 passing
- Iter 3: Added GenerateSitemapCommandTest (tests/Feature/) — 6 passing
- Iter 4: Added ScoreRestaurantsCommandTest (tests/Feature/) — 5 passing
- Iter 5: Added UptimeCanaryCommandTest (tests/Feature/) — 6 passing; fixed Carbon 3 signed diffInHours() bug in command
