# Iteration Notes

## Goal
enable stricter TypeScript compiler flags and fix all resulting type errors

## State
- Enabled `noPropertyAccessFromIndexSignature` in tsconfig.json. Fixed 55 errors across 6 files (SearchFilters.vue, Search.vue, Subcategories.vue, AuthenticatedLayout.vue, useSeo.ts, app.ts, ssr.ts). All were `Record<string, ...>` or index-signature types where dot notation had to become bracket notation (e.g., `props.filters.cuisine` → `props.filters["cuisine"]`, `import.meta.env.VITE_APP_NAME` → `import.meta.env["VITE_APP_NAME"]`, `$slots.header` → `$slots["header"]`). Build passes.
- Next flag: `strictNullChecks` is already covered by `"strict": true`. Check tsconfig strict flags remaining: `noFallthroughCasesInSwitch`, `noImplicitOverride`, `useUnknownInCatchVariables` (already covered by strict). Only meaningful remaining flags are `noFallthroughCasesInSwitch` and `noImplicitOverride`. Enable `noFallthroughCasesInSwitch` next. Also consider enabling `incremental`/`composite`-adjacent flags like `declaration`, but those don't apply (noEmit is set).
- Gotcha: `noFallthroughCasesInSwitch` expects every non-empty case clause to either break/return/throw or be explicitly marked with a comment like `// falls through`. May flag summary-switch patterns if any exist.

## Log
- Iteration 1: Enabled `noUncheckedIndexedAccess` + `noImplicitReturns`, fixed 3 type errors. Build passes.
- Iteration 2: Enabled `noUnusedLocals` + `noUnusedParameters`, fixed 27 unused import/var errors. Build passes.
- Iteration 3: Enabled `noUncheckedSideEffectImports`, added module declaration for `@fontsource/poppins`. Build passes.
- Iteration 4: Enabled `exactOptionalPropertyTypes`, fixed ~40 errors (13 app code, 27 shadcn-vue wrappers). Build passes.
- Iteration 5: Enabled `strictPropertyInitialization`, zero errors (no class-based code). Build passes.
- Iteration 6: Enabled `noPropertyAccessFromIndexSignature`, fixed 55 dot→bracket errors in SearchFilters.vue, Search.vue, Subcategories.vue, AuthenticatedLayout.vue, useSeo.ts, app.ts, ssr.ts. Build passes.
