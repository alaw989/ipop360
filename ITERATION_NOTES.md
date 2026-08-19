# Iteration Notes

## Goal
Add vitest coverage for the shadcn-vue UI components SheetTitle.vue and SheetDescription.vue (resources/js/components/ui/sheet/), which are thin reka-ui passthrough wrappers currently at 0% coverage. Add spec files (resources/js/components/ui/sheet/__tests__/SheetTitle.spec.ts and SheetDescription.spec.ts, or one combined spec) that mount each with @vue/test-utils, assert they render a semantic heading/paragraph with the slot content, forward the correct reka-ui primitive, and pass props/class through. Follow the existing test conventions used in resources/js/Components/__tests__/ and the repo's vitest setup (explicit vitest imports, no globals). Run the new specs and the full vitest suite to confirm green, and keep the vitest coverage thresholds satisfied.

## State
Added SheetTitle.spec.ts + SheetDescription.spec.ts under components/ui/sheet/__tests__ (10 tests, green). Components need a DialogRoot context, so specs mount via Sheet render-fn slot. Full vitest suite: 74 files / 1102 tests pass.

## Log
- Iter 1: Added coverage for SheetTitle.vue + SheetDescription.vue (components/ui/sheet/__tests__). Wrapped mounts in Sheet.vue for reka-ui DialogRoot context; verify data-slot, default/merged classes, slot text, as-prop. All 10 new tests + full suite green.
