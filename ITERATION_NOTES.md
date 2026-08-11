# Iteration Notes

## Goal
replace the is_admin boolean with a role column (admin/editor/user) on users

## State
- **Done**: Created migration `2026_08_10_000001_add_role_to_users_table.php` — adds `role` column (string, default 'user') to users table, migrates existing `is_admin = true` rows to `role = 'admin'`. Migration runs cleanly, all 10 BlogAdminTest + full suite passes.
- **Next**: Update User model (`#[Fillable]`, `casts()`, `isAdmin()` method) to use `role` instead of `is_admin`.
- **Gotchas**: None. `is_admin` column still present — both columns coexist during migration window. The `role` default is `'user'` (string), checked against `'admin'`/`'editor'`/`'user'`.

## Log
1. Added `role` column migration + data migration from `is_admin`
