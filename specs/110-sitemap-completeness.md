# Feature Specification: Sitemap omits 88% of restaurants and advertises an auth-gated page

**Feature Branch**: `feat/spec-110-sitemap`

**Created**: 2026-09-14

**Status**: SHIPPED (2026-09-14) — PR #218 merged + deployed; permission regression
fixed in PR #219

**Series**: Surfaced by `docs/feature-audit-2026-09.md` (§9 SEO/Sitemap; cross-cutting finding #2).

## The problem

`php artisan seo:sitemap` (`app/Console/Commands/GenerateSitemap.php`) has two
defects on production:

1. **Silent truncation.** Restaurants are loaded with `->limit(5000)`
   (`GenerateSitemap.php:66`) while the corpus is **41,167 active** rows, so the
   live sitemap advertises exactly 5,000 restaurant URLs (`/sitemap.xml`:
   5,016 `<loc>` total). The other ~36k pages are undiscoverable. There is no
   sitemap index, so the cap cannot be raised indefinitely without hitting the
   protocol's 50,000-URL / 50 MB per-file limits.
2. **Auth-gated URL advertised.** `/favorites` is listed as a static page
   (`GenerateSitemap.php:45`) but sits behind the `auth` middleware
   (`routes/web.php:71,77`), so crawlers get a login redirect.
3. **Canonical mismatch.** The homepage `<loc>` is `https://ipop360.com` (no
   trailing slash) while the page's canonical is `https://ipop360.com/`.

## Solution

- Drop `/favorites` (an auth-gated page) from the static list.
- Build all URL entries, then:
  - if the total fits under `restaurant-finder.seo.sitemap_chunk_size`
    (default **40,000**, under the 50,000 protocol limit) → write a single
    `/sitemap.xml` `<urlset>` as today (no cap on restaurants);
  - otherwise → write `/sitemap.xml` as a `<sitemapindex>` referencing
    `sitemap-pages.xml`, `sitemap-restaurants-{n}.xml` chunks (≤ chunk size
    each), and `sitemap-blog.xml`.
- Delete stale `public/sitemap-*.xml` files from previous runs so chunks never
  accumulate or outlive their content.
- Make the homepage `<loc>` match its canonical (`https://ipop360.com/`).

## Acceptance criteria

- All active restaurants are represented: with the corpus under the chunk size
  the single file contains every active slug; over it, the union of the
  restaurant chunks does.
- Over the chunk size, `/sitemap.xml` is a valid `<sitemapindex>` and each
  referenced child exists with a `<urlset>` whose entries total the corpus.
- A stale `sitemap-*.xml` from a previous run is removed.
- `/favorites` is absent; the homepage `<loc>` ends in `/`.
- Inactive restaurants and unpublished posts remain excluded; `lastmod` still
  emitted where a timestamp exists.

## Files

- `app/Console/Commands/GenerateSitemap.php`.
- `config/restaurant-finder.php` — `seo.sitemap_chunk_size`.
- `tests/Feature/GenerateSitemapCommandTest.php`.
- `.gitignore`, `.github/workflows/deploy.yml` — keep generated chunk files out
  of the repo and out of the rsync `--delete` set.

## Notes

- robots.txt already points at `/sitemap.xml`; an index at that path is standard
  and needs no change.
- 40,000 keeps headroom under the protocol's 50,000 limit; the corpus is 41,167
  today, so production will use the index path.

## Build log (2026-09-14, hand-built TDD)

- Red: the updated static-pages test, the trailing-slash assertion, the
  chunk/index test, and the stale-chunk test all failed against the old command.
- Green after the command was restructured: entries are collected for
  pages/restaurants/blog, a single `<urlset>` is written while the total fits
  under the chunk size, otherwise a `<sitemapindex>` plus per-kind children
  (`sitemap-pages.xml`, `sitemap-restaurants-{n}.xml`, `sitemap-blog.xml`), and
  stale `public/sitemap-*.xml` files are removed. `/favorites` dropped; homepage
  `<loc>` now ends in `/`.
- Gate: Pint clean · PHPUnit **1459 passed**, 1 skipped · vitest 1129 passed ·
  PHPStan level 8 no errors · `npm run build` clean.
- Not committed/pushed/deployed — local-first and operator-gated.

## Ship log (2026-09-14)

- **PR #218** merged + deployed. Live-verified: `/sitemap.xml` is a valid
  `<sitemapindex>` with 4 children; `sitemap-restaurants-1.xml` 40,000 URLs +
  `sitemap-restaurants-2.xml` 1,171 = **41,171 active restaurants** (was 5,000);
  `/favorites` absent; homepage `<loc>` ends in `/`.
- **Regression found on live-verify:** the chunked children were written into
  `public/` directly, but `public/` is owned by the deploy user (755) while
  `seo:sitemap` runs as `www-data` — creating the new files failed with
  `file_put_contents(...): Permission denied`, and the deploy's pre-created
  `public/sitemap.xml` had already been truncated. **Prod briefly served an
  empty sitemap.**
- **PR #219** fix: children now live in `public/sitemaps/` (pre-created
  `www-data`-owned in `deploy.yml`, rsync-excluded, gitignored); the index
  references `/sitemaps/…`; the command fails loud (FAILURE + error) if a chunk
  or the index can't be written. Re-deployed + re-verified (see above).
