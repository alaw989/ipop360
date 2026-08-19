# Iteration Notes

## Goal
Bring the homepage stats row (Restaurants 39398, Cuisines 59, Cities 1484) up INTO the hero, directly underneath the search button, and display it dramatically: light text over the dark hero, larger numerals, icons, subtle separators. Fade the row in on load and count the numbers up from 0 to their target on load; respect prefers-reduced-motion (no count-up, instant/no fade). Remove the now-redundant separate StatsBand section from the Welcome idle template (the row now lives in the hero). Pass the existing stats prop into HeroBanner and add a reusable count-up implementation. Add or extend vitest specs (HeroBanner, the count-up logic, Welcome) and keep the HomeControllerTest stats/viewport assertions passing. Verify no regression in a 375px viewport and desktop.

## State
Stats row is now an accessible `role="list"` group: each item `role="listitem"` with an `aria-label` of the FINAL target value (not the animated count), and the animated numeral/label are `aria-hidden` so AT reads one clean settled value. 1097 vitest + vue-tsc green.

## Log
- ITER-6: HeroBanner stats a11y — `role="list"`/`listitem`, per-item `aria-label` using final stats target (not animated), animated numeral+label aria-hidden; new spec asserts structure + labels. 1097 vitest + vue-tsc green.
- ITER-5: useCountUp `delay` param (setTimeout, cleared on unmount, skipped under reduced-motion); HeroBanner staggers counts 0/80/160; new delay spec. 1096 vitest + vue-tsc green.
- ITER-4: compacted stats row for 375px (px-3/gap-1.5/text-2xl/h-4 icons; sm: restores px-10/gap-2/text-4xl/h-5); new HeroBanner spec asserts mobile classes; live 375/1440 Playwright measure no-clip; 1095 vitest + vue-tsc green.
- ITER-3: added hero-stats-fade entrance to stats row (transitions.css + reduced-motion block + HeroBanner class); spec asserts class present. 1094 vitest green.
- ITER-2: replaced index ternary with per-item getValue closures in stats row; vue-tsc + HeroBanner/Welcome/useCountUp specs green.
