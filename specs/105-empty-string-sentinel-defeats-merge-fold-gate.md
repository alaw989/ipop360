# Feature Specification: Empty-string/array sentinels defeat mergeVenues' null-only fold gate

**Feature Branch**: `master` (interactive)

**Created**: 2026-08-22

**Status**: SHIPPED (`1c684ab`, 2026-08-22)

**Series**: Follow-up to spec-094 (VenueShape contract + mergeVenues field-fold correctness, shipped 2026-08-22 as the rating-family-independence + SerpApi enrichment-shape fix).

## The problem

Sampled real cached raw per-source rows (3,697 venues pooled from 40 recent
`external_api_cache` entries each of `serpapi`/`overpass_search`/`bizdata`,
normalized via each service's `normalizeRaw` exactly as the live path does,
then run through `VenuePipeline::venuesMatch`/`mergeVenues` — zero SerpApi
quota, read-only). Found 13,188 real cross-source matches and, among them,
**738 field regressions**: a merge where the source venue carried real data
for a field the target lacked, but the merged result still lacked it.

Root cause: `mergeVenues`'s generic fold gate (`app/Services/VenuePipeline.php`,
the loop over `$fields`) only takes the source's value when
`$targetValue === null`. Every per-source normalizer builds its output with
`$raw['field'] ?? null` chains (`BizDataApiService.php:104-105`,
`SerpApiService.php:461-462`, `OverpassService.php:356`,
`SocrataOpenDataService.php:503`, etc.) — but `??` only falls through on a
genuinely **missing/null** key. When the raw upstream API returns the key
with an **empty string** (`"phone": ""`, common in BizData's raw payload —
confirmed directly against cached raw data, e.g. cache entry `bizdata#51660`
for "Barrio Cocina Y Tequileria" has raw `phone: ""`) or an empty array, the
normalized field ends up as `''`/`[]`, not `null`. `$targetValue === null`
is then false, so the fold never fires, even though the target has no usable
data in that field and the source does.

Confirmed instances across the sample (target/source field, real venue
names): `phone` (113), `opening_hours` (152), `features` (322), `address`
(51), `website_url` (100). This is broader than spec-094's finding — it
hits **any** field on **any** source pair whenever the raw API's empty
sentinel is `""`/`[]` rather than an absent key, not just the
rating/review-count family already fixed.

## Solution (recall-protective)

Generalize the fold gate's "target has no usable value" check to treat
`null`, `''`, and `[]` as equivalent to "no value" — mirroring the
`!empty()` semantics already used elsewhere in this file (e.g. the
rating-family gate fixed by spec-094's follow-up), rather than a strict
`=== null` check. Numeric `0` must stay excluded from this treatment for
non-review-count fields (a real `price_range` or `distance` of `0` is valid
data) — scope the generalization to string/array-shaped fields, or use a
per-field "empty" predicate rather than a blanket `empty()` (which would
also treat legitimate `0` values as absent).

Recommended approach: replace the single `$targetValue === null` check with
a small `isBlank($value): bool` helper — `true` for `null`, `''`, and `[]`,
`false` for everything else including `0`/`0.0`/`false` — and use it for
both `$targetValue` and to gate whether `$sourceValue` is worth taking.

## Acceptance criteria

- A merge where the target's `phone`/`website_url`/`opening_hours`/`address`/
  `features` is `''`/`[]` (not `null`) and the source has real data now
  takes the source's value.
- A merge where the target has a real `price_range`/`distance`/review-count
  of literal `0` does **not** get overwritten by the source (numeric `0` is
  still "present").
- Regression test reproducing the exact "Barrio Cocina Y Tequileria" case
  (bizdata target with `phone: ''`, serpapi source with a real phone) merges
  to the real phone number.
- Re-running the sampling script (or an equivalent test) against the same
  cached data shows the field-regression count drop to ~0 for the five
  affected fields.

## Files

- `app/Services/VenuePipeline.php` — `mergeVenues`'s fold gate.
- `tests/Unit/VenuePipelineMergeTest.php` — regression tests for the five
  affected fields, plus a test confirming literal `0` is still treated as
  present.

## Notes from the sampling pass (informational, not separate bugs)

- The phone fast-path match correctly caught real same-venue pairs with
  divergent names across sources (e.g. "Wagaya - Westside" vs "Wagaya" at
  52% name similarity, "Tenya Japanese Eatery" vs "Tenya" at 38%) — working
  as designed (spec-069 4A).
- Near-miss (sub-threshold name similarity, within radius) pairs sampled
  looked like a mix of true near-duplicates with typo'd names ("Sorrento's |
  Italian" vs "Sorrento's Italian Resturant", 75%) and genuinely distinct
  neighboring venues sharing a generic word ("Frank's Restaurant" vs
  "Tianchu Restaurant", 72% — both contain "Restaurant"). No clear false-
  positive/negative pattern emerged beyond the known generic-word-inflation
  risk already documented; not actionable as a standalone finding.
- No phone-match false positives (distinct venues merged via a shared
  central-booking number) were observed in the sample.

## Quota / deploy

Live-path + enrichment-path data-fidelity fix, same class as spec-094.
Zero quota (offline VenuePipeline fix; no live API calls needed to build or
verify — the sampling script that surfaced this reads only cached data).
