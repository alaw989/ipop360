# Iteration Notes

## Goal
add prefers-reduced-motion-aware scroll-reveal animations to homepage sections

## State
Added a reusable `ScrollReveal.vue` wrapper (Components/) with a one-shot
IntersectionObserver that toggles a `scroll-reveal--visible` class, plus CSS in
`resources/css/transitions.css` (`.scroll-reveal` hidden + 24px lift → visible
over 500ms). Reduced-motion awareness is handled in CSS: the existing
`@media (prefers-reduced-motion: reduce)` block forces `.scroll-reveal` to
`opacity:1; transform:none; transition:none`. Wired the five idle-phase homepage
sections (StatsBand, CategoryGrid, PopularCuisines, PopularRestaurants,
BlogPreview) into the wrapper in `Welcome.vue`.

- Tests: new `Components/__tests__/ScrollReveal.spec.ts` (9 tests) + a
  `ScrollReveal` stub added to `Pages/__tests__/Welcome.spec.ts`.
- Verified: `npm run test` (1026 pass) and `npm run build` (exit 0).

### Iteration: per-section stagger delay
Added a progressive `:delay` stagger (0/80/160/240/320ms) to the five
ScrollReveal wrappers in `Welcome.vue` so above-the-fold sections cascade in
instead of revealing simultaneously. The stub now renders `data-delay` and a
new `scroll-reveal stagger` test asserts the ordered delays.
- Verified: `npm run test` (1027 pass).

### Iteration: JS reduced-motion guard
Added a `window.matchMedia('(prefers-reduced-motion: reduce)')` check to
`ScrollReveal.vue`'s `onMounted`: reduced-motion users are marked revealed
immediately and no IntersectionObserver is created (CSS in transitions.css
still forces the section visible). Two new tests in `ScrollReveal.spec.ts`
cover the skip-observing path and the still-observes fallback (matchMedia
stubbed via `vi.stubGlobal`, since jsdom provides no `matchMedia`).
- Verified: `npm run test` (1029 pass).

### Iteration: no-JS progressive-enhancement fallback
The `.scroll-reveal` hidden state (`opacity:0`) is now gated behind a `.js`
class on `<html>` so homepage sections are never stuck invisible when JS is
disabled or fails. `app.blade.php` starts `<html class="no-js">` and an inline
head script swaps it to `js` before first paint; `transitions.css` scopes the
hidden/visible rules to `.js .scroll-reveal` and the reduced-motion override to
`.js .scroll-reveal` (matched specificity so it still wins in the cascade).
Added `test_root_view_is_no_js_by_default_and_swaps_to_js_for_progressive_enhancement`
to `HomeControllerTest.php`.
- Verified: `php artisan test` (665 pass), `npm run test` (1029 pass),
  `vendor/bin/pint --test`, `npm run build` (exit 0).

### Next
- Goal is fully achieved: stagger + reduced-motion-aware reveal + no-JS
  fallback are all in. Remaining ideas are polish only (e.g. re-fire on
  viewport resize) — none are required.

### Gotchas
- `ScrollReveal` reveals immediately when `typeof window.IntersectionObserver
  === 'undefined'` (SSR is fine — `onMounted` only runs client-side).
- jsdom has no `matchMedia`, so the guard's `typeof window.matchMedia ===
  'function'` check keeps the default observe path in tests unless a stub is
  injected via `vi.stubGlobal('matchMedia', ...)`.
- In tests, DOM updates after the observer callback are async — assert post-
  reveal classes/styles after `await nextTick()`.
- The stagger `:delay` is an inline `transition-delay` applied only when
  revealed (`.scroll-reveal--visible` + `delay`). Below-the-fold sections also
  wait their delay after scrolling into view, so keep the step small (80ms).

## Log
