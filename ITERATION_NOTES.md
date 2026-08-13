# Iteration Notes

## Goal
fix the jamaican/caribbean cuisine keywords so real venue names like "Jerk Pit" match

## State
- Changed: added bare `jerk` keyword to `config/cuisine-keywords.php` jamaican
  lexicon (was only `jerk.chicken`/`jerk.pork`/`jerk.sauce`, which require a
  following word so "Jerk Pit"/"Jerk Hut" never matched). Added test cases to
  `CuisineMatcherTest::test_lexicon_additions_recognize_real_venue_names`.
- Changed: added `caribbean` to the lexicons of all four Caribbean member
  cuisines (jamaican, puerto-rican, trinidadian, haitian) so the "All
  Caribbean" umbrella on-set carries the word itself — a venue literally named
  "Caribbean Grill" now matches. Mirrors the existing `african` convention
  (repeated across every member). Added
  `CuisineMatcherTest::test_caribbean_category_scope_includes_caribbean_keyword`
  + two "Caribbean Grill" evidence cases.
- Changed: added `jamaica` (country name) to the jamaican lexicon — it had
  `jamaican` but not `jamaica`, while `china`/`india`/`brazil`/`thailand`/
  `italia` all carry their country word. Real venues named "Jamaica House" /
  "Jamaica Mi Hungry" now match. Added two evidence cases to
  `CuisineMatcherTest::test_lexicon_additions_recognize_real_venue_names`.
- Changed: added `puerto.rico` (territory name) to the puerto-rican lexicon —
  it had `puerto.rican` but not `puerto.rico`, while `brazil`/`china`/`india`/
  `thailand`/`jamaica` all carry their country/territory word. Real venues named
  "Puerto Rico Restaurant" / "Las Delicias De Puerto Rico" (both tagged
  puerto-rican in the DB) now match. Added two evidence cases to
  `CuisineMatcherTest::test_lexicon_additions_recognize_real_venue_names`.
- Verified: `php artisan test --filter=CuisineMatcherTest` green (21 passed,
  142 assertions); `vendor/bin/pint --test` passed.
- Changed: added `coquito` (PR coconut drink) and `jibarito` (plantain
  sandwich) to the puerto-rican lexicon — both cuisine-specific dish/drink
  terms, so real venues "Coquito's" and "Jibaritos y Más" (both tagged
  puerto-rican in the DB) now match. Added two evidence cases to
  `CuisineMatcherTest::test_lexicon_additions_recognize_real_venue_names`.
- Verified: `php artisan test --filter=CuisineMatcherTest` green (21 passed,
  144 assertions); `vendor/bin/pint --test` passed.
- Changed: added `toston` (singular of `tostones`, fried plantain) to the
  puerto-rican lexicon — the lexicon only carried the plural `tostones`, which
  never matched the real venue "Toston & Melao" (tagged puerto-rican in the
  DB). Added one evidence case to
  `CuisineMatcherTest::test_lexicon_additions_recognize_real_venue_names`.
- Verified: `php artisan test --filter=CuisineMatcherTest` green (21 passed,
  145 assertions); `vendor/bin/pint --test` passed. Re-audited the local DB's
  Caribbean-tagged venues: jamaican 10 (miss "14 Parishes"), puerto-rican 1
  (now matched), trinidadian 2 (miss "JERK HUT ISLAND GRILLE & BEACH CLUB",
  whose name carries `jerk` — a jamaican keyword), haitian 1 (matched).
- Changed: promoted `jerk` to the trinidadian lexicon (jamaican already had it).
  "JERK HUT ISLAND GRILLE & BEACH CLUB" is tagged BOTH jamaican AND trinidadian
  in the DB (confirmed: it appears in both lists, so the trinidadian count of 2
  includes it). Its name carries `jerk`, which was a jamaican-only keyword →
  rival keyword for trinidadian searches, so the venue was both failing to
  on-match and being dropped as rival. Adding `jerk` to trinidadian fixes both:
  `jerk` is now an ON keyword (not rival) for trinidadian. Mirrors the existing
  shared-word convention (`african`/`caribbean` repeated across members). Added
  one evidence case to
  `CuisineMatcherTest::test_lexicon_additions_recognize_real_venue_names`.
- Verified: `php artisan test --filter=CuisineMatcherTest` green (21 passed,
  146 assertions); `vendor/bin/pint --test` passed. Tinker-confirmed `jerk` is
  in trinidadian onKeywords, absent from rivalKeywords, and
  `matchesRivalEvidence('Jerk Hut','trinidadian')` is false.
- Changed: added `14.parish` to the jamaican lexicon — "14 Parishes" (the last
  remaining data-backed Caribbean miss, a New Orleans Jamaican chain named for
  Jamaica's 14 administrative parishes) now matches. The prior note ruled out a
  whole-name keyword, but `14.parish` is the middle ground: requiring the
  numeral "14" makes it specific enough to be safe (does NOT collide with
  Louisiana/ecclesiastical "parish" — verified "St. Charles Parish"/"14th Parish
  Church" don't match) while covering both "14 Parishes" and "14-Parishes".
  Added one evidence case to
  `CuisineMatcherTest::test_lexicon_additions_recognize_real_venue_names`.
- Verified: `php artisan test --filter=CuisineMatcherTest` green (21 passed,
  147 assertions); `vendor/bin/pint --test` passed; full `composer test` green
  (678 passed, 2994 assertions). DB re-audit: all jamaican / puerto-rican /
  trinidadian / haitian venues now on-match (0 remaining misses).
- Changed: added `irie.mon` (Jamaican patois "Irie Mon" = "all good, man") to
  the jamaican lexicon — real venue "Irie Mon Cafe" (2 untagged DB rows) now
  matches. Used the two-word `irie.mon` (not bare `irie`) because `irie` is a
  substring of "Metairie" and would false-match the Vietnamese venue
  "Banh Mi Boys - Metairie". Added a positive evidence case plus a
  negative-guard case to
  `CuisineMatcherTest::test_lexicon_additions_recognize_real_venue_names`.
- Verified: `php artisan test --filter=CuisineMatcherTest` green (21 passed,
  149 assertions); `vendor/bin/pint --test` passed; full `composer test` green
  (678 passed, 2996 assertions).
- Changed: added `pastele` (Puerto Rican tamal, singular spelling) to the
  puerto-rican lexicon — real venue "The Pastele Shop" (present in the MySQL
  local DB, untagged) carries "pastele" which the lexicon lacked. `pastele` is
  a prefix-substring of the plural `pasteles`, so one keyword covers both
  spellings. It is NOT a substring of `pastelon` (p-a-s-t-e-l-o-n) nor of
  `pastel.de.nata`/`pastel.de.choclo` (the 'e' after 'pastel' doesn't appear
  there), so it introduces no substring-guard interaction. Added two evidence
  cases to `CuisineMatcherTest::test_lexicon_additions_recognize_real_venue_names`.
- Verified: `php artisan test --filter=CuisineMatcherTest` green (21 passed,
  151 assertions); `vendor/bin/pint --test` passed; full `composer test` green
  (678 passed, 2998 assertions). Tinker-confirmed `pastele` is in puerto-rican
  onKeywords, absent from rivalKeywords, and "The Pastele Shop"/"Pasteles" now
  on-match (and do NOT false-match jamaican).
- Changed: added `jamrock` (Jamaican patois for Jamaica) to the jamaican
  lexicon — the untagged DB venue "876 Jamrock Restaurant" (also "Jamrock Jerk
  Center & Grill") carries the Jamaica nickname "jamrock" which the lexicon
  lacked, so neither matched. `jamrock` is a highly specific term with no
  English-word substring collisions (verified no unrelated DB names contain
  it). Added one evidence case to
  `CuisineMatcherTest::test_lexicon_additions_recognize_real_venue_names`.
- Verified: `php artisan test --filter=CuisineMatcherTest` green (21 passed,
  152 assertions); `vendor/bin/pint --test` passed; full `composer test` green
  (678 passed, 2999 assertions). Tinker-confirmed `jamrock` is in jamaican
  onKeywords, absent from jamaican rivalKeywords, present as rival for chinese
  (correct: a "Jamrock" venue should drop from a Chinese search), and
  "876 Jamrock Restaurant" now on-matches jamaican.
- Changed: added `coal.pot` (separator form of "coal pot", the traditional
  Trinidadian charcoal cooking vessel) to the trinidadian lexicon — real venue
  "D Coal Pot" (from the earlier prod-DB audit) now matches. The prior note
  grouped "D Coal Pot" under "generic/ambiguous", but "coal pot" is a
  cuisine-specific cooking vessel, not a generic structural word, and the
  `coal.pot` separator form is safe: it does NOT false-match the DB's many
  "Coal Fired Pizza" (Italian) venues, since "pot" must follow the "coal "
  separator. Added a positive evidence case ("D Coal Pot") plus a
  negative-guard case ("Coal Fired Pizza" must NOT match trinidadian) to
  `CuisineMatcherTest::test_lexicon_additions_recognize_real_venue_names`.
- Verified: `php artisan test --filter=CuisineMatcherTest` green (21 passed,
  154 assertions); `vendor/bin/pint --test` passed; full `composer test` green
  (678 passed, 3001 assertions).
- Verified (final, independent re-audit): fresh DB scan of all 8013 rows
  confirms the four Caribbean-tagged sets are fully on-match — jamaican 10,
  puerto-rican 1, trinidadian 2, haitian 1, all 0 misses. Swept untagged venues
  for Caribbean keywords ("Ackee Tree", "Caribbean Cabana", "Anegada Delights
  Caribbean Cuisine", "Trinistyle Cuisine", "9 Mile Jamaican", "Island Flavors
  Caribbean Cuisine", etc.): every one already carries a lexicon keyword and
  matches correctly — no further keyword gap. Full `composer test` green
  (678 passed, 3001 assertions). GOAL COMPLETE.
- Next: none — the goal is fully achieved. Remaining gaps from the earlier
  prod-DB audit are judgment calls
  not addressed by keyword additions: "Koolbreeze Jamerican..." (portmanteau),
  "WAT GET KITCHEN"/"Family Flamez" (generic/ambiguous), "The Peppered Goat"
  (bare "goat" is ambiguous — Indian/Nigerian goat curry too). No bare `patty`
  — DB has zero "patty"-named venues and "patty" collides with the personal
  name "Patty". No bare `irie` — it is a substring of "Metairie". No bare
  `876` — a raw numeral would false-match addresses/phones.
- Gotchas: `14.parish` (raw regex) is a wildcard fragment — "." matches any
  single separator char, so it covers "14 Parishes", "14-Parishes", "14/Parish"
  but NOT "14parish" (no char) or "fourteen" spellings. It becomes a rival
  keyword for non-jamaican cuisines (intended: a "14 Parishes" venue should drop
  from, e.g., a French search). `puerto.rico` is a distinct string from
  `puerto.rican` (not a substring of it), so no substring-guard interaction.
   `toston` is a proper substring of `tostones`, so it only affects ON-cuisine
   matching (the substring guard only applies to rival keywords). `pastele` is
   likewise a prefix-substring of `pasteles` but NOT of `pastelon` (the 'o'
   after 'pastel' differs) — it is a distinct on-keyword with no rival-guard
   interaction; the DB has no "pastelería"-style venues to false-match.

## Log
- Added `jerk` to jamaican lexicon so "Jerk Pit" matches; 677 tests pass.
- Added `caribbean` to all four Caribbean member cuisines so "Caribbean Grill"
  matches "All Caribbean"; 678 tests pass.
- Added `jamaica` to jamaican lexicon so "Jamaica House"/"Jamaica Mi Hungry"
  match; 678 tests pass (2 new assertions inside the existing lexicon test).
- Added `puerto.rico` to puerto-rican lexicon so "Puerto Rico Restaurant"/
  "Las Delicias De Puerto Rico" match; 21 CuisineMatcherTest cases pass (142
  assertions).
- Added `coquito` + `jibarito` to puerto-rican lexicon so "Coquito's" and
  "Jibaritos y Más" match; 21 CuisineMatcherTest cases pass (144 assertions).
- Added `toston` (singular) to puerto-rican lexicon so "Toston & Melao" matches;
  21 CuisineMatcherTest cases pass (145 assertions).
- Promoted `jerk` to trinidadian lexicon so "JERK HUT ISLAND GRILLE & BEACH
  CLUB" (tagged trinidadian) on-matches and is no longer rival-dropped; 21
  CuisineMatcherTest cases pass (146 assertions).
- Added `14.parish` to jamaican lexicon so "14 Parishes" (last data-backed
  miss) matches; 21 CuisineMatcherTest cases pass (147 assertions), full suite
  678 passed.
- Added `irie.mon` to jamaican lexicon so "Irie Mon Cafe" matches (with a
  negative guard so bare `irie` doesn't false-match "Metairie"); 21
  CuisineMatcherTest cases pass (149 assertions), full suite 678 passed.
- Added `pastele` to puerto-rican lexicon so "The Pastele Shop"/"Pasteles"
  match; 21 CuisineMatcherTest cases pass (151 assertions), full suite 678
  passed (2998 assertions).
- Added `jamrock` to jamaican lexicon so "876 Jamrock Restaurant"/"Jamrock
  Jerk Center & Grill" match; 21 CuisineMatcherTest cases pass (152
  assertions), full suite 678 passed (2999 assertions).
- Added `coal.pot` to trinidadian lexicon so "D Coal Pot" matches (with a
  negative guard so it doesn't false-match "Coal Fired Pizza"); 21
  CuisineMatcherTest cases pass (154 assertions), full suite 678 passed
  (3001 assertions).
- Goal complete: independent DB re-audit confirms 0 remaining Caribbean misses
  (jamaican 10 / puerto-rican 1 / trinidadian 2 / haitian 1 all on-match) and no
  untagged venue with a Caribbean keyword is left uncovered. Full suite green.
