# Iteration Notes

## Goal
find and remove dead code and unused configuration across the app

## State
Removed 7 deprecated env vars, 3 npm packages, and 4 dead artisan commands. Full PHP codebase scan complete: all services, controllers, middleware, models, jobs, resources are actively referenced. Cleaned `config/database.php`: removed unused `mariadb`, `pgsql`, `sqlsrv` connections and dead Redis section (zero env var references, cache/queue/session all default to `database` driver). Dropped `Illuminate\Support\Str` import (only used by Redis prefix).

Next: clean dead boilerplate from `config/auth.php` — remove commented-out `database` provider (the `eloquent` provider and `web` guard are actively used).

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
10. Cleaned `config/database.php` — removed dead `mariadb`, `pgsql`, `sqlsrv` connections and unused Redis section (zero references in .env or app config; cache/queue/session all default to `database` driver). Removed `Illuminate\Support\Str` import (only used for Redis prefix). File went from 187 lines to 89. 563 tests pass, build clean.

