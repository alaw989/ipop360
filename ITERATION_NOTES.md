# Iteration Notes

## Goal
align the CI PHP version with production (php 8.4), or run both 8.4 and 8.5

## State
Changed deploy.yml PHP versions from 8.5 → 8.4 in both the quality gate step
and the deploy step. CI and Deploy workflows now both use 8.4 (matching prod).

Next: The compose→tinker helper in AGENTS.md still says "PHP 8.3" — that's stale
(the machine actually runs 8.5 in dev; prod is 8.4). Update AGENTS.md stack line.

## Log
- Iteration 1: Changed `.github/workflows/ci.yml` php-version from 8.5 → 8.4.
- Iteration 2: Changed `.github/workflows/deploy.yml` php-version from 8.5 → 8.4
  (both quality gate and deploy steps).
