# Iteration Notes

## Goal
registration/login hardening: (1) send an admin/operator notification (app/Notifications) on every new registration - 'New user registered: name, email' - TO a configurable comma-separated recipient list (ADMIN_NOTIFY_EMAILS env), separate from the user's own verification email; (2) add throttle to the forgot-password POST route (e.g. throttle:3,5) so reset links can't be spammed; (3) document + decide auto-login for unverified users (recommend: keep auto-login, gate protected routes on verified as now - unchanged behavior, made explicit + tested); (4) pin the full lifecycle with feature tests: register -> verification email sent (Mail::fake) -> verify link works -> dashboard; wrong password x5 -> lockout message; forgot-password throttle; duplicate email rejected; new-user notification delivered. Keep registration throttling (spec-089) intact.

## State

### Done (iteration 1 — part 1: admin new-user notification)
- Added `app/Notifications/NewUserRegistered.php` (mail notification, subject/line
  "New user registered: {name}, {email}").
- Wired `RegisteredUserController::store` to send it via `Notification::route('mail', $emails)`
  to a comma-separated recipient list from `ADMIN_NOTIFY_EMAILS`, exposed as
  `config('services.admin_notify_emails')` (config/services.php). No-op when empty.
- Added `ADMIN_NOTIFY_EMAILS=` to `.env.example`.
- Tests in `tests/Feature/Auth/RegistrationTest.php`:
  `test_registration_notifies_admin_recipients_when_configured` (asserts on-demand send
  to both addresses + name/email payload) and `test_registration_does_not_notify_admins_when_unconfigured`
  (`assertSentOnDemandTimes(..., 0)`).
- Verification: `pint --test` passed, `phpstan` clean, `php artisan test tests/Feature/Auth` 23 passed.

### Done (iteration 2 — part 2: forgot-password throttle)
- Added `->middleware('throttle:3,5')` to the `forgot-password` POST route in routes/auth.php
  (3 attempts per 5 min, keyed by IP) so reset links can't be spammed.
- Added `test_forgot_password_is_throttled_after_three_attempts` in
  `tests/Feature/Auth/PasswordResetTest.php`: 3 posts succeed, 4th returns 429.
- Verification: `php artisan test tests/Feature/Auth/PasswordResetTest.php` 5 passed;
  `pint --test` clean.

### Done (iteration 3 — part 3: document + pin auto-login for unverified users)
- Added `test_unverified_registered_user_is_redirected_to_verification_notice_from_dashboard`
  in `tests/Feature/Auth/RegistrationTest.php`: registers (auto-login), asserts authenticated,
  then GET /dashboard redirects to `verification.notice` (/verify-email).
- Documented the decision inline in `RegisteredUserController::store` (auto-login kept;
  protected routes gated on `verified`). Behavior unchanged — now explicit + tested.
- Verification: `php artisan test tests/Feature/Auth/RegistrationTest.php` 6 passed;
  `pint --test` clean.

### Done (iteration 4 — part 4: remaining lifecycle tests)
- Added `test_users_are_locked_out_after_five_failed_login_attempts` in
  `tests/Feature/Auth/AuthenticationTest.php`: 5 wrong-password posts, 6th asserts
  `assertSessionHasErrors('email')` and that the message contains "Too many login attempts".
- Added `test_duplicate_email_registration_is_rejected` in
  `tests/Feature/Auth/RegistrationTest.php`: pre-existing email re-registering asserts
  `assertSessionHasErrors('email')`, `assertGuest()`, and `assertDatabaseCount('users', 1)`.
- Verification: `php artisan test tests/Feature/Auth` 27 passed (61 assertions);
  `pint --test` clean.

### Next
- None — all four goal parts are now complete and pinned with feature tests.
  Registration throttling (`throttle:5,1`, spec-089) left intact in routes/auth.php.

### Gotchas
- `NotificationFake::assertSentOnDemand` callback signature is `(notification, channels[],
  notifiable, locale)` — 2nd arg is the channels ARRAY, not a single channel string.
- There is no `assertNotSentOnDemand`; use `assertSentOnDemandTimes($class, 0)` or
  `assertNotSentTo(new AnonymousNotifiable, $class)`.
- AnonymousNotifiable `getKey()` returns null, so all on-demand sends share one bucket in the fake.

## Log
