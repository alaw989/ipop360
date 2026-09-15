# iPop360 Constitution

> A restaurant discovery app that ranks venues using a free-first scoring blend — real ratings, review counts, proximity, data completeness, and Michelin awards — powered by OpenStreetMap, BizData, Socrata, and Wikidata, with optional SerpApi Google Maps ratings.

**Ralph Wiggum Version:** 3f15f0f (https://github.com/fstandhartinger/ralph-wiggum)

---

## Context Detection

**Ralph Loop Mode** (started by `ralph-loop*.sh`):
- Pick the ONE highest-priority incomplete spec from `specs/` (lowest number whose Status is not COMPLETE) — **exactly one spec per iteration; never batch** (multiple specs overflow the context window and the loop fails the iteration before DONE)
- Implement, test, commit, push
- Output `<promise>DONE</promise>` only when 100% complete
- Output `<promise>ALL_DONE</promise>` when no work remains

**Interactive Mode** (normal conversation):
- Be helpful, guide decisions, create specs

---

## Core Principles

- **Free-first ranking** — scoring must produce meaningful results with $0 data sources. Paid API signals are pure bonus, never required.
- **Data quality** — aggregate from multiple sources, deduplicate intelligently, persist enriched data. Test coverage is non-negotiable.
- **User experience** — polished Vue frontend, responsive, dark mode, fast results. Every ranking decision should make sense to the user.

---

## Technical Stack

- **Backend:** Laravel 13, PHP 8.4, MySQL (prod) / SQLite (dev+test), Inertia.js v2
- **Frontend:** Vue 3 (Composition API), TypeScript, Vite 8, Tailwind CSS 4, shadcn-vue 2, Leaflet 1.9
- **Testing:** PHPUnit 12 (1482 tests, 6299 assertions) + vitest (1132 tests)
- **Infra:** DigitalOcean droplet, GitHub Actions CI/CD
- **Live-search sources (4):** BizData (free), Overpass/OSM (free), Socrata (free), SerpApi google_maps (~250/mo on the current plan — the ONLY rating source and the only paid one; 80% circuit breaker, 30-day cache)
- **Removed paid APIs (do NOT re-add — see `memory/paid-ratings-no-free-lunch.md`):** Foursquare Places (rating fields are premium-tier, $18.75/1k from call 1), Google Places (~$32/1k Nearby), Outscraper. Their service classes + read-path/enrichment wiring were deleted (spec-066 revert + cleanup). Ratings are a walled garden — SerpApi is the only free source.

---

## Autonomy

YOLO Mode: ENABLED
Git Autonomy: ENABLED

---

## Architecture Overview

### Data flow
```
Free APIs (BizData, Overpass, Socrata) + SerpApi (paid bonus)
  → LiveSearchService (parallel fetch, merge, dedup, score)
  → RestaurantEnrichmentService (persist, paid bonus, awards, score)
  → PopularityScoreService (10 weighted signals, renormalized per row)
  → DB query via RestaurantController (byPopularity scope)
```

### Scoring signals (current — spec-104; authoritative detail in `docs/scoring-explained.md`)
| Signal | Weight | Status |
|---|---|---|
| Quality (Bayesian rating × credibility) | 0.35 | serpapi-rated venues only |
| Website Traffic (clicks) | 0.20 | engagement tracking |
| Verified Presence | 0.35 | unrated venues only — stands in for Quality |
| Proximity | 0.15 | live search only |
| Page Views | 0.10 | engagement tracking |
| Award (Wikidata Michelin) | 0.05 | free source |
| Cuisine Match | 0.50 | live scoped search only |
| Data completeness | 0.05 | source-agnostic, 9 fields |
| Social link clicks / menu clicks | 0.05 each | engagement tracking |
| Yelp signals | 0.0 | DEAD (removed) |

### Key directories
- `app/Services/` — core business logic (10 services)
- `app/Models/` — Eloquent models (Restaurant, Cuisine, etc.)
- `resources/js/Components/` — Vue components (RestaurantCard, ScoreBreakdown, etc.)
- `resources/js/Pages/` — Vue pages (Welcome, Restaurants/Index, Restaurants/Show)
- `database/migrations/` — schema migrations
- `docs/` — design documentation (ranking-metrics.md, aggregation-plan.md, ranking-improvements.md)

### Running tests
```bash
composer test    # PHP: php artisan config:clear && php artisan test
npm run test     # Frontend: vitest run
```

---

## Specs

Specs live in `specs/` as markdown files. Pick the highest priority incomplete spec (lower number = higher priority). A spec is incomplete if it lacks `## Status: COMPLETE`.

Spec template: https://raw.githubusercontent.com/github/spec-kit/refs/heads/main/templates/spec-template.md

When all specs are complete, re-verify a random one before signaling done.

---

## NR_OF_TRIES

Track attempts per spec via `<!-- NR_OF_TRIES: N -->` at the bottom of the spec file. Increment each attempt. At 10+, the spec is too hard — split it into smaller specs.

---

## History

Append a 1-line summary to `history.md` after each spec completion. For details, create `history/YYYY-MM-DD--spec-name.md` with lessons learned, decisions made, and issues encountered. Check history before starting work on any spec.

---

## Completion Signal

All acceptance criteria verified, tests pass (`php artisan test`), changes committed and pushed → output `<promise>DONE</promise>`. Never output this until truly complete.
