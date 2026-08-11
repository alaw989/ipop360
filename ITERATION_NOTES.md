# Iteration Notes

## Goal
add restaurant, cuisine, user, and blog post counts to the admin dashboard overview

## State
**Last change**: Added entity count cards (Restaurants, Cuisines, Users, Blog Posts) to the admin dashboard Overview section.
- Controller: `DashboardController` now passes `entityCounts` prop with counts from four models.
- Vue: New "Overview" section with 4 Card components (Utensils/ChefHat/Users/Newspaper icons) rendered before SerpApi Quota.
- Tests: 5 new vitest tests + ChefHat/Users icon mocks added.

**Next**: (none — Goal achieved: restaurant, cuisine, user, and blog post counts now appear on the admin dashboard)

**Gotchas**: Restaurant count now appears in both the Overview section and the Data Quality "Total" card. This is intentional per the Goal's request to show all four counts together.

## Log
1. Added entityCounts (restaurants, cuisines, users, blog_posts) to admin dashboard overview
