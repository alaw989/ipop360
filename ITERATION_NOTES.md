# Iteration Notes

## Goal
add unit coverage for LiveSearchService.search() orchestration

## State
Two orchestration tests now cover the `search()` pipeline end-to-end:
1. `test_search_orchestration_produces_scored_sorted_bounded_results` — unscoped search: verifies `popularity_score`, `score_breakdown`, and score-descending sort.
2. `test_scoped_search_stamps_cuisine_match_and_confidence_filters_output` — scoped (cuisine-filtered) search: verifies every result carries a `cuisine_match` stamp, on-cuisine venues get the "Cuisine Match" signal in their breakdown, and results remain scored + sorted.

Next: add an orchestration test covering the multi-source merge path (venues from 2+ sources flowing through dedup together via `search()`).

## Log
- Iteration 1: Added end-to-end orchestration test for unscoped search → scored, sorted, bounded output.
- Iteration 2: Added scoped orchestration test — verifies `cuisine_match` stamps on every result, "Cuisine Match" signal in `score_breakdown` for on-cuisine venues, and score-descending sort on the full pipeline output.
