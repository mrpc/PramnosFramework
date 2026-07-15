---
date: 2026-07-15
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - passkeys
  - webauthn
  - login
readtime: 2
---

# Passkeys: WebAuthn registration & authentication ceremonies

The passkey (WebAuthn / FIDO2) ceremony layer lands: register a credential,
authenticate with it — including usernameless / discoverable-credential login —
and manage passkeys, all behind a framework-owned API that keeps the third-party
WebAuthn library fully swappable.

<!-- more -->

## Added

- **`Pramnos\Auth\Passkey\PasskeyService`** (and `PasskeyServiceInterface`) — the
  public passkey API: `beginRegistration()` / `finishRegistration()` and
  `beginAuthentication()` / `finishAuthentication()`, plus list / rename / revoke
  for dashboards. It owns the single-use challenge store (cache, 5-minute TTL),
  credential persistence, and — crucially — writing back the advanced signature
  counter so clone/replay is caught across requests.
- **Anti-corruption boundary** — `WebAuthnAdapterInterface` with the default
  `WebAuthnLibAdapter` (backed by `web-auth/webauthn-lib` 5.x). This is the only
  class that speaks the WebAuthn library's dialect; everything above it uses
  framework-owned value objects (`RegistrationOptions`, `AuthenticationOptions`,
  `PasskeyCredential`, `VerificationResult`, `Config`). Swapping the library — or
  hand-rolling an implementation — means writing another adapter, nothing more.
- **`Pramnos\Auth\Controllers\Passkey`** — JSON endpoints for the ceremonies and
  management: `registerOptions` / `register`, `loginOptions` / `login`, and
  `list` / `rename` / `revoke`. The in-flight ceremony's challenge is correlated
  through the session, never round-tripped through the client.
- **`Pramnos\Auth\Auth::loginById(int $userId, bool $remember = true)`** — an
  additive, passwordless counterpart to `auth()`. It establishes a session for an
  already-verified user through the *same* post-login path (`triggerLogin()` →
  user addon or built-in lifecycle → afterLogin callbacks), honouring the same
  active-status gate. Used by passkey login; the existing password flow is
  unchanged.

## Security

- Attestation is `none` (consumer passkeys); signatures are verified with
  ES256 / RS256. The ceremony rejects a non-increasing signature counter
  (clone/replay), a tampered signature, a wrong origin, and a mismatched
  credential/user. These rejections are covered by round-trip tests driven by a
  software authenticator, across MySQL and PostgreSQL.
