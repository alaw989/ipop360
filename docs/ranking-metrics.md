# Ranking Metrics (free-first)

This document specifies how `App\Services\PopularityScoreService` turns raw
restaurant attributes into the single `popularity_score` shown to users. The
overriding design constraint: **the score must be 100% computable from free data
sources.** The quality signal — ratings + review counts — is sourced from
**SerpApi's `google_maps` engine** (free tier **250 searches/mo**, 80% circuit
breaker, 30-day cache), the only free source that provides ratings. Google
Places and Outscraper remain optional paid bonuses (weights 0.0 by default).

> Accuracy note (spec-104): update this doc alongside
> `config/restaurant-finder.php` and `docs/scoring-explained.md`. They drift
> easily and have before.

## Why the old weights were broken

The previous weighting gave paid APIs 65% of the score:

| Signal | Old weight | Source | Problem |
|---|---|---|---|
| `google_review_count` | 0.30 | Google (paid) | required a key |
| `google_rating` | 0.15 | Google (paid) | required a key |
| `popular_times_avg_busyness` | 0.20 | Outscraper (paid) | required a key |
| `yelp_review_count` | 0.15 | Yelp (free) | removed from project |
| `yelp_rating` | 0.10 | Yelp (free) | removed from project |
| `review_recency_score` | 0.05 | **none** | hardcoded `0.5` placeholder |
| `has_michelin_star` | 0.05 | **none** | referenced a column that **did not exist** |

With no keys configured, ~65% of the weight was dead. Because the old code
treated the `0.5` recency placeholder as always-active, an empty row still scored
**~0.25** from dead weight — worse than meaningless.

The current design fixes both: only signals with real data carry weight, and a
row with no data scores **0.0**.

## Free-source landscape (2026)

| Source | Cost | Provides | Role |
|---|---|---|---|
| **SerpApi google_maps** | free **250/mo** | **rating, review_count, price, phone, website, coords** | **primary quality source** — the only free ratings |
| **BizData API** | free | name, address, phone, website, location (OSM mirror) | coverage / completeness |
| **Overpass / OSM** | free, no key | existence, location, cuisine, hours, address | coverage backfill + data-completeness |
| **Wikidata SPARQL** | free, no key | Michelin/award records (low coverage) | `has_award` |
| **Nominatim (OSM)** | free | geocoding | `GeolocationService` |
| **Website social scrape** | free | instagram/facebook/tiktok/twitter/youtube links | `social_links_count` |
| **Engagement tracking** | free | website/directions/call/pageview/menu clicks | engagement counters |
| Foursquare Places | basic free; **rating is premium** | name, address, phone, website, categories | parked |
| Google Places | paid | rating, review_count, photo | optional bonus |
| Outscraper | paid | popular-times busyness | optional bonus |
| Yelp Fusion | — | — | **removed** |

## Weight set (raw — renormalized per row over active signals)

| Signal | Weight | Source | Always active? |
|---|---|---|---|
| `quality` | **0.35** | SerpApi (Bayesian rating, folds in reviews) | only with a quality key **and** a rating |
| `website_clicks_count` | **0.20** | engagement | only when clicks exist (>0) |
| `social_links_count` | **0.20** | website social scrape | only when links found (>0) |
| `proximity` | **0.15** | User coordinates | live search only (`distance` present) |
| `pageviews_count` | **0.10** | engagement | only when views exist (>0) |
| `has_award` | **0.05** | Wikidata (free) | **yes** (`false` is a legitimate signal) |
| `cuisine_match` | **0.50** | live scoped-search stamp | only on cuisine-scoped live search |
| `data_completeness` | **0.05** | field coverage | **yes** (always computable) |
| `social_link_clicks_count` / `menu_click_count` | 0.05 each | engagement | only when clicks exist (>0) |
| `popular_times_avg_busyness` | 0.0 | Outscraper (optional) | min-max, opt-in |
| `yelp_rating` / `yelp_review_count` | 0.0 | — | removed |
| `google_rating` / `google_review_count` | 0.0 | — | folded into `quality` |

`quality` **leads** the ranking: a single Bayesian-weighted rating that folds
review count in, so a high rating from few reviews shrinks toward the credible
mean instead of winning (see *Bayesian quality* below). For a rated venue with
no engagement/social data, quality renormalizes to ~0.78. Weights need not sum
to 1 because the active set is always renormalized (see *Redistribution*).

Engagement signals total 0.40 but only activate once clicks exist. spec-104
fixed the engagement pipeline (previously only ~5 of 6,500 rows had any), so
this weight now has a path to fire for real traffic.

spec-104 rebalance (data-driven, verified on live data): `social_links_count`
raised 0.10→0.20 so the 76% unrated cohort differentiates (spread 2× wider);
`has_award` trimmed 0.10→0.05 because it reads 0 for the whole population and
was taxing every score. Rated stays above unrated (no overlap).

## Bayesian quality

`quality` replaces the old separate `google_rating` + `google_review_count`
signals because a plain linear rating lets a 5.0★/3-review outlier beat a
4.7★/5000-review venue. The Bayesian form shrinks a rating toward a credible
mean `C`, weighted by review count `v` against a prior `m`:

```
Q = (v / (v + m)) · R + (m / (v + m)) · C        # on the 0–5 scale
quality_normalized = Q / 5                         # → 0–1
```

- `R` = `google_rating`, `v` = `google_review_count`.
- `m` = `RANK_QUALITY_PRIOR` (default **50**): reviews needed before a venue's own rating dominates the prior.
- `C` = mean `google_rating` over the collection's **credible** venues (`reviews ≥ m`), excluding the 0,0 null-island artifact. Falls back to `RANK_QUALITY_MEAN_FALLBACK` (default **4.0**) when no credible venue exists.

A venue with a rating but 0 reviews shrinks fully toward `C`. A venue with **no
rating deactivates `quality` entirely** — and because the remaining signals are
low-weight, unrated venues are structurally capped (~0.30) far below rated ones
(~0.54 floor). This split is intended: rated venues rank above unrated.

## Per-signal normalization

Normalization is **per-method** (`PopularityScoreService::METHODS`):

- **Bayesian** → `quality` (see above).
- **Log count** → `website_clicks_count`, `pageviews_count`, `social_links_count`,
  `social_link_clicks_count`, `menu_click_count`: `log(1+n) / log(1+denom)`
  where `denom = max(collection max, floor)`.
- **Inverse distance** → `proximity`: `1 / (1 + distance_km / scale_km)`
  (scale defaults to 2.0; at 2km score = 0.5, at 0 distance score = 1.0).
- **Completeness ratio** → `data_completeness` (0–1, already normalized).
- **Boolean** → `has_award` (`1.0` / `0.0`).
- **Passthrough** → `cuisine_match` (stamped 0–1; clamped).
- **Min-max** → retained for `popular_times_avg_busyness` only (opt-in, weight 0.0).
- **Linear ÷ 5** → dormant `google_rating` / `yelp_rating` (weight 0; feeds `quality`).

## data_completeness

A ratio of **populated descriptive fields ÷ 10**, computed inline from each
row — no dedicated column. The ten fields:

| Completeness field | Column | Source |
|---|---|---|
| name | `name` | any |
| address | `address` | BizData / OSM |
| phone | `phone` | BizData / OSM |
| latitude | `latitude` | BizData / OSM |
| longitude | `longitude` | BizData / OSM |
| price_range | `price_range` | BizData / Overpass |
| website_url | `website_url` | BizData / OSM / backfill |
| photo_url | `photo_url` | BizData / Wikimedia image enrichment |
| features | `features` | OSM tag extraction |
| social_links_count | `social_links_count` | website social scrape |

A field counts as populated when non-null and (for strings) non-empty. A fully
free-enriched row typically reaches 9/10 (social_links_count often 0) ≈ 0.90.

## Redistribution

The service keeps the **skip-missing → divide-active-by-its-sum** mechanism: a
signal's weight is only counted when the restaurant has a value for it (and, for
paid signals, when a key is configured). The active weights are renormalized so
they sum to 1.0 across whatever is present.

- `has_award = false` **stays active** — `false` is a legitimate signal ("no
  Wikidata award record found") and earns its 0.0 contribution. Today every row
  in the population has `has_award = false`, so this always-active-zero absorbs
  10–20% of weight that no row ever collects.
- `data_completeness` is **always active** — a 0 ratio is a valid measurement.
- Engagement + social signals use **log_count**, so a value of 0 is treated as
  "no data" and drops the signal entirely (never a penalizing 0).
- The `google_*` raw columns are never weighted directly; only `quality` (which
  consumes them) counts, and only when `SERPAPI_API_KEY` is set.

## Configurability

Weights and log knobs live in `config/restaurant-finder.php` under `ranking`,
each with an `env()` override (`RANK_WEIGHT_*`). `PopularityScoreService` reads
them in the constructor with a `DEFAULT_WEIGHTS` fallback for pure unit tests.
The config's inline comment documents the renormalization reality — keep it in
sync when weights change.

## Limitations / future work

- `has_award` is boolean — Michelin stars are not distinguished by count. The
  weekly `restaurants:refresh-awards` backfill keeps it populated; it currently
  reads 0 for the whole population (Michelin venues don't overlap the dataset).
- Wikidata coverage is sparse — most venues correctly return `false`.
- Engagement is real but low-traffic: the site produces ~1.5 pageviews/day, so
  the 0.40 engagement weight block is still mostly dormant in practice. It now
  has a working pipeline (spec-104) and will weight in as traffic grows.
- Review **recency** has no free source and was dropped.
