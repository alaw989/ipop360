# 2026-08-15 — Photo pipeline, data hygiene, distance miles, skill conversion, local-first protocol

## Session outcome (all work verified against live droplet)

### Shipped (merged + deployed to prod)
- **#107** — `restaurants:data-hygiene` command (opencode-loop, 4 iters, ALL_DONE):
  scheduled daily 01:00 `--apply --limit=200`. State/city/whitespace/phone
  normalization, true-dup merge (via extracted `RestaurantDeduplicationService`),
  AI-rederive junk rows before delete, AI-enrich missing fields, summary log.
- **#108** — Distance filter switched to MILES. Backend converts query param
  miles→km; resources emit distance in miles; frontend labels/cards `mi`;
  `PopularityScoreService` proximity detail km→mi (latent "mi while km" bug).
- **#109** — Photo verification: `--verify` mode on `restaurants:backfill-photos`
  (HEAD→GET check, re-source dead, weekly Wed 07:30 sweep), LiveVenuePersister
  guard (gps-cs-s never overwrites stable photo).
- **#110** — Name-relevance guard on Wikimedia/Wikipedia image search
  (`titleMatchesRestaurant`). Live audit found 209/400 re-sources were WRONG
  images (books/PDFs/people). 301 wrong images cleared from live DB.
- **#111** — Context-first restaurant image search: `searchImageForRestaurant()`
  chains multi-page website crawl → OSM image= tags → social profile image →
  Wikidata P18 → guarded Wikimedia/Wikipedia → Google CSE last (num=5 pick
  best). `searchAnyImage` is now a thin wrapper. Both backfill + verify use it.

### Current live scheduler (verified firing on time)
- 01:00 data-hygiene, 02:00 score, 03:00 apicache:gc, 04:00 enrich --throttled,
  05:00 sitemap, 05:30 scrape-social, 06:00 backfill-websites,
  06:30 backfill-photos (context chain), 07:30 Wed photo-verify sweep.
- Cron daemon active (`/etc/cron.d/ipop360` → `schedule:run` every min) +
  supervisor scheduler/worker/ssr all RUNNING. 06:30 fill ran today: 153 photos
  found, 445 verify re-sourced, 431 authoritative sweep count.
- **SerpApi quota EXHAUSTED** as of 2026-08-15 (76 calls used, 0 combos today).
  Free-source pipeline carries on; ratings enrichment paused until monthly reset.

## CURRENT OPEN WORK (pick up here after restart)

### 1. Local-first protocol — DONE, needs restart to load
- Created `~/.config/opencode/protocol-default.md` (binding: implement + verify
  locally; NEVER commit/push/PR/merge/deploy until operator approves; leave work
  uncommitted in worktree).
- Wired into `~/.config/opencode/opencode.jsonc` `instructions` (2nd entry).
- **Needs opencode restart to take effect.**

### 2. Enrichment-logs skill → native opencode project skill — DONE, needs restart
- Canonical file now at **`.opencode/skills/enrichment-logs/SKILL.md`** (native
  project-skill path — no symlinks).
- Hardened (opencode-loop, 6 iters, ALL_DONE): MySQL DB summary (NOT stale
  SQLite), current log-message names, quota-exhausted surfaced first, host
  167.71.107.253, flag-aware `--compare` dates, sweep-aggregate verify counts.
- **Removed ALL symlinks** (`~/.config/opencode/skills/` and `~/.claude/skills/`
  copies deleted). User disliked the symlink bridge.
- **Needs opencode restart to register the skill.** Verify with: "check enrichment".
- Worktree: `?? .opencode/skills/` untracked — NOT committed (per protocol).

### 3. Global protocol + skill — both uncommitted in worktree
- `git status` shows only `?? .opencode/skills/`. Nothing committed/pushed.
- PR #112 was closed + remote branch deleted (violated local-first protocol —
  corrected). No re-open until operator approves locally.

## QUEUED BACKLOG GOALS (not yet started, all via opencode-loop, local-only)

### 1. Ingestion-time enrichment (TOP priority — user emphasized)
- Problem (live-verified): of 384 restaurants added in last 7d, 312 (81%) no
  photo, 377 (98%) no description, 375 (98%) no price. Free live-search sources
  set photo_url/description null; nothing bolsters at creation.
- Goal: when `LiveVenuePersister::persist()` CREATES a row, queue async
  enrichment (photo via context chain + AI description/price/phone) so new rows
  are rich in minutes, not weeks. Never block the search response.

### 2. Photo-verify hardening (deferred from #109/#110)
- Verify the `photos` gallery array too (not just primary). Skip
  known-dead-unresolvable rows for N weeks (`photo_verified_at` column).
  Clear-to-null on unresolvable dead (user approved: broken image → null).

### 3. Scheduler/infra hardening audit
- Bare `withoutOverlapping()` everywhere (24h silent-skip risk). 5h35m throttled
  enrichment collision. Per-command runtime/failure telemetry. Cron +
  schedule:work redundancy check.

### 4. Restaurant data-gap remediation
- Live gaps (8,282 rows): description 83% missing, price 75%, no cuisine tag
  68%, phone 46%, photo 39%, hours 32%, 440 dupe name+city+state, 37 bad states.
- Map each gap to owning command + SearchController; tune to close.

### 5. Pull prod DB → local (operator-approved approach)
- `mysqldump` prod `ipop360` excluding runtime tables (pulse_*, sessions,
  cache*, jobs*, failed_jobs, password_reset_tokens) → ~16MB core. Restore into
  local MySQL (`.env` already points there). Verify restaurants ≈ 8,282.

## Key context/commands
- Loop: `opencode-loop N --goal "..." --check "composer test && npm run build"`
  — local-only, work left uncommitted, never pushes/PRs.
- Skill check: `check enrichment` / `/enrichment-logs [--compare] [date]`.
- Local dev: `composer dev`; droplet `167.71.107.253` key `~/.ssh/droplet-vp-nuxt`.
- Current floor: 812 PHPUnit, 1056 vitest, PHPStan 0, pint clean.
