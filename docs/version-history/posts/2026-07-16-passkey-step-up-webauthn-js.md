---
date: 2026-07-16
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - passkeys
  - webauthn
  - 2fa
  - login
readtime: 2
---

# Passkey second-factor step-up + WebAuthn browser glue

The built-in login flow can now complete a pending second factor with a
**passkey**, not just a TOTP code — and ships the dependency-free WebAuthn
browser glue that drives it.

<!-- more -->

## Added

- **`Account::passkeyOptions()` / `Account::passkeyVerify()`** — two JSON
  endpoints for a passkey step-up. They only work while a login is pending
  (after the password leg): `passkeyOptions` issues assertion options pinned to
  the pending user and stashes the challenge server-side; `passkeyVerify`
  verifies the assertion and finishes the login via
  `LoginFlow::completePasskey()`, which succeeds only when the passkey resolves
  the **same** user who passed the password. On success it returns the post-login
  redirect target.
- **`scaffolding/assets/js/pf-webauthn.js`** — a small, dependency-free
  `window.PramnosWebAuthn` helper: `supported()`, `authenticate()` (assertion /
  login / step-up) and `register()` (dashboard). It converts the server's
  base64url options to `ArrayBuffer`s for `navigator.credentials`, serialises the
  authenticator response back to the standard base64url WebAuthn JSON, and posts
  same-origin with the session cookie. Copied into scaffolded apps and loaded
  from the theme footers.
- **Passkey option on the step-up screen** — the built-in `login_2fa` views (all
  three themes) show a "Use a passkey" button when a passkey is offered, wired to
  `pf-webauthn.js` with graceful degradation (hidden when WebAuthn is
  unavailable; the TOTP / backup-code path always remains).

## Security

- The step-up challenge is single-use and server-side; a passkey belonging to a
  different account can never complete someone else's pending login (enforced by
  both the pinned ceremony and `LoginFlow::completePasskey()`'s user match).
