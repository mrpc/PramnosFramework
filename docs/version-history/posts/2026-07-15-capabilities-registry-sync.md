---
date: 2026-07-15
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - capabilities
  - abac
  - migrations
readtime: 2
---

# Client capabilities registry and manifest sync

Resource servers can now declare what they expose — Resources, the Scopes
(action vocabulary) per Resource, and the ABAC Condition keys they support — and
have the auth server persist that declaration with smart, non-destructive sync.

<!-- more -->

## Added

- **Capabilities registry (4 tables).** New authserver tables hold each client's
  declared capabilities: `client_resources`, `client_resource_scopes` (the action
  vocabulary per resource), `client_supported_conditions` (declared ABAC keys such
  as `location_id`), and `client_manifest` (the last-synced MD5 hash). Created by
  the current-date migration `2026_07_15_000002_create_client_capabilities_tables`
  — additive, idempotent, no foreign keys (DB-safe on shared installations).
- **`Pramnos\Auth\CapabilitiesSyncService`.** Applies a client's JSON capabilities
  manifest to the registry with three guarantees:
    - **MD5 short-circuit** — an unchanged manifest is a no-op.
    - **Upsert** — declared resources/scopes/conditions are inserted or refreshed
      and marked active; re-adding a removed item reactivates the existing row
      instead of duplicating it.
    - **Soft delete** — anything dropped from a later manifest is flagged
      `is_active = false`, never hard-deleted, so existing user policies that
      reference it are preserved.
  `hashManifest()` is order-independent, so a cosmetically reordered but
  semantically identical manifest still short-circuits. The service is designed to
  be subclassed so an app layer can filter which declared capabilities are exposed.
