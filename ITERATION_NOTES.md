# Iteration Notes

## Goal
enable stricter TypeScript compiler flags and fix all resulting type errors

## State
- Enabled `noUncheckedIndexedAccess`, `noImplicitReturns`, `noUnusedLocals`, `noUnusedParameters`, `noUncheckedSideEffectImports`, `exactOptionalPropertyTypes`, `strictPropertyInitialization` in tsconfig.json.
- `noUncheckedSideEffectImports` only flagged one issue: `@fontsource/poppins` side-effect import lacked a module declaration. Added `declare module '@fontsource/poppins'` to `resources/js/types/vite-env.d.ts`.
- `exactOptionalPropertyTypes` fixed ~40 errors across application code and shadcn-vue wrappers.
- `strictPropertyInitialization` had zero impact — the codebase uses Vue 3 Composition API with no class-based components/services, so no class properties needed initialization fixes.
- Next flag: `noPropertyAccessFromIndexSignature` — enforces bracket notation for index-signature access (e.g., requires `obj["key"]` instead of `obj.key` for index signature properties). Expect errors wherever objects with loose types (Inertia page props, API responses, form data) use dot notation. Likely the highest-impact remaining flag.
- Gotcha: `noPropertyAccessFromIndexSignature` will likely flag many `.` accesses on Inertia `page.props` and possibly on Zod/validation schemas. May need to define specific interfaces for common page prop shapes rather than blanket `as any` workarounds.

## Log
- Iteration 1: Enabled `noUncheckedIndexedAccess` + `noImplicitReturns`, fixed 3 type errors. Build passes.
- Iteration 2: Enabled `noUnusedLocals` + `noUnusedParameters`, fixed 27 unused import/var errors. Build passes.
- Iteration 3: Enabled `noUncheckedSideEffectImports`, added module declaration for `@fontsource/poppins`. Build passes.
- Iteration 4: Enabled `exactOptionalPropertyTypes`, fixed ~40 errors (13 app code, 27 shadcn-vue wrappers). Build passes.
- Iteration 5: Enabled `strictPropertyInitialization`, zero errors (no class-based code). Build passes.
