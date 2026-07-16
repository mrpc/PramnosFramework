---
date: 2026-07-16
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - login
  - views
  - branding
readtime: 2
---

# Built-in login views + branding seam

The `Account` controller now ships bundled login and second-factor views across
all three scaffold themes (plain-CSS, Bootstrap, Tailwind), so a fresh auth server
renders a working login UI with no view files of its own — and rebrands it by
setting a few settings keys.

<!-- more -->

## Added

- **Bundled `account/login.html.php` and `account/login_2fa.html.php`** for the
  `plain-css`, `bootstrap` and `tailwind` themes. They drive the
  `Account`/`LoginFlow` flow directly:
    - the login form submits once to `<routeBase>/login`;
    - the step-up form submits **only the code** to `<routeBase>/verify` — the
      password is never placed in a hidden field (`LoginFlow` holds the pending
      login server-side), replacing the legacy base64-password round-trip;
    - a "remember me" checkbox, a backup-code entry, a lockout countdown, and
      friendly messages for each error key.
- **`Account::brand()`** — a settings-driven branding seam passed to the views:
    - `auth_brand_name` (falls back to `sitename`, then "Sign in"),
    - `auth_brand_logo`, `auth_brand_primary_color` (default `#2563eb`),
    - `auth_brand_footer`.
  Override the method or set the keys to rebrand; no view edits required.

## Notes

- These new views live under the `account` view group and are entirely separate
  from the existing `login` group used by the scaffolded `Login` controller, so
  nothing changes for apps on the older flow.
- The passkey second-factor option shown on the step-up screen, plus the WebAuthn
  browser glue, arrive in the next phase.
