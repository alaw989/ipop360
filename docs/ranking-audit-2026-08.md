# Ranking Audit (data-driven popularity-score audit)

**Date**: 2026-08-14
**Branch**: feat/popularity-score-audit
**Tool**: `php artisan ranking:audit` (`app/Console/Commands/RankingAuditCommand.php`, read-only)

This records the findings of the popularity-score audit: the read-only
diagnostic command, the two configuration drifts it surfaced, the weight
rebalance, and the post-rebalance score distribution. The canonical scoring
spec remains `docs/ranking-metrics.md`.

## 1. The diagnostic: `ranking:audit`

```
php artisan ranking:audit [--city=NAME] [--recompute]
```

Read-only. Prints, in order:

1. **Active weight set** — the `ranking.weights` entries with weight > 0, verbatim
   (raw, before per-row renormalization).
2. **Corpus** — total / rated / unrated counts and split (rated = `google_rating > 0`).
3. **Score distribution** — `popularity_score` stats (min/max/mean/median/stddev)
   overall, then per cohort. Default source is the persisted `popularity_score`;
   `--recompute` instead forecasts each row's score under the CURRENT weight set
   (see below).
4. **Cohort overlap** — how far the two cohorts bleed into each other: the rated
   min vs unrated max, plus two directional counts — unrated venues scoring above
   the lowest-rated venue, and rated venues scoring below the highest-unrated
   venue. This is the metric that surfaces the "no-overlap exception" (link-rich
   unrated venues reaching into the rated tail) directly, so every re-run reveals
   whether a weight change widens or closes the gap.
5. **Signal activation** — per-signal row counts where that signal is active.
6. **Score deciles** — p10/p25/p50/p75/p90/p95/p99.

**`--recompute` (leading indicator):** the persisted `popularity_score` only
refreshes on the daily 02:00 score run, so the default distribution is a lagging
indicator. `--recompute` runs `PopularityScoreService::computeAggregates` once
over the corpus, then `calculateBreakdownWithAggregatesFromEloquent` per row to
forecast the distribution under the current weights immediately — the fastest way
to see a weight change's effect without waiting for the scheduler or writing
anything. It reads only the columns the scorer touches (id + the completeness/
rating/social/engagement fields), so a full-corpus recompute stays light.

No writes. This is the data source for weight-rebalancing decisions: it shows
which signals actually fire across the corpus and how wide the spread is.

## 2. Drift #1 — stale `.env` reverted the spec-104 rebalance

`ranking:audit` printed `data_completeness 0.25` and `has_award 0.10`, not the
spec-104 defaults (0.05 / 0.05). Root cause: a deployment `.env` carried a
stale `RANK_WEIGHT_*` block that silently reverted the rebalance:

| Signal | `.env` (stale) | spec-104 default | Effect |
|---|---|---|---|
| `RANK_WEIGHT_DATA_COMPLETENESS` | 0.25 | 0.05 | inflated the always-active completeness signal |
| `RANK_WEIGHT_HAS_AWARD` | 0.10 | 0.05 | dead weight taxed every score |
| `RANK_WEIGHT_GOOGLE_RATING` | 0.03 | 0.0 | gave the rated cohort a bonus *outside* `quality` |
| `RANK_WEIGHT_GOOGLE_REVIEW_COUNT` | 0.02 | 0.0 | same — double-counted ratings |

The `.env` overrides were corrected to the spec-104 defaults. Because `.env` is
gitignored, this divergence is invisible to CI — the audit command is what made
it visible.

## 3. Drift #2 — `DEFAULT_WEIGHTS` still listed `yelp`

While fixing drift #1, a lock test (`tests/Feature/RankingWeightsConfigTest.php`)
caught a second drift: `PopularityScoreService::DEFAULT_WEIGHTS` still listed
`yelp_rating` / `yelp_review_count` at 0.0, while `config/restaurant-finder.php`
had removed them entirely. The two yelp keys were removed from `DEFAULT_WEIGHTS`
so the "fallback mirrors the config exactly" contract holds. No score change —
their weight was already 0.

## 4. Locking the change

`RankingWeightsConfigTest` pins BOTH weight sources to the spec-104 set:

- `test_config_file_weight_defaults_match_spec_104` — clears `RANK_WEIGHT_*`
  from the environment, resets the `Env` repository, and re-`require`s the config
  file to observe its default literals.
- `test_service_default_weights_match_spec_104` — reflects `DEFAULT_WEIGHTS`.

Either source can no longer drift from the documented set without a failing test.

## 5. Post-rebalance distribution (local DB, 8,178 rows — historical)

> Note: this local SQLite snapshot was later reset (the local DB now holds only 2
> rows), so these figures are historical. The live MySQL results in §6 supersede
> them.

```
Corpus        Total 8178  Rated 1553 (19.0%)  Unrated 6625 (81.0%)

Score         overall  n=8178  min 0.0730  max 0.7827  mean 0.4529  median 0.4286  sd 0.1292
              rated    n=1553  min 0.5434  max 0.7827  mean 0.6860  median 0.6706  sd 0.0477
              unrated  n=6625  min 0.0730  max 0.5714  mean 0.3983  median 0.3993  sd 0.0660

Signal        quality 19.0%  social_links 54.9%  website_clicks 0.1%  pageviews 0.8%
              social_link_clicks 0.0%  menu_click 0.0%  has_award 0.0%  data_completeness 100.0%

Deciles       p10 0.337  p25 0.359  p50 0.429  p75 0.500  p90 0.667  p95 0.730  p99 0.762
```

Observations:

- **Rated still dominates unrated on average** — mean gap 0.288 (0.686 vs 0.398).
  The intended split holds: dropping the raw `google_rating`/`google_review_count`
  bonuses (0.03/0.02 → 0) and trimming `has_award` (0.10 → 0.05) kept the rated
  cohort on top.
- **"No overlap" has a small exception now.** Unrated max (0.5714) exceeds rated
  min (0.5434): 105 of 6,625 unrated venues (1.6%) score above the lowest-rated
  venue. Cause: `social_links_count` raised to 0.20 is now the dominant signal in
  the unrated active set (`{social, data_completeness, has_award}`), so a
  link-rich unrated venue renormalizes social to ~0.67 weight and reaches into
  the rated tail. This is the intended cost of the rebalance — social presence
  is the one non-quality signal that differentiates the 81% unrated cohort — but
  the old "no overlap" wording in `config/restaurant-finder.php` / `docs/ranking-metrics.md`
  is no longer strictly accurate and should be softened.
- **`has_award` fires 0.0%** — dead weight (Wikidata Michelin venues don't
  overlap the dataset). Resolved by dropping it from `ALWAYS_ACTIVE` (now active
  only when `true`), so its 0.05 no longer taxes every row.
- **Engagement block is real but dormant** — `website_clicks` 0.1%, `pageviews`
  0.8%, `social_link_clicks` 0.0%, `menu_click` 0.0%. The 0.40 engagement weight
  has a working pipeline but ~1.5 pageviews/day is not enough traffic to light it
  up yet.

## 6. Live MySQL results (production data, 8,075 rows)

`ranking:audit` and `ranking:audit --recompute` were run against the production
MySQL DB (over an SSH tunnel; read-only, no writes). The live `.env` has no
`RANK_WEIGHT_*` overrides, so the deployed config already uses the spec-104
defaults — but the persisted `popularity_score` still reflects the *pre-rebalance*
scorer (this feature branch was not yet deployed when those scores were written),
so `--recompute` shows the post-rebalance forecast on live data.

```
Corpus        Total 8075  Rated 1553 (19.2%)  Unrated 6522 (80.8%)
```

Persisted (`ranking:audit`, pre-rebalance scores):

```
Score         overall  n=8075  min 0.0711  max 0.8570  mean 0.3523  median 0.2893  sd 0.1780
              rated    n=1553  min 0.5273  max 0.8570  mean 0.6952  median 0.6464  sd 0.0918
              unrated  n=6522  min 0.0711  max 0.7475  mean 0.2706  median 0.2653  sd 0.0504
Overlap       rated min 0.5273  unrated max 0.7475
              unrated above rated min: 9 (0.1%)   rated below unrated max: 916 (59.0%)
Signal        quality 19.2%  social_links 53.3%  website_clicks 0.1%  pageviews 0.8%
              social_link_clicks 0.0%  menu_click 0.0%  has_award 0.0%  data_completeness 100.0%
Deciles       p10 0.2178  p25 0.2500  p50 0.2893  p75 0.3500  p90 0.6433  p95 0.7895  p99 0.8278
```

Recomputed (`ranking:audit --recompute`, current weights):

```
Score         overall  n=8075  min 0.1650  max 0.9641  mean 0.4978  median 0.3471  sd 0.2000
              rated    n=1553  min 0.5708  max 0.9641  mean 0.7677  median 0.7003  sd 0.1152
              unrated  n=6522  min 0.1650  max 0.8000  mean 0.4335  median 0.3184  sd 0.1577
Overlap       rated min 0.5708  unrated max 0.8000
              unrated above rated min: 1999 (30.7%)  rated below unrated max: 878 (56.5%)
Deciles       p10 0.2784  p25 0.3184  p50 0.5000  p75 0.6485  p90 0.7606  p95 0.8882  p99 0.9313
```

Findings:

- **The rebalance lifts unrated far more than rated on live data** — rated mean
  0.6952 → 0.7677 (+0.07) vs unrated 0.2706 → 0.4335 (+0.16), so the mean gap
  narrows 0.425 → 0.334. Rated still dominates on average, but the unrated cohort
  is no longer concentrated near the floor: `social_links_count` (0.20) now
  spreads 53.3% of the corpus (4,300 link-bearing venues) across a wide range.
- **The "no-overlap" exception is ~20× larger than the local 1.6% figure
  suggested.** Under current weights, **30.7%** of unrated venues (1,999) score
  above the lowest-rated venue, and 56.5% of rated venues score below the highest
  unrated venue. The local 1.6% figure in §5 was a *persisted* (pre-rebalance)
  number, i.e. stale — it did not reflect the current weight set. On live data
  the overlap is substantial, so the softened claim "rare link-rich unrated
  venues enter the rated tail" understates reality: with `social_links` at 0.20,
  the two cohorts interleave heavily. The mean gap holds, but the "rated
  dominates unrated" guarantee does **not** hold pointwise in the tails.
- **Signal activation on live matches local** — quality 19.2%, social_links
  53.3%, engagement dormant (website 0.1% / pageviews 0.8% / rest 0.0%), has_award
  0.0%. Same conclusion: engagement + award are dead weight in practice.
- **Persisted score is a genuinely lagging indicator here** — overall median
  0.2893 (persisted) vs 0.3471 (recompute); the daily 02:00 run had not yet
  applied the new weights when the persisted scores were written.

## 7. Next steps

- Soften the "no overlap" claim in the config comment + `docs/ranking-metrics.md`
  to "rated dominates unrated; rare link-rich unrated venues can enter the rated tail".
- ~~Re-run `ranking:audit` on the live MySQL DB (not SQLite) to confirm the same
  distribution on production data.~~ DONE — see §6. The live corpus (8,075 rows,
  19.2% rated) matches the local split; signal activation is identical.
- ~~Revisit `has_award` once Wikidata coverage broadens (or drop it from
  `ALWAYS_ACTIVE` so the 0.05 stops taxing every row until a real award exists).~~
  DONE: `has_award` is now active only when `true` (see
  `tests/Unit/PopularityScoreServiceTest::test_has_award_inactive_when_false_active_when_true`).
- ~~The audit only reads the persisted score, which is stale until the 02:00 run.~~
  DONE: `ranking:audit --recompute` now forecasts the distribution under the
  current weights directly (leading indicator, no write). Use it to compare a
  proposed weight change's effect against the persisted distribution in one shot.
- ~~Re-run `ranking:audit --recompute` on the live MySQL DB to confirm the
  forecast distribution on production data.~~ DONE — see §6. The forecast shows
  a 30.7% cohort overlap on live (vs the stale local 1.6%), a finding worth a
  follow-up rebalance discussion (not part of this audit's scope).
- ~~Follow-up rebalance (item #1, 2026-08-26).~~ DONE — see below.

## 8. Rebalance follow-up (2026-08-26, item #1)

Re-running `ranking:audit --recompute` against the **current** live corpus
(40,483 rows, up from the 8,075 the audit measured) showed the §6 concern had
already resolved itself: **0% cohort overlap** (rated min 0.2896, unrated max
0.1513). The corpus growth changed the social-links distribution (max
`social_links_count` dropped to 5), so the "link-rich unrated venues enter the
rated tail" overlap is no longer present.

However, the audit surfaced a **mirror-image problem**: the unrated cohort —
88% of the corpus — was **over-compressed** (all 35,640 venues scored
0.03-0.15, sd 0.028). Root cause: `social_links_count` (weight 0.20, the one
signal that differentiates the unrated cohort) maxed at 5 but shared the
review-scale log floor of 500, squashing its normalized range to ~0.11-0.29.
This is the "unrated clumping" spec-104 raised social to 0.20 to fix; it had
regressed as the corpus grew.

**Fix shipped:** a per-signal `social_links_log_floor` (default 10, env
`RANK_SOCIAL_LINKS_LOG_FLOOR`) gives `social_links_count` a scale-appropriate
log denominator. Verified against live data:

| Metric | before (floor 500) | after (floor 10) |
|---|---|---|
| unrated sd | 0.028 | **0.0728** |
| unrated max | 0.1513 | **0.2593** |
| unrated mean | 0.0803 | **0.1263** |
| overlap | 0% | **0%** (unrated max 0.2593 < rated min 0.3077) |
| rated mean | 0.3812 | **0.4084** |

The unrated cohort is ~2.6× more differentiated while rated continues to
cleanly dominate — no overlap reintroduced. Floor 8 spreads further (sd 0.080)
but tightens the safety margin; floor 5 reintroduces overlap (1.1%), so 10 is
the chosen default.

## 9. Follow-up re-check (2026-09-01) — verified-social-links + engagement clicks

Queued from the 2026-08-24 verified-social-links change (PR #136, see
`project-state.md`): re-run against live prod after the log-floor fix (§8) had
a week to settle, to check (a) whether verified-only `social_links_count` held
the cohort-overlap fix, and (b) whether `directions_clicks_count`/
`call_clicks_count` (added to the weight table the same day) show any live
activation. Ran `ranking:audit` and `ranking:audit --recompute` directly on
the droplet (SSH, read-only, no writes) against the current corpus.

```
Corpus        Total 40814  Rated 4853 (11.9%)  Unrated 35961 (88.1%)
```

Recomputed (current weights):

```
Score         overall  n=40814  min 0.0273  max 0.5391  mean 0.1599  median 0.3426  sd 0.1142
              rated    n=4853  min 0.3078  max 0.5391  mean 0.4081  median 0.4495  sd 0.0232
              unrated  n=35961  min 0.0273  max 0.2741  mean 0.1264  median 0.0727  sd 0.0728
Overlap       rated min 0.3078  unrated max 0.2741
              unrated above rated min: 0 (0.0%)   rated below unrated max: 0 (0.0%)
Signal        quality 11.9%  social_links_count 52.5%  directions_clicks_count 3 (0.0%)
              call_clicks_count 3 (0.0%)  website_clicks 0.0%  pageviews 0.3%  has_award 0.0%
```

Findings:

- **Cohort overlap holds at 0%** (§8's log-floor fix, corpus now 40,814 vs
  40,483) — matches §8 almost exactly (unrated sd 0.0728 both times, unrated
  mean 0.1264 vs 0.1263, rated mean 0.4081 vs 0.4084). The verified-only
  `social_links_count` gate (PR #136) is compatible with the fix and hasn't
  reintroduced the 30.7% overlap from §6. Can't isolate its individual
  contribution from the log-floor change (both landed close together and the
  corpus kept growing), but the combined state is stable and healthy.
- **`directions_clicks_count`/`call_clicks_count` are live but negligible**:
  3 rows each out of 40,814 (0.0%). The wiring works (PR #136 added them to
  the weight table at 0.05 each) but real click volume hasn't materialized —
  same "dormant engagement" pattern as `website_clicks_count`/`pageviews_count`
  in §5/§6.
- **Persisted-score overlap looked alarming (99.5% unrated-above-rated-min) but
  is a staleness artifact, not a regression.** 91/4853 (1.9%) rated rows had a
  persisted score at the corpus floor despite a real rating (checked restaurant
  8652: 4.1★/323 reviews, persisted score 0.0273). Its `score_breakdown` JSON
  has no `quality` signal entry at all — confirms the row was still unrated
  *at the time of its last score:run*, and got its rating from enrichment
  afterward. Self-heals on the next `restaurants:score` run; not a scoring bug
  (verified via `score_breakdown`, not assumed).
