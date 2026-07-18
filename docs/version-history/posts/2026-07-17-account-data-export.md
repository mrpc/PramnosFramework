---
date: 2026-07-17
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - account
  - gdpr
  - data-export
readtime: 2
---

# Self-service data export (GDPR) with an app-extensible hook

Users can now download a complete copy of their data from the account UI, and
applications built on the framework can contribute their own sections to that
export without touching the framework.

<!-- more -->

## Added

- **`Account::exportdata()`** — GET renders a confirmation page listing exactly
  what the download will contain; POST (CSRF-checked) streams a pretty-printed
  JSON attachment and records a `data_export_requested` activity entry.
- **`Account::buildExportData()`** aggregates every personal-data source:
  profile, authorized applications, OAuth consents, passkeys, two-factor status,
  active sessions, tokens, token activity, account details, privacy settings and
  the activity log.
- **Extensibility hook** — `buildExportData()` fires the `account.data_export`
  event with the user id; listeners return `[section => data]` maps that are
  merged into the payload. Core sections are protected from being overwritten,
  so an application (e.g. one adding licensing/billing data) extends the export
  without forking.

## Security

- The export is metadata-only where it matters: passwords/salts, token values,
  TOTP secrets and backup codes, passkey public keys and credential ids, raw
  request bodies (`tokenactions.params`) and password-reset hashes are **never**
  included. Each collector is individually guarded — a missing table degrades to
  an empty section rather than failing the whole export.
