# Iteration Notes

## Goal
data-driven popularity-score audit: add ranking:audit diagnostic, document findings, rebalance weights with tests locking the change

## State
**Iteration 1 — done: added `ranking:audit` diagnostic command.**

- New `app/Console/Commands/RankingAuditCommand.php` (`php artisan ranking:audit {--city=}`), read-only:
  active weight set, rated/unrated cohort split, persisted-score distribution (overall + per cohort,
  min/max/mean/median/stddev), per-signal activation rates, score deciles. No writes.
- New `tests/Feature/RankingAuditCommandTest.php` (4 tests). Pint clean, phpstan clean, full suite 730 green.

**Iteration 2 — done: rebalanced weights (dropped stale `.env` overrides) + locked the spec-104 set with tests.**

- `.env` stale overrides corrected to spec-104 defaults: `RANK_WEIGHT_DATA_COMPLETENESS` 0.25→0.05,
  `RANK_WEIGHT_HAS_AWARD` 0.10→0.05, `RANK_WEIGHT_GOOGLE_RATING` 0.03→0, `RANK_WEIGHT_GOOGLE_REVIEW_COUNT` 0.02→0.
  (`.env` is gitignored; this is the local/live-adjacent rebalance — the persisted-score inputs are now spec-104.)
- New `tests/Feature/RankingWeightsConfigTest.php` (2 tests) locking the default weight set to spec-104 in
  BOTH sources: the config file's `env()` defaults (via env-clear + Env-repo reset + fresh `require`) and
  `PopularityScoreService::DEFAULT_WEIGHTS` (via reflection). 732 tests green, pint + phpstan clean.
- **Drift found & fixed by the lock test:** `DEFAULT_WEIGHTS` still listed `yelp_rating`/`yelp_review_count`
  at 0.0 while `config/restaurant-finder.php` had removed them entirely. Removed the two yelp keys from
  `DEFAULT_WEIGHTS` so the "mirrors config exactly" contract holds. No score change (weight was already 0).

**Iteration 3 — done: documented the audit findings + corrected an inaccurate "no overlap" claim.**

- New `docs/ranking-audit-2026-08.md`: documents the `ranking:audit` command, drift #1 (stale `.env`
  `RANK_WEIGHT_*` block reverted spec-104: data_completeness 0.25→0.05, has_award 0.10→0.05,
  google_rating/google_review_count 0.03/0.02→0), drift #2 (`DEFAULT_WEIGHTS` still listed `yelp_rating`/
  `yelp_review_count`), the lock test, and the post-rebalance distribution.
- Ran `ranking:audit` on local DB (8,178 rows): rated 19.0% / unrated 81.0%; rated mean 0.686 vs unrated
  0.398 (gap 0.288); social_links fires 54.9%, has_award 0.0%, engagement block ~dormant.
- **New finding — the "no overlap" guarantee has a small exception:** unrated max 0.5714 > rated min 0.5434;
  105/6625 unrated venues (1.6%) score above the lowest-rated venue. Cause: social_links_count (0.20) now
  dominates the unrated active set and lifts link-rich unrated venues into the rated tail. Softened the
  "no overlap" wording in `docs/ranking-metrics.md` and the config comment, cross-linked the new doc.

**Iteration 4 — done: dropped `has_award` from `ALWAYS_ACTIVE` (dead 0.05 no longer taxes every row).**

- `PopularityScoreService::ALWAYS_ACTIVE` is now `['data_completeness']` only. Added a `boolean`-method
  branch in `isPresent()`: `has_award` is active only when truthy — `false`/`0`/`null` drop out of the
  active set so its 0.05 weight is redistributed to signals that actually fire (0% of the corpus is awarded).
- New lock test `tests/Unit/PopularityScoreServiceTest::test_has_award_inactive_when_false_active_when_true`;
  updated 3 score-value tests (0.40→0.80, 0.05→0.10, 0.40→0.80) + their comments for the new single-active-signal
  renormalization. Docs synced: `config/restaurant-finder.php` "Always active" bullet, `docs/ranking-metrics.md`
  weight table + "Redistribution" bullet, and the `docs/ranking-audit-2026-08.md` next-step marked DONE.
- Full suite 733 green, pint + phpstan clean.

**Iteration 5 — done: baked the cohort-overlap finding into `ranking:audit` as a first-class metric.**

- New `RankingAuditCommand::printCohortOverlap()` (printed between distribution and signal activation):
  rated min vs unrated max, plus two directional counts — `unrated above rated min` (N + % of unrated)
  and `rated below unrated max` (N + % of rated). Skips gracefully (with a message) when either cohort
  is empty. This makes the iteration-3 "no-overlap exception" (1.6% of unrated venues reaching the rated
  tail) a re-runnable output, so every audit — local or live — reveals whether a weight change widens or
  closes the cohort gap.
- New tests `tests/Feature/RankingAuditCommandTest::test_audit_reports_cohort_overlap` +
  `test_audit_skips_cohort_overlap_when_one_cohort_empty`. Gotcha hit & fixed: `Artisan::output()`
  *clears* the buffer on read, so a second inline call returns `''` — always capture it once into a var.
- Docs synced: `docs/ranking-audit-2026-08.md` §1 now lists the overlap section; the live re-run next-step
  notes it can compare against the local 1.6% figure directly.
- Full suite 735 green (+2), pint + phpstan clean.

**Iteration 6 — done: added `--recompute` to `ranking:audit` (leading indicator, not lagging).**

- New `--recompute` flag: `forecastScores()` runs `PopularityScoreService::computeAggregates`
  once over the corpus, then `calculateBreakdownWithAggregatesFromEloquent` per row, so the
  distribution/overlap/deciles sections show the score under the CURRENT weights immediately —
  no write, no wait for the 02:00 scheduler. Default (persisted `popularity_score`) is unchanged.
  Reads only the scorer-touched columns via `forecastColumns()` (excludes photos/ai_metadata/
  opening_hours + persisted score fields), so a full-corpus recompute stays light.
- `scoreValues()` now takes an optional `id => score` forecast map; the distribution header prints
  `persisted popularity_score` vs `recomputed under current weights` so the two modes can't be confused.
- New test `test_audit_recompute_uses_forecast_not_persisted_scores` (name-only row: persisted 0.999
  vs forecast 0.1000). Verified live on local DB (`--recompute` → forecast distribution printed).
  Full suite 736 green (+1), pint + phpstan clean. Docs synced (`docs/ranking-audit-2026-08.md` §1 + §6).

**Iteration 7 — done: ran the audit on live MySQL and documented the production findings.**

- Ran `ranking:audit` + `ranking:audit --recompute` against the production MySQL DB (8,075 rows,
  19.2% rated) over an SSH tunnel (`ssh -L 3307:127.0.0.1:3306`), read-only. Live `.env` has no
  `RANK_WEIGHT_*` overrides, so persisted scores reflect the *pre-rebalance* scorer (this branch not
  yet deployed), making `--recompute` the first post-rebalance look at production data.
- Key findings: (1) rebalance lifts unrated (+0.16) far more than rated (+0.07), narrowing the mean
  gap 0.425→0.334 — rated still dominates on average; (2) **cohort overlap under current weights is
  30.7%** (1,999 unrated above rated min) vs the stale local 1.6% — the "rare link-rich" wording
  understates it ~20×; (3) signal activation matches local (engagement + award dead); (4) persisted
  score lags (median 0.2893 vs 0.3471 recomputed) — confirms the lagging-indicator concern.
- Documented in `docs/ranking-audit-2026-08.md`: new §6 "Live MySQL results" (both tables + findings),
  §5 marked historical (local DB now reset to 2 rows), §7 Next steps — live re-run items marked DONE.
- No code change this iteration (docs only); the loop gate (`composer test` + phpstan) should stay green.

**Next:** the three goal components (diagnostic, documented findings, rebalanced + test-locked weights)
are complete, including the live re-run. A follow-up (out of this goal's scope) is to discuss whether
the 30.7% live cohort overlap warrants a further `social_links_count` rebalance or a stricter "rated
dominates" guarantee.

## Log

### Iteration 1
Added `ranking:audit` command + tests. Found `.env` weight drift vs spec-104 defaults.

### Iteration 2
Rebalanced `.env` to spec-104 defaults; added `RankingWeightsConfigTest` locking config-file defaults +
`DEFAULT_WEIGHTS` to the spec-104 set; removed stray yelp keys from `DEFAULT_WEIGHTS`.

### Iteration 3
Documented findings in `docs/ranking-audit-2026-08.md`; ran `ranking:audit` on local DB (8,178 rows);
found the "no overlap" guarantee has a ~1.6% exception (link-rich unrated venues enter the rated tail);
softened that claim in `docs/ranking-metrics.md` and cross-linked.

### Iteration 4
Dropped `has_award` from `ALWAYS_ACTIVE`; added `boolean`-method branch in `isPresent()` so a false/0 award
drops out of the active set. New lock test + 3 updated score-value tests + docs sync. 733 green.

### Iteration 5
Baked the cohort-overlap finding into `ranking:audit` as a first-class section (rated-min vs unrated-max +
two directional bleed counts); 2 new tests. Noted the `Artisan::output()` clears-buffer gotcha. 735 green.

### Iteration 6
Added `--recompute` to `ranking:audit` (forecast under current weights via computeAggregates +
calculateBreakdownWithAggregatesFromEloquent, reading only scorer columns); `scoreValues()` accepts an
optional forecast map; distribution header distinguishes persisted vs recomputed. New test + docs sync.
736 green.

### Iteration 7
Ran `ranking:audit` + `--recompute` against live MySQL (8,075 rows) via SSH tunnel; found the cohort
overlap under current weights is 30.7% (vs stale local 1.6%) and that persisted scores lag the forecast.
Documented in `docs/ranking-audit-2026-08.md` §6; marked local §5 historical. Docs-only change.
