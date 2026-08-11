# iPop360 Ralph Wiggum History

One-line summaries of completed specs work. For details, see `history/YYYY-MM-DD--spec-name.md`.

---

- 2026-06-19 — [001-kill-dead-yelp-weights](history/2026-06-19--001-kill-dead-yelp-weights.md) — Set Yelp ranking weights to 0 after Yelp removal
- 2026-07-09 — [087-deploy-atomicity-and-auto-rollback](history/2026-07-09--087-deploy-atomicity-and-auto-rollback.md) — Pre-deploy snapshot, non-fatal cache builds, separate restart step, opt-in auto-rollback gate, `db:restore` command, secret injection hardening (090 fold-in)
- 2026-07-09 — [090-deploy-secret-injection-hardening](history/2026-07-09--087-deploy-atomicity-and-auto-rollback.md) — Folded into 087 (STDIN-based secret injection, no-argv leak, worker `|| true`)
- 2026-08-10 — [opencode-loop-quality-sprint](history/2026-08-10--opencode-loop-quality-sprint.md) — PRs #71–#77: PHPStan level 8 zero-baseline, dead-code sweep, LiveSearch orchestration coverage, CI Node-24 actions, page-level vitest for all pages. Key lesson: wait for CI green before merging.
- 2026-08-11 — [opencode-loop-sprint](history/2026-08-11--opencode-loop-sprint.md) — PRs #78–#82: scheduled-command test coverage, complex component vitest specs, CI coverage enforcement (PHPUnit + vitest thresholds), CI PHP 8.4 alignment, user roles foundation. Key lesson: hand-review the loop's CI/`ci.yml` output before merging (split-job regression), gitignore `coverage/` artifacts.
- 2026-08-11 — [blog-editor-permissions](history/2026-08-11--blog-editor-permissions.md) — PR #83: editor-role users CRUD their own blog posts (admins manage all); `UserRole` enum; `EnsureUserIsAdmin` → parameterized `EnsureUserHasRole`; ownership scoping. PHPUnit 631 (+15), vitest 937 (+9). Key lesson: Larastan infers `#[Fillable]` columns as `string`, so an enum *cast* conflicts with PHPStan level 8 — use `UserRole::tryFrom($role)` compares instead. Live-verified behaviorally in-browser (editor login → CRUD → public publish, `/admin` 403).
