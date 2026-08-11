# Iteration Notes

## Goal
replace the is_admin boolean with a role column (admin/editor/user) on users

## State
- **Done**: Created migration `2026_08_10_000001_add_role_to_users_table.php` — adds `role` column (string, default 'user') to users table, migrates existing `is_admin = true` rows to `role = 'admin'`. Migration runs cleanly, all 10 BlogAdminTest + full suite passes.
- **Done**: Updated User model — `#[Fillable]` now uses `'role'` instead of `'is_admin'`, removed boolean cast, `isAdmin()` now checks `$this->role === 'admin'`.
- **Done**: Updated UserFactory — added `'role' => 'user'` to default state. Updated BlogAdminTest — uses `['role' => 'admin']` and `['role' => 'user']` instead of `['is_admin' => true/false]`.
- **Done**: Hid `is_admin` from User model serialization (`#[Hidden]` attribute), updated `AuthenticatedLayout.vue` to use `role === 'admin'` instead of `is_admin === true`, updated `types/index.d.ts` to use `role?: string` instead of `is_admin?: boolean`.
- **Done**: Created migration `2026_08_11_000001_drop_is_admin_from_users_table.php` — drops the `is_admin` column from users table. Removed `is_admin` from `#[Hidden]` attribute on User model (no longer needed since the column is gone). Zero remaining `is_admin` references in `app/` directory.
- **Next**: None — the goal is fully achieved. `is_admin` is replaced by `role` (admin/editor/user) everywhere: DB, model, factory, tests, frontend, types, middleware.
- **Gotchas**: None.

## Log
1. Added `role` column migration + data migration from `is_admin`
2. Updated User model, factory, and BlogAdminTest to use `role` instead of `is_admin`
3. Hid `is_admin` from User serialization, updated AuthenticatedLayout.vue + types/index.d.ts to use `role`
4. Dropped `is_admin` column via migration, removed from `#[Hidden]` attribute — full replacement complete
