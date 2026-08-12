# Iteration Notes

## Goal
redirect admin/editor users to the admin dashboard after login and make blog editing discoverable in nav

## State
- **Done**: Login now redirects admin users → `admin.dashboard`, editors → `admin.blog.index`, regular users → `dashboard` (unchanged). Controller: `AuthenticatedSessionController::store()` uses `match` on `$user->isAdmin()` / `$user->isEditor()`. Added 2 tests in `AuthenticationTest`.
- **Next**: Make blog editing discoverable in nav — currently `AuthenticatedLayout` already shows "Blog" link for admin/editor users. Consider adding a blog management link in the public `AppLayout` when a blog-capable user is logged in, or making the admin dashboard "Manage Blog" card more prominent.
- **Gotchas**: Editor cannot access `/admin` dashboard (route guarded by `role:admin`), so editors redirect to `admin.blog.index` instead.

## Log
### Iteration 1 — Role-based login redirect
- Modified `AuthenticatedSessionController::store()` to redirect admins and editors to their respective admin areas instead of the generic dashboard.
- Added `test_admin_users_are_redirected_to_admin_dashboard_after_login` and `test_editor_users_are_redirected_to_blog_management_after_login` to `AuthenticationTest`.
- All 21 auth tests pass.
