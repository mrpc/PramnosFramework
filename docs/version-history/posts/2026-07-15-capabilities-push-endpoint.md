---
date: 2026-07-15
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - capabilities
  - oauth2
readtime: 1
---

# Capabilities push endpoint

Resource servers can now push their capabilities manifest to the auth server
over HTTP, completing the CI/CD push model (feature 2).

<!-- more -->

## Added

- **`Pramnos\Auth\Controllers\Capabilities`.** Handles the capabilities push at
  `PUT /api/internal/clients/{client_id}/capabilities`. The caller authenticates
  with its own Client Credentials (HTTP Basic or request body) and sends the JSON
  manifest as the request body; the controller validates the credentials, enforces
  that a client may only push its **own** manifest (the authenticated client must
  match the path `{client_id}`), parses the manifest, and applies it via
  `CapabilitiesSyncService`. Responses: `200` with the sync result
  (`status`, counts), `400` malformed manifest, `401` invalid/missing credentials,
  `403` cross-client push, `405` wrong method.
- Credential extraction, manifest reading, authentication, and the sync service
  are exposed as protected seams, so the whole flow is unit-testable without a
  live HTTP request. As with the other framework auth controllers, the route
  itself is wired by the consuming application.
