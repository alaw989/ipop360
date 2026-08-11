# Iteration Notes

## Goal
add vitest specs for the complex Vue components (Modal, CardGallery, BlogEditor, BlogPreview, SearchMap, DetailMap, HeroBanner, PopularRestaurants, RestaurantCardSkeleton)

## State
Added: RestaurantCardSkeleton.spec.ts (7 tests), BlogPreview.spec.ts (11 tests), SearchMap.spec.ts (20 tests), DetailMap.spec.ts (18 tests), HeroBanner.spec.ts (18 tests), PopularRestaurants.spec.ts (33 tests), Modal.spec.ts (24 tests — render/slot visibility, default props, maxWidthClass for all 5 sizes, backdrop click close/closeable=false, Escape close when shown/not shown/non-Escape keys, body overflow toggling on show transitions, lifecycle keydown listener register+remove, dialog showModal/close methods, body overflow cleanup on unmount).
Next: CardGallery.spec.ts or BlogEditor.spec.ts (from the remaining Goal components: CardGallery, BlogEditor)
Gotchas: 
- Link stub pattern from Blog.Index.spec.ts: `vi.mock('@inertiajs/vue3', async () => { const actual = await vi.importActual('@inertiajs/vue3'); return { ...actual, Link: { template: '<a :href="href"><slot /></a>', props: ['href'] } } })`
- `$page.props` in templates needs `global.mocks.$page` in mount options (not mock of `usePage` alone)
- `month: 'short'` produces abbreviated month ("Mar" not "March") in jsdom
- Skeleton child component renders as `<div data-slot="skeleton">`
- Leaflet dynamic import in SearchMap uses `await import('leaflet')` (module namespace, not default) — mock named exports: `vi.mock('leaflet', () => ({ map, tileLayer, divIcon, marker }))`. Also mock `leaflet/dist/leaflet.css` with empty object.
- Mock map instance needs `removeLayer` for `clearMarkers()` called during restaurant watch.
- Use `flushPromises()` + `$nextTick()` after mount to wait for async `onMounted` (import + map init).
- Use `vi.useFakeTimers()` to suppress setInterval in components with slideshows
- Modal: jsdom 29 lacks `HTMLDialogElement.prototype.showModal/close` — polyfill in beforeEach. Modal watch only fires on change, not initial mount — mount with `show: false` then `setProps({ show: true })` to test body overflow/dialog side effects. Two `.fixed.inset-0` elements (outer wrapper + backdrop); use `findAll(...)[1]` for backdrop click.

## Log
1. RestaurantCardSkeleton — 7 tests, all pass
2. BlogPreview — 11 tests, all pass
3. SearchMap — 20 tests, all pass
4. DetailMap — 18 tests, all pass
5. HeroBanner — 18 tests, all pass
6. PopularRestaurants — 33 tests, all pass
7. Modal — 24 tests, all pass
