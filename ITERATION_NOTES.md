# Iteration Notes

## Goal
find and remove dead code and unused configuration across the app

## State
Removed 7 deprecated env vars from `.env.example`, `@tailwindcss/forms` and `autoprefixer` from `package.json`.

Next: scan the rest of `package.json` devDependencies for dead packages. Candidates: `postcss` (Tailwind 4 uses the Vite plugin directly, no postcss.config needed), `vue` in devDependencies (already in dependencies), `@vue/server-renderer` (SSR entry might not need it via devDeps — check ssr config). Also scan for dead PHP classes/configs/helpers.

## Log
1. Removed from `.env.example`: `GOOGLE_PLACES_API_KEY`, `OUTSCRAPER_API_KEY`, `FOURSQUARE_API_KEY`, `OUTSCRAPER_CACHE_TTL_HOURS`, `GOOGLE_CACHE_TTL_HOURS`, `FOURSQUARE_CACHE_TTL_HOURS`, `LIVE_SEARCH_FOURSQUARE_TIMEOUT` — all zero PHP references.
2. Removed `@tailwindcss/forms` from `package.json` devDependencies — zero references in any source/config file (Tailwind 4 handles form styles natively). Re-ran `npm install` to clean lockfile.
3. Removed `autoprefixer` from `package.json` devDependencies — no postcss.config exists, Tailwind 4 + lightningcss handle prefixing natively. Re-ran `npm install` (3 packages removed).
