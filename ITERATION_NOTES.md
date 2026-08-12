# Iteration Notes

## Goal
redirect admin/editor users to the admin dashboard after login and make blog editing discoverable in nav

## State
- **Done**: Goal fully achieved — login redirects admin/editor users to admin areas, and blog editing is discoverable from both `AuthenticatedLayout` (existing "Blog" nav link) and the public `AppLayout` (new "Manage Blog" nav link for admin/editor users).
- **Next**: None — goal complete.
- **Gotchas**: Editor cannot access `/admin` dashboard (route guarded by `role:admin`), so editors redirect to `admin.blog.index` instead. The public `AppLayout` "Manage Blog" link uses `route('admin.blog.index')` and won't render for editors who don't have that route accessible (though it does render since the Ziggy route definition exists — the server middleware would still block access at request time).

## Log
### Iteration 2 — Public nav "Manage Blog" link
- Added `canManageBlog` computed to `AppLayout.vue` checking `usePage().props.auth?.user?.role`.
- Added a "Manage Blog" link (`route('admin.blog.index')`) in the public `AppLayout` nav, shown only when user is admin or editor.
- Created `AppLayout.spec.ts` with 7 tests: shows for admin/editor, hides for user/guest, plus public link sanity checks.
- All 982 frontend tests + 656 PHP tests pass. Build (`vue-tsc && vite build && vite build --ssr`) succeeds.

### Iteration 1 — Role-based login redirect
- Modified `AuthenticatedSessionController::store()` to redirect admins and editors to their respective admin areas instead of the generic dashboard.
- Added `test_admin_users_are_redirected_to_admin_dashboard_after_login` and `test_editor_users_are_redirected_to_blog_management_after_login` to `AuthenticationTest`.
- All 21 auth tests pass.
