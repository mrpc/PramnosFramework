---
date: 2026-07-15
categories:
  - Changelog
  - Authentication
tags:
  - oauth2
  - consent
  - applications
readtime: 2
---

# Trusted clients: skip the OAuth2 consent screen (silent flow)

First-party applications can now be marked **trusted** so the authorization-code
flow issues a code without showing the consent screen.

<!-- more -->

## Added

- **`trusted` flag on applications.** A new `trusted` column on the applications table
  (`SMALLINT NOT NULL DEFAULT 0`) marks first-party / internal clients. When
  `trusted = 1`, `Oauth::authorize()` skips the consent screen entirely and issues the
  authorization code silently (`clientSkipsConsent()` gates the branch). Every existing
  application defaults to `0` (untrusted), so third-party clients keep seeing the consent
  screen exactly as before. Added by the current-date migration
  `2026_07_15_000001_add_trusted_to_applications`, which is strictly additive and
  idempotent (guarded by `hasColumn()`, no foreign key).

## Fixed

- **`Oauth::getLoggedInUser()` visibility.** The method was `private`, which silently
  prevented test doubles (and any subclass) from overriding the logged-in-user lookup.
  It is now `protected` — a backward-compatible visibility widening that makes the
  authorization flow properly testable.
