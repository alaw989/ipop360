# How Restaurant Rankings Work

Every restaurant in iPop360 gets a **popularity score** between 0 and 1. Higher
score = higher rank. This document explains how that score is computed.

> Accuracy note (spec-104): earlier versions of this doc described a 4-signal
> 60/20/15/5 model. The real system has 10 weighted signals (see
> `config/restaurant-finder.php`) with the values below. Keep docs and config
> in sync — if you change one, change the other.

## The short version

Ten signals carry weight. In order of how much they move the rank on a typical
restaurant:

| Signal | Raw weight | What it measures | Data source |
|---|---|---|---|
| **Quality** | 0.35 | Bayesian-weighted rating (rating × review credibility) | SerpApi (Google Maps) |
| **Website Traffic** | 0.20 | Clicks on the website link from search results | engagement tracking |
| **Social Presence** | 0.20 | Distinct social platforms found on the venue's own site | website social scrape |
| **Proximity** | 0.15 | How close the restaurant is to the user (live search only) | user's lat/lng |
| **Page Views** | 0.10 | Detail-page views | engagement tracking |
| **Award** | 0.05 | Whether it has a Michelin star in Wikidata | Wikidata (free) |
| **Cuisine Match** | 0.50 (live scoped search only) | Matches the searched cuisine | name/type match |
| **Data Completeness** | 0.05 | Fraction of descriptive fields filled | OSM / BizData / scrapers |
| **Social Link Clicks / Menu Clicks** | 0.05 each | Engagement on those link types | engagement tracking |

Weights are **renormalized** over a row's active signals (below), so the raw
values above are not the final split.

> spec-104 rebalance: `social_links_count` was raised 0.10→0.20 and `has_award`
> trimmed 0.10→0.05. Verified on live data: rated venues stay above unrated
> (no overlap), and the previously clumped unrated cohort now differentiates
> (spread 2× wider), so a sparse city's #1/#2/#3 are meaningfully ordered.

## The problem this solves

The old system had most of its weight tied to paid APIs (Google Places,
Outscraper). With no keys configured, a restaurant with no data still scored
~0.25, and one 5-star review could beat 4.7 stars with 5,000 reviews.

The current design: **missing data drops the signal entirely** (never a
guessed 0), and a **Bayesian shrink** prevents review-count gaming.

## The signals in detail

### Quality (0.35) — the anchor signal

Folds rating and review count into one number:

```
Q = (reviews / (reviews + 50)) × rating + (50 / (reviews + 50)) × credible_mean
```

- **Many reviews** (e.g., 500): 91% of Q comes from the venue's own rating.
- **Few reviews** (e.g., 3): 94% comes from the credible mean — the data isn't
  trusted yet.
- **No rating at all**: the quality signal is **dropped entirely** for that
  venue. Because a missing rating leaves only low-weight signals active, an
  unrated venue's ceiling is far below a rated venue's floor — by design,
  rated venues rank above unrated ones.

The **credible mean** is the average rating of restaurants with 50+ reviews in
the scored collection (excluding the 0,0 null-island artifact).

### Website Traffic (0.20) and Page Views (0.10)

Engagement signals. A `log(1+n)` count normalized against the collection max.
They only activate once clicks exist — a 0 count is treated as "no data" and
the weight redistributes. (spec-104 fixed the pipeline so engagement actually
flows; previously only ~5 of 6,500 restaurants had any clicks.)

### Proximity (0.15) — live search only

```
score = 1 / (1 + distance_km / 2)
```

- 0 km → 1.0 · 2 km → 0.5 · 10 km → ≈0.17

Proximity requires a `distance` value, which only `scopeNearby` / live search
provide. **The persisted daily score never includes it.**

### Social Presence (0.20)

Count of distinct platforms (instagram, facebook, tiktok, twitter, youtube)
found by regex on the venue's own website. Note: **0 means "no data"** — the
venue may not be scraped, have no website, or genuinely have no links. The
signal rewards presence; it never penalizes absence.

### Award (0.05)

Boolean: 1 if the venue has a Michelin star in Wikidata (matched by name + ≤1.5
km proximity). Currently 0 for the app's restaurant population — Michelin
venues are rare and mostly don't overlap the dataset. A weekly
`restaurants:refresh-awards` backfill keeps it populated.

### Cuisine Match (0.50) — live scoped searches only

Stamped by `LiveSearchService` on a cuisine-scoped search: 1.0 for a name
keyword match, 0.5 for a type/description match, 0.0 for a scoped non-match
(kept active at 0 so renormalization is uniform). Never active in the
persisted daily score.

### Data Completeness (0.05)

Ratio over **10** fields: name, address, phone, latitude, longitude,
price_range, website_url, photo_url, features, social_links_count. A fully
populated listing scores 1.0 on this signal.

## Active-set renormalization

Not every signal applies to every restaurant. Missing signals are **dropped**
and their weight redistributed over the ones present.

**Rated venue, no engagement, no social** (common):

| Signal | Raw | Renormalized |
|---|---|---|
| Quality | 0.35 | 0.78 |
| Award | 0.05 | 0.11 |
| Completeness | 0.05 | 0.11 |

**Unrated venue, no engagement** (the majority of rows):

| Signal | Raw | Renormalized |
|---|---|---|
| Completeness | 0.05 | 0.50 |
| Award | 0.05 | 0.50 (contributes 0 — no awards in population) |

**Unrated venue with social links** (the differentiator):

| Signal | Raw | Renormalized |
|---|---|---|
| Social Presence | 0.20 | 0.67 |
| Completeness | 0.05 | 0.17 |
| Award | 0.05 | 0.17 (contributes 0) |

The consequence is a deliberate **rated/unrated split**: rated venues floor
near 0.54 (quality renormalizes to ~0.78 × a high normalized rating), unrated
venues cap near 0.30 without social or ~0.40 with strong social. Rated venues
still rank above unrated, but the unrated cohort now differentiates — a venue
with real social presence outranks one without, so a sparse city's top entries
are meaningfully ordered rather than all clumped near 0.20.

## Sort modes

| Mode | Primary sort | Tiebreaker |
|---|---|---|
| Best Match (default) | `popularity_score DESC` | — |
| Nearest | distance ASC | `popularity_score DESC` |
| Rating | `google_rating DESC` | `popularity_score DESC` |
| Most Reviews | `google_review_count DESC` | `popularity_score DESC` |
| Social Presence | `social_links_count DESC` | `popularity_score DESC` |
| Website Traffic | `website_clicks_count DESC` | `popularity_score DESC` |

## Free-first design

The scoring works without paid keys: quality (SerpApi) is the only signal that
needs one, and its absence just drops the signal. Free sources (BizData,
Overpass, Socrata, Wikidata, social scrape) fill everything else.

## Configuration

| Variable | Default | Effect |
|---|---|---|
| `RANK_WEIGHT_QUALITY` | 0.35 | Quality signal weight |
| `RANK_WEIGHT_PROXIMITY` | 0.15 | Proximity signal weight |
| `RANK_WEIGHT_HAS_AWARD` | 0.05 | Award signal weight |
| `RANK_WEIGHT_DATA_COMPLETENESS` | 0.05 | Completeness signal weight |
| `RANK_WEIGHT_CUISINE_MATCH` | 0.50 | Cuisine match (live scoped search) |
| `RANK_WEIGHT_SOCIAL_LINKS_COUNT` | 0.20 | Social presence |
| `RANK_WEIGHT_WEBSITE_CLICKS` | 0.20 | Website traffic |
| `RANK_WEIGHT_PAGEVIEWS` | 0.10 | Page views |
| `RANK_WEIGHT_SOCIAL_LINK_CLICKS` | 0.05 | Social link clicks |
| `RANK_WEIGHT_MENU_CLICKS` | 0.05 | Menu clicks |
| `RANK_QUALITY_PRIOR` | 50 | Review threshold for Bayesian shrink |
| `RANK_QUALITY_MEAN_FALLBACK` | 4.0 | Fallback credible mean |
| `RANK_PROXIMITY_SCALE_KM` | 2.0 | Distance decay scale |

## Code

- Scoring engine: `app/Services/PopularityScoreService.php`
- Driver-agnostic SQL helpers: `app/Support/SqlDialect.php`
- CLI recompute: `php artisan restaurants:score {--city=}`
- Award backfill: `php artisan restaurants:refresh-awards`
- Runs automatically: score daily 02:00, awards weekly Sun 07:00 (`routes/console.php`)
- Technical reference: `docs/ranking-metrics.md`
