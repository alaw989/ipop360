# Iteration Notes

## Goal
enable stricter TypeScript compiler flags and fix all resulting type errors

## State
- Enabled `noUncheckedIndexedAccess`, `noImplicitReturns`, `noUnusedLocals`, `noUnusedParameters`, `noUncheckedSideEffectImports` in tsconfig.json.
- `noUncheckedSideEffectImports` only flagged one issue: `@fontsource/poppins` side-effect import lacked a module declaration. Added `declare module '@fontsource/poppins'` to `resources/js/types/vite-env.d.ts`.
- **Enabled `exactOptionalPropertyTypes`** — all 40+ errors fixed across two categories:
  - **Application code (13 errors):** Used `!` non-null assertions for Vue template optional prop bindings (e.g. `:message="form.errors.password!"`), `v-if` guards (BlogEditor editor), and conditional spreads for function arguments with optional params (useSeo, generateArticleJsonLd, resort, fetch).
  - **shadcn-vue UI wrappers (27 errors):** Converted `v-bind="delegatedProps"` pattern (from `reactiveOmit`) to explicit `:as="props.as!"` / `:as-child="props.asChild!"` bindings in 9 components (Badge, CommandEmpty, CommandGroup, CommandSeparator, DialogOverlay, Separator, SheetDescription, SheetOverlay, SheetTitle). Used `!` assertions on explicit template bindings in 16 other components. Fixed `useVModel` defaultValue with conditional spread in Input/Textarea. Fixed `useRestaurantSearch.ts` fetch signal with conditional spread. For PopoverContent's complex `v-bind="{ ...$attrs, ...forwarded }"` pattern (useForwardPropsEmits), used `useAttrs()` + computed with `as any` cast since TS can't model runtime undefined filtering.
- Next flag: `strictPropertyInitialization` — requires all instance properties to be initialized. Expect errors in Vue component data/props patterns and potentially Inertia form handling.
- Gotcha: `strictPropertyInitialization` is only relevant for classes (not interfaces/types), so impact on this codebase may be minimal unless there are class-based service files.

## Log
- Iteration 1: Enabled `noUncheckedIndexedAccess` + `noImplicitReturns`, fixed 3 type errors. Build passes.
- Iteration 2: Enabled `noUnusedLocals` + `noUnusedParameters`, fixed 27 unused import/var errors. Build passes.
- Iteration 3: Enabled `noUncheckedSideEffectImports`, added module declaration for `@fontsource/poppins`. Build passes.
- Iteration 4: Enabled `exactOptionalPropertyTypes`, fixed ~40 errors (13 app code, 27 shadcn-vue wrappers). Build passes.
