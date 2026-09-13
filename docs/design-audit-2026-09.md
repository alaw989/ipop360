# Design audit and redesign plan (September 2026)

The client asked for the site to "look very similar to yelp.com". The brief
also covers the featured restaurant section, professional free fonts, photo
badges that stand out, a better price filter, and a site that behaves like a
native app on a phone. The audit was done on the live site on 2026-09-12, at
1440 px and at 390 px (iPhone). An illustrated version with before
screenshots and mock-ups was shared with the client as a page.

## Decisions (2026-09-12)

| | Decision |
|---|---|
| Fonts | Poppins 600/700 for headings and restaurant names; Source Sans 3 (variable) for everything else. |
| Brand color | The logo's red-orange (#DC5028), darkened to **#C2401C** so white text passes AA (5.2:1). Not Yelp's red: it isn't in the logo, and Yelp's red star tiles are its trade dress. |
| Featured restaurant | An admin picks the restaurant, optionally with a blog story. With no pick, the section shows the top-ranked restaurant near the visitor. |
| Price filter | Several levels at once, as on Yelp; single-price links keep working. |
| Price in results | All four signs, the restaurant's level dark and the rest light ("$$" reads as 2 of 4); no dollar icons, which read as clip-art at 14 px. |

## Findings

- **Fonts.** Only Poppins 400 was loaded, so the 232 medium/semibold/bold uses were all faked by the browser. Poppins is also hard to read at the 12–14 px of card details.
- **Color.** The primary was the component kit's default near-black; the site's reds were one-off shades. Prices showed in bright green ("good deal"), stars as amber outlines, badge tiers in four unrelated hues.
- **Search.** No search box outside the home page. Yelp's defining pattern is a two-part search (what + where) in the header of every page.
- **Home hero.** The search was a fill-in-the-blanks sentence with grey placeholders on a busy photo; the logo and "Beta" appeared twice. All five slideshow photos (1600 px, **2,114 KB**) downloaded at once, on phones too.
- **Featured section.** Titled "Featured Restaurant", it showed a blog post (the site has one), with two "Featured" badges and text over the photo; no rating, price, place or link to the restaurant.
- **Photo badges.** A gradient rank pill (🔥 for #1), a 16 px rank-change bubble, and an 11 px tier chip on a see-through tint that disappears on light photos.
- **Price.** Four separate 36 px squares (below the 44 px tap size), one level at a time. Only **10.7%** of restaurants have a price (see `ranking-metrics.md`, "Where prices come from"), so any price filter hides about nine in ten.
- **Results on phones.** The toolbar didn't wrap (Sort cut off at the screen edge); cards kept the desktop layout, clipping review counts and the Call button and hiding Website and Save. The results range was computed from the page length ("1–20 resultsSort:"). The map used OpenStreetMap's default colorful tiles.
- **Restaurant page.** One inset photo, a mostly empty rating box, an all-caps "POPULARITY SCORE" explained in statistics terms ("Bayesian rating shrinks…"), no hours near the top, a large busy map.
- **Compatibility.** Tailwind v4 and oklch colors need Safari/iOS 16.4+, Chrome 111+ and Firefox 128+. Brand colors are now hex.
- **Good already.** Filters, cuisine and city bottom sheets; the pinned action bar on restaurant pages; safe-area padding; lazy-loaded map and blog editor chunks.

## Design system

| Token | Light | Dark | Use |
|---|---|---|---|
| `--primary` | #C2401C | #F0643C | actions, selected states, saved heart, focus ring |
| `--rating` | #E0661A | #F07828 | rating stars (3.4:1 on white) |
| `--success` | #1E7A3C | #4CB36E | "Open now" only |
| `--foreground` | #2B2928 | #EEEBE8 | text |
| `--muted-foreground` | #6B6663 | #ABA5A1 | addresses, counts, price |
| `--muted` / `--secondary` | #F7F6F5 | #2E2B29 | surfaces |
| `--border` | #E6E3E1 | #3A3634 | borders, empty stars |

Greys lean slightly warm toward the red-orange. The logo's purple and blue
stay in the logo. Type scale: 12 · 14 · 16 · 18 · 21 · 24 · 36 · 48 px, nothing
below 12, no all-caps labels.

**Principles.** The search bar is the one bold element. At most one badge per
photo, solid and readable; the rank lives in the name ("1. Austhentico").
Plain words, no internal scores or percentages. Phone first: 44 px targets,
bottom sheets, safe areas, nothing that only works on hover.

## Build plan

One PR each, merged and checked live on desktop and phone before the next.

1. **Price data** (#190): the website scrape reaches every site; no prices from websites (calibration failed).
2. **Fonts and colors:** real weights, the palette above, `PriceLevel`, solid stars labeled with their source.
3. **Search on every page** and a faster home hero (one photo up front, sized for the screen; under 250 KB on a phone).
4. **Search results:** rank in the name, one photo badge, the phone card, the multi-select price control, the results-range fix, muted map tiles.
5. **Featured restaurant:** `featured_restaurants` table, an admin picker, a spotlight with photo, rating, price, place and an optional story.
6. **Restaurant page:** photo band, action row, contact card, hours near the top, the score in plain words.
7. **Phone polish, speed, compatibility, accessibility:** Add to Home Screen, prefetch, photo sizes, a lighter tooltip, a contrast and tap-target pass.
