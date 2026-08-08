# Iteration Notes

## Goal
enable stricter TypeScript compiler flags and fix all resulting type errors

## State
- All strict-adjacent compiler flags are now enabled. The tsconfig.json has: `strict`, `noUncheckedIndexedAccess`, `noImplicitReturns`, `noUnusedLocals`, `noUnusedParameters`, `noUncheckedSideEffectImports`, `exactOptionalPropertyTypes`, `strictPropertyInitialization`, `noPropertyAccessFromIndexSignature`, `noFallthroughCasesInSwitch`, `noImplicitOverride`. Build passes clean with zero type errors.
- **Goal achieved.** No further strict-adjacent flags remain to enable. All flags that could be enabled have been enabled and verified.

## Log
- Iteration 1: Enabled `noUncheckedIndexedAccess` + `noImplicitReturns`, fixed 3 type errors. Build passes.
- Iteration 2: Enabled `noUnusedLocals` + `noUnusedParameters`, fixed 27 unused import/var errors. Build passes.
- Iteration 3: Enabled `noUncheckedSideEffectImports`, added module declaration for `@fontsource/poppins`. Build passes.
- Iteration 4: Enabled `exactOptionalPropertyTypes`, fixed ~40 errors (13 app code, 27 shadcn-vue wrappers). Build passes.
- Iteration 5: Enabled `strictPropertyInitialization`, zero errors (no class-based code). Build passes.
- Iteration 6: Enabled `noPropertyAccessFromIndexSignature`, fixed 55 dot→bracket errors in SearchFilters.vue, Search.vue, Subcategories.vue, AuthenticatedLayout.vue, useSeo.ts, app.ts, ssr.ts. Build passes.
- Iteration 7: Enabled `noFallthroughCasesInSwitch`, zero errors. Build passes.
- Iteration 8: Enabled `noImplicitOverride`, the last strict-adjacent flag. Zero errors (no TS classes in project). Build passes. Goal achieved.
