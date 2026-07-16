---
date: 2026-07-16
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - login
  - account
  - 2fa
readtime: 2
---

# Account controller: built-in login / verify / logout

The framework's account controller is promoted from `Dashboard` to a general
`Pramnos\Auth\Controllers\Account` that now also hosts the **public login flow** a
scaffolded auth server needs out of the box — password login, 2FA step-up, and
logout — driven by the `LoginFlow` orchestrator.

<!-- more -->

## Added

- **`Pramnos\Auth\Controllers\Account`** — one controller spanning the whole
  account lifecycle, split by authentication:
    - Public: `login` (GET form / POST credential leg), `verify` (complete a
      pending 2FA step-up), `logout`.
    - Authenticated: the existing account-management surface (dashboard,
      profile, applications, security, change-password, GDPR export/erasure,
      privacy).
  The login actions delegate every decision to `LoginFlow`, so a fresh app gets
  working password + 2FA login with no custom code. Each render, redirect, and
  collaborator is a protected seam, so an app rebrands or re-wires one piece by
  subclassing.

## Security

- The password is never round-tripped through the step-up form — `LoginFlow`
  keeps the pending login server-side.
- Every state-changing POST (login, verify) is CSRF-checked.
- The `?return=` post-login redirect is sanitised: cross-origin, protocol-relative
  (`//host`) and control-character targets are rejected; only same-origin absolute
  URLs and site-relative paths are honoured.

## Changed

- `Pramnos\Auth\Controllers\Dashboard` is now a thin, **backward-compatible**
  subclass of `Account` (it only pins the historical `Dashboard` route base).
  Existing routes, scaffolds and apps referencing `Dashboard` keep working
  unchanged; new code should use `Account`.

## Notes

- A passkey second-factor step-up rides the same pending state via
  `LoginFlow::completePasskey()`; its browser ceremony is wired in a later phase
  alongside the WebAuthn front-end and the built-in views.
