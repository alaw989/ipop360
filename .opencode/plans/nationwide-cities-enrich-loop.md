# Nationwide City Expansion + Continuous Enrich Loop

## What

Expand from 18 to 77 US cities and run the free-source enrich loop continuously to seed nationwide restaurant data.

## Changes

### 1. `config/restaurant-finder.php` — Expand cities array

Replace the current 18-city list with 77 cities covering all 50 states:

- **Existing (18):** New York, Los Angeles, Chicago, Houston, Phoenix, San Francisco, Seattle, Austin, Denver, Miami, Portland, Nashville, Atlanta, Boston, Dallas, San Diego, Philadelphia, Las Vegas
- **New (59):** Albuquerque, Anchorage, Baltimore, Billings, Birmingham, Boise, Buffalo, Burlington, Charleston (SC), Charleston (WV), Charlotte, Cheyenne, Cincinnati, Cleveland, Colorado Springs, Columbus, Des Moines, Detroit, El Paso, Fargo, Fresno, Hartford, Honolulu, Indianapolis, Jackson (MS), Jacksonville, Kansas City, Little Rock, Louisville, Manchester (NH), Memphis, Milwaukee, Minneapolis, New Orleans, Oklahoma City, Omaha, Orlando, Pittsburgh, Portland (ME), Providence, Raleigh, Reno, Richmond, Sacramento, Salt Lake City, San Antonio, Sioux Falls, Spokane, St. Louis, Tampa, Trenton, Tucson, Virginia Beach, Washington DC, Wichita

### 2. `scripts/enrich-loop.sh` — Already created

Runs `restaurants:enrich --all-cities --free-only` then `restaurants:score` in a loop.
- `--dry-run` flag added
- `--once` for single pass
- Default hourly cadence

### 3. No other changes needed

- Free APIs (BizData, Overpass, Socrata) have no quota limits
- SerpApi is skipped via `--free-only`
- The nightly `--throttled` enrichment still runs at 04:00 UTC for SerpApi quality signals
- 77 cities × 15 cuisines = 1,155 combos × ~10s ≈ 3.2 hrs per full pass

## Verification

- `vendor/bin/pint --test` — style check
- `php artisan test` — all 406+ tests green
- `php artisan restaurants:enrich --all-cities --free-only --once` — dry run or real run
