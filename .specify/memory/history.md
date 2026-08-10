# iPop360 Ralph Wiggum History

One-line summaries of completed specs work. For details, see `history/YYYY-MM-DD--spec-name.md`.

---

- 2026-06-19 — [001-kill-dead-yelp-weights](history/2026-06-19--001-kill-dead-yelp-weights.md) — Set Yelp ranking weights to 0 after Yelp removal
- 2026-07-09 — [087-deploy-atomicity-and-auto-rollback](history/2026-07-09--087-deploy-atomicity-and-auto-rollback.md) — Pre-deploy snapshot, non-fatal cache builds, separate restart step, opt-in auto-rollback gate, `db:restore` command, secret injection hardening (090 fold-in)
- 2026-07-09 — [090-deploy-secret-injection-hardening](history/2026-07-09--087-deploy-atomicity-and-auto-rollback.md) — Folded into 087 (STDIN-based secret injection, no-argv leak, worker `|| true`)
- 2026-08-10 — [opencode-loop-quality-sprint](history/2026-08-10--opencode-loop-quality-sprint.md) — PRs #71–#77: PHPStan level 8 zero-baseline, dead-code sweep, LiveSearch orchestration coverage, CI Node-24 actions, page-level vitest for all pages. Key lesson: wait for CI green before merging.
