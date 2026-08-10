# Iteration Notes

## Goal
find and remove dead code and unused configuration across the app

## State
Removed 7 deprecated env vars, 3 npm packages, and 4 dead artisan commands. Full PHP codebase scan complete: all services, controllers, middleware, models, jobs, resources are actively referenced. Cleaned `config/database.php`: removed unused `mariadb`, `pgsql`, `sqlsrv` connections and dead Redis section (zero env var references, cache/queue/session all default to `database` driver). Dropped `Illuminate\Support\Str` import (only used by Redis prefix). Verified all 12 scheduled commands in `routes/console.php` — all exist and are active. Cleaned `config/pulse.php`: removed 3 Telescope ignore patterns (Telescope not installed). Standard boilerplate stores (cache: memcached/redis/dynamodb/octane, queue: beanstalkd/sqs/redis) left untouched — Laravel framework defaults, not dead app code.

Removed `BCRYPT_ROUNDS` and `BROADCAST_CONNECTION` from `.env.example` — zero PHP references (config/hashing.php and config/broadcasting.php don't even exist, no broadcast events in app). Verified all 6 remaining unscheduled commands (AuditRestaurantCuisines, BackfillRestaurantPhotos, DeduplicateRestaurants, RestoreDatabaseCommand, BackupDatabaseCommand, QuotaStatusCommand) are NOT dead — all have dedicated test suites; db:backup and db:restore are used in deploy.yml.

Next: scan `config/app.php` for dead providers/aliases no longer used in this stack (e.g. no Blade means no BladeCompiler provider, no broadcasting means no BroadcastServiceProvider). Also check for dead middleware declarations in `bootstrap/app.php`.

## Log
1. Removed commented-out `database` provider from `config/auth.php` — dead boilerplate, only the `eloquent` provider and `web` guard are actively used.
2. Removed from `.env.example`: `GOOGLE_PLACES_API_KEY`, `OUTSCRAPER_API_KEY`, `FOURSQUARE_API_KEY`, `OUTSCRAPER_CACHE_TTL_HOURS`, `GOOGLE_CACHE_TTL_HOURS`, `FOURSQUARE_CACHE_TTL_HOURS`, `LIVE_SEARCH_FOURSQUARE_TIMEOUT` — all zero PHP references.
3. Removed `@tailwindcss/forms` from `package.json` devDependencies — zero references (Tailwind 4 handles form styles natively). Re-ran `npm install`.
4. Removed `autoprefixer` from `package.json` devDependencies — no postcss.config exists, Tailwind 4 + lightningcss handle prefixing natively. Re-ran `npm install` (3 packages removed).
5. Removed `postcss` from `package.json` devDependencies — no postcss.config, no source references, Tailwind 4 + Vite plugin handle everything natively. Re-ran `npm install`.
6. Full PHP codebase scan (grep for every class name): all 19 services, 22 controllers, 3 middleware, 7 models, 2 jobs, 2 resources, 1 trait, 1 support class are actively referenced. 4 dead commands found: `SearchAuditCommand`, `BackfillRestaurantLocation`, `ValidateRestaurantData`, `CopySqliteToMysql` (all zero references, no tests, never scheduled).
7. Removed `app/Console/Commands/SearchAuditCommand.php` — zero references, zero tests, never scheduled in `routes/console.php`. 563 tests pass.
8. Removed `app/Console/Commands/BackfillRestaurantLocation.php` — zero references, zero tests, never scheduled. 563 tests pass, build clean.
9. Removed `app/Console/Commands/ValidateRestaurantData.php` — zero references, zero tests, never scheduled. 563 tests pass, build clean.
10. Removed `app/Console/Commands/CopySqliteToMysql.php` — zero references, zero tests, never scheduled. 563 tests pass.
11. Cleaned `config/database.php` — removed dead `mariadb`, `pgsql`, `sqlsrv` connections and unused Redis section (zero references in .env or app config; cache/queue/session all default to `database` driver). Removed `Illuminate\Support\Str` import (only used for Redis prefix). File went from 187 lines to 89. 563 tests pass, build clean.
12. Verified all 12 scheduled commands in `routes/console.php` — every one maps to an existing command file. `inspire` is built-in Laravel. No dead scheduled commands.
13. Scanned all 14 config files for dead sections. Removed 3 Telescope ignore patterns from `config/pulse.php` — Telescope is not in composer.json and has zero app references. Verified `google_custom_search` in services.php is actively used by RestaurantWebsiteScraperService. Verified `restaurant-finder.cuisines` is actively used by EnrichRestaurants and RestaurantEnrichmentService. 563 tests pass, build clean.
14. Removed `BCRYPT_ROUNDS` and `BROADCAST_CONNECTION` from `.env.example` — zero PHP references (config/hashing.php and config/broadcasting.php don't exist; no `ShouldBroadcast` imports in app/). Verified all 6 remaining unscheduled artisan commands (AuditRestaurantCuisines, BackfillRestaurantPhotos, DeduplicateRestaurants, RestoreDatabaseCommand, BackupDatabaseCommand, QuotaStatusCommand) are NOT dead — all have dedicated test suites; `db:backup` and `db:restore` are additionally referenced in `.github/workflows/deploy.yml`. 563 tests pass, build clean.

