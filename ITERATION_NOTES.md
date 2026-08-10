# Iteration Notes

## Goal
find and remove dead code and unused configuration across the app

## State
Removed 7 deprecated env vars, 3 npm packages, and 4 dead artisan commands (`SearchAuditCommand`, `BackfillRestaurantLocation`, `ValidateRestaurantData`, `CopySqliteToMysql`). Full PHP codebase scan complete: all services, controllers, middleware, models, jobs, resources are actively referenced. All 4 unused artisan commands removed. Config files `database.php` and `auth.php` have dead boilerplate sections left to clean up.

Next: clean dead boilerplate from `config/database.php` (sqlite connection is mysql-shaped; only `driver` + `database` + `foreign_keys` matter) and `config/auth.php` (unused guards like `web` session config, unused provider configs).

## Log
1. Removed from `.env.example`: `GOOGLE_PLACES_API_KEY`, `OUTSCRAPER_API_KEY`, `FOURSQUARE_API_KEY`, `OUTSCRAPER_CACHE_TTL_HOURS`, `GOOGLE_CACHE_TTL_HOURS`, `FOURSQUARE_CACHE_TTL_HOURS`, `LIVE_SEARCH_FOURSQUARE_TIMEOUT` — all zero PHP references.
2. Removed `@tailwindcss/forms` from `package.json` devDependencies — zero references (Tailwind 4 handles form styles natively). Re-ran `npm install`.
3. Removed `autoprefixer` from `package.json` devDependencies — no postcss.config exists, Tailwind 4 + lightningcss handle prefixing natively. Re-ran `npm install` (3 packages removed).
4. Removed `postcss` from `package.json` devDependencies — no postcss.config, no source references, Tailwind 4 + Vite plugin handle everything natively. Re-ran `npm install`.
5. Full PHP codebase scan (grep for every class name): all 19 services, 22 controllers, 3 middleware, 7 models, 2 jobs, 2 resources, 1 trait, 1 support class are actively referenced. 4 dead commands found: `SearchAuditCommand`, `BackfillRestaurantLocation`, `ValidateRestaurantData`, `CopySqliteToMysql` (all zero references, no tests, never scheduled).
6. Removed `app/Console/Commands/SearchAuditCommand.php` — zero references, zero tests, never scheduled in `routes/console.php`. 563 tests pass.
7. Removed `app/Console/Commands/BackfillRestaurantLocation.php` — zero references, zero tests, never scheduled. 563 tests pass, build clean.
8. Removed `app/Console/Commands/ValidateRestaurantData.php` — zero references, zero tests, never scheduled. 563 tests pass, build clean.
9. Removed `app/Console/Commands/CopySqliteToMysql.php` — zero references, zero tests, never scheduled. 563 tests pass.

