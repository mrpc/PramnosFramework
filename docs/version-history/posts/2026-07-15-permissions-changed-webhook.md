---
date: 2026-07-15
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - permissions
  - webhooks
readtime: 1
---

# Instant invalidation: permissions_changed webhook

Admin permission changes now emit a `permissions_changed` webhook so resource
servers drop the affected user's cached permissions and re-fetch — closing the
live-fetch loop (feature 7).

<!-- more -->

## Added

- **`permissions_changed` webhook on RBAC changes.** `PermissionsController::save()`
  and `delete()` now queue a `permissions_changed` event (via `WebhookService`)
  after writing to `authserver.permissions`. For a user-subject the event targets
  that user; for role/application subjects it targets user `0` and carries the
  subject in the payload, so a subscriber can invalidate every affected user's
  cache. The payload also includes the operation (`create` / `update` / `delete`)
  and, for saves, the object type and action.
- Delivery is best-effort: a webhook/queue failure is swallowed so it can never
  break permission administration. The emission is exposed as a protected seam
  (`emitPermissionsChanged()` / `webhookService()`) for testing and overriding.

Together with the resolver and the internal permissions endpoint, this completes
the "lightweight token + live fetch + event-driven invalidation" model: tokens
stay identity-only, apps fetch permissions on demand and cache them, and a change
at the auth server invalidates those caches immediately.
