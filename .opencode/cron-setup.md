# Laravel Scheduler (Cron)

The Laravel scheduler runs via the user crontab. Every minute cron calls
`php artisan schedule:run`, which checks the schedule defined in
`routes/console.php` and runs any due tasks.

## Crontab entry

```
* * * * * cd /home/alaw989/Sites/ipop360 && /usr/bin/php artisan schedule:run >> /home/alaw989/Sites/ipop360/storage/logs/scheduler.log 2>&1
```

## Logs

`storage/logs/scheduler.log` — appended each minute with command output.

## Scheduled tasks (UTC)

| Time (UTC) | Command | Description |
|------------|---------|-------------|
| 01:00 | `restaurants:update-engagement` | Aggregate engagement clicks |
| 02:00 | `restaurants:score` | Recompute popularity scores |
| 03:00 | `apicache:gc` | Garbage collect expired API cache |
| 04:00 | `restaurants:enrich --throttled` | DB enrichment under SerpApi quota |
| 05:00 | `restaurants:backfill-websites` | Backfill missing website URLs |
| 05:30 | `restaurants:scrape-social` | Scrape social links from websites |
| Sat 06:30 | `restaurants:scrape-social --force` | Weekly full refresh |
| Sun 06:00 | `restaurants:verify-websites --limit=200` | Dead-link check |
| every 15min | `uptime:canary` | Health check |

## Restoring after reboot

The crontab persists across reboots — no action needed.
