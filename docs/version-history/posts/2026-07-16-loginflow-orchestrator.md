---
date: 2026-07-16
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - login
  - 2fa
  - passkeys
readtime: 2
---

# LoginFlow: the overridable password → step-up → session state machine

`Pramnos\Auth\LoginFlow` composes the framework's existing auth building blocks
into the canonical login flow a scaffolded auth server adopts with zero custom
code: verify a password, honour the brute-force lockout, step up to a second
factor when the account requires one, and only then establish the session.

<!-- more -->

## Added

- **`Pramnos\Auth\LoginFlow`** — an overridable orchestrator with three entry
  points:
    - `attempt(username, password, remember)` — checks the lockout *before*
      touching credentials, verifies the password, and then either logs the user
      in directly or, when a second factor is required, stashes a **server-side**
      pending step-up and asks for it.
    - `completeTwoFactor(code)` — finishes a pending login with a TOTP / backup
      code; a wrong code leaves the pending state intact so the user can retry.
    - `completePasskey(verifiedUserId)` — finishes a pending login with a passkey
      that was cryptographically verified for **the same user** who passed the
      password leg.
  Plus `pendingUserId()` and `cancel()` for the controller to render / abandon a
  step-up.
- **`Pramnos\Auth\LoginFlowResult`** — an immutable value object describing every
  outcome (`SUCCESS`, `FAILED`, `LOCKED` with remaining seconds,
  `STEP_UP_REQUIRED` with the offered methods), so a controller branches on one
  thing.

## Security

- The pending state between legs holds only the user id, the *remember* flag and
  the lockout identifier — **never the password**. Nothing sensitive is
  round-tripped through a hidden form field, and a step-up can only complete a
  login this same session started.
- The lockout gate runs before any credential check; a failed password records
  one attempt against a case-normalised identifier so the progressive lockout can
  escalate.
- A pending step-up expires after 5 minutes and is scrubbed on read, so a stale
  half-login can never be completed later.

## Notes

- This is a **new** entry point (backward-compatible, additive). Apps with their
  own login controller are unaffected — it relies only on the additive
  `Auth::loginById()` for session bootstrap and never enforces a second factor
  inside `Auth::auth()`.
- Every collaborator and policy decision (`stepUpMethods()`, the lockout
  identifier, the step-up window, and each service) is a protected seam, so a
  scaffolded app can change one rule by subclassing instead of forking.
