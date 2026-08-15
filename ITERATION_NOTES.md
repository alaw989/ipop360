# Iteration Notes

## Goal
fail-open enrichment: when the SerpApi provider is exhausted, change the throttled enrichment break -> continue so every city x cuisine combo still runs the free sources + AI/photo/social/website enrichment; keep quota_exhausted=true in the log + admin so the outage stays surfaced; ratings backfill later via the existing need-ordering (buildCityCuisineGrid). Tests: provider exhausted -> combos still processed via free sources, 0 SerpApi calls, quota_exhausted true; all-unrated collection still scores finite + differentiated (lock in PopularityScoreService renormalization). Frontend: expose serpapi_exhausted shared prop; RELABEL (not hide) the Rating sort option ('ratings temporarily unavailable') so the UI doesn't shift on recovery; neutral SEO copy for the 'real reviews / accurate ratings' phrasing while exhausted.

## State

### Done (this iteration)
- **All-unrated renormalization lock-in test** (`PopularityScoreServiceTest`) —
  `test_all_unrated_collection_still_scores_finite_and_differentiated`. When SerpApi is
  exhausted, every fail-open-enriched row has no rating, so the leading `quality` signal
  (0.35) drops out for the entire collection. The test locks in that the always-active
  `data_completeness` + free `social_links_count` signals renormalize over the shrunken
  active set: a rich free listing (9/10 completeness) vs a sparse one (2/10) both score
  finite + non-zero, the rich row strictly outranks the sparse one (no tie), and
  `Quality` stays OUT of the active set (its weight redistributed, not a dead contributor).
  No production code change needed — the renormalization path already behaved correctly.
- **Ratings backfill** is already served by the existing `buildCityCuisineGrid`
  need-ordering (unrated cities sort first, so a recovered provider refills ratings
  where they're missing first). Confirmed no code change is required there.

### Verified
- `php artisan test` — 816 passed (3452 assertions)
- `vendor/bin/pint --test tests/Unit/PopularityScoreServiceTest.php` — clean
- (prior iterations) `npx vitest run` — 71 files / 1068 tests; `npx vue-tsc --noEmit` — clean

### Next (remaining goal work)
- None. All goal items are complete:
  1. fail-open `break`→`continue` (iteration 1)
  2. `quota_exhausted` surfaced in log + admin (iterations 1–2)
  3. `serpapi_exhausted` shared prop (iteration 3)
  4. Rating sort relabel (iteration 4)
  5. neutral SEO copy (iteration 5)
  6. all-unrated finite + differentiated lock-in (this iteration)

### Gotchas
- Provider exhaustion state lives in the app cache (`serpapi_provider_exhausted`, 24h
  retry), NOT the DB — so the admin flag reflects the live cache, not a persisted row.
  In array-cache tests `markProviderExhausted()` / `Cache::forget()` drive it directly.
- The `where`-closure rewrite of the missing-data query must stay behavior-identical:
  it reproduces "at least one of the four gaps" exactly (no HAVING, no aggregate).
- `serpapi_exhausted` (provider "out of searches") is DISTINCT from
  `enrich_budget_exhausted` (monthly spend) and `circuit_breaker_tripped` (30d calls)
  — the three are shown side-by-side in the same SerpApi Quota card grid.

## Log
- 2026-08-15: iteration 1 — fail-open `break`→`continue` + free-source processing for
  provider-exhausted combos; unit test rewritten to assert the new behavior.
- 2026-08-15: iteration 2 — admin dashboard surfaces `serpapi_exhausted` (controller
  + Vue banner + tests); fixed SQLite `HAVING`-on-non-aggregate bug in the missing-data
  query that blocked any admin dashboard feature test.
- 2026-08-15: iteration 3 — global `serpapi_exhausted` Inertia shared prop
  (`HandleInertiaRequests` + `PageProps` type + feature test); unlocks the Rating
  relabel + SEO copy work on the public search UI.
- 2026-08-15: iteration 4 — relabeled the Rating sort option to "Ratings temporarily
  unavailable" when exhausted (`Welcome.vue`, `Search.vue`, `Restaurants/Index.vue`
  `sortOptions` → `computed` keyed off `usePage().props.serpapi_exhausted`); added
  `usePage` mocks + relabel assertions to the three page spec files.
- 2026-08-15: iteration 5 — neutral SEO copy while exhausted: `Welcome.vue`,
  `Search.vue`, `Restaurants/Index.vue` meta descriptions drop "real reviews / accurate
  ratings" (and "rating" in Search's "by cuisine, rating, and price") when
  `serpapi_exhausted`; `useSeo` mocks in the three specs now pass options through for
  call-arg assertions + new "SEO description" describe blocks.
- 2026-08-15: iteration 6 — all-unrated finite + differentiated renormalization lock-in
  test (`PopularityScoreServiceTest::test_all_unrated_collection_still_scores_finite_and_differentiated`).
  Goal complete.
