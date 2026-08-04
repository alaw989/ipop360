# Feature Specification: Migrate production DB from SQLite to MySQL

**Feature Branch**: `master` (interactive)

**Created**: 2026-08-03

**Status**: PLANNED

**Series**: Wave 4 — infrastructure (104 → ...).

## The problem

Live prod runs SQLite (`/var/www/ipop360/database/database.sqlite`, 853MB, ~6.4k restaurants) with
`journal_mode=delete`. Every writer takes an exclusive file lock for the duration of its transaction.
The scheduler overlaps enrichment (04:00), backfill, social scrape, scoring, and the queue worker's
`EnrichRestaurantWithAi` jobs — concurrent writers collide and fail with `SQLSTATE[HY000]: General
error: 5 database is locked`. Live log for 2026-08-03 alone shows 78 such failures
(`Failed to process free venue`). Each one is a venue silently dropped from that enrichment run.

AGENTS.md claims "SQLite (dev/test), MySQL (prod)" — that line is stale; prod has always been SQLite.
The mysql/mariadb drivers are already configured in `config/database.php`; nothing else is in place.

## Goals

1. Kill the `database is locked` failures by giving prod a DB that handles concurrent writers
   (InnoDB row-level locking instead of whole-file exclusive locks).
2. Keep the SerpApi ~250/mo budget intact — the data (restaurants, social links, cache) is worth
   years of quota; the migration must be lossless.
3. No outage beyond a short maintenance window (the deploy already has down → backup → migrate →
   always-up wiring from spec-077).

## Out of scope

- Performance tuning, read replicas, connection pooling, partitioning.
- Moving the cache/queue/sessions off the app DB.
- A schema redesign (this is a straight engine swap; schema changes stay additive afterward).

## Compatibility fixes (done 2026-08-03, all in this PR)

Verified the full suite on a local MySQL 8.4 instance — 411 tests pass on both SQLite and MySQL. Fixes:

1. **`app/Support/SqlDialect.php`** (new) — driver-agnostic SQL fragments:
   - haversine clamp: SQLite scalar `MIN/MAX` → MySQL `LEAST/GREATEST`
   - `julianday('now') - julianday(updated_at)` → MySQL `TIMESTAMPDIFF(SECOND, updated_at, NOW())/86400`
   - `MAX(a,b)` scalar → MySQL `GREATEST(a,b)`
   - `CAST(? AS REAL)` → MySQL `CAST(? AS DOUBLE)`
2. **`app/Models/Restaurant.php`** — haversine (`scopeNearby`) + `decayedPopularityScoreExpression()`
   now use `SqlDialect`.
3. **`app/Http/Controllers/RestaurantController.php` + `SearchController.php`** — dropped `NULLS LAST`
   (MySQL syntax error; MySQL sorts NULLs last on DESC anyway), and the price-sort CASE switched
   `"…"` string literals (MySQL treats `"` as an identifier) to `'…'`, and `GLOB "$*"` (SQLite-only)
   to `LIKE '$%'`.
4. **Migration order** — `create_cuisine_restaurant_table` shared timestamp `2026_06_06_171950` with
   `create_restaurants_table`/`create_cuisines_table` and ran BEFORE them (alphabetical). SQLite
   tolerates forward FK refs; MySQL fails at DDL. FKs moved out of the create into a new guarded
   migration `2026_08_03_000001_add_foreign_keys_to_cuisine_restaurant_table` (no-ops when the FK
   already exists, so pre-existing SQLite DBs are unaffected).
5. **`external_api_cache.data`** — was `jsonb()` which becomes MySQL `JSON`; MySQL reorders JSON object
   keys on storage, breaking cache round-trips. Now `text()` (Eloquent `array` cast already encodes/
   decodes), round-trips exactly. The restaurants JSON-in-TEXT columns were already fine.

## Approach: MySQL 8 on the droplet, dump-and-load with verification

Do NOT attempt `artisan migrate:fresh` on prod (would rebuild restaurants from scratch — impossible
under SerpApi quota). Instead: dump the live SQLite DB, load into MySQL, verify row-for-row, then
flip `DB_CONNECTION`. Laravel's built-in `migrations` table is compatible, so existing migrations
apply cleanly after the flip.

### Phases

1. **Provision** — install MySQL 8 server on the droplet, secure it, create `ipop360` DB + user with
   a scoped password (inject via the same secret mechanism the deploy workflow uses, spec-090). Bind to
   127.0.0.1. Verify `pdo_mysql` is loaded in the PHP-FPM + queue/CLI SAPI.

2. **Schema** — dump SQLite schema (`sqlite3 ... .schema`), translate to MySQL DDL (column types,
   indexes, FKs, drop `AUTOINCREMENT` semantics in favor of `AUTO_INCREMENT`). Generate a one-time
   migration file that is a no-op once `migrations` matches (or run raw SQL, recorded in the spec).

3. **Data dump** — export each table via PDO (same technique as the AGENTS.md live-copy script —
   never copy the SQLite file while services write). Write a `db:dump-sqlite` artisan command that
   streams each table to TSV/CSV to avoid 853MB in memory.

4. **Load** — `LOAD DATA LOCAL INFILE` (or prepared-insert batches) per table, in FK order. Keep
   source `id` values identical so `favorite_restaurant_user` / `restaurant_engagement` / foreign keys
   stay intact. Disable FK checks during load, re-enable after.

5. **Verify** — row-count match per table, checksums (e.g. `COUNT(*)` + `COALESCE(SUM(...))` over a
   few numeric columns), spot-check `restaurants` slugs/URLs, `external_api_cache` TTL rows, `users`.
   The spec-086 deploy verify must include a "restaurant count within X of pre-migration" assert.

6. **Flip** — set `DB_CONNECTION=mysql` + creds in `.env`. Keep SQLite as the dev/test DB
   (`phpunit.xml` already uses `:memory:`). Add a read-only sanity route or tinker check that the app
   serves rows. Watch the queue worker + scheduler logs for 24h; confirm zero `database is locked`.

7. **Rollback** — keep the pre-migration SQLite snapshot (spec-077 backup) and the dumped SQL files
   on the droplet for 30 days. If MySQL is broken, flip `.env` back and redeploy; SQLite data is
   unchanged because it was only ever read during the dump.

## Migration commands (sketch — final commands live in the migration script)

```bash
# On droplet
apt install mysql-server
mysql_secure_installation
mysql -e "CREATE DATABASE ipop360 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'ipop360'@'127.0.0.1' IDENTIFIED BY '<scoped>'; GRANT ALL ON ipop360.* TO 'ipop360'@'127.0.0.1';"

# Dump SQLite (app-side command reads the live DB)
php artisan db:dump-sqlite --output=/tmp/ipop360-mysql

# Load
for t in cuisines cuisine_categories restaurants ...; do
  mysql -uipop360 -p ipop360 < /tmp/ipop360-mysql/$t.sql
done

# Verify
php artisan db:verify-mysql --expected-rows-from=/tmp/ipop360-mysql/summary.json
```

## Risks

- **SQLite vs MySQL type drift** — booleans (SQLite 0/1 → MySQL TINYINT(1)), `datetime` defaults
  (`CURRENT_TIMESTAMP` vs Laravel `now()`). The JSON column mapping was already resolved (see
  compatibility fixes above).
- **The app assumes SQLite quirks** — search the code for raw SQL / `sqlite_*` / `PRAGMA` /
  `date('now')` etc. and neutralize them (JSON_ functions, `limit()`/`offset()` both fine, but
  `groupBy`/`having` strictness differs).
- **InnoDB row-size limits** — `restaurants` is wide; confirm no row exceeds 65535-byte index limit.
- **Migration timing** — the `artisan migrate --force` on deploy now touches MySQL, not SQLite.
  Any additive migration must be MySQL-compatible from day one (CI runs SQLite; add a MySQL test job).
- **The `external_api_cache`** (30d TTL) — SerpApi results must survive the move or the ~250/mo
  budget takes a hit on re-fetch. Cache must be loaded verbatim (it is, phase 4).

## Acceptance criteria

- [ ] MySQL running on droplet, app connects, `restaurants` count matches pre-migration exactly.
- [ ] 24h of prod logs with zero `database is locked`.
- [ ] Existing test suite green on SQLite; `npm run build` + `vendor/bin/pint --test` + PHPStan clean.
- [ ] Deploy workflow: migrate runs against MySQL, spec-077 down/backup/always-up still sound.
- [ ] SerpApi cache loaded verbatim; budget unchanged for the month following migration.

## Recommended timeline

- 1 day: provisioning + schema translation + dump/load scripts (dump of 853MB SQLite ~8 min per
  AGENTS.md live-copy experience; load similar).
- Half day: verification + app-side SQLite-quirk sweep + CI MySQL job.
- Maintenance-window flip + 24h watch: same deploy, low-traffic hour.

## Decisions (locked 2026-08-03)

1. **MySQL 8** (not MariaDB) — native `JSON` type parity with the SQLite JSON-in-TEXT columns,
   plus `->`/`->>` operators.
2. **On-droplet MySQL, bound to `127.0.0.1`** — single-tenant, ~6.4k restaurants, no managed cost.
   Single point of failure is unchanged from today (SQLite is single-file).
3. **Keep the SQLite file after the flip** — archived to `storage/backups/`, retained 30 days, then
   deleted. It is read-only after the dump; never in the live path. Protects the SerpApi cache
   against a MySQL-side data disaster for 30 days.

## Flip (Phase 5, done 2026-08-03 23:14 UTC)

- Droplet `.env` switched `DB_CONNECTION=sqlite` → `mysql` (backup at `.env.sqlite-backup`).
- `config:cache` rebuilt; cached config confirms `default: mysql, host: 127.0.0.1, db: ipop360`.
- All 5 supervisor processes (worker ×2, enrich-loop, scheduler, SSR) restarted on MySQL, all RUNNING.
- Live verified: home 200, API returns restaurants, sort modes 200, SSR renders 186KB HTML.
- Zero app errors since the flip; the only `Connection: mysql` error in logs was the pre-fix copy
  attempt at 23:12 (config-cache issue), not the live app.
- Live SQLite archived to `storage/backups/database.sqlite.pre-mysql-20260803.sqlite` (854MB).
- Rollback: `.env.sqlite-backup` + the archived SQLite restore the old path in minutes.

## Provisioning (Phase 1, done 2026-08-03)

- Ubuntu 24.04, MySQL **8.0.46** installed, enabled, running, bound to `127.0.0.1:3306` only.
- `pdo_mysql` loaded in droplet CLI + PHP-FPM 8.4.
- DB `ipop360` (utf8mb4_unicode_ci) + user `ipop360@localhost` with scoped password.
- Creds injected via the spec-090 deploy pattern (GitHub secret → runner env → SSH stdin → `.env`)
  at flip time, same as SERPAPI_API_KEY / AI_API_KEY. The droplet `.env` currently has
  `DB_CONNECTION=sqlite`; MySQL creds are added at Phase 5.

