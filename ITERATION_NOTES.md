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

### Next
- Consider a JS-side reduced-motion guard in ScrollReveal.vue to skip observing
  entirely (cosmetic; CSS already covers the visual). Optional.

### Gotchas
- `ScrollReveal` reveals immediately when `typeof window.IntersectionObserver
  === 'undefined'` (SSR is fine — `onMounted` only runs client-side).
- In tests, DOM updates after the observer callback are async — assert post-
  reveal classes/styles after `await nextTick()`.
- The stagger `:delay` is an inline `transition-delay` applied only when
  revealed (`.scroll-reveal--visible` + `delay`). Below-the-fold sections also
  wait their delay after scrolling into view, so keep the step small (80ms).

## Log
