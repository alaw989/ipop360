# Iteration Notes

## Goal
add vitest specs for the complex Vue components (Modal, CardGallery, BlogEditor, BlogPreview, SearchMap, DetailMap, HeroBanner, PopularRestaurants, RestaurantCardSkeleton)

## State
Added: RestaurantCardSkeleton.spec.ts (7 tests), BlogPreview.spec.ts (11 tests — empty posts, header, view-all link, card count, featured image presence/absence, title, excerpt, date formatting, null date, slug links).
Next: SearchMap.spec.ts (Leaflet-based, will need to figure out how to mount with vue2-leaflet or mock L globals)
Gotchas: 
- Link stub pattern from Blog.Index.spec.ts: `vi.mock('@inertiajs/vue3', async () => { const actual = await vi.importActual('@inertiajs/vue3'); return { ...actual, Link: { template: '<a :href="href"><slot /></a>', props: ['href'] } } })`
- `month: 'short'` produces abbreviated month ("Mar" not "March") in jsdom
- Skeleton child component renders as `<div data-slot="skeleton">`

## Log
1. RestaurantCardSkeleton — 7 tests, all pass
2. BlogPreview — 11 tests, all pass
