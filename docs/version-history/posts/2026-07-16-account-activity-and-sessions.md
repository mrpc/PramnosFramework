---
date: 2026-07-16
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - activity-log
  - sessions
  - account
readtime: 2
---

# Account activity log, session tracking & active-device management

The built-in authserver now records account activity, tracks logged-in
devices, and lets a user sign out other sessions — all wired into the account
UI, with zero impact on apps that bring their own login stack.

<!-- more -->

## Added

- **`Pramnos\Auth\ActivityLog`** — a single, self-guarding writer for
  `authserver.user_activity_log`. It records `login`, `logout`,
  `login_failed`, `account_locked`, `password_changed`,
  `password_reset_requested`, `password_reset_completed`,
  `application_authorized`, `application_revoked`, `data_export_requested`,
  `privacy_settings_updated`, `passkey_added/removed/renamed` and
  `twofactor_enabled/disabled`. It no-ops when the `auth` feature or the table
  is absent, and never throws into the caller.
- **Login/logout logging lives in the built-in lifecycle only**
  (`Auth::executeDefaultLogin/Logout`). Apps that register their own
  `Addon\User\*` handler (e.g. the reference application) take the addon path and are never
  double-logged.
- **Session tracking** — `Application::bootSessionTracking()` runs
  `SessionTrackingMiddleware` automatically so the `sessions` table (active
  devices, force-logout) is populated with zero wiring. Scaffolded apps instead
  declare the middleware explicitly (`'middleware' => [...]`) and run it through
  a pipeline in `www/index.php`; the auto-run then stands down so tracking
  happens exactly once.
- **Active Sessions on the Security page** — lists the user's devices and lets
  them sign out any other session (`Account::revokesession()` sets
  `sessions.logout = 1`; the tracking layer force-logs-out that device on its
  next request).
- **Web-session token on login** — a `usertokens` row (`web_session`) is created
  on built-in login and invalidated on logout, so per-request activity is
  attributed in `tokenactions` (already logged by `Application::exec()`).

## Fixed

- **`User::addToken()`** used an `ON CONFLICT (userid, tokentype, token)` upsert
  with no matching unique constraint — it threw on PostgreSQL and silently
  degraded to a plain insert on MySQL. It is now a plain insert (tokens are
  unique random values).
- **`authserver.*` tables are now schema-qualified** in `Account`
  (`user_activity_log`, `user_twofactor`, …). Unqualified names silently
  resolved against `public` on PostgreSQL (whose `search_path` lacks
  `authserver`) and failed without an exception, leaving the activity list and
  2FA status blank.
