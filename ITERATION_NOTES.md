# Iteration Notes

## Goal
context-first restaurant image search: add searchImageForRestaurant(Restaurant , ?string  = null): ?string to RestaurantWebsiteScraperService that chains image sources using the row's VERIFIED context before falling back to keyword/search, and is used by BOTH the daily restaurants:backfill-photos fill and the --verify re-source path. Chain order: (1) multi-page website crawl — new scrapePhotos()/scrapeMultiPage() that fetches the homepage og:image plus /menu and /gallery and /photos paths (bounded to homepage + 2 extra) and extracts <img>/og:image via the existing extractPhotos/extractPhotoUrl; (2) OSM image= tag — accept the passed  directly (the Overpass normalizer should surface image/image:0/wikimedia_commons tags as photo_url); (3) social-profile image — look up the row's restaurant_social_links for an instagram handle and fetch its ?__a=1 or og:image, or facebook page og:image; (4) Wikidata wdt:P18 — extend the existing WikidataService coord box query to also return the image property; (5) the existing name-relevance-guarded searchWikimediaCommons + searchWikipediaImage; (6) Google CSE LAST with num=1→num=5 picking the best result. searchAnyImage() becomes a thin wrapper that calls searchImageForRestaurant() so the existing callers and the name-relevance guard stay intact. Log per-source hit attribution (which step found the image) to the enrichment channel. Google CSE is 429-exhausted at 100/day so it must remain the final fallback only

## State
Iteration 1 (2026-08-15): implemented the core `searchImageForRestaurant(Restaurant $restaurant, ?string $osmImage = null): ?string` on `RestaurantWebsiteScraperService` and made all 5 seed tests in `tests/Unit/ContextImageSearchTest.php` green.

- **Chain built (steps 1,2,3,5,6):** (1) `scrapePhotos()` multi-page crawl (homepage + up to 2 of `/menu`,`/gallery`,`/photos`, bounded, reusing the domain-skip/SSRF/robots guards) → (2) OSM `image=` passed through directly → (3) `searchSocialProfileImage()` (instagram→facebook og:image from `restaurant_social_links`) → (5) existing name-relevance-guarded Wikimedia/Wikipedia → (6) Google CSE LAST. Added `logImageSource()` per-source hit attribution to the `enrichment` channel.
- **CSE change:** `searchGoogleImages()` now `num=5` + `pickBestGoogleImage()` (prefer a link whose URL slug contains the restaurant-name slug, else first link).
- **Test tweak:** seed test setUp now sets `restaurant-finder.website_scraper.ssrf_guard=false` (`.example` domains don't resolve; the fail-closed SSRF guard would otherwise block the crawl) — same pattern as `PhotoBackfillScheduleTest`.
- **Left `searchAnyImage()` unchanged** this iteration (still calls `scrape`/`searchWikimediaCommons`/`searchWikipediaImage`/`searchGoogleImages`) so `PhotoBackfillScheduleTest`'s partial mock keeps passing.

Iteration 2 (2026-08-15): wired chain step (4) — Wikidata `wdt:P18` coord-verified image lookup.

- **`WikidataService` extended** with the image box-query machinery: `searchWikidataImage(name, lat, lng)` (box wrapper), `findRestaurantImagesInBox()` (HTTP + 30-day `ExternalApiCache` under `images_box:`), `buildImageSparql()` (same geof:latitude/longitude FILTER as the award query, `wdt:P18 ?image` via OPTIONAL), `bestImageInSet()` (name-similarity ≥ threshold + `IMAGE_MAX_DISTANCE_KM` proximity cap, closest wins), and `imageUrlFromValue()` (Commons filename → `Special:FilePath/<file>?width=800`; absolute http(s) passes through verbatim).
- **Chain step (4) wired** in `searchImageForRestaurant()`: only when `latitude`/`longitude` are non-null, via a lazy `wikidataService()` accessor (keeps `new RestaurantWebsiteScraperService` working in non-Wikidata tests). Logs `source=wikidata`.
- **Type fix:** added `@extends Factory<Restaurant>` to `RestaurantFactory` (matching `UserFactory`) so `Restaurant::factory()->create()` is typed `Restaurant`; this surfaced a latent null-call in `test_updates_updated_at_timestamp` — fixed with an explicit `assertNotNull($fresh->updated_at)`.

Iteration 3 (2026-08-15): wired chain step (2) upstream — the Overpass normalizer now surfaces the OSM image tags as `photo_url`.

- **`OverpassService::normalizeResults()`** (the live-search shape, shared by `normalizeRaw`) now sets `photo_url` via a new `extractPhotoUrl()` helper instead of hardcoding null. Priority: `image` → `image:0` → `wikimedia_commons`. A direct `http(s)` URL passes through verbatim; a `File:`-prefixed or bare Commons filename resolves to `Special:FilePath/<file>?width=800` (same convention as `WikidataService::imageUrlFromValue`); a `wikimedia_commons` `Category:` value is dropped (can't resolve to one image).
- **`normalizeForEnrichment()`** now carries `photo_url` through (`$r['photo_url'] ?? null`) instead of nulling it — matching `SerpApiService::normalizeForEnrichment` — so OSM images actually persist through `RestaurantEnrichmentService::processFreeVenue` (which already reads `$venue['photo_url']`).
- **Tests:** 5 new unit tests in `OverpassServiceTest` (image tag verbatim, image:0 + wikimedia_commons File: fallback, Category: skip, priority order, enrichment carries photo_url).

Iteration 4 (2026-08-15): wired the command onto the context-first chain — `BackfillRestaurantPhotos` fill + `--verify` now call `searchImageForRestaurant()` directly.

- **Both paths rewired:** `handle()` (fill) and `handleVerify()` (re-source) call `$scraper->searchImageForRestaurant($restaurant, $this->osmContextImage($restaurant))` instead of the old scalar `searchAnyImage(name, city, state, website_url)`.
- **New `osmContextImage()` helper:** returns the row's current `photo_url` as the OSM-image argument ONLY when its host contains `wikimedia.org`/`wikipedia.org` (stable, verified-context images — OSM `wikimedia_commons`, Wikidata `wdt:P18`, Wikipedia infobox all resolve there). Decay-prone Google CDN URLs (gps-cs-s / lh3.googleusercontent.com) are nulled out, so a dead CDN row re-sources from context (website → social → wikidata → wikimedia) before ever touching Google CSE again.
- **Tests:** migrated the command-facing mocks from `searchAnyImage` → `searchImageForRestaurant` across `BackfillRestaurantPhotosTest`, `PhotoBackfillScheduleTest`, `PhotoVerifyTest`, `PhotoBackfillImprovementsTest`; added two `PhotoVerifyTest` cases pinning the osm-context rule (dead CDN → `null` arg; dead Wikimedia → URL passed through). `composer test` 812 passed; `npm run build` ok; `pint --test` clean.

Iteration 5 (2026-08-15): converted `searchAnyImage()` into a thin wrapper over the context-first chain and rewired the last two callers — the Goal is now fully achieved.

- **`searchAnyImage()` is now a thin wrapper:** builds a transient, unsaved `Restaurant` from `name`/`city`/`state`/`website_url` and delegates to `searchImageForRestaurant($restaurant)` (OSM arg left null — that only comes from a caller's verified context). The name-relevance guard and every other chain step stay intact for the remaining callers.
- **`searchSocialProfileImage()` guard:** returns null early when `$restaurant->getKey() === null` — a transient model can't hold stored social links, and this also avoids a pointless (and, in unit tests without migrations, fatal) `restaurant_id IS NULL` query.
- **`RestaurantEnrichmentService::enrichWebsiteData` rewired:** both `searchAnyImage(name, city, state[, website])` calls → `searchImageForRestaurant($restaurant)`, so enrichment now uses the row's verified context (website → social → wikidata → wikimedia → CSE) instead of the scalar fallback.
- **`PhotoBackfillScheduleTest::test_google_custom_search_is_the_last_resort_image_source` updated:** partial mock now fakes `scrapePhotos` (not `scrape`) alongside the wikimedia/wikipedia/google collaborators; the CSE-last contract still holds through the wrapper. `WebsiteScraperNameRelevanceGuardTest` needed no changes — the wrapper preserves its exact behavior (transient model → no website/OSM/coords/social → straight to the guarded keyword sources).
- **Gate green:** `composer test` 812 passed; `pint --test` clean; `phpstan` 0 errors; `npm run build` ok.

**Next:** none — every step of the Goal is implemented and verified.

**Gotchas:**
- `gethostbynamel()` on `.example` fails → SSRF guard must be disabled in tests, else `scrapePhotos()` returns null.
- Seed test `test_falls_through_to_guarded_wikimedia_when_no_context` triggers a real (unfaked) Wikipedia request in the fallthrough — same pre-existing pattern as `WebsiteScraperNameRelevanceGuardTest`; takes ~1s. Fine locally/CI (DNS fails fast) but don't add a `preventStrayRequests()` to these specs without faking Wikipedia + Google too.
- The two "no context" seed tests now set `latitude`/`longitude` null so they skip the Wikidata step; otherwise the factory's default SF coords would fire an unfaked `query.wikidata.org` request.
- Wikidata image match reuses the award `nameSimilarity` (similar_text) threshold — coord box is ±0.01° so false positives are unlikely, but same-named chains across ~1.5km will still share a photo.
- `osmContextImage()` is domain-based: an OSM `image=` tag holding an arbitrary non-Wikimedia URL (e.g. the venue's own host, or a Google CDN pasted into OSM) is NOT recognized as verified context — it's nulled out like any decay-prone URL. Safe by design (we'd rather re-source than re-use a URL we can't trust to be stable).
- Gate is green: `composer test` 812 passed; `npm run build` ok; `phpstan` 0 errors; `pint --test` clean.

## Log
