# iPop360 — Agent Guide

## Conventions
- Development must always be test driven: write or update tests first, then implement to make them pass.

## Stack
- **Backend**: Laravel 13 + PHP 8.4
- **Frontend**: Inertia 2 + Vue 3 + TypeScript + Tailwind 4 + Vite 8
- **UI**: shadcn-vue (reka-nova style), lucide icons, Leaflet maps
- **DB**: SQLite (dev/test), MySQL (prod)

## Commands

| Command | What it does |
|---------|-------------|
| `composer dev` | Dev server (port 8090) + queue worker + log tailer + Vite HMR, all via concurrently |
| `composer test` | `php artisan config:clear` then `php artisan test` (PHPUnit) |
| `npm run test` | `vitest run` (frontend tests, jsdom, no globals, explicit vitest imports) |
| `npm run build` | `vue-tsc && vite build && vite build --ssr` — **must pass before pushing** |
| `vendor/bin/pint --test` | Laravel Pint lint check |
| `opencode-loop N --goal "…" --check "…"` | Finite-cap iterative improvement loop driving `opencode run` (installed at `~/.local/bin/opencode-loop`) — see `.specify/memory/backlog.md` for the next goal |
| `./vendor/bin/phpstan analyse` | PHPStan level 8 over `app/ + tests/` (zero baseline — `phpstan-baseline.neon` deleted; 18 regex `ignoreErrors` patterns remain in `phpstan.neon` for mockery/Model limitations) |

In agentic/loop sessions prefer **quiet command flags** so output stays out of
the model's context: `npx vitest run <file> --reporter=dot`, `composer test
--compact` (failures only), and tail/two-line versions of anything noisy.
Delegate grep-heavy or exploratory reads to a subagent; read known files at
their exact path instead of searching for them.

## Get running locally

To stand up the full local environment (server + queue + scheduler + vite):

```bash
# Stop anything already on the ports
fuser -k 8090/tcp 2>/dev/null; fuser -k 5173/tcp 2>/dev/null

# Run all four services in the background
nohup php artisan serve --port=8090 > storage/logs/serve.log 2>&1 &
nohup php artisan queue:listen --tries=1 --timeout=0 > storage/logs/queue.log 2>&1 &
nohup php artisan schedule:work > storage/logs/schedule.log 2>&1 &
nohup npm run dev > storage/logs/vite.log 2>&1 &
```

This gives you the same footprint as production: HTTP server on :8090, queue worker processing `EnrichRestaurantWithAi` jobs, scheduler triggering all 11 scheduled tasks on their natural cadence, and Vite HMR on :5173.

Verify: `curl -s -o /dev/null -w "%{http_code}" http://localhost:8090/` should return 200.

## Copy live DB to local

**Prod runs MySQL**, not SQLite — flipped 2026-08-03 (see
`specs/104-infra-mysql-migration.md`) specifically to kill `database is locked`
errors from concurrent writers. The live droplet is at `167.71.107.253` (SSH
key `~/.ssh/droplet-vp-nuxt`), MySQL creds in the droplet's `.env` (`DB_*`).
`mysqldump --single-transaction` takes a consistent snapshot without locking
out live writers, so there's no PDO-export dance needed anymore:

```bash
# 1. Dump on the droplet, compressed (creds from the droplet's .env DB_* vars)
ssh -i ~/.ssh/droplet-vp-nuxt root@167.71.107.253 \
  'mysqldump --single-transaction --quick --routines --triggers \
     -uipop360 -p"<DB_PASSWORD>" ipop360 | gzip > /tmp/ipop360-dump.sql.gz'

# 2. Download it, then remove the remote temp copy
scp -i ~/.ssh/droplet-vp-nuxt root@167.71.107.253:/tmp/ipop360-dump.sql.gz /tmp/
ssh -i ~/.ssh/droplet-vp-nuxt root@167.71.107.253 'rm -f /tmp/ipop360-dump.sql.gz'

# 3. Import into a local MySQL-compatible server (create the DB/user first)
mysql -u<local_user> -p -h127.0.0.1 -e "DROP DATABASE IF EXISTS ipop360; CREATE DATABASE ipop360 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c /tmp/ipop360-dump.sql.gz | mysql -u<local_user> -p -h127.0.0.1 ipop360
```

Then point `.env` at it: `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`,
`DB_PORT=3306`, `DB_DATABASE=ipop360`, `DB_USERNAME`/`DB_PASSWORD` for the
local user. (Local dev still defaults to SQLite for day-to-day work — this
MySQL path is for when you need real prod-fidelity data locally.)

**Known gotcha (hit 2026-08-30, Percona Server 9.7 locally vs. prod's MySQL
8.0.46):** newer MySQL/Percona builds reject `MD5()` inside generated columns
(`ERROR 3763 (HY000): Expression of generated column 'key_hash' contains a
disallowed function: md5`), which breaks Laravel Pulse's `key_hash` columns in
`pulse_aggregates`, `pulse_entries`, `pulse_values`. If import fails on that
error, patch the dump in-flight (same byte length, cosmetic — only affects
Pulse's internal telemetry hash, not app data):

```bash
gunzip -c /tmp/ipop360-dump.sql.gz \
  | sed 's/unhex(md5(`key`))/unhex(left(sha2(`key`,256),32))/g' \
  | mysql -u<local_user> -p -h127.0.0.1 ipop360
```

Verify: `mysql -u<local_user> -p -h127.0.0.1 ipop360 -e "SELECT COUNT(*) FROM restaurants;"` — tens of thousands as of 2026-08 (grows over time; the old ~5500 baseline is long stale).

## Local DB recovery

The dev database is `database/database.sqlite` (SQLite). If it becomes corrupted (e.g. from abrupt shutdown), you'll see `database disk image is malformed` errors on every request.

To recover:
1. Kill any process holding a lock (`lsof -ti:8090 | xargs kill`)
2. Dump critical data table-by-table to a fresh DB:
   ```bash
   php artisan tinker --execute="
   \$old = new SQLite3('database/database.sqlite');
   \$new = new SQLite3('database/database-recovered.sqlite');
   foreach (['migrations','cuisine_categories','cuisines','restaurants','cuisine_restaurant','users','restaurant_social_links','restaurant_engagement','external_api_cache'] as \$t) {
       \$new->exec(\$old->querySingle(\"SELECT sql FROM sqlite_master WHERE type='table' AND name='\$t'\"));
       \$rows = \$old->query(\"SELECT * FROM \\\"\$t\\\"\");
       while (\$r = \$rows->fetchArray(SQLITE3_ASSOC)) {
           \$cols = implode(',', array_keys(\$r));
           \$vals = implode(',', array_fill(0, count(\$r), '?'));
           \$s = \$new->prepare(\"INSERT INTO \\\"\$t\\\" VALUES (\$vals)\");
           foreach (array_values(\$r) as \$i => \$v) \$s->bindValue(\$i + 1, \$v);
           \$s->execute();
       }
   }
   \$old->close(); \$new->close();
   "
   ```
3. Swap files and create auxiliary tables:
   ```bash
   mv database/database.sqlite database/database-corrupted.sqlite
   mv database/database-recovered.sqlite database/database.sqlite
   php artisan tinker --execute="
   \$db = new SQLite3('database/database.sqlite');
   foreach ([
     'cache' => 'CREATE TABLE \"cache\" (\"key\" varchar not null, \"value\" text not null, \"expiration\" integer not null, primary key (\"key\"))',
     'cache_locks' => 'CREATE TABLE \"cache_locks\" (\"key\" varchar not null, \"owner\" varchar not null, \"expiration\" integer not null, primary key (\"key\"))',
     'sessions' => 'CREATE TABLE \"sessions\" (\"id\" varchar not null, \"user_id\" integer, \"ip_address\" varchar, \"user_agent\" text, \"payload\" text not null, \"last_activity\" integer not null, primary key (\"id\"))',
     'failed_jobs' => 'CREATE TABLE \"failed_jobs\" (\"id\" integer primary key autoincrement not null, \"uuid\" varchar not null, \"connection\" varchar not null, \"queue\" varchar not null, \"payload\" text not null, \"exception\" text not null, \"failed_at\" datetime not null default CURRENT_TIMESTAMP)',
     'jobs' => 'CREATE TABLE \"jobs\" (\"id\" integer primary key autoincrement not null, \"queue\" varchar not null, \"payload\" text not null, \"attempts\" integer not null, \"reserved_at\" integer, \"available_at\" integer not null, \"created_at\" integer not null)',
     'job_batches' => 'CREATE TABLE \"job_batches\" (\"id\" varchar not null, \"name\" varchar not null, \"total_jobs\" integer not null, \"pending_jobs\" integer not null, \"failed_jobs\" integer not null, \"failed_job_ids\" text not null, \"options\" text, \"cancelled_at\" integer, \"created_at\" integer not null, \"finished_at\" integer, primary key (\"id\"))',
     'password_reset_tokens' => 'CREATE TABLE \"password_reset_tokens\" (\"email\" varchar not null, \"token\" varchar not null, \"created_at\" datetime, primary key (\"email\"))',
     'favorite_restaurant_user' => 'CREATE TABLE \"favorite_restaurant_user\" (\"id\" integer primary key autoincrement not null, \"user_id\" integer not null, \"restaurant_id\" integer not null, \"created_at\" datetime, \"updated_at\" datetime, foreign key(\"user_id\") references \"users\"(\"id\") on delete cascade, foreign key(\"restaurant_id\") references \"restaurants\"(\"id\") on delete cascade)',
     'pulse_aggregates' => 'CREATE TABLE \"pulse_aggregates\" (\"id\" integer primary key autoincrement not null, \"bucket\" integer not null, \"period\" integer not null, \"type\" varchar not null, \"key\" text not null, \"key_hash\" varchar not null, \"aggregate\" varchar not null, \"value\" numeric not null, \"count\" integer)',
     'pulse_entries' => 'CREATE TABLE \"pulse_entries\" (\"id\" integer primary key autoincrement not null, \"timestamp\" integer not null, \"type\" varchar not null, \"key\" text not null, \"key_hash\" varchar not null, \"value\" integer)',
     'pulse_values' => 'CREATE TABLE \"pulse_values\" (\"id\" integer primary key autoincrement not null, \"timestamp\" integer not null, \"type\" varchar not null, \"key\" text not null, \"key_hash\" varchar not null, \"value\" text not null)',
   ] as \$t => \$sql) { if (!\$db->querySingle(\"SELECT name FROM sqlite_master WHERE type='table' AND name='\$t'\")) \$db->exec(\$sql); }
   \$db->close();
   "
   ```
4. Verify with `php artisan tinker --execute="echo DB::table('restaurants')->count();"` — should show 4636 rows.
5. Restart the dev server: `composer dev`

## Backlog workflow (binding)
- Backlog goals (`.specify/memory/backlog.md`) are implemented EXCLUSIVELY via
  `opencode-loop` — never implement a backlog goal directly. Run the loop in
  **legacy single-branch mode** (the only mode; `--pr` was removed 2026-08-11) on a
  pre-created `feat/<goal-slug>` branch: each iteration is one committed change,
  the loop never pushes or opens PRs. Monitor `logs/opencode-loop-<slug>.out`.
- **Local-first, operator-gated deploy (binding):** looping sessions run LOCALLY.
  Stack goal branches on top of each other (goal N branches off goal N−1's local
  branch). After EVERY goal's loop signals done, harden on the branch before
  stacking the next: `pint --test` → `composer test` → `npm run test` →
  `./vendor/bin/phpstan analyse` → `npm run build` → coverage pre-check
  (`composer coverage` + `npx vitest run --coverage`) — fix anything red, no debt
  carries forward. Do NOT push, open PRs, or deploy until the operator says so;
  multiple goals may land locally before any ship. Deploy is always operator-gated.
- **Shipping (operator says so):** each goal ships as its OWN PR — **one major
  feature per PR**, never a combined mega-PR. Push the branch, run the full gate,
  **create ONE PR and stop to notify the operator** before merging. Merge in
  sequence (stacked order), then deploy + live-verify.
- Backlog ✅ marks happen at MERGE time, never during local looping.
- Docs-only edits (memory bank, `history/`, `ITERATION_NOTES.md`, backlog
  mark-done/renumbering) and the post-loop PR/merge/deploy/verify steps are done
  directly — they are not loop work.

## PR workflow (binding)
- Never push directly to master. Always PR.
- Before opening a PR: `composer test` → `vendor/bin/pint --test` → `npm run build` — all must pass.
- After merge + deploy, verify the change is live on the droplet by testing in browser.

## Architecture
- **4 live-search sources**: BizData (free, no key), SerpApi (~50/mo quota), Overpass/OSM (free), Socrata (free)
- **Processing pipeline** (LiveSearchService::search): garbage name filter → cuisine relevance filter → non-restaurant filter → cross-source dedup (fuzzy name + distance + phone) → distance filter → bound to 60 → cuisine match stamp → score → sort → snapshot for pagination
- **Scoring** (PopularityScoreService): 10 weighted signals — quality 0.35 (Bayesian), website_clicks 0.20, social_links 0.20, proximity 0.15 (live search only), pageviews 0.10, has_award 0.05, cuisine_match 0.50 (live scoped search only), completeness 0.05, social/menu clicks 0.05 each — weights renormalize over each row's active signal set
- **Single JSON shape**: `app/Http/Resources/RestaurantResource.php` for all persisted restaurant responses
- **Shared venue pipeline**: `app/Services/VenuePipeline.php` — dedup, merge, sort, haversine, name matching

## Testing
- Backend: PHPUnit — `tests/Unit/` + `tests/Feature/` (SQLite :memory:, array cache, sync queue)
- Frontend: vitest — `resources/js/**/*.spec.ts` (jsdom, no globals, `@/` and `ziggy-js` aliases in vitest.config.ts)
- Tests are excluded from `vue-tsc` typecheck (see tsconfig.json exclude) — vitest runs them via esbuild
- `php artisan config:cache` must succeed before any cached config reads in prod

## Deploy
- Push to master → GitHub Actions → rsync to DO droplet → migrate → cache → verify
- Supervisor-managed: queue worker (2 procs, auto-restart hourly), Inertia SSR server (node, falls back to CSR), scheduler (cron-driven)
- Live site: https://ipop360.com
- Post-deploy: restart supervisor programs, verify behaviorally in browser (not just "deploy finished")

## Key constraints
- **SerpApi is the only paid source** (~250/mo quota, 80% circuit breaker, 30d cache). All other sources are free/unlimited.
- App works without any API keys (free sources only, no ratings without SerpApi).
- **Config keys read in service constructors** — `Config::set()` must happen before `app()->make(...)`.

## Scheduler (cron: every minute → php artisan schedule:run)
Daily: 00:30 engagement → 01:00 data hygiene → 02:00 score → 03:00 cache GC → 04:00 throttled enrichment (long window, ~04:00–10:00) → 10:15 sitemap → 10:45 social scrape → 11:45 website backfill → 13:45 photo backfill → ai-enrich every 6h. Weekly: Sat 12:00 full social re-scrape, Sun 11:00 dead-link check, Sun 11:30 refresh-awards, Mon 11:00 coverage, Wed 12:30 photo verify. Every 15min: uptime canary. Daily jobs are all scheduled AFTER the enrichment window to avoid SQLite write-lock contention (enforced by SchedulerCollisionTest).

## Outdated references
- `.specify/memory/` (constitution.md, project-state.md, history/, backlog.md) is the **living memory bank** — read it at session start. `history.md` + `.specify/memory/history/` hold per-spec records.
- PROMPT_build.md, PROMPT_plan.md, TOOL_AUDIT.md, SHARED_TASK_NOTES.md, specs/ are historical/loop artifacts. Iteration work uses `opencode-loop` (see backlog).
- `ITERATION_NOTES.md` is the loop's relay file — re-seeded automatically when the goal changes.
