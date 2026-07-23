---
date: 2026-07-23
categories:
  - Changelog
  - Authentication
tags:
  - auth
  - loginflow
  - activity-log
  - twofactor
  - passkey
  - authserver
---

# Login method recorded in the activity log

The activity log now distinguishes **how** a user logged in — `password`,
`twofactor` or `passkey` — instead of recording every login as a generic
`login`. This makes the security audit trail meaningfully richer: a step-up
login is now visibly different from a plain password login.

<!-- more -->

## Added

- **`Auth::setLoginMethod(?string $method)`** — a new, additive public method
  that tags the authentication method for the *next* login the built-in
  lifecycle establishes. The tag is consumed by that login and then reset, so it
  can never leak into a later login on the same `Auth` instance. A null (or
  unset) tag falls back to `password` — the default for the plain `Auth::auth()`
  path.

- **`LoginFlow` now tags each completion path.** `attempt()` (straight password
  login) tags `password`, `completeTwoFactor()` tags `twofactor`, and
  `completePasskey()` tags `passkey`. The `login` row's `details` JSON therefore
  carries the real method, e.g. `{"method":"twofactor","remember":false}`.

## Why the signature did not change (BC)

The natural fix — threading the method through `Auth::loginById()` — would add a
parameter to a public method that scaffolded apps override, an incompatible
signature change PHP rejects (CLAUDE.md §6). Instead the method is set on the
`Auth` instance just before the session is established: `LoginFlow::finishLogin()`
calls `$this->auth()->setLoginMethod($method)` ahead of `establishSession()`, and
`Auth::buildLoginResponse()` reads it into the login response the lifecycle
records. No public signature changed; the capability is purely additive.

## Tests

- **Unit** (`LoginFlowTest`) — each completion path tags the correct method, and
  a failed step-up tags nothing (no session, no tag).
- **Characterization** (`AuthCharacterizationTest`) — `setLoginMethod()` stores a
  one-shot tag that reaches the login lifecycle and is reset afterwards, and an
  untagged login defaults to `password`.
- **Integration** (`LoginFlowActivityTest`, real MySQL) — a password, a
  two-factor and a passkey login each write exactly one `login` row whose
  details record `password` / `twofactor` / `passkey` respectively.
