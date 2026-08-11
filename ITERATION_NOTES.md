# Iteration Notes

## Goal
add vitest specs for the complex Vue components (Modal, CardGallery, BlogEditor, BlogPreview, SearchMap, DetailMap, HeroBanner, PopularRestaurants, RestaurantCardSkeleton)

## State
Added: RestaurantCardSkeleton.spec.ts (7 tests), BlogPreview.spec.ts (11 tests), SearchMap.spec.ts (20 tests), DetailMap.spec.ts (18 tests), HeroBanner.spec.ts (18 tests), PopularRestaurants.spec.ts (33 tests — heading/heading+city/subtitle, 8 skeleton loading grid, card rendering with name/photo/gradient-fallback/rank-badges/score-chip/award/star-rating/price/cuisine/link-href, yelp-over-google rating preference, show-more toggle for >12, expanded shows all).
Next: Modal.spec.ts or CardGallery.spec.ts or BlogEditor.spec.ts (from the remaining Goal components: Modal, CardGallery, BlogEditor)
Gotchas: 
- Link stub pattern from Blog.Index.spec.ts: `vi.mock('@inertiajs/vue3', async () => { const actual = await vi.importActual('@inertiajs/vue3'); return { ...actual, Link: { template: '<a :href="href"><slot /></a>', props: ['href'] } } })`
- `$page.props` in templates needs `global.mocks.$page` in mount options (not mock of `usePage` alone)
- `month: 'short'` produces abbreviated month ("Mar" not "March") in jsdom
- Skeleton child component renders as `<div data-slot="skeleton">`
- Leaflet dynamic import in SearchMap uses `await import('leaflet')` (module namespace, not default) — mock named exports: `vi.mock('leaflet', () => ({ map, tileLayer, divIcon, marker }))`. Also mock `leaflet/dist/leaflet.css` with empty object.
- Mock map instance needs `removeLayer` for `clearMarkers()` called during restaurant watch.
- Use `flushPromises()` + `$nextTick()` after mount to wait for async `onMounted` (import + map init).
- Use `vi.useFakeTimers()` to suppress setInterval in components with slideshows

## Log
1. RestaurantCardSkeleton — 7 tests, all pass
2. BlogPreview — 11 tests, all pass
3. SearchMap — 20 tests, all pass
4. DetailMap — 18 tests, all pass
5. HeroBanner — 18 tests, all pass
6. PopularRestaurants — 33 tests, all pass
