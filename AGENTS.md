# iPop360 — Agent Guide

## Conventions
- Development must always be test driven: write or update tests first, then implement to make them pass.

## Stack
- **Backend**: Laravel 13 + PHP 8.3
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
| `./vendor/bin/phpstan analyse` | PHPStan level 5 (baseline in phpstan-baseline.neon) |

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

The live droplet is at `167.71.107.253` (SSH key `~/.ssh/droplet-vp-nuxt`). **Never copy the SQLite file directly** while live services are writing to it — the copy will corrupt.

Instead, use PHP on the server to export via PDO, then SCP the clean copy:

```bash
# 1. Write a PHP export script to the droplet
ssh -i ~/.ssh/droplet-vp-nuxt root@167.71.107.253 'cat > /tmp/backup.php << '\''PHPEOF'\''
<?php
$src = new PDO("sqlite:/var/www/ipop360/database/database.sqlite");
$src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dst = new PDO("sqlite:/tmp/ipop360-clean.sqlite");
$dst->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = $src->query("SELECT name, sql FROM sqlite_master WHERE type=\"table\" AND name NOT LIKE \"sqlite_%\"")->fetchAll(PDO::FETCH_ASSOC);
foreach ($tables as $t) {
    echo "Creating " . $t["name"] . "...\n";
    $dst->exec($t["sql"]);
}

$tablesList = $src->query("SELECT name FROM sqlite_master WHERE type=\"table\" AND name NOT LIKE \"sqlite_%\"")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tablesList as $table) {
    $count = $src->query("SELECT COUNT(*) FROM \"$table\"")->fetchColumn();
    echo "Copying $table ($count rows)...\n";
    $rows = $src->query("SELECT * FROM \"$table\"")->fetchAll(PDO::FETCH_NUM);
    if (empty($rows)) continue;
    $colCount = count($rows[0]);
    $placeholders = implode(",", array_fill(0, $colCount, "?"));
    $stmt = $dst->prepare("INSERT INTO \"$table\" VALUES ($placeholders)");
    foreach ($rows as $row) { $stmt->execute($row); }
}
echo "Done!\n";
PHPEOF'

# 2. Run the export on the droplet (takes ~8 min for a full DB)
ssh -i ~/.ssh/droplet-vp-nuxt root@167.71.107.253 "php /tmp/backup.php"

# 3. Download the clean copy
scp -i ~/.ssh/droplet-vp-nuxt root@167.71.107.253:/tmp/ipop360-clean.sqlite /tmp/ipop360-clean.sqlite

# 4. Verify integrity and vacuum
sqlite3 /tmp/ipop360-clean.sqlite "PRAGMA integrity_check;"
# Should print "ok"

# 5. Replace local DB and restart services
cp database/database.sqlite database/database.sqlite.backup
cp /tmp/ipop360-clean.sqlite database/database.sqlite
```

The `failed_jobs` table (90k+ rows with large exception payloads) will likely cause the export to time out at 10 min. That's fine — all core data tables (restaurants, cuisines, social links, api cache, etc.) copy first. If `failed_jobs` is corrupt or missing, drop and recreate it:

```bash
sqlite3 database/database.sqlite "DROP TABLE failed_jobs; CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE, connection BLOB NOT NULL, queue BLOB NOT NULL, payload BLOB NOT NULL, exception BLOB NOT NULL, failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);"
```

Also verify row counts: `sqlite3 database/database.sqlite "SELECT COUNT(*) FROM restaurants;"` — should return ~5500+.

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
Daily: 00:30 engagement aggregation → 02:00 score → 03:00 cache GC → 04:00 throttled enrichment → 05:00 website backfill → 05:30 social scrape. Weekly: Sat 06:30 full social re-scrape, Sun 06:00 dead-link check. Every 15min: uptime canary.

## Outdated references
- `.specify/` directory does not exist in this repo. CLAUDE.md references to it are stale.
- SHARED_TASK_NOTES.md, PROMPT_build.md, PROMPT_plan.md, TOOL_AUDIT.md, specs/ are historical/loop artifacts.
