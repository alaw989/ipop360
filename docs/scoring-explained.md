# How Restaurant Rankings Work

Every restaurant in iPop360 gets a **popularity score** between 0 and 1. Higher
score = higher rank. This document explains how that score is computed.

## The short version

Four factors determine a restaurant's rank, in order of importance:

| Factor | Weight | What it measures | Data source |
|---|---|---|---|
| **Quality** | 60% | Bayesian-weighted rating (rating × review count credibility) | SerpApi (Google Maps) |
| **Proximity** | 20% | How close the restaurant is to the user | User's lat/lng |
| **Award** | 15% | Whether it has a Michelin or other recognized award | Wikidata (free) |
| **Completeness** | 5% | How many descriptive fields are filled in | OSM / BizData |

If a restaurant is popular nearby and well-described on the web, it rises. If
it has a Michelin star, that's a strong bonus. If it has no rating data at all,
proximity and completeness still produce a meaningful sort.

## The problem this solves

The old system had 65% of its weight tied to paid APIs (Google Places, Outscraper).
With no API keys configured, most of the score was dead weight — a restaurant with
no data at all still scored ~0.25. Worse, a restaurant with one 5-star review could
beat a restaurant with 4.7 stars and 5,000 reviews.

The new design fixes both: **no data = score of 0**, and a Bayesian formula
prevents review-count gaming.

## The signals in detail

### Quality (60%) — the anchor signal

The quality signal folds rating and review count into one number using a
**Bayesian shrink**:

```
Q = (reviews / (reviews + 50)) × rating + (50 / (reviews + 50)) × credible_mean
```

- **Many reviews** (e.g., 500): `500/550 = 91%` of the score comes from the
  restaurant's own rating, `9%` from the credible mean.
- **Few reviews** (e.g., 3): `3/53 = 6%` from its rating, `94%` from the mean.
  The score is "shrunk" toward the average — the data isn't trusted yet.
- **No rating at all**: the quality signal drops out entirely for that venue.
  The remaining signals are redistributed (see below).

The **credible mean** is the average rating of restaurants in the collection
that have 50+ reviews. This prevents a few high-rated outliers from pulling
the mean up.

### Proximity (20%)

```
score = 1 / (1 + distance_km / 2)
```

- At 0 km distance → score = 1.0
- At 2 km distance → score = 0.5
- At 10 km distance → score ≈ 0.17

The decay curve means nearby restaurants get a strong bump, but once you're
past ~5 km the difference between two faraway venues is small.

### Award (15%)

Straightforward boolean: 1 if the restaurant has a Michelin star or similar
award in Wikidata, 0 otherwise. Sparse coverage (most venues correctly return
0), but when it fires it's a meaningful differentiator.

### Completeness (5%)

Fields checked: name, address, phone, latitude, longitude, price_range,
website_url, photo_url, features, social_links. A restaurant with all 10
populated scores 1.0 on this signal. One with only name and address scores
0.2. This rewards well-described listings regardless of their ratings.

## Active-set renormalization

Not every signal applies to every restaurant. A venue might have no rating,
or the user might not have shared their location. Instead of assigning 0 to
missing signals, the system **drops them entirely and redistributes the weight**.

Example — restaurant with all signals present:

| Signal | Raw weight | Renormalized | Contribution |
|---|---|---|---|
| Quality | 0.60 | 0.60 | high |
| Proximity | 0.20 | 0.20 | medium |
| Award | 0.15 | 0.15 | small |
| Completeness | 0.05 | 0.05 | tiny |
| **Total** | **1.00** | **1.00** | |

Example — restaurant with no rating and no proximity:

| Signal | Raw weight | Renormalized | Contribution |
|---|---|---|---|
| Quality | 0.60 | — (dropped) | — |
| Proximity | 0.20 | — (dropped) | — |
| Award | 0.15 | 0.75 | high |
| Completeness | 0.05 | 0.25 | low |
| **Total** | — | **1.00** | |

The active set always sums to 1.0. The score is **never penalized** for missing
data — it's just less opinionated.

## Sort modes

The frontend offers four sort modes. All default to `popularity_score DESC`:

| Mode | Primary sort | Tiebreaker |
|---|---|---|
| Best Match (default) | `popularity_score DESC` | — |
| Nearest | distance ASC | `popularity_score DESC` |
| Rating | `google_rating DESC` | `popularity_score DESC` |
| Most Reviews | `google_review_count DESC` | `popularity_score DESC` |

## Free-first design

The entire scoring system works **without any paid API keys**. The SerpApi key
(~50 free searches/month) enables the quality signal, but without it the active
set (proximity + completeness + award = 0.40) is renormalized to 1.0 and
produces a proximity-leaning sort. Paid signals (Outscraper busyness, Google
Places) are opt-in bonuses, never required.

## Configuration

All weights and parameters are env-overridable in `.env`:

| Variable | Default | Effect |
|---|---|---|
| `RANK_WEIGHT_QUALITY` | 0.60 | Quality signal weight |
| `RANK_WEIGHT_PROXIMITY` | 0.20 | Proximity signal weight |
| `RANK_WEIGHT_HAS_AWARD` | 0.15 | Award signal weight |
| `RANK_WEIGHT_DATA_COMPLETENESS` | 0.05 | Completeness signal weight |
| `RANK_QUALITY_PRIOR` | 50 | Review count threshold for Bayesian shrink |
| `RANK_QUALITY_MEAN_FALLBACK` | 4.0 | Fallback credible mean when no venue has 50+ reviews |
| `RANK_PROXIMITY_SCALE_KM` | 2.0 | Distance decay scale |

## Code

- Scoring engine: `app/Services/PopularityScoreService.php`
- CLI recompute: `php artisan restaurants:score {--city=}`
- Runs automatically: daily at 02:00 UTC (see `routes/console.php`)
- Technical reference: `docs/ranking-metrics.md`
