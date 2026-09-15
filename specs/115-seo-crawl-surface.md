# Feature Specification: SEO crawl surface — sitemap additions, server-side noindex, config-driven origins

**Feature Branch**: `fix/spec-115-seo-crawl-surface`

**Created**: 2026-09-15

**Status**: IN PROGRESS (local)

**Series**: 2026-09 feature audit, finding #5 (`docs/feature-audit-2026-09.md`
§9). Follows spec-110 (sitemap completeness), which fixed the truncation
bug but left the crawl-surface issues below.

## The problem (audit evidence)

1. **Discoverability:** `/leaderboard` and `/compare` are public with SEO
   metadata but absent from `GenerateSitemap::pageEntries()`.
2. **Crawl-budget noise:** `/login` and `/register` were advertised in the
   sitemap with no ranking value and no noindex.
3. **Infinite indexable space:** `/search` sets a canonical that preserves
   `cuisine`/`lat`/`lng` (`useSeo` param-stripping) and has no `noindex`, so
   parameter combinations are theoretically all indexable; `/favorites`
   carried no `noindex` either.
4. **Hardcoded origin:** `useBaseUrl.ts` fell back to `https://ipop360.com`
   on SSR, and `public/robots.txt` hardcoded the same — any non-prod SSR
   render emitted production canonicals.
5. **www variant:** `www.ipop360.com` does not resolve (verified: no DNS
   record, curl fails) and apex HTTP → HTTPS 301s at nginx, so no redirect
   code is needed; leaving this documented instead of adding dead config.

## Solution

- **Sitemap** (`GenerateSitemap::pageEntries`): adds `/leaderboard`
  (daily, 0.8) and `/compare` (weekly, 0.6); drops `/login` and `/register`;
  `/search` deliberately absent (noindex).
- **Server-side noindex** (`App\Http\Middleware\SeoRobots`, prepended to the
  web group): matches `config('restaurant-finder.seo.noindex_paths')`
  (`search`, `favorites`, `login`, `register`) and sets a request attribute.
  `HandleInertiaRequests` shares it as `seo.noindex` (plus
  `seo.base_url = config('app.url')`) and `app.blade.php` renders
  `<meta name="robots" content="noindex, nofollow">` — visible to crawlers
  that do not execute JavaScript. `useSeo()` now takes noindex from that
  shared prop (pages can still force it, e.g. live previews).
- **Config-driven origins:** new `resources/js/lib/baseUrl.ts`
  (`resolveBaseUrl()`) reads the shared `seo.base_url` prop under SSR with a
  safe fallback; `useBaseUrl()` and `lib/api.getBaseUrl()` both delegate to
  it, so all canonicals and API fetches follow the configured origin.
- **Dynamic `robots.txt`** (`App\Http\Controllers\ServeRobots`, route
  `/robots.txt`): emits the Disallow list from the same `noindex_paths`
  config plus `Sitemap: {APP_URL}/sitemap.xml`; `public/robots.txt` deleted.

## Tests

- `GenerateSitemapCommandTest`: static-page expectations updated; new test
  pinning `/search` absent.
- `SeoRobotsTest` (new): server-rendered meta + shared prop for
  search/login/register; favorites authenticated (guests get a 302);
  public pages stay indexable; `seo.base_url` follows `app.url`;
  `/robots.txt` serves config-driven body and content type.
- Frontend: `useSeo` noindex contract from the shared prop; `ssr-fallback`
  now covers both helpers reading `seo.base_url`.

## Verification

- Gate (local): pint clean · PHPStan L8 zero errors · PHPUnit 1482 passed /
  1 skipped · vitest 1132 passed · `vue-tsc` clean · `npm run build` clean.
- Local serve: `/robots.txt` emits the config body with the localhost
  sitemap; `/search` + `/login` HTML carry the noindex meta; `/restaurants`
  does not.
- Live (post-deploy): `/robots.txt` → 200 with `Sitemap:
  https://ipop360.com/sitemap.xml`; page source of `/search` and `/login`
  carries noindex; `sitemap-pages.xml` includes `/leaderboard` + `/compare`
  and excludes `/login`/`/register`/`/search`.
