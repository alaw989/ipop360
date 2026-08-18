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
Next: data-hygiene → 37 bad states (already handled by normalizeState — only 1 bad
locally) + prod verify (`--limit=200` bounded); remaining description/price needs are
AI-enrich quota-bound.

## Log
- 2026-08-18: Iter 6 — cache phase also backfills price_range + description (sanitizeDescription min-20-char guard); candidates widened to missing website/phone/price/description; per-field summary lines; 4 tests in BackfillWebsitesCachePhoneTest. Full suite 925 pass, pint + phpstan clean.
- 2026-08-18: Iter 5 — backfill-websites cache phase now backfills missing phone from cached live-search data (free, fill-empty, 10-digit); candidates widened to missing website OR phone (cacheCandidates + normalizeCachePhone); 4 tests in BackfillWebsitesCachePhoneTest. Full suite 921 pass, pint + phpstan clean.
- 2026-08-18: Iter 4 — scrapeMenuData widened to rows missing EITHER menu_url or opening_hours (hours-only rows now revisited); update made truly fill-empty (existing values never clobbered); 2 tests in BackfillWebsitesMenuScrapeTest. Suite 916 pass (SchedulerHealthTest flaky, unrelated), pint + phpstan clean.
- 2026-08-18: Iter 3 — BackfillRestaurantWebsites `scrapeMenuData` phase (menu_url + opening_hours backfill, fill-empty, bounded 200/run, popularity-first, dry-run safe); 4 tests in BackfillWebsitesMenuScrapeTest. Suite 912 pass, pint + phpstan clean.
- 2026-08-18: Iter 2 — CuisineTagMapper + EnrichRestaurantWithAi pivot attach (AI cuisines → seeded slugs, syncWithoutDetaching); 3 tests in EnrichRestaurantWithAiCuisineAttachTest. Full suite + pint + phpstan clean.
- 2026-08-18: DataHygiene proximity merge pass (name+city+state + 0.15km radius, sub-grouped by anchor so chains survive); `state` added to normalized map; summary/log lines + docblocks updated; 4 tests in DataHygieneCommandTest. Full suite 905 pass, pint + phpstan clean. Dry run on local MySQL found 3 proximity pairs.
