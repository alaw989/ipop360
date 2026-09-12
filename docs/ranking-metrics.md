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
| **Website social scrape** | free | instagram/facebook/tiktok/twitter/youtube links, HTTP-verified | `social_links_count` |
| **Overture Maps places** | free, open data (monthly release; attribution in footer) | existence confidence, operating status (closures), multi-source corroboration, phones, websites, socials, addresses | corroboration + empty-field fill (`overture:import`) |
| **Engagement tracking** | free | website/directions/call/pageview/menu/social clicks | engagement counters (all 7 now scored) |
| Foursquare Places | basic free; **rating is premium** | name, address, phone, website, categories | parked |
| Google Places | paid | rating, review_count, photo | optional bonus |
| Outscraper | paid | popular-times busyness | optional bonus |
| Yelp Fusion | — | — | **removed** |

### Overture Maps import (data-integrity phase 3)

`overture:import` runs monthly on the 25th at 20:00 UTC, with `--apply`.

- **Extract.** A single DuckDB pass (`App\Services\Overture\DuckDb`, which self-installs a checksum-pinned CLI) keeps only US food places from the newest public release (~1.8M rows) in a local Parquet file.
- **Match.** Each active restaurant is matched to the place at the same location with `VenuePipeline::venuesMatch`: the same phone, or a name at least 85% similar, within 200 m.
- **On a match**, the importer:
  - records `overture_id`, `overture_confidence` (0–1 existence), `overture_sources` (how many independent datasets Overture merged for the place) and `overture_status`;
  - fills only empty fields: phone (only when its area code fits the restaurant's state), address, website (never a blocked/reference host; identity-checked later by the daily `restaurants:verify-websites`) and validated social profiles;
  - writes each fill's provenance to `field_sources`, e.g. `{"phone": "overture:2026-08-19.0"}`;
  - deactivates a place Overture marks `permanently_closed`, through `field_quarantine` (reason `closed_per_overture`, reversible).

## Weight set (raw — renormalized per row over active signals)

| Signal | Weight | Source | Always active? |
|---|---|---|---|
| `quality` | **0.35** | SerpApi (Bayesian rating, folds in reviews) | only with a quality key **and** a rating |
| `evidence` | **0.35** | Overture corroboration + verified website/socials + OSM detail | **only when `quality` is not** (every unrated venue) |
| `website_clicks_count` | **0.20** | engagement | **yes** (0.0 when absent) |
| `social_links_count` | 0.0 | website social scrape | retired as a standalone signal (a component of `evidence`) |
| `proximity` | **0.15** | User coordinates | live search only (`distance` present) |
| `pageviews_count` | **0.10** | engagement | **yes** (0.0 when absent) |
| `has_award` | **0.05** | Wikidata (free) | only when `true` (a false award drops out) |
| `cuisine_match` | **0.50** | live scoped-search stamp | only on cuisine-scoped live search |
| `data_completeness` | **0.05** | field coverage | **yes** (always computable) |
| `social_link_clicks_count` / `menu_click_count` | 0.05 each | engagement | **yes** (0.0 when absent) |
| `directions_clicks_count` / `call_clicks_count` | 0.05 each | engagement | **yes** (0.0 when absent) |
| `popular_times_avg_busyness` | 0.0 | Outscraper (optional) | min-max, opt-in |
| `yelp_rating` / `yelp_review_count` | 0.0 | — | removed |
| `google_rating` / `google_review_count` | 0.0 | — | folded into `quality` |

`quality` **leads** the ranking: a single Bayesian-weighted rating that folds
review count in, so a high rating from few reviews shrinks toward the credible
mean instead of winning (see *Bayesian quality* below). For a rated venue with
no engagement/social data, quality renormalizes to ~0.78. Weights need not sum
to 1 because the active set is always renormalized (see *Redistribution*).

Engagement signals total 0.50 (was 0.40) and are **always active** (0.0 when
a count is 0), mirroring `data_completeness` and `cuisine_match`'s
active-at-zero design. They previously only activated once a count went
above 0 — but because these six counters are mutated live by the *current*
user's own pageview/click, and search results are rescored fresh on every
request, that meant a restaurant's first real engagement click instantly
expanded its active-weight denominator by up to 0.50 while barely moving the
numerator, diluting `quality`/`proximity`/`cuisine_match` and crashing its
rank right after a user interacted with it (fixed; see
`history.md`). spec-104 fixed the engagement pipeline (previously only
~5 of 6,500 rows had any), so this weight now has a path to fire for real
traffic. As of the 2026-08 ranking audit, live activation is still near-zero
at current traffic (`website_clicks` ~0.1%, `pageviews` ~0.8%,
`social_link_clicks`/`menu_click` ~0.0%) — this weight block is scaffolding
for future traffic growth, not
currently load-bearing on the live corpus.

`directions_clicks_count` and `call_clicks_count` are tracked live
(`EngagementController`) and aggregated nightly
(`restaurants:update-engagement`) exactly like the other five engagement
counters, but were omitted from the scoring weight table with no documented
reason — an oversight, now closed. They're weighted the same as
`menu_click_count`/`social_link_clicks_count` (0.05 each).

spec-104 rebalance (data-driven, verified on live data): `social_links_count`
raised 0.10→0.20 so the 76% unrated cohort differentiates (spread 2× wider);
`has_award` trimmed 0.10→0.05 because it reads 0 for the whole population and
was taxing every score. Rated stays above unrated on average (mean gap 0.29),
but the old "no overlap" guarantee no longer strictly holds: ~1.6% of unrated
venues with heavy social links score above the lowest-rated venue (see
`docs/ranking-audit-2026-08.md`).

## Verified-presence evidence (data-integrity phase 4)

Ratings are a walled garden. SerpApi's 250 calls a month is the only free source, so about 90% of venues have no rating. The old ranking ordered that 90% by raw social-link count, which turned out to be dominated by junk and corporate links.

`evidence` is the stand-in for `quality` on unrated venues. It has the same weight (0.35) and is active **only** when `quality` is not, so rated and unrated venues are scored on one scale. It is a weighted mean (`PopularityScoreService::evidenceFor`):

| Component | Weight | Value |
|---|---|---|
| Independent-source corroboration | 30% | `overture_sources`: 1 → 0.5, 2 → 0.8, 3+ → 1.0 |
| Existence confidence | 20% | `overture_confidence` (0–1) |
| Website identity | 20% | verified 1.0 · brand homepage 0.6 · unchecked 0.3 · none 0 |
| Verified location socials | 15% | `social_links_count` / 3, capped at 1 (brand accounts never count) |
| OSM detail | 15% | OSM feature/amenity tags present |

The result is scaled to at most `ranking.evidence_cap` (`RANK_EVIDENCE_CAP`, default **0.85** ≈ a 4.25★ Bayesian quality). Bayesian shrinkage puts almost every rated venue at or above about 0.86, so:

- a fully evidenced unknown outranks only weakly rated venues;
- well-reviewed venues stay on top;
- among unrated venues, a confirmed going concern outranks an unverified listing.

A place Overture reports `permanently_closed` scores 0. Unrated venues show a **"Not yet rated"** badge on their cards, and the score breakdown's "Verified Presence" line explains what was verified.

Calibration: `ranking:audit --recompute` on a fresh prod clone taken 2026-09-11, after the phase-2 cleanup, the Overture fill and the #175 repair. 41,069 active venues, 4,185 rated.
- 26,080 venues (63.5% of the corpus) are Overture-corroborated.
- Unrated scores range 0.02–0.34 (median 0.22). Rated scores range 0.31–0.54 (median 0.41).
- 1,017 unrated venues (2.8%) score above the lowest-rated venue. 15 rated venues (0.4%) score below the best unrated one.
- Chains are 0.8% of the top 500 unrated venues, against 7.7% of all unrated. Corporate accounts don't lift them.
- Austin, New York and Dallas: every rated venue still outranks every unrated one. The first unrated venue ranks 257th of 718 in Austin (behind all 256 rated), 30th in New York and 19th in Dallas.

The best unknowns currently land at about a 3.4★ equivalent. One reason is that 35k stored websites have not been identity-checked yet, so they score "unchecked" (0.3) instead of "verified" (1.0). `restaurants:verify-websites` clears that backlog at 2,000 a day, and evidence rises as it does. Revisit the cap once the backlog is done.

| `RANK_EVIDENCE_CAP` | Austin: rated venues the first unknown outranks | Corpus: rated venues below the best unknown |
|---|---|---|
| 0.85 (default) | 0 | 15 (0.4%) |
| 1.0 | 29, the best a 4.4★ with 596 reviews | 1,005 (24%) |

At 1.0, unknowns pass well-reviewed venues, which is too aggressive.

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
  `social_link_clicks_count`, `menu_click_count`, `directions_clicks_count`,
  `call_clicks_count`: `log(1+n) / log(1+denom)` where
  `denom = max(collection max, floor)`. The floor is `log_review_floor` (500,
  tuned for review counts) EXCEPT for `social_links_count`, which uses its own
  `social_links_log_floor` (default 10, env `RANK_SOCIAL_LINKS_LOG_FLOOR`).
  `social_links_count` is a small integer (0-5) — the 500 review floor
  squashed its normalized range to ~0.11-0.29 and re-compressed the unrated
  cohort (the 88% without a Google rating that social presence is meant to
  differentiate). The scale-appropriate floor spreads that cohort (~2.6× wider
  distribution on live data) without reintroducing rated/unrated overlap
  (see the item #1 rebalance).
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
| price_range | `price_range` | SerpApi (Google) / Overpass; never the restaurant's website (below) |
| website_url | `website_url` | BizData / OSM / backfill |
| photo_url | `photo_url` | BizData / Wikimedia image enrichment |
| features | `features` | OSM tag extraction |
| social_links_count | `social_links_count` | website social scrape (verified-only) |

A field counts as populated when non-null and (for strings) non-empty. A fully
free-enriched row typically reaches 9/10 (social_links_count often 0) ≈ 0.90.

**AI guesses don't count (2026-09 data-integrity overhaul).** The AI enrichment
model has no browsing, so a `price_range`/`phone` it "filled" is a guess, as is
an AI-written `website_url` that never passed `WebsiteIdentityVerifier`. Those
fields (identified via `ai_metadata.fields_updated` + `website_identity`) count
as unpopulated. `EnrichRestaurantWithAi` no longer writes phone/price at all
(kept in `ai_metadata.inferred`), so this only discounts legacy rows.

**Where prices come from (2026-09-13).** Only 10.7% of active restaurants have a
price: 86% of rated rows, 2.2% of unrated ones, because the price arrives with
the SerpApi/Google rating (~250 lookups a month). The restaurant's own website
is **not** a price source. Checked against Google's level on 49 restaurants
that publish both, a site's JSON-LD `priceRange` matched exactly 45% of the time
(within one level 98%), and 73% of sites said "$$", a common site-builder
default; 16 of the 21 restaurants Google calls "$" claimed "$$". The
backfill-websites cache phase tags each price it fills with its cache source in
`field_sources.price_range` (`serpapi`, `preview`, `bizdata`).

## Social link verification (spec-109)

Before spec-109, `social_links_count` counted any platform URL
`extractSocialLinks` regex-matched on the restaurant's own website HTML —
with no check the discovered profile was actually reachable. A dead link,
typo'd handle, or stale placeholder counted identically to a live profile.

Now, `ScrapeRestaurantSocialLinks`/`BackfillRestaurantWebsites` call
`RestaurantWebsiteScraperService::verifyProfileUrl()` for each discovered URL
(SSRF-guarded, short-timeout HEAD with a ranged-GET fallback for platforms
that reject HEAD) and stamp `restaurant_social_links.verified_at` on success
or `last_check_failed_at` on failure. **A failed check does not delete the
row** — it's kept for recall/debugging; only `verified_at` gates scoring.
`social_links_count` is recomputed via `Restaurant::countScoredSocialLinks()`,
which counts only `verified_at IS NOT NULL` rows when
`RANK_REQUIRE_VERIFIED_SOCIAL` (default true) is on — set it false to revert
to the pre-spec-109 raw distinct-platform count if verification proves too
strict/flaky in practice.

A weekly `restaurants:reverify-social-links` job re-checks previously-checked
links (oldest-checked first) so link rot decays a dead profile out of scoring
instead of counting it forever, and gives a previously-failed link another
chance in case of a transient failure or a since-fixed site.

Tightening this signal to verified-only links is expected to reduce the
30.7%-unrated-above-lowest-rated overlap noted below (fewer unrated venues
will have a nonzero `social_links_count`); re-run `ranking:audit` after
deploy to confirm.

### Only real, location-scoped profiles count (2026-09 data-integrity overhaul)

Reachability was not enough: the raw regex stored the `xmlns:fb` namespace URI
`facebook.com/2008` (1,929 prod rows), the Meta Pixel `facebook.com/tr`
(1,048), id-less `profile.php` (467), share/intent endpoints and website-builder
footer accounts — all answer HTTP 200, so all were "verified". And every chain
location carried the corporate account (528 Domino's rows with @dominos).

- `extractSocialLinks` now takes candidates from JSON-LD `sameAs`, then `<a
  href>`, then a raw-HTML fallback, and every candidate must pass
  `App\Support\SocialProfileUrl::canonicalize` (real profile shapes only).
- `SocialLinkRecorder` (the single write path) marks a URL shared by
  `data_integrity.social_brand_min_restaurants` (default 5) or more restaurants
  as `scope = brand`. `countScoredSocialLinks()` counts only verified
  `scope = location` links, so corporate marketing no longer lifts chains over
  independents.

## Redistribution

The service keeps the **skip-missing → divide-active-by-its-sum** mechanism: a
signal's weight is only counted when the restaurant has a value for it (and, for
paid signals, when a key is configured). The active weights are renormalized so
they sum to 1.0 across whatever is present.

- `has_award` is **active only when `true`** — a `false`/0 award means "no
  award" and drops out of the active set, so its 0.05 weight is redistributed
  to the signals that actually fire instead of taxing every row (spec-104
  audit: 0% of the corpus is awarded, so the old always-active-zero was pure
  dead weight).
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

## Homepage "Trending restaurants" quality floor

This is a **display-layer curation rule**, not part of `PopularityScoreService`
itself — it gates which persisted rows are even *eligible* to appear in the
homepage's "Trending restaurants / Top-ranked dining spots right now" section
(`HomeController::getHomepageData()`), on top of the existing
`orderByDecayedScore()` ordering.

Before this, the only gate was `is_active` — a barely-populated, unrated,
photo-less row could surface under "Top-ranked" purely by having a recent
`updated_at` (low decay). `Restaurant::scopeTrendingQualified()`
(`app/Models/Restaurant.php`) requires `popularity_score >=
min_popularity_score` (default 0.4) **and** a non-empty `photo_url` by
default — `require_photo` does the real filtering in practice (a Trending
card needs an image); the score floor is defense-in-depth for future data.

Config: `config/restaurant-finder.php` → `trending` (env `TRENDING_*`).
Kill-switch: `TRENDING_REQUIRE_QUALITY_FLOOR=false` reverts to the old
gate-free behavior.

`HomeController` applies this in a three-tier fallback so the section is
never empty: (1) city-scoped + quality-qualified, (2) global +
quality-qualified, (3) global unfiltered (last resort, only reached if the
floor filters out the entire corpus). `location` in the API/Inertia payload
is only set on tier 1 — the frontend (`PopularRestaurants.vue`) uses this to
switch the subtitle between "Top-ranked dining spots right now" (genuinely
local) and "Popular across iPop360" (any fallback tier), so a data-thin city
is never silently shown an unlabeled non-local list.

## Limitations / future work

- `has_award` is boolean — Michelin stars are not distinguished by count. The
  weekly `restaurants:refresh-awards` backfill keeps it populated; it currently
  reads 0 for the whole population (Michelin venues don't overlap the dataset).
- Wikidata coverage is sparse — most venues correctly return `false`.
- Engagement is real but low-traffic: the site produces ~1.5 pageviews/day, so
  the 0.40 engagement weight block is still mostly dormant in practice. It now
  has a working pipeline (spec-104) and will weight in as traffic grows.
- Review **recency** has no free source and was dropped.
