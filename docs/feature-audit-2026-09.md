# Feature-Audit 2026-09

**Date**: 2026-09-15
**Scope**: read-only, full-feature spec-conformance pass across all 10 feature domains.
**Method**: per domain — (a) spec reference (existing docs/specs, or explicitly "none — derived from code"); (b) code-level check against that intent with `file:line` citations; (c) live check on https://ipop360.com (curl for endpoints, HTTP status for pages); (d) verdict OK / drift / bug.

Verdicts are evidence-backed, never asserted from memory. This pass fixes nothing — findings are
triaged into candidate specs at the bottom.

---

## 1. Live Search & Ranking — verdict: OK (one stale-doc note)

**Spec reference**: `docs/ranking-metrics.md`, `docs/scoring-explained.md`, `docs/ranking-audit-2026-08.md` — the best-documented domain.

**Code check**: `SearchController` → `UnifiedSearchService` → `LiveSearchService` → `PopularityScoreService` → `CuisineMatcher`. Ten weighted signals (renormalized per row's active set); Quality 0.35 / Verified Presence 0.35 (unrated only) / Website Traffic 0.20 / Proximity 0.15 / Page Views 0.10 / Award 0.05 / Cuisine Match 0.50 (live scoped only) / Completeness 0.05 / social+menu clicks 0.05. Confirmed against `config/restaurant-finder.php` and `PopularityScoreService`.

**Live check**: `/search` → 200; `/api/restaurants` → 200 with the documented envelope (`data`, `current_page`, `last_page`, `total`, `next_page_url`, …) and `data.0` carrying `google_rating`, `source`, `score_breakdown`, `popularity_score`. Total corpus 41,238 active rows.

**Findings**:
- **drift (docs only)**: `constitution.md`'s "Scoring signals" table still lists the pre-spec-104 4-signal model (Proximity 0.30 / Completeness 0.25 / Award 0.15 / Google rating 0.03 / review count 0.02) and stale infra facts ("PHP 8.3", "266 tests", "Foursquare", "SQLite"). It contradicts `scoring-explained.md` (which carries an explicit spec-104 "keep docs and config in sync" note). Constitution is a memory-bank doc, not user-facing, but it is the session-start read and will mislead future agents.

---

## 2. Restaurants (detail / compare / leaderboard / cuisine browse) — verdict: OK

**Spec reference**: none consolidated — inferred from scattered `specs/0NN-*.md` fixes (040, 078, 085, 088, 101) and the code.

**Code check** (`app/Http/Controllers/RestaurantController.php`):
- `show()` route-model-binds by slug and `abort_if(! $restaurant->is_active, 404)` (line 307) — quarantined rows never render publicly.
- `preview()` renders live results from the snapshot service; upserts synthetic (negative-id) venues to mint a real id so engagement doesn't 422 (lines 349–355).
- `leaderboard()`/`compare()` both filter `active()` and share the spec-078 once-per-set aggregate computation (lines 533–549, 581–592).
- `apiIndex()`/`index()` share `buildRestaurantQuery()` and `applySortMode()` so DB filtering can't drift between the two (lines 46–81). Merged live+DB union via `UnifiedSearchService` with deterministic page-2+ snapshot slicing (lines 464–527).

**Live check**: `/restaurants` 200, `/leaderboard` 200, `/compare` 200, `/cuisine/american` 200, `/restaurants/preview/*` reachable (route exists, snapshot-gated).

**Findings**: none functional. Cross-domain note: `/leaderboard` and `/compare` are public and SEO-tagged but absent from the sitemap (see §9).

---

## 3. Favorites — verdict: OK (hardened; one deferred gap)

**Spec reference**: specs 035 (hybrid persistence), 085 (live-result FK orphan fix), 088 (write-path hardening).

**Code check** (`app/Http/Controllers/FavoriteController.php`):
- `toggle()` validates a tightly-bounded client payload (lines 66–83); rating/score/`is_active` never accepted from the client.
- `ensurePersisted()` quarantines client-created rows (`is_active=false`, line 250), resolves cuisines against the DB (lines 299–312), and recovers TOCTOU unique-constraint races by re-resolving the winner's row (lines 270–287).
- `merge()` runs the multi-venue persist+attach in one outer transaction so a partial merge can't leave orphan rows (lines 148–164).
- `index()` bounds the query at `favorites.index_cap` (200) and shares spec-078 aggregates (lines 34–41).

**Live check**: `/favorites` → 302 (redirect to login) for guests, as intended.

**Findings**:
- **drift**: favorites routes are `auth`-only, not `auth,verified` (`routes/web.php:76–80`). Because `User` implements `MustVerifyEmail` (spec-089), an unverified throwaway account can still toggle/merge favorites. This is the *documented* spec-089 deferral — but the `require_verified_for_favorites` kill-switch key the spec references was never added, so the gate can't be flipped without a code change. Carried into §6.

---

## 4. Blog / CMS — verdict: drift (several small correctness gaps)

**Spec reference**: none — derived from code (`history.md` blog entries only).

**Code check**:
- Public `BlogController` (`app/Http/Controllers/BlogController.php`): `index()` filters/paginates `BlogPost::published()`; `show()` aborts 404 unless published and `published_at <= now()` (lines 55–57). Category filter is case-insensitive via `LOWER(category)` (line 19).
- Admin `Admin/BlogPostController.php`: `store()`/`update()` sanitize `body` through `HtmlSanitizer` (lines 46, 79); ownership enforced by `authorizePost()` (lines 106–111) + `role:admin,editor` middleware. Validation is `title`, `excerpt`, `category`, `body`, `featured_image`, `is_featured`, `status` (lines 118–131).
- `BlogPost` (`app/Models/BlogPost.php`): `published()` scope; slug generated on `creating` only (lines 41–48); `publish()` sets `published_at` once (lines 87–93).

**Live check**: `/blog` → 200, `/blog/{slug}` reachable; 2 published posts in `sitemap-blog.xml`.

**Findings**:
- **drift**: slug is generated only on `creating`, never regenerated on title change (`BlogPost.php:41–48`). Editing a post's title leaves the public URL stale (route binds `{post:slug}`).
- **drift**: unpublishing (draft→published→draft) never clears `published_at`; re-publishing keeps the old date (`BlogPostController.php:81–87`, `BlogPost.php:91`).
- **nit**: stray debug `assert($user->id >= 0)` in `store()` (`BlogPostController.php:53`).
- **drift**: `featured_image` has no URL-scheme validation (`nullable|string|max:2048`, line 125) — client `type="url"` is the only guard.
- **drift**: no indexes on `status`/`published_at`/`category`/`is_featured` despite being filtered/sorted (`BlogController.php:15–41`, `HomeService.php:83–88`).
- **nit**: `authorizePost()` is a no-op when `$user` is null (line 108) — currently masked by route `auth` middleware.

---

## 5. Admin (dashboard / users / featured) — verdict: OK (minor hardening gaps)

**Spec reference**: none — derived from code (`AdminDashboardController`, `Admin/UserController`, `Admin/FeaturedRestaurantController`, `UserRoleService`).

**Code check**:
- Role enforcement: `EnsureUserHasRole` middleware (`app/Http/Middleware/EnsureUserHasRole.php:15–26`) maps via `UserRole::tryFrom(...)?->value` and `abort(403)` when the user's role isn't in the allowlist — fails closed.
- `UserController::update()` (`Admin/UserController.php:33–57`): `Rule::enum(UserRole::class)`, then two guards — `wouldRemoveLastAdmin()` (line 41) and self-demotion (line 47) — before assigning.
- `UserRoleService::wouldRemoveLastAdmin()` (`app/Services/UserRoleService.php:28–33`) is shared with the `user:role` CLI command.
- Featured spotlight: `FeaturedRestaurantController::store()` wraps `endCurrent()` + create in a transaction (lines 48–58); validation includes `Rule::exists('blog_posts','id')->where('status','published')`.

**Live check**: `/admin` → 302 (redirect to login) for guests.

**Findings**:
- **drift**: no DB-level single-active-featured constraint — dedup is application-level `endCurrent()` only (`FeaturedRestaurantController.php:49`; `featured_restaurants` has only a `[starts_at, ends_at]` index, `2026_09_13_000002_...php:31`). A concurrent double-submit could yield two "current" rows.
- **nit**: `role` is not enum-cast on `User` (`User.php:27–33`) — correctness is maintained at the write boundary (`Rule::enum`) + helpers, but an out-of-band bad write degrades silently to `isAdmin() === false`.
- **nit**: `created_by` on `FeaturedRestaurant` has no `creator()` relation — stored but never read.

---

## 6. Auth / Users — verdict: drift (deferred kill-switch + dead flow)

**Spec reference**: spec-089 (registration throttle + email-verify gate).

**Code check** (`app/Http/Controllers/Auth/*`, `ProfileController`, `routes/auth.php`):
- Registration throttled `throttle:5,1` (`routes/auth.php:18–19`); login throttle is `email|ip` keyed, 5 attempts (`LoginRequest.php:61–85`); forgot-password `throttle:3,5`; verify/resend `throttle:6,1`.
- `RegisteredUserController::store()` auto-logs in and notifies operators (lines 50–58).
- `User` implements `MustVerifyEmail`; `/dashboard` and `/admin` are `auth,verified`.
- `ProfileController::update()` nulls `email_verified_at` on email change (lines 40–42); `destroy()` requires `current_password` (lines 54–56).

**Live check**: `/login` 200, `/register` 200, `/dashboard`/`/profile` → 302 (guest redirect).

**Findings**:
- **drift**: the spec-089 `require_verified_for_favorites` config key **does not exist** anywhere in `config/` (grep-verified). The "gate favorites on verified email" deferral has no kill-switch, so unverified accounts can write favorites (and edit/delete profile — profile routes are `auth`-only, `routes/web.php:71–74`).
- **drift (dead code)**: the `password.confirm` GET/POST routes and `ConfirmablePasswordController` exist (`routes/auth.php:52–55`) but **no route applies `password.confirm` middleware** (grep-verified). Sensitive actions use `current_password` instead. The 3h confirmation window (`config/auth.php:110`) and `Auth/ConfirmPassword.vue` are unreachable.
- **nit**: rate-limit key asymmetry — register/forgot/verify are IP-keyed only (route `throttle:`), while login is `email|ip`.

---

## 7. Homepage — verdict: **bug** (live serialization defect) + drift

**Spec reference**: none — derived from code (`HomeController`, `HomeService`).

**Code check** (`app/Services/HomeService.php`):
- `getHomepageData()` (lines 28–106): categories → 3-tier trending cascade → popular cuisines (cached) → latest posts → stats band → return array.
- `getScopedCategories()` (lines 247–277) maps each category to `'cuisines' => $cat->cuisines->map(...)` — a **Laravel Collection**, not `->toArray()`-ed.
- `allCategories()` (lines 239–242) caches `getScopedCategories(null, null)` for an hour, served by `/api/cuisine-categories`.
- The known serialization hazard is documented *for `popularCuisines`* (lines 207–212: with `config('cache.serializable_classes')` false, a cached object unserializes to `__PHP_Incomplete_Class`) — but the same hazard was **not** handled in `getScopedCategories`.

**Live check** — **confirmed bug**:
```
GET /api/cuisine-categories
[{"id":1,"name":"Asian","slug":"asian","icon":"…","cuisines":{"__PHP_Incomplete_Class_Name":"Illuminate\\Support\\Collection"}}, …]
```
Every category's `cuisines` is the literal `__PHP_Incomplete_Class` object, not an array. The endpoint feeds the header search via `useCuisineCategories.ts` (`resources/js/composables/useCuisineCategories.ts:31`), whose `SearchCategory.cuisines` is typed `SearchCuisine[]`. The header-search cuisine drill-down is broken in production. The test (`HomeControllerTest.php:63–79`) passes only because the test suite runs the **array** cache driver, which never serializes — so the Collection JSON-encodes cleanly there. `/` and `/api/homepage-data` are unaffected (they call `getScopedCategories` uncached).

**Findings** (bug first):
- **bug**: `/api/cuisine-categories` `cuisines` → `__PHP_Incomplete_Class`. Fix is a one-liner: `->toArray()` (or `->values()->all()`) on the inner `cuisines` map in `getScopedCategories`, mirroring the existing `popularCuisines` fix. Needs a regression test that exercises a *serializing* cache driver, not the array driver.
- **drift**: `stats`, `popularCuisines`, `location` are computed and returned by `HomeService::getHomepageData()` (lines 96–105) but `Welcome.vue:82–92` declares only `categories`, `popularCities`, `popularRestaurants`, `featuredRestaurant`, `latestPosts`. The three props are never rendered (zero `resources/js` matches).
- **drift**: `stats` runs three uncached `COUNT`s (incl. a `DISTINCT city`) on every `/` and `/api/homepage-data` request (`HomeService.php:90–94`) — results unused.
- **drift**: `popularRestaurants` serialize full Eloquent models (no column selection, `HomeService.php:166–175`) — every column incl. JSON blobs (`photos`, `ai_metadata`, `score_breakdown`, …) shipped for ~18 cards.
- **drift**: case-sensitive `where('city', $city)` (`HomeService.php:49–50, 255–256`) while `Restaurant::scopeInCity` lowercases both sides (`Restaurant.php:215–220`) — GPS-originated casing can miss the curated Title-Case rows.

---

## 8. Engagement + Geocode — verdict: OK (documented risk surface)

**Spec reference**: none — derived from code (`EngagementController`, `GeocodeController`, `GeolocationService`).

**Code check**:
- `EngagementController::store()` (`POST /api/engage`): action allowlist (`website|directions|call|pageview|social_link_click|menu`), `exists:restaurants,id` + integer validation, bot UA regex filter (lines 46–53), authenticated-only 60s dedup (lines 56–67), live counter increment (lines 76–87), 204 response. Throttled `throttle:30,1` + CSRF-exempt (`bootstrap/app.php:35–37`).
- `GeocodeController` reverse/search/forward: `between:-90,90` / `between:-180,180` on reverse; `q min:2 max:100` on search; hardcoded Nominatim/Photon base URLs, `User-Agent: iPop360/1.0`, cached (`revgeo:`/`fwdgeo:`/`citysearch:`).

**Live check**: `/api/geocode` → `{"city":"Atlanta","state":"Georgia"}`; `/api/geocode/forward` → `{"lat":33.75,"lng":-84.39}`; `/api/geocode/search?q=atl` → 4 filtered US results; `POST /api/engage` empty → 422 (validation).

**Findings**:
- **drift**: `/api/engage` is unauthenticated + CSRF-exempt, so any client can inflate persisted counters that feed ranking signals (website_clicks 0.20, pageviews 0.10). Only guards are `exists:restaurants,id` + 30/min/IP. Anonymous requests get no dedup (lines 56–67).
- **drift**: `searchCities()` sends no `User-Agent` (lines 81–85) while forward/reverse do — some free geocoders reject default HTTP-client UAs.
- **nit**: `ipLookupFull()` interpolates `$request->ip()` into `https://ipapi.co/{$ip}/json/` (line 222) — an SSRF gadget *if* proxy trust (`TRUSTED_PROXIES`) is ever misconfigured.

---

## 9. SEO / Sitemap — verdict: drift

**Spec reference**: spec-110 (sitemap completeness) + `GenerateSitemap` command.

**Code check** (`app/Console/Commands/GenerateSitemap.php`):
- Emits static pages (`/`, `/restaurants`, `/login`, `/register`, `/blog`), every `cuisine_categories` slug, every `is_active` restaurant, every published post. Chunked at 40,000 into `public/sitemaps/sitemap-*.xml` with a `<sitemapindex>`; stale chunks removed (`removeStaleChunkFiles`, lines 233–240). `baseUrl = config('app.url')` (line 37).
- `robots.txt` hardcodes `Sitemap: https://ipop360.com/sitemap.xml`.

**Live check**: `/sitemap.xml` → 200 (index with pages + 2 restaurant chunks + blog); `sitemap-restaurants-1.xml` 40,000 `<loc>`, `-2.xml` 1,173, blog 2 — **41,173 total vs 41,238 API total** (65 delta = rows added since the 10:15 UTC run; not a defect).

**Findings**:
- **drift**: `/search`, `/leaderboard`, `/compare` are public (no auth middleware) but absent from `pageEntries()` (lines 111–117) — not discoverable via sitemap despite having SEO meta.
- **drift**: `Search.vue` sets canonical `${baseUrl}${usePage().url}` and **no `noindex`** (line 147), while `useSeo` preserves `cuisine`/`lat`/`lng` params — a near-infinite indexable parameter space (`/search?cuisine=…&lat=…&lng=…`).
- **drift**: `/login` and `/register` are advertised to crawlers (lines 114–115) — crawl-budget noise with no ranking value; they should be `noindex`/excluded.
- **drift**: `/favorites` sets no `noindex` (auth-gated, so dropped from sitemap correctly — but the page itself isn't marked `noindex`).
- **drift**: hardcoded origin in two SSR/SEO spots — `useBaseUrl.ts:18` (`https://ipop360.com` SSR fallback) and `robots.txt:4` — so any non-prod SSR render resolves canonicals to production.
- **drift**: no `www`→apex or HTTP→HTTPS redirect (only `TrustHosts`, which 400s forged hosts, `app/Http/Middleware/TrustHosts.php`) — duplicate-host variant risk if the site is reachable at `www.ipop360.com`.

---

## 10. Scheduler / Cron / Ops — verdict: OK (code-verified)

**Spec reference**: specs 096 (scheduled-job observability) + 108 (scheduled-job audit fixes).

**Code check** (`routes/console.php`): ~19 scheduled commands, every one attached to `SchedulerTelemetry` (`->tap(...)`) and carrying an `onFailure` enrichment-log handler. Timing is deliberately stacked after the 04:00–~10:00 enrichment window to avoid SQLite write-lock contention (the `mysql` prod switch removes the hard dependency but the ordering is retained). Read-only canaries (`uptime:canary`, `scheduler:health`) run `evenInMaintenanceMode`.

**Live check**: not verifiable via HTTP (cron-driven). The schedule manifest is pinned by `SchedulerManifestTest`; command code paths are covered by their own feature tests. Cross-referenced against the AGENTS.md "Scheduler" section — consistent.

**Findings**: none. Note the `seo:sitemap` (10:15) / social-scrape (10:45) / backfill (11:45+) daily ordering comments still describe SQLite write-lock contention even though prod is MySQL (spec-104) — stale reasoning in comments, harmless.

---

## Triage

Findings ranked for follow-up (none fixed in this pass; no `specs/` files created — that is its own step):

| # | Severity | Finding | Proposed spec |
|---|---|---|---|
| 1 | **bug** | `/api/cuisine-categories` returns `__PHP_Incomplete_Class` for `cuisines` (breaks header search). One-line `->toArray()` + serializing-cache regression test. | spec-111 |
| 2 | drift | Blog: slug never regenerated on title change; stale `published_at` on unpublish; `featured_image` URL validation; missing indexes; stray `assert`. | spec-112 |
| 3 | drift | Auth: `require_verified_for_favorites` kill-switch missing; `password.confirm` flow is dead code; profile routes not `verified`. | spec-113 |
| 4 | drift | Homepage: dead `stats`/`popularCuisines`/`location` props; uncached COUNTs; full-model payload bloat; case-sensitive city match. | spec-114 |
| 5 | drift | SEO: add `/search`/`/leaderboard`/`/compare` to sitemap; `noindex` search/favorites/login/register; config-driven canonical origin. | spec-115 |
| 6 | nit | Docs: `constitution.md` scoring table + infra facts are stale (spec-104 predates them). | direct docs edit |
| 7 | nit | Admin: single-active-featured DB constraint; `role` enum cast; `created_by` relation. | fold into a later spec |
| 8 | nit | Engagement: anonymous dedup; `searchCities` User-Agent; `ipLookupFull` IP interpolation. | fold into a later spec |

---

## Fix log

Fixes are implemented locally, gated (`pint --test`, PHPStan L8 zero-error, full PHPUnit), then shipped as their own PR. spec-111 through spec-115 are **merged + deployed + live-verified** — the audit triage is fully closed (remaining items were nits folded into later specs).

### spec-111 — `/api/cuisine-categories` `__PHP_Incomplete_Class` (SHIPPED — PR #221)

- `app/Services/HomeService.php:269` — `getScopedCategories()` now `->values()->all()`s the inner `cuisines` map, so the cached payload holds plain arrays instead of `Collection` objects (mirrors the existing `popularCuisines` fix at lines 207–212).
- `tests/Feature/HomeControllerTest.php` — new `test_cuisine_categories_endpoint_survives_a_serializing_cache_store` forces the serializing `file` store, warms then re-reads the cache, and asserts the second response's `cuisines` is a real array. Mutation-checked (red without the fix; the original test could not catch it because the `array` test store never serializes).
- Live-verified post-deploy: `GET /api/cuisine-categories` → 200, `cuisines` is a JSON array (15 entries in the first category), `__PHP_Incomplete_Class` gone.

### spec-112 — Blog / CMS hardening (SHIPPED — PR #222)

- `app/Models/BlogPost.php` — new `updating` hook re-slugs a **never-published** post when its title changes (`published_at === null`), so drafts track their title while published URLs stay stable; `uniqueSlug()` takes an `$ignoreId` so re-slugging never collides with the post's own row. `publish()` now always sets `published_at = now()` (no scheduling UI exists), so unpublish→republish gets a fresh date instead of a stale one; editing an already-published post still preserves its original date.
- `app/Http/Controllers/Admin/BlogPostController.php` — `featured_image` is validated as `url:http,https` (blocks `javascript:` and other schemes; empty string is nulled by `ConvertEmptyStringsToNull`); the stray `assert($user->id >= 0)` was removed and the assignment converted to `author()->associate($user)` (which the assert had been masking from PHPStan).
- `database/migrations/2026_09_15_000001_add_indexes_to_blog_posts_table.php` — indexes `(status, published_at)`, `category`, `is_featured` (all filtered/sorted but previously unindexed).
- `tests/Feature/BlogAdminTest.php` — 6 new tests: draft re-slug, published slug stability, fresh republish date, `javascript:` image rejected, https image accepted, empty-string image allowed.
- Live-verified post-deploy: `information_schema.STATISTICS` shows `blog_posts_status_published_at_index (status,published_at)`, `blog_posts_category_index`, `blog_posts_is_featured_index`; migration `2026_09_15_000001_add_indexes_to_blog_posts_table` recorded.

### spec-113 — Auth hardening (SHIPPED — PR #223)

- `app/Http/Middleware/VerifiedWhenConfigured.php` (alias `verified.gate`) — applies Laravel's `verified` gate only when a named config flag is true, checked per-request so `route:cache` cannot freeze the decision.
- `config/auth.php` — `require_verified_for_favorites` and `require_verified_for_profile` kill-switches, both default `false` (prod mailer is `log`; an always-on gate would lock everyone out).
- `routes/web.php` — favorites writes + profile PATCH/DELETE honor their flags; `/profile` GET stays open.
- Removed the dead Breeze confirm-password flow (routes, controller, page, test, Ziggy entry).
- `tests/Feature/Auth/VerifiedGateTest.php` — 8 tests pinning default-off, gate-on, verified-user, and removals.
- Live-verified post-deploy: `/confirm-password` → 404; `/register` 200; `/profile` guest → 302; on the droplet both gate configs resolve `[false,false]` (behavior unchanged).

### spec-114 — Homepage payload diet + case-safe city scoping (SHIPPED — PR #224)

- `app/Services/HomeService.php` — dropped the unconsumed `stats`/`popularCuisines` props (and `getPopularCuisines()` + its config key); Trending rows map through `trendingCard()` to the exact 14 fields the card renders; Trending scopes via `Restaurant::scopeInCity` and scoped categories via `LOWER()`; `location` echoes the matched row's stored casing.
- Frontend types aligned: `PopularRestaurant` drops `score_breakdown`; `Welcome.vue` types the payload as `TrendingRestaurant`; fixtures trimmed.
- Tests: dead-prop missing-path guard, exact slim-key list, case-insensitive scoping for trending + categories (`HomeControllerTest`, `HomeServiceTest`).
- Live-verified post-deploy: `/api/homepage-data` has no `stats`/`popularCuisines`; 18 trending cards carry exactly the 14 slim keys (no `photos`/`ai_metadata`/`score_breakdown`); `?city=atlanta&state=ga` → `location: {city: Atlanta, state: GA}` with 18 results.

### spec-115 — SEO crawl surface (SHIPPED — PR #225)

- `GenerateSitemap::pageEntries()` — added `/leaderboard` + `/compare`, dropped `/login` + `/register`; `/search` deliberately absent.
- `App\Http\Middleware\SeoRobots` (prepended to web) + `config('restaurant-finder.seo.noindex_paths')` — server-side `<meta name="robots" content="noindex, nofollow">` via `app.blade.php`, shared as `seo.noindex` so `useSeo()` agrees; pages can still force noindex (live previews).
- `resources/js/lib/baseUrl.ts` — `resolveBaseUrl()` reads the shared `seo.base_url` (`config('app.url')`) under SSR; `useBaseUrl()` and `lib/api.getBaseUrl()` both delegate (hardcoded prod fallback gone from the canonical path).
- `App\Http\Controllers\ServeRobots` — `/robots.txt` served from config (`{APP_URL}/sitemap.xml` + the noindex Disallows); static `public/robots.txt` deleted.
- Not done: www→apex redirect — `www.ipop360.com` has no DNS record and apex HTTP already 301s to HTTPS (verified live), so it would be dead config.
- Live-verified post-deploy: `/robots.txt` 200 with the prod sitemap URL + Disallow lines; `/search`, `/login`, `/register` HTML carry the noindex meta (0 on `/` and `/restaurants`); `sitemap-pages.xml` has 13 entries including `/leaderboard` + `/compare` and none of `/login`/`/register`/`/search`.

### Direct docs edit — stale `constitution.md` (DONE)

The memory-bank constitution still described the pre-spec-104 4-signal scoring model, PHP 8.3, SQLite-only, Foursquare as a live source, and "266 tests". Refreshed: 10-signal weighted table pointing at `docs/scoring-explained.md` for detail, PHP 8.4 + MySQL/SQLite split, 4 live-search sources, current test counts, `composer test`/`npm run test` commands.
