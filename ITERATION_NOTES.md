# Iteration Notes

## Goal
find and remove dead code and unused configuration across the app

## State
Removed 7 deprecated env vars from `.env.example` — all from paid sources (Google Places, Outscraper, Foursquare) that were removed in spec-066 revert. Zero PHP references to any of these keys.

Next: remove `@tailwindcss/forms` from package.json (unused Tailwind 3 plugin; Tailwind 4 handles form styles natively).

## Log
1. Removed from `.env.example`: `GOOGLE_PLACES_API_KEY`, `OUTSCRAPER_API_KEY`, `FOURSQUARE_API_KEY`, `OUTSCRAPER_CACHE_TTL_HOURS`, `GOOGLE_CACHE_TTL_HOURS`, `FOURSQUARE_CACHE_TTL_HOURS`, `LIVE_SEARCH_FOURSQUARE_TIMEOUT` — all zero PHP references.
