# Iteration Notes

## Goal
find and remove dead code and unused configuration across the app

## State
Removed 7 deprecated env vars from `.env.example`, and `@tailwindcss/forms`, `autoprefixer`, `postcss` from `package.json`. Verified `@vue/server-renderer` is still used by `ssr.ts`, `@tailwindcss/typography` by `app.css`, and `vue` is not duplicated (only in devDependencies, not dependencies — fine for Vite).

Next: scan PHP side for dead classes, config files, and helpers. Candidates: check `app/` and `config/` for classes/files with zero references. Also check `routes/` web/api routers for registered routes pointing to dead controllers/methods.

## Log
1. Removed from `.env.example`: `GOOGLE_PLACES_API_KEY`, `OUTSCRAPER_API_KEY`, `FOURSQUARE_API_KEY`, `OUTSCRAPER_CACHE_TTL_HOURS`, `GOOGLE_CACHE_TTL_HOURS`, `FOURSQUARE_CACHE_TTL_HOURS`, `LIVE_SEARCH_FOURSQUARE_TIMEOUT` — all zero PHP references.
2. Removed `@tailwindcss/forms` from `package.json` devDependencies — zero references (Tailwind 4 handles form styles natively). Re-ran `npm install`.
3. Removed `autoprefixer` from `package.json` devDependencies — no postcss.config exists, Tailwind 4 + lightningcss handle prefixing natively. Re-ran `npm install` (3 packages removed).
4. Removed `postcss` from `package.json` devDependencies — no postcss.config, no source references, Tailwind 4 + Vite plugin handle everything natively. Re-ran `npm install`.
