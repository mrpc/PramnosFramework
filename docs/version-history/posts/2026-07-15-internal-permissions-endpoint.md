---
date: 2026-07-15
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - permissions
  - oauth2
readtime: 1
---

# Internal permissions endpoint (live fetch)

Resource servers can now fetch a user's effective permissions over HTTP,
completing the live-fetch read path (feature 6).

<!-- more -->

## Added

- **`Pramnos\Auth\Controllers\InternalPermissions`.** Serves
  `GET /api/internal/permissions?user_id={id}` — a resource server authenticates
  with its own Client Credentials and receives the `PermissionResolver` result
  for that user within its own audience (the authenticated client determines the
  `app_id`; an explicit `client_id` query, if present, must match). Responses:
  `200` with the effective grants, `400` missing/invalid `user_id`, `401`
  invalid/missing credentials, `403` cross-client request, `405` wrong method.
  The resource server caches the result and refreshes on a `permissions_changed`
  webhook — so access tokens stay lightweight (identity only).
- **`ClientCredentialsAuthTrait`.** The Basic/body credential extraction and
  validation shared by the capabilities-push and internal-permissions endpoints
  is now a single trait (the `Capabilities` controller was refactored onto it),
  keeping the client-authentication behaviour in one place.
