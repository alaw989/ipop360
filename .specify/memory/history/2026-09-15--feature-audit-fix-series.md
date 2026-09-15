# 2026-09-15 — 2026-09 feature audit + spec-111–115 fix series (PRs #221–225)

**Session type:** interactive (operator-driven; the audit and its fix specs
were explicitly *not* opencode-loop goals — the backlog entry for the audit
says "investigative/read-only, runs directly/interactively", and every fix
was hand-built TDD, reviewed, and shipped one PR at a time with an explicit
operator OK).

## Part 1 — full-feature spec-conformance audit (`docs/feature-audit-2026-09.md`)

A read-only pass over all 10 feature domains (live search/ranking,
restaurants, favorites, blog/CMS, admin, auth, homepage, engagement+geocode
APIs, SEO/sitemap, scheduler/ops), each checked three ways: against its
spec/doc reference (or derived from code where none exists), at `file:line`
level, and behaviorally against https://ipop360.com. Verdicts: 6 OK, 4 drift
— with one **live production bug** (below). Triage table at the doc's end
ranked 8 findings into specs 111–115 + 2 nits.

## Part 2 — the fix series (one PR each, merged in order, deployed + live-verified)

| Spec | PR | What |
|---|---|---|
| 111 | #221 | `/api/cuisine-categories` returned `__PHP_Incomplete_Class` in prod: `getScopedCategories()` left `cuisines` as a Collection; the prod cache (database store, `serializable_classes=false`) unserialized it into an incomplete class, breaking the header cuisine search. `->values()->all()`, mirroring the existing `popularCuisines` fix. |
| 112 | #222 | Blog/CMS hardening: drafts re-slug on title change (published URLs stay stable, `uniqueSlug($ignoreId)`); `publish()` always stamps `now()`; `featured_image` validated `url:http,https`; indexes on `(status,published_at)`, `category`, `is_featured`; stray `assert()` → `author()->associate()` (which the assert had been masking from PHPStan). |
| 113 | #223 | Auth: spec-089's deferred favorites `verified` gate finally got its kill-switch — `verified.gate:<config.key>` middleware reads the flag **per-request** (baking it into route registration would freeze it at `route:cache` time); `require_verified_for_favorites` / `require_verified_for_profile` both default false (prod mailer is `log`; an always-on gate locks everyone out). Dead Breeze confirm-password flow removed (routes, controller, Vue page, test, Ziggy entry). |
| 114 | #224 | Homepage: dropped the unconsumed `stats`/`popularCuisines` props (stats was 3 uncached COUNTs per request) and `getPopularCuisines()`; Trending maps to a 14-field card array instead of full Eloquent models; city scoping case-insensitive (`scopeInCity` / `LOWER()`); `location` echoes the stored casing. |
| 115 | #225 | SEO crawl surface: sitemap gains `/leaderboard` + `/compare`, drops `/login` + `/register`; server-side noindex (`SeoRobots` middleware → blade meta + shared `seo.noindex` prop) for search/favorites/login/register; `lib/baseUrl.resolveBaseUrl()` reads the shared `seo.base_url` (= `app_url`) under SSR; `/robots.txt` served from config (`ServeRobots`), static file deleted. |

Also: refreshed the stale `constitution.md` (pre-spec-104 4-signal scoring
table, PHP 8.3, SQLite-only, Foursquare, "266 tests") and committed the
project's opencode tooling (`opencode.json` Playwright MCP + design
agent/skill).

**Final floor:** PHPUnit 1482 passed / 1 skipped (6299 assertions), vitest
1132, PHPStan L8 zero errors, pint clean, `vue-tsc` + build clean. Every PR
had CI green before merge; every merge deploy was live-verified (cuisine API
arrays, prod blog indexes via `information_schema`, gate flags resolving
`[false,false]` on the droplet, slim homepage payload, robots/noindex/sitemap
responses).

## Lessons

- **Cache-serialization bugs are invisible to the `array` test store.** The
  prod bug (#221) survived because the test suite's cache driver never
  serializes; the regression test must force a serializing store (`file`) and
  read the cache back. Same class of bug the code comments already warned
  about for `popularCuisines`.
- **Audit claims must be verified before implementing.** Several didn't
  survive contact: `location` *was* consumed (by the `/api/homepage-data`
  fetch path), `www.ipop360.com` has **no DNS record** (so the proposed
  www→apex redirect would be dead config), and the "remove the dead
  confirm-password flow" turned out to touch Ziggy's route whitelist too.
- **`route:cache` freezes route-registration-time config reads.** Kill
  switches must be evaluated in middleware at request time; only the config
  cache build is required to flip an env-backed flag.
- **Match the real client contract in tests.** The `verified` middleware
  aborts 403 for JSON requests (axios) and redirects for HTML — asserting a
  redirect for a `postJson` test was wrong.
- **Removing dead props removes queries.** `stats` was three COUNTs (incl.
  `DISTINCT city`) on every homepage request with zero consumers.
- **PHPUnit 12** needs `#[DataProvider]` attributes (docblock annotations
  don't register), and **PHPStan needs array shapes** for `array` returns.
- API responses echoing request casing need normalization at the boundary —
  the homepage heading rendered "atlanta" until `location` echoed the stored
  row's casing.
