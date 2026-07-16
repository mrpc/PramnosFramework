---
date: 2026-07-15
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - 2fa
  - migrations
readtime: 1
---

# Two-factor authentication tables migration

The framework already shipped `TwoFactorAuthService` and `TOTPHelper`, but not
the tables they read and write — so a freshly scaffolded authserver had no schema
for two-factor auth. This migration closes that gap: 2FA now works out of the box.

<!-- more -->

## Added

- **`user_twofactor` / `twofactor_setup` / `twofactor_attempts` tables.** A new
  framework migration creates the three authserver tables consumed by
  `Pramnos\Auth\TwoFactorAuthService`: per-user 2FA state (enabled flag, TOTP
  secret, hashed backup codes, replay/last-used marker), pending setup sessions
  (temporary secret + TTL), and a verification-attempt audit trail. UNIX-timestamp
  columns are `BIGINT`; the attempts audit uses a datetime.

  Non-breaking: brand-new tables, each guarded by `hasTable()`, no hard foreign
  key on `userid` (app-layer integrity, consistent with the other authserver
  tables), current-date timestamp. Existing installations that already have these
  tables (e.g. an app that shipped its own 2FA) are untouched.

## Tests

An integration test verifies create/drop/idempotency and then drives a full
`TwoFactorAuthService` lifecycle (start setup → confirm code → verify → disable)
against the created schema — the strongest proof that the columns match what the
service expects — across MySQL and PostgreSQL.
