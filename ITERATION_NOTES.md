# Iteration Notes

## Goal
Bring the homepage stats row (Restaurants 39398, Cuisines 59, Cities 1484) up INTO the hero, directly underneath the search button, and display it dramatically: light text over the dark hero, larger numerals, icons, subtle separators. Fade the row in on load and count the numbers up from 0 to their target on load; respect prefers-reduced-motion (no count-up, instant/no fade). Remove the now-redundant separate StatsBand section from the Welcome idle template (the row now lives in the hero). Pass the existing stats prop into HeroBanner and add a reusable count-up implementation. Add or extend vitest specs (HeroBanner, the count-up logic, Welcome) and keep the HomeControllerTest stats/viewport assertions passing. Verify no regression in a 375px viewport and desktop.

## State
GOAL COMPLETE. Stats row lives in hero (fade-in, staggered count-up, reduced-motion aware, a11y list); useCountUp skips re-animation on unchanged target post-settle. StatsBand removed; stats prop wired into HeroBanner. Full gate green (vitest 1099, HomeControllerTest 20/164, vue-tsc). No improvement remains.

## Log
- ITER-8: verified full goal end-to-end — HeroBanner/useCountUp specs (29) + HomeControllerTest (20/164, stats+viewport assertions) green; no remaining gap.
- ITER-7: useCountUp skips re-animation when target unchanged post-settle (settledTarget guard); 2 new specs. 1099 vitest green.
- ITER-6: HeroBanner stats a11y — `role="list"`/`listitem`, per-item `aria-label` using final stats target, animated numeral+label aria-hidden. 1097 vitest + vue-tsc green.
- ITER-5: useCountUp `delay` param (setTimeout, cleared on unmount, skipped under reduced-motion); HeroBanner staggers 0/80/160. 1096 vitest + vue-tsc green.
- ITER-4: compacted stats row for 375px (px-3/gap-1.5/text-2xl/h-4; sm restores px-10/gap-2/text-4xl/h-5); Playwright no-clip at 375/1440. 1095 vitest green.
