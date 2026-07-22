# Tool Quality Audit

## Fixed This Session

| Date | Tool | Issue | Fix |
|---|---|---|---|
| 2026-07-20 | `QuotaStatusCommandTest` | Tests hardcoded `monthly_budget` of 150, but env was updated to 250 | Updated assertions to expect 250 and recalculated percentages |
| 2026-07-22 | `EngagementController`, `PopularityScoreService`, `Show.vue`, `SocialLinks.vue`, `restaurant.ts` | Fire-and-forget engagement tracking, no pageviews, no social link clicks, stale day-old counters, modest engagement weights | Queued engagement with dedup + bot filter, pageview/menu/social-link click tracking, sendBeacon, 3 new scoring signals, rebalanced weights to prioritize engagement, rescheduled aggregation before scoring |

## Per-Tool Status

| Tool | Status | Notes |
|---|---|---|
| `QuotaStatusCommandTest` | ✓ | All 9 tests pass |
| `EngagementController` | ✓ | sendBeacon + queue-ready + dedup + bot filter + 6 action types (411 PHP tests pass) |
| `UpdateEngagement` | ✓ | Handles pageview, social_link_click, menu_click aggregation |
| `PopularityScoreService` | ✓ | 3 new signals (pageviews_count, social_link_clicks_count, menu_click_count), rebalanced weights prioritizing engagement (17 unit tests pass) |
| `SocialLinks.vue` | ✓ | social_link_click tracking, changed `<a>` to `<button>` with window.open (4 component tests pass) |
| `restaurant.ts` | ✓ | sendBeacon fallback, trackPageview, trackSocialLinkClick, trackMenuClick |
| `Show.vue` | ✓ | Pageview on mount, menu click tracking, Vue-tsc passes |
