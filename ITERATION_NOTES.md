# Iteration Notes

## Goal
add unit coverage for LiveSearchService.search() orchestration

## State
Four orchestration tests now cover the `search()` pipeline end-to-end:
1. `test_search_orchestration_produces_scored_sorted_bounded_results` — unscoped search.
2. `test_scoped_search_stamps_cuisine_match_and_confidence_filters_output` — scoped search.
3. `test_multi_source_merge_dedup_collapses_same_venue_and_preserves_distinct` — multi-source merger by fuzzy name.
4. `test_phone_dedup_fast_path_collapses_divergent_name_venues_within_proximity` — phone fast-path: 3 venues from serpapi+overpass, "Tony's Pizza Napoletana" (SerpApi, phone `+1 (212) 555-0142`) + "Tonys Pizza" (Overpass, phone `2125550142`) match by last-10-digits + proximity despite names below 85% similarity; collapsed to 1 merged venue carrying `description` from SerpApi, `cuisines` from Overpass, plus rating; "Maria Trattoria" (different phone) survives separately.

No remaining uncovered dedup pathways in the orchestration layer. The Goal is achieved.

## Log
- Iteration 1: Added end-to-end orchestration test for unscoped search → scored, sorted, bounded output.
- Iteration 2: Added scoped orchestration test — verifies `cuisine_match` stamps on every result, "Cuisine Match" signal in `score_breakdown` for on-cuisine venues, and score-descending sort on the full pipeline output.
- Iteration 3: Added multi-source merge orchestration test — 3 venues from serpapi+overpass dedup into 2 via crossSourceDedup, verifies merged venue carries data from both sources (description + cuisines).
- Iteration 4: Added phone-dedup orchestration test — divergent-name venues with same last-10-digit phone number collapse through the phone fast-path (spec-069 4A), distinct-phone venue survives, merged result carries SerpApi description, Overpass cuisines, and rating.
