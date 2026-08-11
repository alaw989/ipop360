# Iteration Notes

## Goal
replace the is_admin boolean with a role column (admin/editor/user) on users

## State
- **Done**: Created migration `2026_08_10_000001_add_role_to_users_table.php` — adds `role` column (string, default 'user') to users table, migrates existing `is_admin = true` rows to `role = 'admin'`. Migration runs cleanly, all 10 BlogAdminTest + full suite passes.
- **Done**: Updated User model — `#[Fillable]` now uses `'role'` instead of `'is_admin'`, removed boolean cast, `isAdmin()` now checks `$this->role === 'admin'`.
- **Done**: Updated UserFactory — added `'role' => 'user'` to default state. Updated BlogAdminTest — uses `['role' => 'admin']` and `['role' => 'user']` instead of `['is_admin' => true/false]`.
- **Next**: Update the admin dashboard view or UserResource to expose `role` instead of `is_admin` (need to check what the frontend consumes). Also update any remaining Frontend references to `is_admin`.
- **Gotchas**: None. `is_admin` column still present — both columns coexist during migration window. The `role` default is `'user'` (string), checked against `'admin'`/`'editor'`/`'user'`.

## Log
1. Added `role` column migration + data migration from `is_admin`
2. Updated User model, factory, and BlogAdminTest to use `role` instead of `is_admin`
