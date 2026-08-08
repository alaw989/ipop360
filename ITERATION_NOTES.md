# Iteration Notes

## Goal
enable stricter TypeScript compiler flags and fix all resulting type errors

## State
- Enabled `noUncheckedIndexedAccess` and `noImplicitReturns` in tsconfig.json.
- Fixed 3 resulting errors: HeroBanner.vue (optional chain for slides array access), LocationPicker.vue (guard before index access), ScoreBreakdown.vue (non-null assertion on defaultColors).
- Next flags to enable: `noUnusedLocals` (~25 unused import/var errors), `noUnusedParameters` (1 error).
- Gotcha: `noUnusedLocals` will require cleaning up ~25 unused imports across many components.

## Log
- Iteration 1: Enabled `noUncheckedIndexedAccess` + `noImplicitReturns`, fixed 3 type errors. Build passes.
