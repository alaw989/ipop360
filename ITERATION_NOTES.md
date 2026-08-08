# Iteration Notes

## Goal
enable stricter TypeScript compiler flags and fix all resulting type errors

## State
- Enabled `noUncheckedIndexedAccess`, `noImplicitReturns`, `noUnusedLocals`, `noUnusedParameters`, `noUncheckedSideEffectImports` in tsconfig.json.
- `noUncheckedSideEffectImports` only flagged one issue: `@fontsource/poppins` side-effect import lacked a module declaration. Added `declare module '@fontsource/poppins'` to `resources/js/types/vite-env.d.ts`.
- Next flag: `exactOptionalPropertyTypes` — expect ~41 errors, ~27 in shadcn-vue UI wrappers (`v-bind` pattern with optional props + `exactOptionalPropertyTypes` incompatibility) and ~14 in application code.
- Gotcha: `exactOptionalPropertyTypes` will require significant work on shadcn-vue wrappers. The `useForwardProps` from reka-ui already filters `undefined` at runtime but TypeScript still sees `prop?: Type` as `Type | undefined` in source while target expects exactly `Type`.

## Log
- Iteration 1: Enabled `noUncheckedIndexedAccess` + `noImplicitReturns`, fixed 3 type errors. Build passes.
- Iteration 2: Enabled `noUnusedLocals` + `noUnusedParameters`, fixed 27 unused import/var errors. Build passes.
- Iteration 3: Enabled `noUncheckedSideEffectImports`, added module declaration for `@fontsource/poppins`. Build passes.
