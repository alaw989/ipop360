# Iteration Notes

## Goal
enable stricter TypeScript compiler flags and fix all resulting type errors

## State
- Enabled `noPropertyAccessFromIndexSignature` in tsconfig.json. Fixed 55 errors across 6 files (SearchFilters.vue, Search.vue, Subcategories.vue, AuthenticatedLayout.vue, useSeo.ts, app.ts, ssr.ts). All were `Record<string, ...>` or index-signature types where dot notation had to become bracket notation (e.g., `props.filters.cuisine` → `props.filters["cuisine"]`, `import.meta.env.VITE_APP_NAME` → `import.meta.env["VITE_APP_NAME"]`, `$slots.header` → `$slots["header"]`). Build passes.
- Next flag: `noImplicitOverride`. This is the last meaningful strict-adjacent flag remaining. It requires methods overriding a base class method to include the `override` keyword. Only applies to class-based code; since this project has no TS classes (strictPropertyInitialization found zero), this should pass trivially.
- Gotchas gone: `noFallthroughCasesInSwitch` passed with zero errors — both existing switch statements (StarRating.vue, Dashboard.vue) already returned from every case.

## Log
- Iteration 1: Enabled `noUncheckedIndexedAccess` + `noImplicitReturns`, fixed 3 type errors. Build passes.
- Iteration 2: Enabled `noUnusedLocals` + `noUnusedParameters`, fixed 27 unused import/var errors. Build passes.
- Iteration 3: Enabled `noUncheckedSideEffectImports`, added module declaration for `@fontsource/poppins`. Build passes.
- Iteration 4: Enabled `exactOptionalPropertyTypes`, fixed ~40 errors (13 app code, 27 shadcn-vue wrappers). Build passes.
- Iteration 5: Enabled `strictPropertyInitialization`, zero errors (no class-based code). Build passes.
- Iteration 6: Enabled `noPropertyAccessFromIndexSignature`, fixed 55 dot→bracket errors in SearchFilters.vue, Search.vue, Subcategories.vue, AuthenticatedLayout.vue, useSeo.ts, app.ts, ssr.ts. Build passes.
- Iteration 7: Enabled `noFallthroughCasesInSwitch`, zero errors. Build passes.
