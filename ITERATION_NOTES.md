# Iteration Notes

## Goal
find and remove dead code and unused configuration across the app

## State
Removed 7 deprecated env vars from `.env.example` and `@tailwindcss/forms` from `package.json`. `autoprefixer` is also possibly dead — it's still in devDependencies but Tailwind 4/lightningcss likely handles prefixing natively.

Next: verify `autoprefixer` is unused (check for autoprefixer references in CSS configs, postcss config, or vite config). If dead, remove it and re-run `npm install`.

## Log
1. Removed from `.env.example`: `GOOGLE_PLACES_API_KEY`, `OUTSCRAPER_API_KEY`, `FOURSQUARE_API_KEY`, `OUTSCRAPER_CACHE_TTL_HOURS`, `GOOGLE_CACHE_TTL_HOURS`, `FOURSQUARE_CACHE_TTL_HOURS`, `LIVE_SEARCH_FOURSQUARE_TIMEOUT` — all zero PHP references.
2. Removed `@tailwindcss/forms` from `package.json` devDependencies — zero references in any source/config file (Tailwind 4 handles form styles natively). Re-ran `npm install` to clean lockfile.
