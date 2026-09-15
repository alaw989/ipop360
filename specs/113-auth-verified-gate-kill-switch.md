# Feature Specification: Auth hardening — verified-gate kill-switches + dead confirm-password removal

**Feature Branch**: `fix/spec-113-auth-verified-gate` (interactive)

**Created**: 2026-09-15

**Status**: IN PROGRESS (local, uncommitted)

**Series**: Follow-up to spec-089 (registration throttle + email verification
gate). The 2026-09 feature audit (`docs/feature-audit-2026-09.md` §6, finding
#3) found that spec-089's favorites deferral shipped without its referenced
kill-switch, and that the Breeze `password.confirm` flow is unreachable dead
code.

## The problem (audit evidence)

1. **Missing kill-switch.** spec-089 §3/§41 deferred the favorites
   `verified`-gate because prod's mailer is `log` — with no SMTP nobody can
   verify, so an always-on gate would lock every user out. The deferral was
   supposed to be flippable via `config('auth.require_verified_for_favorites')`,
   but that key **does not exist** anywhere in `config/` (grep-verified; the
   audit cites `routes/web.php:76–80` post-merge). Enabling the gate today
   requires a code edit, and because deploy runs `route:cache`, a gate baked
   into route registration would additionally be frozen until redeploy.
2. **Unverified users can write profile data.** Profile routes are `auth`-only
   (`routes/web.php:71–74`); an unverified throwaway account can edit/delete
   its profile. The same mailer caveat applies, so this too must be a
   kill-switch, not an unconditional gate.
3. **Dead `password.confirm` flow.** The GET/POST routes and
   `ConfirmablePasswordController` exist (`routes/auth.php:52–55`) but **no
   route applies `password.confirm`** (grep-verified) — sensitive actions use
   `current_password` instead. `Auth/ConfirmPassword.vue`, the 3h
   `password_timeout` window, and `PasswordConfirmationTest` cover a screen no
   user can reach.

## Solution

1. **New middleware** `App\Http\Middleware\VerifiedWhenConfigured`
   (alias `verified.gate` in `bootstrap/app.php`), applied as
   `verified.gate:auth.require_verified_for_favorites` /
   `verified.gate:auth.require_verified_for_profile`. It reads the named
   config flag **per-request** and delegates to Laravel's
   `EnsureEmailIsVerified` only when true — so `route:cache` cannot freeze the
   decision (only a `config:cache` rebuild is needed to flip an env value,
   which is normal).
2. **Two config flags, both default `false`** in `config/auth.php`
   (`AUTH_REQUIRE_VERIFIED_FOR_FAVORITES`, `AUTH_REQUIRE_VERIFIED_FOR_PROFILE`)
   with a comment explaining the log-mailer footgun and the flip procedure.
3. **Route wiring** (`routes/web.php`): both favorites writes
   (toggle/merge) behind the favorites flag; profile PATCH/DELETE behind the
   profile flag, while `/profile` GET stays open on `auth` only — an
   unverified user must still be able to reach their own account.
4. **Remove the dead flow**: `ConfirmablePasswordController`,
   both `confirm-password` routes, `resources/js/Pages/Auth/ConfirmPassword.vue`,
   `tests/Feature/Auth/PasswordConfirmationTest.php`, and the
   `password.confirm` Ziggy whitelist entry. `config/auth.php`'s
   `password_timeout` stays — it is framework-standard and harmless.
   Rationale: the audit explicitly flags the flow as dead code; wiring it up
   instead would duplicate `current_password` validation on the same actions.

## Tests

`tests/Feature/Auth/VerifiedGateTest.php` (8 tests):

- Both flags default off → unverified favorites toggle and profile PATCH
  succeed (pins that deploying this changes nothing).
- Gate on → unverified JSON favorites write gets `403`
  (`EnsureEmailIsVerified`'s JSON branch — axios contract), nothing persisted.
- Gate on → unverified HTML favorites write redirects to
  `verification.notice`.
- Gate on → verified user still writes favorites.
- Gate on → unverified profile PATCH redirects and does not mutate; `/profile`
  GET still renders `200`.
- `password.confirm` is absent: no named route, both paths `404`.

## Verification

- Gate (local): pint clean · PHPStan L8 zero errors · PHPUnit 1471 passed /
  1 skipped · vitest 1129 passed · `npm run build` clean.
- Live (post-deploy): `/confirm-password` → 404; profile edit still works for
  the operator's account (flags default off).
