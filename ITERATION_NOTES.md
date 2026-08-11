# Iteration Notes

## Goal
add vitest specs for the complex Vue components (Modal, CardGallery, BlogEditor, BlogPreview, SearchMap, DetailMap, HeroBanner, PopularRestaurants, RestaurantCardSkeleton)

## State
Added: RestaurantCardSkeleton.spec.ts (7 tests), BlogPreview.spec.ts (11 tests), SearchMap.spec.ts (20 tests — map container, pinned count, expand/collapse toggle, height classes, leaflet map init with center, tile layer, marker creation, popup content, fitBounds, unmount cleanup, prop reactivity, lat/lng prop center, rating/price in popup, detail link in popup, outer container classes).
Next: DetailMap.spec.ts (also Leaflet-based; similar mock pattern will work)
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
