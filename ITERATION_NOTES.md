# Iteration Notes

## Goal
Bring the homepage stats row (Restaurants 39398, Cuisines 59, Cities 1484) up INTO the hero, directly underneath the search button, and display it dramatically: light text over the dark hero, larger numerals, icons, subtle separators. Fade the row in on load and count the numbers up from 0 to their target on load; respect prefers-reduced-motion (no count-up, instant/no fade). Remove the now-redundant separate StatsBand section from the Welcome idle template (the row now lives in the hero). Pass the existing stats prop into HeroBanner and add a reusable count-up implementation. Add or extend vitest specs (HeroBanner, the count-up logic, Welcome) and keep the HomeControllerTest stats/viewport assertions passing. Verify no regression in a 375px viewport and desktop.

## State
Moved stats row into HeroBanner under search button: light text, larger numerals, icons, separators; added reusable useCountUp composable (count-up on load, respects prefers-reduced-motion). Passed stats prop into HeroBanner; removed StatsBand usage from Welcome idle template + deleted StatsBand component/spec; renumbered ScrollReveal delays. Refactored stats row to bind each count ref via per-item getValue closure, removing brittle positional ternary. All vitest + vue-tsc + HomeControllerTest pass.

## Log
- ITER-2: replaced index ternary with per-item getValue closures in stats row; vue-tsc + HeroBanner/Welcome/useCountUp specs green.
- ITER-1: hero stats row + useCountUp composable; removed StatsBand; specs for HeroBanner/useCountUp/Welcome; delays 0/80/160/240.
