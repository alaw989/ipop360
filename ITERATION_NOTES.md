# Iteration Notes

## Goal
align the CI PHP version with production (php 8.4), or run both 8.4 and 8.5

## State
Changed ci.yml PHP version from 8.5 → 8.4 to align CI with production (dot).
All 616 tests pass.

Next: deploy.yml still has PHP 8.5 in both the quality gate step (line 38) and
the deploy step (line 89). Those should also switch to 8.4 for consistency.
The compose→tinker helper in AGENTS.md still says "PHP 8.3" — that's stale
(the machine actually runs 8.5) and should be updated to match.

## Log
- Iteration 1: Changed `.github/workflows/ci.yml` php-version from 8.5 → 8.4.
