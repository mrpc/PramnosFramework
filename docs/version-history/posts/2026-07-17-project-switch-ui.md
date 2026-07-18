---
date: 2026-07-17
categories:
  - Changelog
  - CLI
tags:
  - cli
  - scaffolding
  - themes
readtime: 1
---

# New `project:switch-ui` command

Switch an existing project between the bundled UI frameworks in place — no
re-scaffolding — to preview the built-in account/auth UI under each.

<!-- more -->

## Added

- **`project:switch-ui <plain-css|bootstrap|tailwind>`** —
  - updates `scaffold_theme` in `app/app.php` (and relaxes the CSP `style-src`
    to `'unsafe-inline'` for Tailwind's runtime build, strict otherwise);
  - re-installs the theme chrome and `www/assets` and pulls the framework's
    CSS/JS vendor assets (delegating to `Init::installUiFramework()`).

  The scaffolded account/auth views are theme-agnostic and resolve per-framework
  from the bundled scaffolding, so switching needs no view copying.
