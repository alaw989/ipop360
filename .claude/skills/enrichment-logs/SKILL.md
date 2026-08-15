---
name: enrichment-logs
description: "Check the live droplet's enrichment log for evidence that commands are running, with optional day-over-day comparison. Usage: 'check enrichment', '/enrichment-logs 2026-07-16', or 'check enrichment --compare'"
---

# Check Enrichment Logs

Triggered when the user mentions enrichment, throttled, budget, quota, photo backfill, photo verify, or AI enrich. Checks the JSON enrichment log on the **live droplet** and queries the **live MySQL** database (restaurants now live in MySQL — the old SQLite mount is stale and must not be used).

Supports a **day-over-day comparison** (`--compare`).

## Setup

- **Droplet:** `167.71.107.253` (SSH key `~/.ssh/droplet-vp-nuxt`) — per AGENTS.md
- **Log dir:** `/var/www/ipop360/storage/logs/enrichment-YYYY-MM-DD.log` (JSON lines, 30-day retention)
- **DB:** MySQL `ipop360` on the droplet; creds read from `/var/www/ipop360/.env` at query time
- Mount via `sshfs` at `/tmp/ipop360-remote` (reuse if already mounted)

## Steps

### 1. Determine the log date

Defaults to today. A date like `2026-07-16` overrides. `--compare` adds yesterday's side-by-side.

### 2. Mount the droplet (once)

```bash
MOUNT_POINT="/tmp/ipop360-remote"
if ! mountpoint -q "$MOUNT_POINT"; then
    mkdir -p "$MOUNT_POINT"
    sshfs -o IdentityFile=~/.ssh/droplet-vp-nuxt,reconnect,ServerAliveInterval=15,allow_other \
      root@167.71.107.253:/var/www/ipop360 "$MOUNT_POINT"
fi
```

### 3. Parse today's log

```bash
LOG_DATE="${1:-$(date +%Y-%m-%d)}"
LOG_FILE="/tmp/ipop360-remote/storage/logs/enrichment-${LOG_DATE}.log"

python3 -c "
import json, os, sys
from collections import Counter

log_path = '${LOG_FILE}'
if not os.path.exists(log_path):
    print(f'No enrichment log found for ${LOG_DATE}')
    sys.exit(0)

entries = []
for line in open(log_path):
    line = line.strip()
    if line:
        try:
            entries.append(json.loads(line))
        except json.JSONDecodeError:
            pass

print(f'[enrichment] Log Check — ${LOG_DATE}')
print(f'  ({len(entries)} total entries)')
print()

# --- SerpApi quota (surface FIRST — a quota-exhausted enrichment is a headline) ---
starts = [e for e in entries if e.get('message') == 'Starting throttled enrichment']
results = [e for e in entries if e.get('message') == 'Throttled enrichment result']
completes = [e for e in entries if e.get('message') == 'Throttled enrichment complete']

if starts:
    s = starts[0]
    ctx = s.get('context', {})
    print(f'[enrich] Throttled enrich started at {s[\"datetime\"][11:16]}'
          f'  (cap: {ctx.get(\"per_run_cap\", \"?\")},'
          f' budget: {ctx.get(\"monthly_budget\", \"?\")},'
          f' used: {ctx.get(\"real_calls_this_month\", \"?\")})')
    if results:
        r = results[-1]
        rc = r.get('context', {})
        print(f'  -> {rc.get(\"combos_processed\", \"?\")} combos,'
              f' {rc.get(\"real_calls_made\", \"?\")} calls,'
              f' {rc.get(\"cache_hits_skipped\", \"?\")} cache hits')
        if rc.get('quota_exhausted'):
            print('  ⚠ [QUOTA] SerpApi quota EXHAUSTED — throttled enrichment ran 0 combos; ratings/quality enrichment is paused until the monthly window resets. Free-source work (photos, social, AI, backfill) continues.')
    elif completes:
        c = completes[-1]
        cc = c.get('context', {})
        print(f'  -> {cc.get(\"total_processed\", \"?\")} processed,'
              f' {cc.get(\"real_calls_made\", \"?\")} calls,'
              f' {cc.get(\"cache_hits_skipped\", \"?\")} cache hits')
        if cc.get('quota_exhausted'):
            print('  ⚠ [QUOTA] SerpApi quota EXHAUSTED — see above.')
else:
    print('Throttled enrichment: No run detected')

# --- Cap / budget / cache-skip events ---
caps = [e for e in entries if e.get('message') == 'Per-run cap reached']
budgets = [e for e in entries if e.get('message') == 'Monthly budget exhausted']
skips = [e for e in entries if e.get('message') == 'Skipping cache-fresh combo']
if caps:
    print(f'[cap] Per-run cap reached at {caps[-1][\"datetime\"][11:19]}')
if budgets:
    print(f'[budget] Monthly budget exhausted at {budgets[-1][\"datetime\"][11:19]}')
if skips:
    print(f'[cache] {len(skips)} cache-fresh combos skipped')

# --- Photo pipeline (current message names) ---
backfill = len([e for e in entries if e.get('message') == 'Photo backfill found photo'])
verify_resourced = len([e for e in entries if e.get('message') == 'Photo verify re-sourced dead photo'])
verify_sw = len([e for e in entries if e.get('message') == 'Photo verify sweep complete'])
image_src = len([e for e in entries if e.get('message') == 'Image search source'])
if backfill or verify_resourced:
    print(f'[photo] Backfill found: {backfill}  |  Verify re-sourced: {verify_resourced}  |  Image-search source attributions: {image_src}')

# --- AI enrichment ---
ai_completes = [e for e in entries if e.get('message') == 'AI enrichment complete']
ai_fails = [e for e in entries if e.get('message') == 'All AI providers rate-limited or failed']
ai_dispatch_fails = [e for e in entries if e.get('message') == 'AI enrichment dispatch failed for restaurant']
if ai_completes:
    models = {e.get('context', {}).get('model') for e in ai_completes if e.get('context', {}).get('model')}
    print(f'[ai] Enrich: {len(ai_completes)} restaurants via {\", \".join(sorted(models))}')
if ai_fails:
    print(f'[ai] Rate-limited: {len(ai_fails)} restaurants')
if ai_dispatch_fails:
    print(f'[ai] Dispatch failed: {len(ai_dispatch_fails)} restaurants')

# --- Social scrape (current message names) ---
social_found = len([e for e in entries if e.get('message') == 'Social scrape found links'])
social_done = [e for e in entries if e.get('message') == 'Social scrape completed']
if social_done:
    s = social_done[-1]
    ctx = s.get('context', {})
    print(f'[social] Scrape: {ctx.get(\"updated\", \"?\")} updated, {ctx.get(\"skipped\", \"?\")} skipped, {ctx.get(\"errors\", \"?\")} errors ({ctx.get(\"total_processed\", \"?\")} total)')
if social_found:
    print(f'[social] Links found: {social_found}')

# --- Website backfill (current message names) ---
web_web = len([e for e in entries if e.get('message') == 'Website backfilled from web search'])
web_cache = len([e for e in entries if e.get('message') == 'Website backfilled from cache'])
web_domain = len([e for e in entries if e.get('message') == 'Website backfilled from domain guess'])
if web_web or web_cache or web_domain:
    print(f'[website] Backfilled: {web_web} web-search, {web_cache} cache, {web_domain} domain-guess')

# --- Scoring / engagement ---
scores = [e for e in entries if e.get('message') == 'Scoring complete']
if scores:
    print(f'[score] Complete at {scores[-1][\"datetime\"][11:19]}')
eng = len([e for e in entries if e.get('message') == 'Engagement counters updated'])
if eng:
    print(f'[engagement] Counters updated')

# --- Venue create/update ---
created_search = len([e for e in entries if e.get('message') == 'Venue created via search'])
created_fav = len([e for e in entries if e.get('message') == 'Venue created via favorites'])
updated = len([e for e in entries if e.get('message') == 'Venue updated'])
if created_search or created_fav or updated:
    parts = []
    if created_search: parts.append(f'+{created_search} search')
    if created_fav: parts.append(f'+{created_fav} favorites')
    if updated: parts.append(f'{updated} updated')
    print(f'[venues] {\", \".join(parts)}')

# --- Errors ---
errors = [e for e in entries if e.get('message') == 'Failed to process free venue']
if errors:
    print()
    print('[errors]')
    for e in errors[-3:]:
        ctx = e.get('context', {})
        print(f'  {e[\"datetime\"][:19]}  {ctx.get(\"name\", \"?\")} — {str(ctx.get(\"message\", \"?\"))[:80]}')

# --- Noop / empty ---
noops = len([e for e in entries if e.get('message') == 'No free venues found'])
if noops:
    print(f'[free-apis] {noops} skips (no new results from free APIs)')
"
```

### 4. Database summary (live MySQL — NOT the stale SQLite)

Query the droplet's MySQL via SSH. Read creds from `.env` on the droplet:

```bash
ssh -i ~/.ssh/droplet-vp-nuxt root@167.71.107.253 "cd /var/www/ipop360 && mysql -uipop360 -p\"\$(grep '^DB_PASSWORD=' .env |cut -d= -f2-)\" ipop360 -N -e '
SELECT DATE(created_at) cohort, COUNT(*) created FROM restaurants WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 3 DAY) GROUP BY cohort ORDER BY cohort DESC;
SELECT \"new today\", COUNT(*) FROM restaurants WHERE DATE(created_at)=CURDATE();
SELECT \"  with photo\", COUNT(*) FROM restaurants WHERE DATE(created_at)=CURDATE() AND photo_url IS NOT NULL AND photo_url!=\"\";
SELECT \"  with desc\", COUNT(*) FROM restaurants WHERE DATE(created_at)=CURDATE() AND description IS NOT NULL AND description!=\"\";
SELECT \"  with price\", COUNT(*) FROM restaurants WHERE DATE(created_at)=CURDATE() AND price_range IS NOT NULL AND price_range!=\"\";
SELECT \"  with hours\", COUNT(*) FROM restaurants WHERE DATE(created_at)=CURDATE() AND opening_hours IS NOT NULL AND opening_hours!=\"[]\";
SELECT \"  with phone\", COUNT(*) FROM restaurants WHERE DATE(created_at)=CURDATE() AND phone IS NOT NULL AND phone!=\"\";'"
```

Interpret the coverage row — low `with photo` / `with desc` / `with price` on new rows signals the **ingestion-time enrichment gap** (new rows come in bare because free live-search sources don't carry those fields).

### 5. Day-over-day comparison (`--compare`)

```bash
LOG_DATE="${1:-$(date +%Y-%m-%d)}"
PREV_DATE=$(date -d "$LOG_DATE - 1 day" +%Y-%m-%d)

python3 -c "
import json

def load(d):
    out = []
    for line in open(f'/tmp/ipop360-remote/storage/logs/enrichment-{d}.log'):
        line = line.strip()
        if line:
            try: out.append(json.loads(line))
            except json.JSONDecodeError: pass
    return out

def cnt(es, m): return len([e for e in es if e.get('message') == m])

t = load('${LOG_DATE}')
y = load('${PREV_DATE}')
if not t: print(f'No log for ${LOG_DATE}'); exit(1)
if not y: print(f'No log for ${PREV_DATE} — cannot compare'); exit(1)

def delta(a, b):
    d = a - b
    return f'+{d}' if d > 0 else str(d)

def pct(a, b):
    if b == 0: return '—' if a == 0 else '+100%'
    return f'{((a-b)/b)*100:+.0f}%'

rows = [
    ('Venues created (search)', cnt(t,'Venue created via search'), cnt(y,'Venue created via search')),
    ('Photos found (backfill)', cnt(t,'Photo backfill found photo'), cnt(y,'Photo backfill found photo')),
    ('Verify re-sourced', cnt(t,'Photo verify re-sourced dead photo'), cnt(y,'Photo verify re-sourced dead photo')),
    ('AI enrich complete', cnt(t,'AI enrichment complete'), cnt(y,'AI enrichment complete')),
    ('Social links found', cnt(t,'Social scrape found links'), cnt(y,'Social scrape found links')),
    ('Website backfilled', cnt(t,'Website backfilled from web search')+cnt(t,'Website backfilled from cache')+cnt(t,'Website backfilled from domain guess'), cnt(y,'Website backfilled from web search')+cnt(y,'Website backfilled from cache')+cnt(y,'Website backfilled from domain guess')),
    ('Total entries', len(t), len(y)),
]
print(f'{\"Metric\":<28}{\"$PREV_DATE\":>12}{\"$LOG_DATE\":>12}')
print('-' * 52)
for name, tt, yy in rows:
    print(f'{name:<28}{yy:>12}{tt:>12}')
"
```

## What each section means

| Indicator | Meaning |
|---|---|
| `[enrich]` / `[QUOTA]` | Daily throttled enrichment; **quota-exhausted is surfaced first** (SerpApi paused, free-source work continues) |
| `[photo]` | Photo backfill (found) + verify sweep (re-sourced dead gps-cs-s) + per-source attribution |
| `[ai]` | `restaurants:ai-enrich` LLM enrichments |
| `[social]` | `restaurants:scrape-social` links found + completed summary |
| `[website]` | `restaurants:backfill-websites` (web-search / cache / domain-guess) |
| `[venues]` | Rows created via search/favorites + updated |
| `[errors]` | Failed venue processing |
| `DB summary` | Live MySQL report of new-row coverage — **low photo/desc/price on new rows = ingestion gap** |

## Notes

- Always mount at `167.71.107.253` (AGENTS.md host), key `~/.ssh/droplet-vp-nuxt`. Unmount with `fusermount -u /tmp/ipop360-remote`.
- **Never read `/tmp/ipop360-remote/database/database.sqlite`** — that mount copy is stale (pre-MySQL). Query MySQL over SSH instead.
- Log message names were updated to the CURRENT lines (2026-08): `Photo backfill found photo`, `Photo verify re-sourced dead photo`, `Image search source`, `Website backfilled from …`, `Social scrape found links`, `Venue created via search`.
