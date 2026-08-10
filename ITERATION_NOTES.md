# Iteration Notes

## Goal
find and remove dead code and unused configuration across the app

## State
Removed 7 deprecated env vars, 3 npm packages, and 1 dead artisan command (`SearchAuditCommand`). Full PHP codebase scan complete: all services, controllers, middleware, models, jobs, resources are actively referenced. 3 more dead commands remain as candidates (`BackfillRestaurantLocation`, `ValidateRestaurantData`, `CopySqliteToMysql`). Config files `database.php` and `auth.php` have dead boilerplate sections.

Next: remove `BackfillRestaurantLocation` (dead command, zero references, no tests).

## Log
1. Removed from `.env.example`: `GOOGLE_PLACES_API_KEY`, `OUTSCRAPER_API_KEY`, `FOURSQUARE_API_KEY`, `OUTSCRAPER_CACHE_TTL_HOURS`, `GOOGLE_CACHE_TTL_HOURS`, `FOURSQUARE_CACHE_TTL_HOURS`, `LIVE_SEARCH_FOURSQUARE_TIMEOUT` — all zero PHP references.
2. Removed `@tailwindcss/forms` from `package.json` devDependencies — zero references (Tailwind 4 handles form styles natively). Re-ran `npm install`.
3. Removed `autoprefixer` from `package.json` devDependencies — no postcss.config exists, Tailwind 4 + lightningcss handle prefixing natively. Re-ran `npm install` (3 packages removed).
4. Removed `postcss` from `package.json` devDependencies — no postcss.config, no source references, Tailwind 4 + Vite plugin handle everything natively. Re-ran `npm install`.
5. Full PHP codebase scan (grep for every class name): all 19 services, 22 controllers, 3 middleware, 7 models, 2 jobs, 2 resources, 1 trait, 1 support class are actively referenced. 4 dead commands found: `SearchAuditCommand`, `BackfillRestaurantLocation`, `ValidateRestaurantData`, `CopySqliteToMysql` (all zero references, no tests, never scheduled).
6. Removed `app/Console/Commands/SearchAuditCommand.php` — zero references, zero tests, never scheduled in `routes/console.php`. 563 tests pass.
