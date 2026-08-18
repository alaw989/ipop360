# Iteration Notes

## Goal
restaurant data-gap remediation: map every gap to its owning scheduled command / SearchController and tune to close it — AI-enrich → description/price/phone; website-scrape → hours/menu; context backfill → photo; data-hygiene → state/dupes; enrichment → cuisine tags; prioritize by search impact; extend data-hygiene to the 440 dupe groups + 37 bad states

## State
Iter 1: extended `restaurants:data-hygiene` with a **proximity merge pass** — merges
same normalized name+city+state rows whose coords are within 0.15km (the audit's
"440 dupe name+city+state groups"). Chains never merged; `--limit` budget respected.
4 new tests. PHPUnit 901→905.
Iter 2: closed the **cuisine-tag gap** (68% untagged — highest search impact via
cuisine_match 0.50). New `CuisineTagMapper` service normalizes free-text AI cuisine
names to seeded slugs; `EnrichRestaurantWithAi` now `syncWithoutDetaching`s them onto
the pivot so untagged rows become cuisine-searchable as the every-6h ai-enrich
sweep fills them. Existing tags preserved; unseeded names (e.g. "Pizza") skipped.
3 new tests. TDD: tests first.
Iter 3: gave **menu_url (92% missing, was ownerless) a home** — `restaurants:backfill-websites`
(daily 11:45, the website-scrape owner) now runs a bounded `scrapeMenuData` phase:
active rows with website_url but no menu_url get menu_url + opening_hours scraped
from their own site (fill-empty only, cached 7d/domain, `MENU_SCRAPE_DAILY_LIMIT=200`,
highest popularity_score first, dry-run safe, enrichment-channel log).
4 new tests. TDD: tests first.
Iter 4: closed the **opening_hours trail** (32% gap; scrape only fired when menu_url
was missing, so rows with menu_url but no hours were never revisited). `scrapeMenuData`
now targets rows missing **either** field; the update is genuinely fill-empty (existing
menu_url/opening_hours never clobbered) — the hours-only test caught an overwrite bug.
2 new tests. TDD: tests first. Suite green (916 pass; SchedulerHealthTest is pre-existing flaky).
Iter 5: **phone (46% missing) gets a free cache backfill** — `restaurants:backfill-websites`'s
cache phase now serves rows missing website OR phone, backfilling phone (10-digit,
fill-empty, null-safe) from cached live-search venue data. No AI quota burned; ~850
local rows estimable. New `cacheCandidates()` + `normalizeCachePhone()`, phone summary
line. 4 new tests. TDD: tests first. Full suite 921 pass.
Iter 6: **price (75%) + description (83%) join the free cache backfill** — the same
cache phase now fills missing price_range (parseExtractedPrice) and description
(min-20-char guard) from cached venue data; candidates widened to missing website/
phone/price/description. ~103 price + ~141 description local rows estimable, keeping
AI quota for rows the cache can't help. New `sanitizeDescription()`, per-field summary
lines. 4 new tests. TDD: tests first. Full suite 925 pass.
Iter 7: **address (27.4% missing, was ownerless) joins the free cache backfill** —
the cache phase now fills missing address (sanitizeAddress: min-8-char + contains a
space guard) from cached venue data. Cache index no longer skips website-less venues
(entryHasNothingUseful gate) so an address-only cached venue can fill the gap too.
~661 local rows estimable by name match. New per-field summary line. 4 new tests.
TDD: tests first. Full suite 926 pass, pint + phpstan clean.
Iter 8: **opening_hours (54% missing, was ownerless) joins the free cache backfill** —
the cache phase now fills missing opening_hours from cached venues via
`normalizeCacheHours`: accepts only raw OSM-style hours strings carrying a HH:MM
time marker and wraps them into the app's `{structured:false, raw_text}` shape
(matching LiveVenuePersister::normalizeOpeningHours / the OpeningHours component);
junk ("closed") and structured per-day maps rejected. ~891 local rows estimable.
Candidates widened to missing opening_hours (incl. '[]'), new summary line.
4 new tests. TDD: tests first. Full suite 930 pass, pint + phpstan clean.
Iter 9: **photo (39% missing, biggest remaining) gets a free cache backfill** — the
cache phase now also fills missing photo_url from SerpApi venue `thumbnail` values
(sanitizeCachePhoto: http(s) only, junk rejected) via the existing name/phone index.
Google CDN thumbnails decay, but the weekly photo-verify sweep (gps-cs-s first)
re-sources or clears them. Candidates widened to missing photo_url; new photo
summary line + log field. 4 new tests in BackfillWebsitesCachePhoneTest.
TDD: tests first. Full suite 934 pass, pint + phpstan clean.
Iter 10: **ai-enrich dispatches by search impact among equally-needy rows** —
description (96%) and price (94%) are the last big gaps, served only by the
quota-bound AI-enrich sweep; it already dispatched neediest-first (most missing
fields) but broke ties by arbitrary DB order. `restaurants:ai-enrich` now sorts by
missingFieldCount DESC then popularity_score DESC, so the scarce Groq quota is
spent on the most-visible rows first. 1 new test in AiEnrichRestaurantsTest.
TDD: tests first. Full suite 935 pass, pint + phpstan clean.
Iter 11: **city (audit 262 / local 605 missing, was ownerless) joins the data-hygiene
pass** — `normalizeCity()` derives a missing city from the restaurant's own address
(`deriveCityFromAddress`: US "ST ZIP" tail, OSM numeric-zip tail, and OSM street+number
shapes; `plausibleCity` rejects digit-led/junk tokens) then title-cases it. Existing
cities never overwritten; address-only gap rows become city-scoped searchable. 475/509
local missing-city addresses yield a city. 6 new tests in DataHygieneCommandTest.
TDD: tests first. Full suite 941 pass, pint + phpstan clean.
Iter 12: **state joins the address-derivation gap fill** — `normalizeState()` now
accepts the address and, when state is null/blank, derives it from a US "ST ZIP" tail
(`deriveStateFromAddress`: e.g. "…, Phoenix, AZ 85004" → AZ; OSM numeric-zip tails and
foreign postal codes rejected). Existing state never overwritten; pairs with iter 11's
city derivation so the same address-only rows become state-scoped too. Real MySQL DB
is nearly clean (only "Texas" full name remains — normalizeState already maps it).
4 new tests in DataHygieneCommandTest. TDD: tests first. Full suite 945 pass, pint + phpstan clean.
Iter 13: **google_rating (95% missing, quality signal weight 0.35) joins the free cache
backfill** — the cache phase now also fills missing google_rating + google_review_count
from cached SerpApi venue `rating`/`reviews` (sanitizeCacheRating: numeric 0-5 rounded
to 1dp; review count only alongside a usable rating). Candidates widened to missing
rating (incl. <=0); new rating summary line + log fields. ~128 local rows name-matchable
(phone match more). 4 new tests. TDD: tests first. Full suite 949 pass, pint + phpstan clean.
Iter 14: **description (96.5% missing, biggest remaining gap) gets a second free source:
the venue's own website** — `RestaurantWebsiteScraperService::scrape()` now extracts a
blurb from og:description / twitter:description / name=description meta tags
(min-20-char guard; too-short blurb alone → null result, so no junk persists). The
`scrapeMenuData` phase (daily 11:45 website-scrape owner) persists it fill-empty, and
its candidate query now targets rows missing description too — so every site it already
fetches can close the description gap without burning AI quota. 3 scraper + 2 backfill
tests. TDD: tests first. Full suite 954 pass, pint + phpstan clean.
Iter 15: **SerpApi hours backfill was dead; now live + the cache phase stops crashing.**
Two fixes in the daily 11:45 `restaurants:backfill-websites` cache phase:
(1) `normalizeCacheHours` read `$venue['opening_hours']`, which exists in **0** SerpApi
cache rows — hours actually live in `operating_hours` (per-day map) + `hours` (status
string). It now also converts a SerpApi `operating_hours` map into the app's
`{structured:true, hours:[{day,open,close}]}` shape (normalizeCacheHoursMap/
normalizeCacheDayName/parseCacheHoursRange; closed / multi-range / digit-less junk days
skipped, nothing persisted when no usable day — matches the website scraper's structured
shape the OpeningHours component renders). ~43 local rows name/phone-matchable today.
(2) the cache phase crashed on real data: `preview` rows cache ONE restaurant object per
row, not a venue list, so `foreach ($venues as $venue)` iterated scalar fields into
parseExtractedPrice and aborted the whole command (also poisoned the other 5 backfills).
Non-list payloads now normalize to a single-venue list. 4 new tests. TDD: tests first.
Full suite 958 pass, pint + phpstan clean; live dry-run: cache matched 5626, hours 582,
no errors.
Iter 16: **untagged rows (5100, cuisine_match 0.50) get free cuisine tags from the
serpapi cache** — the cache phase now reads each cached venue's Google `type`/`types`
("Chinese restaurant", "Thai restaurant") via `cacheCuisineIds`: strips trailing
non-cuisine qualifiers (`stripCuisineQualifier`, 29-word list) then maps the core name
through `CuisineTagMapper` to seeded cuisines — the same conservative normalization the
AI job uses, so "Pizza restaurant"/"Bar"/"Caterer" never create phantom tags. Attach is
fill-empty only: restaurants that already carry evidence-backed tags are never touched
(the audit sweep owns tag hygiene). Candidates widened with `orWhereDoesntHave('cuisines')`
so fully-populated-but-untagged rows are visited; `entryHasNothingUseful` includes
`_cuisine_ids` so a types-only venue isn't dropped from the index. 5 new tests in
BackfillWebsitesCachePhoneTest (name match, singular type string, already-tagged rows
untouched, unmappable types ignored, phone match). TDD: tests first. Full suite 963 pass,
pint + phpstan clean; live dry-run: cache matched 5853, **cuisine tags 230**, no errors.
Iter 17: **description (96.5%, biggest gap) gets a third free source inside the same
website scrape** — `extractDescription` now falls back to the page's JSON-LD
`description` (`extractJsonLdDescription`: first application/ld+json object/array
carrying a string ≥20 chars; non-string/short skipped) when no og:/twitter:/name=meta
blurb qualifies. The daily 11:45 `scrapeMenuData` phase already persists `description`
fill-empty, so every site it already fetches can now close the gap from its structured
data too — no extra requests, no AI quota. Meta sources stay preferred. 4 new tests in
RestaurantWebsiteScraperServiceTest (single JSON-LD, array, short-rejected, meta-wins).
TDD: tests first. Full suite 967 pass, pint + phpstan clean.
Iter 18: **price_range (94%, second-biggest gap) joins the same free website scrape** —
`scrape()` now also extracts JSON-LD `priceRange` (`extractPriceRange` /
`normalizePriceRange`: dollar-sign form "$$" kept as-is; a numeric range uses the
average of its first two numbers, a single value itself — both mapped to the corpus's
$-$$$$ thresholds matching the cache phase's parseExtractedPrice; junk like "Price on
request" rejected). `scrapeMenuData` persists it fill-empty (never clobbers) and its
candidate query + log line now include price_range. Same fetch, no quota. 3 scraper +
2 backfill tests. TDD: tests first. Full suite 972 pass, pint + phpstan clean.
Iter 19: **photo backfill spends its daily 200-budget on the most search-visible rows**
— `restaurants:backfill-photos` (the photo-gap owner) now orders candidates
`orderByDesc('popularity_score')` after the existing website-first reliability sort
(website owners still processed first, then highest popularity before id), so the
scarce daily limit hits the most-visible restaurants first — the same search-impact
priority ai-enrich and scrapeMenuData already use. Photo gap was the largest still-open
free gap (~1802 local rows). 1 new test in BackfillRestaurantPhotosTest. TDD: tests
first. Full suite 973 pass, pint + phpstan clean.
Iter 20: **website-scrape cache is versioned so new extraction fields reach cached
sites** — `RestaurantWebsiteScraperService::scrape` cached results under an
unversioned key (`website_scrape:<md5>`), so any site scraped within the 7-day TTL
kept returning a stale blob from BEFORE iterations 14/17/18 added description and
price_range extraction. The daily 200-row scrapeMenuData budget was being spent on
rows that could never gain the new fields. Key is now `website_scrape:v2:<md5>`
(bump the suffix on every extraction-logic change) — the first run after deploy
re-scrapes those sites and captures description/price/hours/menu for free. 1 new
test proving a seeded legacy-key blob is ignored (page re-fetched). TDD: tests first.
Full suite 974 pass, pint + phpstan clean.
Next: description (82%) and price (70%) local gaps now flow via cache + website scrape
(meta + JSON-LD, cache-version fixed) + quota AI-enrich; photo (28%) is popularity-
ordered. menu_url (91%) remains the largest raw gap, served only by the 200/day
scrapeMenuData — consider raising MENU_SCRAPE_DAILY_LIMIT once the queue backlog is
comfortable. Prod verify: bump lands, run `backfill-websites --dry-run` on droplet and
confirm cache hits re-scrape (was scraping cached rows for nothing).

## Log
- 2026-08-18: Iter 20 — website-scrape cache key versioned (`website_scrape:<md5>` → `website_scrape:v2:<md5>`); the old unversioned 7-day TTL served stale blobs from before iters 14/17/18's description/price extraction, wasting the daily 200-row scrape budget on rows that could never gain the new fields. 1 test (legacy blob ignored, page re-fetched). Full suite 974 pass, pint + phpstan clean.
- 2026-08-18: Iter 19 — backfill-photos orders candidates orderByDesc(popularity_score) after the website-first sort, so the daily 200-budget hits the most search-visible rows first (same priority as ai-enrich + scrapeMenuData). 1 test in BackfillRestaurantPhotosTest. Full suite 973 pass, pint + phpstan clean.
- 2026-08-18: Iter 18 — scrape() extracts JSON-LD priceRange via extractPriceRange/normalizePriceRange (dollar-sign form kept; numeric ranges averaged then mapped to $-$$$$ thresholds like parseExtractedPrice; junk rejected); scrapeMenuData persists it fill-empty + candidates/log include price_range. 3 scraper + 2 backfill tests. Full suite 972 pass, pint + phpstan clean.
- 2026-08-18: Iter 17 — extractDescription now falls back to JSON-LD description (first application/ld+json object/array with a string ≥20 chars; junk skipped) when no meta blurb qualifies; scrapeMenuData persists it fill-empty as before. Meta sources stay preferred. 4 tests in RestaurantWebsiteScraperServiceTest. Full suite 967 pass, pint + phpstan clean.
- 2026-08-18: Iter 16 — cache phase backfills cuisine tags from cached serpapi Google type/types via cacheCuisineIds + stripCuisineQualifier (29 qualifiers) + CuisineTagMapper (same normalization as AI job); fill-empty only (tagged rows untouched), candidates widened to untagged, types-only venues kept in index. 5 tests in BackfillWebsitesCachePhoneTest. Full suite 963 pass, pint + phpstan clean; live dry-run cuisine tags 230.
- 2026-08-18: Iter 15 — SerpApi hours backfill was dead (read `opening_hours`, present in 0 SerpApi rows): normalizeCacheHours now converts the `operating_hours` per-day map to the app's structured shape (closed/multi-range/junk days skipped); cache phase also stops crashing on preview's single-object rows (non-list → single-venue list) which had aborted every cache backfill on real data. 4 tests. Full suite 958 pass, pint + phpstan clean; live dry-run clean.
- 2026-08-18: Iter 14 — scrape() now extracts description from og:description/twitter:description/meta name=description (min-20-char guard; too-short-only → null result); scrapeMenuData persists it fill-empty + candidate query widened to missing description; 3 scraper + 2 backfill tests. Full suite 954 pass, pint + phpstan clean.
- 2026-08-18: Iter 13 — cache phase also backfills google_rating + google_review_count from cached SerpApi venue rating/reviews (sanitizeCacheRating 0-5; review count only with a usable rating); candidates widened to missing rating; rating summary line + log fields; 4 tests in BackfillWebsitesCachePhoneTest. Full suite 949 pass, pint + phpstan clean.
