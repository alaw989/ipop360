# Tool Quality Audit

## Fixed This Session

| Date | Tool | Issue | Fix |
|---|---|---|---|
| 2026-07-20 | `QuotaStatusCommandTest` | Tests hardcoded `monthly_budget` of 150, but env was updated to 250 | Updated assertions to expect 250 and recalculated percentages |

## Per-Tool Status

| Tool | Status | Notes |
|---|---|---|
| `QuotaStatusCommandTest` | ✓ | All 9 tests pass (406 total) |
