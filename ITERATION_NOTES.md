# Iteration Notes

## Goal
add unit coverage for LiveSearchService.search() orchestration

## State
Added `test_search_orchestration_produces_scored_sorted_bounded_results` in `tests/Unit/LiveSearchScoringTest.php`. This is the first test that verifies the full `search()` output shape — it asserts that results come back with `popularity_score` and `score_breakdown` fields, and that they are sorted by score descending. Uses the existing `makeServiceWithVenues()` helper with serpapi-source venues in an unscoped search.

Next: add a scoped (cuisine-filtered) orchestration test that verifies `cuisine_match` stamps and confidence-filtered output. Also, add an orchestration test covering the multi-source merge path (venues from 2+ sources flowing through dedup together).

## Log
- Iteration 1: Added end-to-end orchestration test for unscoped search → scored, sorted, bounded output.
