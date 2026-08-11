# 2026-08-11 — Blog editor permissions (PR #83)

**Date:** 2026-08-11 · **Branch:** `feat/blog-editor-permissions` · **Status:** COMPLETE

## What shipped (deployed + live-verified behaviorally, CI green before merge)
Backlog goal #1: `editor`-role users get CRUD on their **own** blog posts (including
publishing); admins manage all. Also landed the flagged `UserRole` enum sub-task.

- **`UserRole` enum** (`app/Enums/UserRole.php`) — `admin`/`editor`/`user`, with
  `canManageBlog()` (admin|editor) and `canManageAllBlogPosts()` (admin only).
- **`User` model** — `isAdmin()`, `isEditor()`, `canManageBlog()`, `canManageAllBlogPosts()`.
- **Middleware** — `EnsureUserIsAdmin` deleted → parameterized `EnsureUserHasRole`
  (`role:admin,editor`); alias `role` registered in `bootstrap/app.php`.
- **Routes** — `/admin` dashboard stays `role:admin`; `/admin/blog` resource is
  `role:admin,editor` (nested group under the same `admin` prefix).
- **Controller** — `index` scopes to `author_id = user->id` for non-admins; `edit`/`update`/
  `destroy` abort(403) on others' posts via `authorizePost()`; `author_id` auto-set on
  create (already the case, kept).
- **Frontend** — Blog nav link (desktop + mobile) shown to editors in
  `AuthenticatedLayout`; per-row Edit/Delete gated by ownership in `Admin/Blog/Index.vue`;
  `User.role` TS union type.

## Tests (TDD-first, written before implementation)
- Backend: PHPUnit **616 → 631** (+15) — 12 editor-permission feature cases in
  `BlogAdminTest` (allowed/denied, create/index-scope/edit/update/publish/delete,
  cross-user 403s, admin-manages-all) + 3 `EnsureUserHasRole` unit cases.
- Frontend: vitest **895 → 937** (+9 in 2 files) — `Admin.Blog.Index.spec` (4 ownership
  cases) + new `Layouts/__tests__/AuthenticatedLayout.spec.ts` (5 nav-gating cases).
- Gates: `composer test` 631 ✓ · `vendor/bin/pint --test` ✓ · `npm run build` ✓ ·
  PHPStan level 8 zero-baseline ✓ · local coverage PHPUnit 76.25%/55.02% and vitest
  73.39/70.94/67.06/73.79 (all above CI thresholds).

## Key lesson — Larastan infers `#[Fillable]` columns as `string`
Initial design cast `role` to the enum in `User::casts()` — PHPStan level 8 immediately
failed with `Strict comparison === between string and UserRole::Admin will always evaluate
to false` (11 errors): Larastan's magic-property inference from `#[Fillable]` types the
attribute as `string`, and it wins over both a class `@property UserRole $role` docblock
and the cast. Fix: **no enum cast** — keep `role` as a plain DB string and compare via
`UserRole::tryFrom($this->role)` in the model + `->value` mapping in the middleware. The
enum stays the single source of truth for role constants; the attribute stays `string`
(which also keeps the Inertia JSON serialization unchanged).

## Verification (live, behaviorally)
- Live `/` + `/api/restaurants` both 200; `route:list` on droplet shows
  `EnsureUserHasRole:admin` on `/admin` and `EnsureUserHasRole:admin,editor` on all
  `/admin/blog` routes.
- Headless-browser smoke test as a freshly created prod `editor` user:
  login ✓ → nav shows **Blog** (no **Admin**) ✓ → `/admin/blog` 200 ✓ → created +
  published own post ✓ → Edit/Delete shown on own row ✓ → `/admin` **403 Forbidden** ✓ →
  post live on public `/blog` ✓. Smoke user + post then deleted from prod.

## Follow-ups
- No `UserRole` enum cast (see lesson) — documented in backlog so a future goal doesn't
  re-attempt it.
- `blog_posts.author_id` is `cascadeOnDelete` — deleting an editor user deletes their
  posts (pre-existing; still out of scope).
- Next backlog goal is now #1: **featured blog section on the homepage** (`npm run build` gate).
