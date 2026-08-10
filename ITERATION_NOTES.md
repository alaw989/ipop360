# Iteration Notes

## Goal
add unit coverage for the 7 scheduled artisan commands (UpdateEngagement, GenerateSitemap, ScoreRestaurants, UptimeCanary, ScrapeRestaurantSocialLinks, GarbageCollectApiCache, VerifyRestaurantWebsites)

## State
- ✅ UpdateEngagement — 4 tests (correct counters, empty table, multiple restaurants, restaurants without engagement)
- ✅ GarbageCollectApiCache — 5 tests (no expired, dry-run preserves rows, dry-run >10, live deletes expired, idempotent)
- ✅ GenerateSitemap — 6 tests (valid XML, static pages, cuisine pages, active/inactive restaurants, published/draft posts, lastmod presence)
- ✅ ScoreRestaurants — 5 tests (no active restaurants, scores active with breakdown/rank_change, excludes inactive, city filter, rank change reordering)
- Next: UptimeCanary (uptime:canary command — check HTTP request and logging side effects)
- Gotchas: cuisines table has category_id NOT NULL FK — insert cuisine_category first; set Config::set('app.url', ...) in setUp for deterministic base URL in XML; ScoreRestaurants needs factories with is_active=true and google_rating set for quality signal to activate

## Log
- Iter 1: Added UpdateEngagementCommandTest (tests/Feature/) — 4 passing
- Iter 2: Added GarbageCollectApiCacheCommandTest (tests/Feature/) — 5 passing
- Iter 3: Added GenerateSitemapCommandTest (tests/Feature/) — 6 passing
- Iter 4: Added ScoreRestaurantsCommandTest (tests/Feature/) — 5 passing
