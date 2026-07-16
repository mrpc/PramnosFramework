---
date: 2026-07-16
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - scaffolding
readtime: 1
---

# Scaffolding exposes every auth controller

`pramnos init` now generates a thin wrapper for **every** framework auth
controller, so a scaffolded auth server exposes the full surface out of the box —
nothing is silently missing.

<!-- more -->

## Added

- The `auth` scaffold now also generates `Passkey` and `Session` controllers.
- The `authserver` scaffold now also generates `Discovery`, `Device`, `Gdpr`,
  `Capabilities` and `InternalPermissions` controllers.

Each is a thin `extends` of its framework counterpart under
`Pramnos\Auth\Controllers` (via the new `writeAuthControllerWrapper()` helper),
so all logic stays in the framework while the app decides — by having the file —
which URLs are routable. Nothing is auto-routed behind the app developer's back:
routing stays explicit and opt-in, and the generated controllers-contract test
verifies each wrapper extends the right base.

The two internal endpoints (`Capabilities`, `InternalPermissions`) authenticate
via client credentials, not the user session, so exposing their URL is safe.
