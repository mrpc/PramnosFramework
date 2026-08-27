---
date: 2026-08-27
categories: [Changelog]
---

# The backup codes a user saved were never the stored ones

Three faults in one flow, and each of them removes the recovery path or the step-up check
that two-factor authentication is there to provide.

<!-- more -->

## Fixed

### The codes on the setup screen could never work

`startSetup()` generated ten backup codes and the setup screen listed them under "store
these in a safe place — they will not be shown again". Then `completeSetup()` generated ten
*different* codes, hashed those, and stored them. The plain set it stored was dropped on the
floor.

So the account's real recovery codes were known to nobody. The page enrolment redirects to
says "Setup complete. Save your backup codes before leaving this page" and had none to show
— it only ever populated `newBackupCodes` after a *regeneration*. A user who followed the
instructions exactly ended up with ten codes that could never work, and found out the first
time they lost their phone.

- `startSetup()` no longer returns `backup_codes`; the key is gone rather than misleading.
- `completeSetup()` keeps the plain codes, and **`takeNewBackupCodes()`** hands them over
  once (through the session, so they survive the redirect; cleared on read).
- `TwoFactorAuth::backup()` populates the view with them on `?setup=complete`.
- The three bundled themes' setup views say the codes are coming after verification instead
  of listing a set that is about to be replaced.

Showing them after verification is also the right moment: somebody who abandons setup
halfway should not walk away holding recovery codes for an account with no second factor.

### `disable()` ignored the password it was given

`TwoFactorAuth::disable()` collects the account password and calls
`$service->disable($userId, $password)`. The service took **one** parameter, and PHP
discards extra arguments to a userland function — so nothing was verified. Any signed-in
session could turn the second factor off with an arbitrary string, and the controller's
"That password is not correct" branch was unreachable: the service returned false only when
the account had no 2FA row at all.

A stolen session cookie is exactly what a second factor is for. A step-up check that does
not check is worse than none, because the screen in front of it says otherwise.

### `regenerateBackupCodes()` ignored it too

The same discarded argument, and destructive as well as disclosing: rotating the codes
invalidates every code the account's owner had written down, and prints ten new ones to
whoever asked.

Both now take `?string $password = null` and verify it when it is supplied:

| Call | Meaning |
| --- | --- |
| `disable($userId, $password)` | the user's own action — refused on a wrong **or empty** password |
| `disable($userId)` | administrative — an operator clearing 2FA off an account whose owner cannot |

An empty string counts as wrong, not absent. `null` is absent, and means the caller's own
authority is the authorisation — which keeps the administrative recovery path working.

### `User::verifyPassword()` on an account with no password

It passed a null hash to `password_verify()` — a deprecation on PHP 8.4 and an error later,
and a comparison against nothing in the meantime. Accounts in that state are ordinary: one
created by an administrator, or provisioned by an SSO run, and never given a password.
Now refused before the call.

## Documentation

- `Pramnos_Authentication_Guide.md` — *TwoFactorAuthService — full setup flow*.
