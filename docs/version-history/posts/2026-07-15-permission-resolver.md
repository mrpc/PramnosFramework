---
date: 2026-07-15
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - permissions
  - rbac
  - abac
readtime: 1
---

# Permission resolver (RBAC + ABAC, live-fetch read side)

A resolver that computes a user's effective permissions for one application —
the read side of the live-fetch model (feature 6).

<!-- more -->

## Added

- **`Pramnos\Auth\PermissionResolverInterface` + `PermissionResolver`.** Given a
  user and an application, the resolver reads `authserver.permissions` — the
  user's own grants plus those of the active roles they hold
  (`authserver.user_roles`) — and returns each effective grant:
    - **Audience scoping** — global rows (`app_id IS NULL`) always apply;
      app-scoped rows apply only to the matching application.
    - **Deny-over-allow** — resolved per `(object_type, object_id, action)`,
      mirroring the `effective_permissions` view's priority semantics.
    - **Active / non-expired only** — inactive rows and expired grants (and
      permissions from expired role assignments) are excluded.
    - **ABAC pass-through** — conditions are not evaluated here; each grant
      carries its predicate(s) so the calling app evaluates them against its own
      request context. A grant is unconditional when any winning row is.
  `PermissionResolverInterface` is an extension seam: an app layer can decorate
  it to intersect the result with a licensing/entitlement gate. The resolver is
  independent of the legacy `Pramnos\Auth\Permissions` class. The internal HTTP
  endpoint that serves this to resource servers lands next.
