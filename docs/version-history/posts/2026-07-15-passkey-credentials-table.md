---
date: 2026-07-15
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - passkeys
  - webauthn
  - migrations
readtime: 1
---

# Passkeys groundwork: credential store and WebAuthn library

The foundation for passkey (WebAuthn / FIDO2) support: the credential store and
the WebAuthn library, wired in behind an upcoming anti-corruption wrapper layer.

<!-- more -->

## Added

- **`web-auth/webauthn-lib` dependency.** The framework now requires
  `web-auth/webauthn-lib ^5.3` (Spomky-Labs — the same ecosystem as the existing
  `web-token/jwt-framework`). It will be used only behind a framework-owned
  wrapper layer, so the public passkey API stays framework-native and the library
  can be swapped without breaking backward compatibility.
- **`passkey_credentials` table.** New authserver table storing one row per
  registered passkey: owner, base64url credential id (unique), COSE public key,
  signature counter (clone detection), AAGUID, transports, a user label, the
  backup-eligible / backup-state flags, an `is_active` revocation flag, and
  created / last-used timestamps. Added by the current-date migration
  `2026_07_15_000004_create_passkey_credentials_table` — a brand-new table,
  guarded and portable (binary values stored base64-encoded), no foreign key.
