# Iteration Notes

## Goal
continuous restaurant data-hygiene loop: new restaurants:data-hygiene command (scheduled daily, bounded pass per run) that (1) deterministically normalizes state (full names→abbreviations via a state map, lowercase→uppercase, foreign/junk states→null), city (title-case with small-word exceptions), name/address whitespace (collapse double spaces, strip trailing), and phone (normalize), (2) merges true duplicates (exact name+city+coords and same-phone+same-city groups, reusing the existing DeduplicateRestaurants::mergePair logic — chain locations with the same name but different city/coords are NOT merged), (3) AI-rederives junk rows (one-char names, empty shells) before hard-delete, (4) AI-enriches still-missing fields (description/price_range/phone/website, bounded ~200 rows/day highest score first via the existing EnrichRestaurantWithAi job), (5) logs a per-run summary to the enrichment channel. Default dry run, --apply persists

## State
All goal parts DONE — normalization + true-dup merge + AI-rederive-junk + AI-enrich missing fields + summary logging, plus the bounded-pass `--limit` option, now wired into the daily schedule. Nothing remaining.

What landed this iteration (`--limit` bounded pass):
- `app/Console/Commands/DataHygiene.php` — new `--limit` option parsed by `parseLimit()` (positive int → bound, otherwise null = unbounded). It bounds **merge pairs** (not rows examined): `mergeDuplicates` ksort-sorts the normalized rows by id, then `mergeGroups` shares a by-ref `$budget` that is decremented per `mergePair` call and stops both passes when exhausted — so the whole corpus gets deduped over successive runs instead of stalling on a lowest-N-id slice. It also caps **enrich** at `min(limit, ENRICH_DAILY_LIMIT=200)`, preserving the Groq-quota guard (a `--limit` can only shrink enrich, never expand it). `pass_limit` added to the log summary and a "Pass bound" line to the console render.
- `routes/console.php` — daily schedule now runs `restaurants:data-hygiene --apply --limit=200` (bounded 200 merge pairs + 200 enrich rows/day).
- `tests/Feature/DataHygieneCommandTest.php` — schedule test asserts `--apply --limit=200`; 3 new tests: `--limit=1` merges exactly one of two dup pairs (lowest-id first), `--limit=2` caps enrich dispatch to 2, `--limit=0` falls back to unbounded.

Next: none — goal complete.

Gotchas:
- **`.env` AI key leaks into tests** — `phpunit.xml` doesn't set `AI_API_KEY` and there is no `.env.testing`, so the dev key is live in every test unless cleared. Any command test that can reach the AI path must clear `services.ai` in `setUp()` (see DataHygieneCommandTest) or `Http::fake()` every AI endpoint, or it will fire real HTTP and mock-mismatch `Log::warning`.
- `assertDatabaseHas/Missing($table, $data, $connection)` — a 3rd string is a connection name, not a message.
- Merge detection runs in-memory over normalized fields so dry-run and apply group identically (DB floats are keyed via string concat).
- The dedupe `findDuplicatePairs`/`mergePair` semantics are preserved exactly by the service; the dedupe command output still prints "DRY RUN".
- `restaurants.name` is NOT NULL — junk "empty shells" are `name = ''`, not `name = null`; `collapseWhitespace('')` returns null so the name normalization must skip null to avoid a constraint violation.
- `rederiveName` throws `RuntimeException` when all AI providers are rate-limited — the command catches it and skips (leaves the row), never hard-deletes on a transient AI failure.
- Junk tests mock AI via `Http::fake` on `https://api.groq.com/openai/v1/chat/completions` after `config(['services.ai' => ...])`; assert the enrichment summary log with `Log::shouldReceive('channel')->with('enrichment')->andReturnSelf()`.
- Enrichment eligibility excludes blank names (`trim(name) <> ''`) so empty-shell junk rows (deleted/re-derived in the prior step) aren't also queued for enrichment; scoring sort uses `COALESCE` for cross-dialect MySQL/SQLite compat (MySQL has no `NULLS LAST`).
- The `--limit` bounds merge *pairs* (via a shared `$budget` by-ref through `mergeGroups`), not the rows examined — bounding by rows would wedge on the lowest-N ids and never reach the rest of the corpus. Pint strips `@param` on native-typed params (incl. `?int &$budget`) and converts `! $x` → `!$x`; run `vendor/bin/pint` before committing.

## Log
- [2026-08-14] Created `restaurants:data-hygiene` (normalize + merge + summary), extracted `RestaurantDeduplicationService`, scheduled daily, fixed 2 test-seed assertion bugs. 755 tests green, pint + phpstan + build clean.
- [2026-08-14] Part 3: AI junk re-derivation (`rederiveName`) + junk-row hard-delete in the hygiene command; refactored `AiEnrichmentService` provider loop into `callProviders`. 763 tests green, pint + phpstan + build clean.
- [2026-08-14] Part 4: `enrichMissingFields` step dispatches `EnrichRestaurantWithAi` for still-missing fields (bounded 200/day, highest score first); test `setUp()` clears the leaked `.env` AI key. 767 tests green, pint + phpstan clean.
- [2026-08-14] Bounded-pass `--limit` option: merge bounded by pair budget (lowest-id first, shared by-ref `$budget`), enrich capped at `min(limit, 200)`; daily schedule wired to `--apply --limit=200`. 770 tests green, pint + phpstan clean.
