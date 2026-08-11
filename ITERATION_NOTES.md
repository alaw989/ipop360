# Iteration Notes

## Goal
add vitest specs for the complex Vue components (Modal, CardGallery, BlogEditor, BlogPreview, SearchMap, DetailMap, HeroBanner, PopularRestaurants, RestaurantCardSkeleton)

## State
Added: RestaurantCardSkeleton.spec.ts (7 tests), BlogPreview.spec.ts (11 tests), SearchMap.spec.ts (20 tests), DetailMap.spec.ts (18 tests — map container, outer container classes, map init with center/zoom/controls, tile layer, marker creation, popup content, fitBounds, divIcon styling, unmount cleanup, Get Directions render/absence, Google Maps URL on click, prop reactivity, null lat/lng guard).
Next: HeroBanner.spec.ts (simple presentation component; shallow mount, check slot rendering and link)
Gotchas: 
- Link stub pattern from Blog.Index.spec.ts: `vi.mock('@inertiajs/vue3', async () => { const actual = await vi.importActual('@inertiajs/vue3'); return { ...actual, Link: { template: '<a :href="href"><slot /></a>', props: ['href'] } } })`
- `month: 'short'` produces abbreviated month ("Mar" not "March") in jsdom
- Skeleton child component renders as `<div data-slot="skeleton">`
- Leaflet dynamic import in SearchMap uses `await import('leaflet')` (module namespace, not default) — mock named exports: `vi.mock('leaflet', () => ({ map, tileLayer, divIcon, marker }))`. Also mock `leaflet/dist/leaflet.css` with empty object.
- Mock map instance needs `removeLayer` for `clearMarkers()` called during restaurant watch.
- Use `flushPromises()` + `$nextTick()` after mount to wait for async `onMounted` (import + map init).

## Log
1. RestaurantCardSkeleton — 7 tests, all pass
2. BlogPreview — 11 tests, all pass
3. SearchMap — 20 tests, all pass
4. DetailMap — 18 tests, all pass
