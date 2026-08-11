# Iteration Notes

## Goal
add vitest specs for the complex Vue components (Modal, CardGallery, BlogEditor, BlogPreview, SearchMap, DetailMap, HeroBanner, PopularRestaurants, RestaurantCardSkeleton)

## State
Added: RestaurantCardSkeleton.spec.ts (7 tests), BlogPreview.spec.ts (11 tests), SearchMap.spec.ts (20 tests), DetailMap.spec.ts (18 tests), HeroBanner.spec.ts (18 tests — root section, nav links (Blog + conditional auth: Login vs Favorites/Dashboard), BrandLogo, CuisinePicker/LocationPicker stubs, Search button text/spinner/disabled states, search emit + disabled guard, slide dots/active state, photo attribution, play/pause toggle, logo link).
Next: PopularRestaurants.spec.ts (list component showing restaurant cards; needs restaurant stubs and Inertia Link mock)
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
