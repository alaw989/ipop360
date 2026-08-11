# Iteration Notes

## Goal
add vitest specs for the complex Vue components (Modal, CardGallery, BlogEditor, BlogPreview, SearchMap, DetailMap, HeroBanner, PopularRestaurants, RestaurantCardSkeleton)

## State
Added: RestaurantCardSkeleton.spec.ts (7 tests — renders outer container, 6 skeleton placeholders, image 4:3 aspect, title/subtitle/description sizing, 2 cuisine chip pills).
Next: BlogPreview.spec.ts (straightforward — just props + Link stubs, no complex state)
Gotchas: Skeleton child component renders as `<div data-slot="skeleton">` — use that attr for querying skeleton placeholders.

## Log
1. RestaurantCardSkeleton — 7 tests, all pass
