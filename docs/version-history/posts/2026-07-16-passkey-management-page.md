---
date: 2026-07-16
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - passkeys
  - account
readtime: 1
---

# Passkey management page + reachable from the account UI

Passkeys can now be managed from the account UI. Previously the passkey
endpoints existed but no page linked to them — a user could never actually reach
passkey management. That gap is closed.

<!-- more -->

## Added

- **`Pramnos\Auth\Controllers\Passkey::display()`** — an HTML management page
  (auth-only) that lists the user's passkeys and lets them add, rename and
  revoke, all client-side via `pf-webauthn.js` against the existing JSON
  endpoints.
- **Bundled `passkey/manage.html.php`** views for the plain-CSS, Bootstrap and
  Tailwind themes (framework fallbacks; publishable via `project:publish-views`).

## Fixed

- **Reachability:** the account dashboard sidebar and the Security page now link
  to **Passkeys** (alongside Two-Factor Auth and Change Password), so every
  built-in account-security feature is reachable through the UI — no orphan
  pages.
