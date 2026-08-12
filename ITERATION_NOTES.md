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

### Next
- Add a per-section `delay` stagger in Welcome.vue so above-the-fold sections
  cascade in (e.g. 0/80/160ms) instead of revealing simultaneously.
- Consider a JS-side reduced-motion guard to skip observing entirely (cosmetic;
  CSS already covers the visual). Optional.

### Gotchas
- `ScrollReveal` reveals immediately when `typeof window.IntersectionObserver
  === 'undefined'` (SSR is fine — `onMounted` only runs client-side).
- In tests, DOM updates after the observer callback are async — assert post-
  reveal classes/styles after `await nextTick()`.

## Log
