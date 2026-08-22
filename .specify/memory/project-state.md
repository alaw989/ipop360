# iPop360 — Current Project State

> Living snapshot for Claude (and humans) picking up this project. Read this
> together with `constitution.md` and `backlog.md` at session start. Detailed
> per-spec history lives in `history.md` (one-line-per-spec log) and
> `history/` (deep-dive writeups). Updated: 2026-08-22.
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

## In-flight work (check before starting anything new)

As of 2026-08-22 the working tree has **uncommitted** work on branch
`feat/bundle-size-cwv` (an `opencode-loop` goal: measure Lighthouse/CWV on the
homepage + key routes, trim the largest bundles, confirm no regression). See
`ITERATION_NOTES.md` at the repo root for the live scratch log (state + next
step + gotchas) — it is the authoritative in-flight state, more current than
this file. Always check `git status` + `ITERATION_NOTES.md` before starting a
new backlog goal; don't stack a new loop branch on top of unfinished/uncommitted
work.

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
