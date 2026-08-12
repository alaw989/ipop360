# Iteration Notes

## Goal
adopt the AppLayout top nav on the homepage and tighten the hero banner while preserving the current Yelp-style design

## State
Extracted the AppLayout top nav into a reusable `resources/js/Components/TopNav.vue` (a `sticky` prop, default true) and adopted it on the homepage:
`Welcome.vue` renders `<TopNav :sticky="false" />` above the hero; `AppLayout.vue` now just composes `<TopNav />` + footer. `HeroBanner.vue` lost its redundant floating links (Blog/Favorites/Dashboard/Login) and got tightened: `min-h-screen` → `min-h-[80vh]`, and the hero logo is now a plain home link (was `@click.prevent="$emit('search')"`). TDD: new `TopNav.spec.ts` (10 cases), `HeroBanner.spec.ts` nav tests moved/rewritten, `Welcome.spec.ts` asserts the top nav renders.

**Next**: Run the remaining gates (`composer test` — PHP untouched so should pass; `npm run build` green-below), then decide whether TopNav on the homepage should be sticky in the results phase (still non-sticky so `StickySearchBar` keeps its `sticky top-0` single-bar role — no stacking conflict, likely leave as-is), and finally consider extra polish per backlog goal #1.

**Gotchas**: `Welcome.spec.ts` must stub `TopNav` (the real one needs `usePage()` + `route()` which the Welcome spec doesn't mock). HeroBanner no longer reads `$page.props.auth` — its `usePage` mock was removed from the spec. Live visual verification pending (dev SSR node server not running locally; CSR shell returns 200).

## Log
- **Iteration 1**: Extracted `AppLayout` top nav → reusable `TopNav.vue` (adds `sticky` prop); adopted it on the homepage as non-sticky above the hero; tightened hero (`min-h-[80vh]`), removed the hero's float-in-slideshow links (now covered by TopNav), made hero logo a plain home link. AppLayout now composes the shared component (all other pages unchanged in behaviour as verified by its passing spec). Added `TopNav.spec.ts` (10 cases), rewrote the moved HeroBanner nav assertions, extended `Welcome.spec.ts`. `npm run test` 992/992 pass; `npm run build` gate green.
- **Iteration 2**: Added the missing **Browse** link (`/restaurants`) to the shared `TopNav.vue` — leftmost of the right-hand links, matching the goal spec ("Browse/Leaderboard/Blog") and the footer's existing Browse link. TDD: new `TopNav.spec.ts` case "always shows Browse link" (red first, then green). `npm run test` 993/993 pass.
