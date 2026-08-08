# Iteration Notes

## Goal
enable stricter TypeScript compiler flags and fix all resulting type errors

## State
- Enabled `noUncheckedIndexedAccess`, `noImplicitReturns`, `noUnusedLocals`, `noUnusedParameters` in tsconfig.json.
- Fixed all 27 `noUnusedLocals` / `noUnusedParameters` errors across 13 files (removed unused imports, destructured vars, and `const props =` assignments where props was only used in template).
- Next flags to enable: `exactOptionalPropertyTypes` or `noUncheckedSideEffectImports` — whichever yields the fewest errors.
- Gotcha: Several `defineProps` had `const props =` prefix where `props` was only used in template (not script). Removed assignment, kept `defineProps` call.

## Log
- Iteration 1: Enabled `noUncheckedIndexedAccess` + `noImplicitReturns`, fixed 3 type errors. Build passes.
- Iteration 2: Enabled `noUnusedLocals` + `noUnusedParameters`, fixed 27 unused import/var errors. Build passes.
