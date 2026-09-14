# iPop360 — Current Project State

> Living snapshot for Claude (and humans) picking up this project. Read this
> together with `constitution.md` and `backlog.md` at session start. Detailed
> per-spec history lives in `history.md` (one-line-per-spec log) and
> `history/` (deep-dive writeups). Updated: 2026-09-02.
>
> **This file was trimmed 2026-08-22** — it had grown to 564 lines of
> spec-by-spec narrative (specs 001–103) that duplicated `history.md`. The
> removed content is archived verbatim at
> `history/2026-08-22--project-state-pre-trim-archive.md`. Keep this file to
> *current/operational* state only — anything spec-shipment-shaped belongs in
> `history.md`, not here.

## Latest (2026-08-15) — photo pipeline, data hygiene, distance miles, skill conversion, local-first protocol

**Session wrap-up:** see `history/2026-08-15--photo-pipeline-skill-protocol.md`.

**Highlights (all live-verified):**
- **Data-hygiene loop** (#107), **distance in miles** (#108), **photo
  verification** (#109), **name-relevance guard** (#110), **context-first image
  search** (#111) — merged + deployed.
- Scheduler verified firing on time; **SerpApi quota exhausted** (free sources
  carry on). 06:30 fill: 153 photos, 445 verify re-sourced (431 authoritative).
- **Local-first protocol** created: `~/.config/opencode/protocol-default.md`
  (no commit/PR/deploy until operator approves locally) — wired into
  `opencode.jsonc`, needs restart.
- **enrichment-logs skill → `.opencode/skills/enrichment-logs/SKILL.md`** (native
  project-skill path, no symlinks), hardened via loop, needs restart to register.
- **QUEUED backlog goals (not started as of 2026-08-15):** ingestion-time enrichment (top),
  photo-verify hardening, scheduler audit, data-gap remediation, prod-DB
  pull-down. Worktree had uncommitted `.opencode/skills/`.

**Current floor (as of 2026-08-15):** 812 PHPUnit + 1056 vitest; PHPStan level 8 zero baseline;
pint clean; CI enforces coverage + PHP 8.4; CI + deploy green.

## 2026-08-24 — spec-106 + spec-107 (SerpApi quota accounting + live account-status sync)

User-reported live bug (not a backlog goal): admin dashboard showed a
provider-confirmed "SerpApi exhausted" badge alongside a usage box claiming
58% used, 105 remaining. spec-106 root cause + fix on branch
`feat/serpapi-call-log-quota-fix` (off `master`, not `feat/venue-shape-merge-fold`
which is unrelated spec-105 work): see `specs/106-…md` and the `history.md`
entry. New `SerpApiCallLog` model is now the source of truth for SerpApi
quota decisions (circuit breaker, enrichment budget, dashboard, uptime
canary, quota:status) — `ExternalApiCache::stats()['serpapi_calls_last_30d']`
is a cache-inventory stat only, do not use it for quota logic again.

Follow-up spec-107 (same session, branch `feat/serpapi-account-status-sync`):
right after spec-106 deployed, a direct check against SerpApi's own
`/account.json` (zero quota cost, verified live) showed the account truly
exhausted while the fresh dashboard read 0/250 clean — because the app's
counters are self-reported from its own call history, with no history yet.
Added a `serpapi:sync-account-status` scheduled command (every 15 min,
zero quota) that pulls the provider-confirmed snapshot and reconciles the
local exhausted flag against it — see `specs/107-…md`. **Both 106 and 107
merged to master (`3471d74`, `3367a86`), GHA-green, LIVE-VERIFIED**: the
dashboard now shows the real account state (`0/250 left, "Your account has
run out of searches.", renews 2026-09-19`) with every signal (exhausted
banner, Usage, Circuit Breaker, Enrich Budget, Live Read Path) agreeing —
the original contradiction cannot recur.

**Follow-up hardening same day (PR #135, `87d101a`):** discovered live that
`deploy.yml`'s "Migrate + build caches" step runs `artisan cache:clear` on
EVERY deploy, wiping the entire database cache store — including spec-107's
snapshot and the `serpapi_provider_exhausted` flag. Not a correctness bug
(fails safe) but the dashboard went dark until the next 15-min scheduler
tick on every deploy. Fixed by running `serpapi:sync-account-status`
immediately after `cache:clear` in the same step — live-verified: the next
deploy showed fresh data within ~1 minute, no dark window.

Also flagged separately: the SerpApi key is committed in plaintext at
`SHARED_TASK_NOTES.md:52` and matches the currently-active `.env` key.
Operator directive: not an action item — do not re-raise rotating it.

## 2026-08-24 — directions/call click scoring + verified social links

User-requested engagement-ranking audit found the requested tracking (page
views, link clicks, social platform counts feeding rank) was **already
built and live** — see `docs/ranking-metrics.md`. Two real gaps closed:

- `directions_clicks_count`/`call_clicks_count` were tracked live and
  aggregated nightly alongside the other 5 engagement counters, but never
  added to `PopularityScoreService`'s weight table — added at 0.05 each.
  New `EngagementActionSyncTest` guards the live-increment vs. nightly-resync
  write paths from drifting apart.
- `social_links_count` counted any platform URL regex-extracted from a
  restaurant's own website with **no reachability check**. Added
  `RestaurantWebsiteScraperService::verifyProfileUrl()` (SSRF-guarded HEAD/
  ranged-GET) + `restaurant_social_links.verified_at`/`last_check_failed_at`;
  `social_links_count` now counts verified-only links via new
  `Restaurant::countScoredSocialLinks()`, gated by kill-switch
  `RANK_REQUIRE_VERIFIED_SOCIAL` (default true). Failed checks keep the row
  (recall-protective). New weekly `restaurants:reverify-social-links` job
  decays link rot out of scoring.

**Merged + deployed** (PR #136 `f3205b8`, hotfix PR #137 `b7a0dad` for a
flaky redirect test — `verifyProfileUrl`'s test auto-followed a 301 via
Guzzle to an unfaked URL, causing a real outbound network call in CI that
passed on the PR check and flaked on the push-triggered deploy run; fixed by
faking the redirect target too). GHA green, deploy green, live-verified via
API (200 + real results). 1043 backend tests (was 1030).

**Follow-up DONE (2026-09-01):** re-ran `ranking:audit` (persisted + `--recompute`)
against prod over SSH — see `docs/ranking-audit-2026-08.md` §9. Cohort overlap
holds at 0% (matches the §8 log-floor fix, corpus 40,483→40,814), verified-only
`social_links_count` didn't reintroduce the old 30.7% overlap.
`directions_clicks_count`/`call_clicks_count` are wired and firing but
negligible (3/40,814 rows each, 0.0%). One apparent anomaly (91 rated rows at
the persisted-score floor) traced to score:run staleness, not a bug — confirmed
via `score_breakdown` (no `quality` entry at scoring time), self-heals next run.

## 2026-09-02 — specs 093, 095–100 merged and shipped (PRs #160–166)

The remainder of the 2026-06-30 audit wave (101–103 still queued) — each had
already been built on its own `opencode-loop` branch off the 2026-09-01
master point; this session's work was merging all 7 (plus one unrelated docs
PR, #156) into master one at a time in spec-number order, with CI + a deploy
+ a live-verify pass between each merge, per the binding process below.

Full per-spec summary in `history.md`'s 2026-09-02 entry and
`.specify/memory/backlog.md`'s "✅ Done (2026-09-02)" section (includes the
merge-conflict details — a shared `ITERATION_NOTES.md` conflict on 5 of the
6 independent branches, and a real code conflict on spec-100 where its
extracted `LiveSearchSnapshotService` was missing spec-095's `DB::transaction`
batching fix that landed on master after 100's branch was cut; ported the fix
into the extraction during the merge). End state: 1129 PHPUnit (4856
assertions), 1079 vitest, PHPStan 0, Pint clean, master at `2b1f28e`, zero
open PRs, all deploys green and live-verified (including a headless-browser
repro of the spec-099 fast-back race and a droplet log check post-deploy for
spec-100, the highest-risk item).

## In-flight work (check before starting anything new)

**Data-integrity + ranking overhaul (started 2026-09-10).** Built directly by
Claude: an operator decision that overrides the opencode-loop rule below for
this judgment-heavy work. The local-first gate still applies: no push, PR,
deploy, or prod-data change without an explicit OK. Four stacked phases, one
PR each:

1. **`feat/data-integrity-verify` — stop writing junk.**
   - `WebsiteIdentityVerifier` gates every searched, guessed, or AI-suggested
     website.
   - The cache backfill matches by location; it used to match by name alone
     across cities.
   - `SocialProfileUrl` + `SocialLinkRecorder`: no pixel or namespace URIs, and
     corporate accounts are brand-scoped (not scored).
   - The AI never overwrites addresses and never writes phone or price.
   - A `field_quarantine` table + `FieldQuarantineService` make every removal
     reversible.
2. **Reversible bulk cleanup** of existing prod junk (`restaurants:integrity`).
3. **Overture Maps monthly import** (free; attribution required).
4. **Evidence-based ranking** for the 88% unrated, plus SerpApi yield targeting.

**The prod baseline that motivated it (2026-09-10, read-only):**
- Websites on 96% of rows; 2,443 of them are dictionary, encyclopedia, or IMDb
  pages.
- 1,920 of 4,892 rated rows share an exact rating + review count with a
  restaurant in another city (from the name-only cache matching).
- 13,180 restaurants (32%) carry shared or junk social URLs.
- 3,663 addresses were rewritten by the AI.

A local prod clone lives in MariaDB `ipop360_prodclone`; run commands against
it with `php artisan --env=prodclone` (see AGENTS.md). Specs 102–103 stay
queued behind this work (see `backlog.md`).

**Status (2026-09-10): phases 1–2 SHIPPED; phases 3–4 in progress.**

- **Merged and verified:** PR #169 (phase 1) and PR #170 (phase 2) merged,
  deployed, and browser-verified live.
- **Prod cleanup applied** after a full DB backup
  (`/root/ipop360-backups/ipop360-pre-integrity-20260910T191913Z.sql.gz` on
  the droplet; the apply log is alongside it).

| What was cleaned | Count |
|---|---|
| Websites (+ photos/socials scraped from them; 245 were the venue's own social profiles → moved to social links) | 4,014 |
| Junk social links (+ 3,935 canonicalized) | 5,420 |
| Corporate accounts re-scoped `brand` | 1,181 shared URLs |
| Copied ratings (owner kept in 181 clusters) | 835 |
| Copied phones | 1,283 |
| Wrong-state addresses | 424 |
| AI guesses | 3,139 |

- **Totals:** 20,705 values in `field_quarantine`. Undo any detector with
  `php artisan restaurants:integrity --restore=<reason>`, then
  `restaurants:score`.
- **After the rescore:** the scorecard reads 0 on all 7 detectors. Rated
  restaurants: 4,058 (9.9%). `social_links_count` is active on 20.1% (was
  52%).
- **Follow-up PR:** `restaurants:verify-websites` runs daily at 2000/run
  (16:00 UTC), and a weekly report-only `restaurants:integrity` runs Mondays
  11:15.
- **Local `.env` note:** it has stale `LIVE_SEARCH_MAX_RESULTS=30` and
  `RANK_WEIGHT_*` values that skew `ranking:audit` and fail two config-default
  tests. Use the prod clone env for audits. To run the tests like CI, create an
  empty `.env.testing`: with APP_ENV=testing, Laravel then loads it instead of
  `.env`. Run `vendor/bin/phpunit` directly, not `php artisan test`, which
  boots with `.env` first and leaks its values into the child process.

**Status (2026-09-11): phases 3–4 SHIPPED, plus fixes #175–#178 and #181.**
- **Phase 3:** Overture import #172–#174, first applied on prod 09-11.
  Scheduled on the 25th at 20:00 UTC.
- **#175:** enrichment's `processFreeVenue` and `LiveVenuePersister` were
  nulling stored fields and reopening closures on every source match. The
  shared `RestaurantFieldMerger` now decides what a source record may change.
  Prod repaired (see `history.md`).
- **#176:** Overture-filled websites are queued first for identity checks.
- **#177:** live search drops rows matching a nearby closed restaurant instead
  of re-creating it.
- **#178:** a website whose domain no longer exists is rejected as
  `dead_domain`; `restaurants:verify-websites --unreachable` re-checks rows
  with a check date but no verdict.
- **#181:** an address whose ZIP is far from the pin (`App\Support\ZipLocation`,
  Census ZCTA centroids) is replaced by `overture:import` with the matched
  place's address, or removed by `restaurants:integrity
  address_far_from_location` when there's no Overture match. No write path
  fills a far ZIP.
- **Backups on the droplet** (`/root/ipop360-backups/`): pre-integrity,
  pre-overture, pre-restore, pre-dead-domain, pre-address-fix.
- **Phase 4** (#179, #180): the evidence signal, "Not yet rated" on result
  cards, and per-call SerpApi yield logging (`serpapi_call_log` yield columns,
  shown in `quota:status`). Calibration and the evidence-cap choice are in
  `docs/ranking-metrics.md`.
- **Prod env check (2026-09-12):** `ENRICH_MONTHLY_BUDGET` is 150 on prod, not
  250, and the budget counts every SerpApi call, so it can't overspend. The AI
  fallback is dead: the droplet `.env` pins the retired Azure URL and the
  deploy injects a GitHub token as `AI_FALLBACK_KEY` (see `backlog.md`).

**Status (2026-09-12): AI enrichment flood fixed (#183).** `restaurants:ai-enrich`
had been queueing ~40k jobs every 6 hours, ~163k failed calls a day against
Groq's free tier (~350 answers a day). Providers now cool down after a
failure, and each run queues at most `AI_ENRICH_PER_RUN` (75) jobs spread
over 6 hours, never-tried rows first, with a 30-day retry
(`AI_ENRICH_RETRY_DAYS`). See `history.md`.

**Status (2026-09-12): wrong cities (#184).** `restaurants:integrity
city_far_from_location` corrects a city or state that isn't where the pin is:
the state from a ZIP at the pin (`ZipLocation::state()`), the city from the
row's own address, checked against Census places (`PlaceLocation`). Enrichment
no longer stores the search grid's name on venues whose address names their
town. Corrections are restorable (`--restore=city_far_from_location`,
`--restore=city_grid_label`). Applied on prod 2026-09-12 20:04 UTC (backup
`ipop360-pre-city-fix-20260912T200403Z.sql.gz`), browser-verified. `pint.json`
now keeps Pint off the generated `database/data` files (#186): uncached, they
pushed the deploy's quality job past its timeout.

**Status (2026-09-12): wrap-up (#188).** ZIP centroid overrides for four
detached Census points (`database/data/zip_centroid_overrides.php`, e.g.
Anchorage 99503), `postal_far_from_location`, formatted phones, full score
breakdown labels, and the AI fallback is off (no default provider; Cerebras
isn't free, so never a default). Remaining time-gated items: revisit
`RANK_EVIDENCE_CAP` once the ~35k never-checked websites clear; the Overture
monthly run on the 25th (no deploys during it).


**In flight (2026-09-13): Yelp-like redesign, handed to opencode.** The client
wants the site to "look very similar to yelp.com". Audit, decisions and the
seven-PR plan: `docs/design-audit-2026-09.md`. Decided: Poppins 600/700 +
Source Sans 3, primary = the logo's red-orange (#C2401C, not Yelp's red),
featured restaurant picked by an admin with a top-ranked fallback,
multi-select price filter, price shown as four signs with the level dark.
Built directly by Claude with the user's blanket approval for this job (merge
after green CI, deploy, verify live); on 2026-09-13 the user asked to hand
the rest to opencode.

- **Shipped and verified live:** #190 (website scrape reaches every site, no
  website prices), #191 (fonts, colors, `PriceLevel`), #192 (search on every
  page, faster hero), #193 (search results, multi-price), #194 (featured
  restaurant + admin picker), #195 (restaurant page; hours for every stored
  format via `App\Support\OpeningHoursDisplay`; the page had never received
  hours/menu/social/ZIP because `RestaurantResource` keyed them on a route
  name the route doesn't have). Prod `restaurants:score` rerun after #195.
- **Prod data (done):** blog post "Moose's Tooth: The Anchorage Pizza Pub With
  12,000 Reviews" (id 6) and the first featured pick (Moose's Tooth, id 8629,
  credited Commons photo). It's data, not code: change it from `/admin`.
- **PR 7 (redesign 7/7) is merged + deployed** — #196 (`1ea54e2`) fixed the
  Leaflet `_leaflet_pos` unmount crash and the map-pin WCAG 2.2 `target-size`
  regression; #197 (`632084b`) fixed the scroll-restore bug live-verify found
  (`Search.vue` showed its skeleton on every visit, so leaving for a restaurant
  reflowed the outgoing page and Chrome's scroll anchoring moved the saved
  position 900 → 1886). Skeleton now shows only for visits that stay on
  `/search`. Both live-verified.
- **Shipped — backlog goal 17 (redesign 8), PR #198 (`23e430e`), merged +
  deployed + live-verified:** card-sized WebP thumbnails for photos from hosts
  that don't resize. `restaurants:photo-thumbnails` + `/thumbs/{file}` route +
  `photo_thumb` column; `deploy.yml` excludes `storage/app/private/thumbs/`.
  Prod `--apply --limit=200` generated 200; `imgweight.mjs` on
  `/search?city=Austin&state=TX` dropped from **17.6 MB to 325 KB (390px) /
  366 KB (1440px)**, under the ~1.5 MB goal. See backlog goal 17 for the
  storage/deploy deviations and the skipped-vs-failed counting follow-up.
- **Shipped — backlog goal 18 (redesign 9), PR #201 (`d00226a`), merged +
  deployed + live-verified:** platform logo sprites (Instagram/Facebook
  `rsrc.php`) rejected at every photo write path via new
  `App\Support\PhotoUrl::isPlatformAsset()`, plus `restaurants:photo-junk`
  (report-first, reversible). Prod: backup taken, report flagged 3,850 rows,
  `--apply` left 0 sprites and 3,906 quarantine entries; site + API 200.
  See backlog goal 18.
- **Verified — backlog goal 20:** first daily `restaurants:backfill-websites`
  run of the new code set `website_scraped_at` on 2,000 rows, no site fetched
  twice (duplicate domains cache-served), and 0 website-sourced prices. See
  backlog goal 20; note the 87-min runtime vs `withoutOverlapping(240)`.
- **Report delivered — backlog goal 19:** `restaurants:wikimedia-photo-audit`
  (report-only; `--apply` at #205; batched/30-day-cached lookups at #206/#207)
  classifies Wikimedia photos as Commons-geotag / Wikidata-P18 verified or
  name-only. Full prod report: **5,205 audited → 2,948 unverified**, 19
  Commons-verified, 4 Wikidata-verified, 2,234 uncheckable (Commons 429).
  Samples are clear junk (recipes/history PDFs, a person named Ela, a logo
  SVG). Bounded `--apply` quarantined 19 reversibly. **Full bulk `--apply` run
  (operator-approved): 2,351 unverified photos quarantined, 1,278 galleries
  stripped — 2,370 photo_url + 1,295 gallery entries total; the 2,838
  rate-limited `uncheckable` rows were left untouched.** Reversible via
  `restaurants:integrity --restore=wikimedia_name_only_match`. A second pass
  after the 429 cleared added **657 more (3,027 photo_url + 1,671 gallery
  entries total)**; further passes ran to completion — final **5,232
  `photo_url` + 3,222 gallery entries quarantined, 30 verified photos left**.
  Batched-lookup robustness: transient errors retry per-batch, 429 backs off
  and retries (#211/#212).

  Hand-built with TDD (the `opencode-loop` harness is no longer on this
  machine), each on its own branch, full gate after each, no push until the
  operator says so.

  Browser checks for all of these: `scripts/ui-checks/`.

## Binding process rules (opencode-loop workflow)

- **Backlog goals are ALWAYS executed via `opencode-loop`, never implemented
  directly** (binding, 2026-08-11) — one `feat/<goal-slug>` branch, one commit
  per iteration, legacy single-branch mode (no per-iteration PRs).
- **Local-first, operator-gated deploy** (binding, 2026-08-12) — looping runs
  happen locally; goal branches stack on each other; harden after every goal
  (pint → composer test → npm run test → phpstan → npm run build → coverage)
  before stacking the next. No push/PR/deploy until the operator says so.
  Shipping is one major feature per PR — create ONE PR, stop to notify, merge
  in stacked order, then deploy + live-verify. Backlog ✅ marks happen at merge
  time, never during local looping.

Full loop recipe (model choice, monitoring, gates) lives in
`.specify/memory/backlog.md` — that file is the current work queue and
supersedes the old `specs/` queue (specs 001–103 are all shipped; see
`history.md`).

## What this is

A restaurant-discovery app that ranks venues with a free-first scoring blend.
**Live site:** https://ipop360.com. Stack: Laravel 13 / PHP 8.4,
SQLite (dev/test) / MySQL (prod), Inertia.js + Vue 3, Tailwind, shadcn-vue.
Full principles + process in `constitution.md`.

## Current state summary

Specs 001–103 — backend/live-search correctness, the Airbnb-style results
redesign, SEO/JSON-LD/SSR, security + quota hardening, ranking-correctness,
first frontend tests (Vitest), and deploy-safety — are **ALL COMPLETE**. Live
search works and is SerpApi-rated (any city returns real, quality-ranked
restaurants via the Bayesian `quality` signal). The DB is intentionally
near-empty (live-search-first architecture) though some cities (Austin, NYC)
have accumulated persisted/enriched rows over time. Full spec-by-spec detail:
`history.md` + `history/*.md`.

Since specs 001–103, work moved to the `opencode-loop`-driven backlog (see
`backlog.md`) — blog features, admin roles, photo pipeline, data hygiene, and
now bundle-size/CWV work (see "In-flight work" above).

## The binding constraint: SerpApi's ~250/mo quota

Restaurant **ratings are a proprietary walled garden** (Google + Yelp/
Foursquare) — there is no free, legal, at-scale source. The ONLY free quality
source is SerpApi's `google_maps` engine, gated by `SERPAPI_API_KEY`. Quota
was long mis-assumed at 50/mo; corrected to **250/mo** via a dashboard email
(2026-06-30) — see `memory/serpapi-quota-is-250-not-50.md`. As of 2026-08-15
the quota has been observed exhausted mid-cycle; free sources (OSM/BizData/
Socrata/Wikidata) carry search recall on their own when it is.

Architecture chosen around this (respect these decisions):
- **Demand-driven live search + ~30-day `ExternalApiCache`** — 1 call per unique
  city/query per 30 days, repeats free. Universal (works for ANY searched city).
- **Writing to the DB on the read path: REJECTED.**
- **Pre-enriching a fixed city list: REJECTED** (must work for any searched city).
- Read-path has a circuit breaker (cache-only above 0.8·quota) + per-IP hourly
  limiter (spec-073).

**Ruled-out dead ends (don't re-propose without new info):** scraping Google/
Yelp/TripAdvisor directly (ToS + paid proxies cost more than SerpApi), AI-
aggregated ratings from search engines (LLMs hallucinate numbers), Foursquare/
Google Places ratings as a free alternative — both are metered/premium, see
`memory/paid-ratings-no-free-lunch.md` (spec-066 was shipped then reverted for
exactly this reason).

## Deploy / infra gotchas

- **All work goes through PRs** — never push directly to master. Open a PR →
  quality checks run (tests, Pint, PHPStan) → review → merge → deploy.
- Deploy: `.github/workflows/deploy.yml` on push to master. CI runs
  `migrate --force` (one-time data migrations auto-apply) + `config:cache` +
  php8.4-fpm reload. Deploy "Verify deployment" asserts HTTP 200 + a minimum
  live-result count (`DEPLOY_VERIFY_MIN_RESULTS`, default 5) — a
  `{"data":[]}` deploy now fails the gate (spec-086).
- **`.env` is deploy-excluded**: the droplet keeps its own `.env`. API keys
  reach prod via GitHub **secrets** + a deploy injection step. **Local `.env`
  changes do NOT reach prod.**
- **Cannot SSH to the droplet from a checkout** — droplet creds are
  write-only GitHub secrets. For prod DB changes, use a one-time migration
  (runs via deploy). See `AGENTS.md` for the PDO-export DB-pull-down recipe if
  you need a local copy of prod data.
- `config:clear` / `config:cache` is mandatory after weight/TTL config changes
  (the deploy already runs `config:cache`).
- **Monitoring a deploy** (~4–6 min): `gh run watch` if `gh` is authed;
  otherwise poll the unauthenticated
  `https://api.github.com/repos/alaw989/ipop360/actions/runs?head_sha=<sha>&event=push`.
  The workflow's own verify step is a real cache-cold live search — a green
  gate means the live search returns within nginx's 60s limit. Verify
  behaviorally after deploy in the browser per `CLAUDE.md`'s binding rule, not
  just via the API.

## Key tools

- `php artisan search:audit <city> [<city>...] [--limit=N] [--cuisine=slug]
  [--lat= --lng=]` — verify live ranking quality across cities; respects the
  cache (no quota burn on repeat). Aliases: nyc, sf, la, vegas, philly.
- Live API: `https://ipop360.com/api/restaurants?lat=..&lng=..`
  (`is_live: true` = served from live search; false/null = DB-served).
- Scorer: `app/Services/PopularityScoreService.php` (Bayesian `quality` +
  `cuisine_match` + proximity + completeness + awards).
- Retriever: `app/Services/LiveSearchService.php`; shared dedup/merge/sort
  primitives in `app/Services/VenuePipeline.php`.
- Cuisine matching: `app/Services/CuisineMatcher.php` (+ `CuisineScope`) — the
  single accessor for `config/cuisine-keywords.php`.
- Config: `config/restaurant-finder.php` (weights + knobs); `config/cuisine-keywords.php`.
- `php artisan quota:status` — SerpApi burn vs quota + cache inventory.
- Tests: `composer test` (PHPUnit) / `npm run test` (Vitest). Current floor
  per the 2026-08-15 entry above; run the suites for the live count rather
  than trusting a stale number here.

## Working across machines / new-machine setup

This repo is the single source of truth — `git pull` on any machine and Claude
reads `CLAUDE.md` → this file + `constitution.md`. Per-machine `~/.claude`
memory does NOT sync between machines, so anything Claude must always know
lives **here in the repo**, not in local memory.

`.env` is gitignored, so a fresh clone has none. First-time setup on a machine:
```bash
cp .env.example .env
php artisan key:generate
composer install
npm install && npm run build
php artisan migrate --seed     # SQLite DB + cuisines + a test user (RestaurantSeeder is a no-op)
php artisan test               # backend suite should pass
php artisan serve              # http://localhost:8000
```
Prereqs: PHP 8.4, Composer, Node 22+, SQLite (dev), MySQL (prod parity).

Add the SerpApi quality key to `.env` so live search returns ratings:
```
SERPAPI_API_KEY=<the validated key — value is in docs/ranking-improvements.md>
```
Without it, search still works but returns unrated OSM results (see the
"binding constraint" section above — it's the only free quality source).

The DB file (`database/database.sqlite`) is gitignored — each machine has its
own local DB. To verify local ranking quality after setup: `php artisan
search:audit nyc`.

See `AGENTS.md` for the full local dev-server bring-up (serve + queue +
scheduler + Vite HMR) and the prod-DB pull-down procedure.
