# Iteration Notes

## Goal
add unit coverage for LiveSearchService.search() orchestration

## State
Three orchestration tests now cover the `search()` pipeline end-to-end:
1. `test_search_orchestration_produces_scored_sorted_bounded_results` — unscoped search.
2. `test_scoped_search_stamps_cuisine_match_and_confidence_filters_output` — scoped search.
3. `test_multi_source_merge_dedup_collapses_same_venue_and_preserves_distinct` — multi-source merge: 3 venues from serpapi + overpass (2 sources), same-name/same-location "China Palace" dedup-collapses to 1 merged venue carrying data from both sources (`description` from SerpApi, `cuisines` from Overpass), "Golden Dragon" survives separately, results are scored + sorted.

Next: add orchestration test covering the dedup phone-number fast-path (venues matching by last-10 digits of phone + proximity).

## Log
- Iteration 1: Added end-to-end orchestration test for unscoped search → scored, sorted, bounded output.
- Iteration 2: Added scoped orchestration test — verifies `cuisine_match` stamps on every result, "Cuisine Match" signal in `score_breakdown` for on-cuisine venues, and score-descending sort on the full pipeline output.
- Iteration 3: Added multi-source merge orchestration test — 3 venues from serpapi+overpass dedup into 2 via crossSourceDedup, verifies merged venue carries data from both sources (description + cuisines).
