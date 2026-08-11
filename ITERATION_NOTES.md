# Iteration Notes

## Goal
replace the is_admin boolean with a role column (admin/editor/user) on users

## State
- **Done**: Created migration `2026_08_10_000001_add_role_to_users_table.php` — adds `role` column (string, default 'user') to users table, migrates existing `is_admin = true` rows to `role = 'admin'`. Migration runs cleanly, all 10 BlogAdminTest + full suite passes.
- **Done**: Updated User model — `#[Fillable]` now uses `'role'` instead of `'is_admin'`, removed boolean cast, `isAdmin()` now checks `$this->role === 'admin'`.
- **Done**: Updated UserFactory — added `'role' => 'user'` to default state. Updated BlogAdminTest — uses `['role' => 'admin']` and `['role' => 'user']` instead of `['is_admin' => true/false]`.
- **Done**: Hid `is_admin` from User model serialization (`#[Hidden]` attribute), updated `AuthenticatedLayout.vue` to use `role === 'admin'` instead of `is_admin === true`, updated `types/index.d.ts` to use `role?: string` instead of `is_admin?: boolean`.
- **Next**: Drop the `is_admin` column in a follow-up migration once the deployment window confirms no frontend code reads it anymore.
- **Gotchas**: None. `is_admin` column still present in DB but hidden from Inertia serialization — both columns coexist during migration window. The `role` default is `'user'` (string), checked against `'admin'`/`'editor'`/`'user'`.

## Log
1. Added `role` column migration + data migration from `is_admin`
2. Updated User model, factory, and BlogAdminTest to use `role` instead of `is_admin`
3. Hid `is_admin` from User serialization, updated AuthenticatedLayout.vue + types/index.d.ts to use `role`
