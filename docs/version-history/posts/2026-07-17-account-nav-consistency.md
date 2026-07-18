---
date: 2026-07-17
categories:
  - Changelog
  - Authentication
tags:
  - authserver
  - account
  - scaffolding
  - ui
readtime: 1
---

# Consistent account navigation across all three themes

The built-in account area now has one coherent navigation model in the
plain-CSS, Bootstrap and Tailwind scaffolding.

<!-- more -->

## Changed

- **Shared sidebar + breadcrumb partials** (`partials/account_sidebar`,
  `partials/account_breadcrumb`), included via the framework's `$this->insert()`
  view-in-view mechanism, so every account page shares the same navigation.
- **A clear hierarchy:** Security groups change-password, two-factor and
  passkeys; Privacy groups data export and account deletion. Options that live
  inside a section are no longer duplicated in the sidebar.
- **Stable back-links** via a single `accountBase`, and the 2FA/passkey
  controllers render inside the same account chrome. All auth-view JavaScript is
  consolidated into the shared, data-attribute-driven `pf-auth.js`.
- **Footers no longer carry a "Powered by" line** in any theme (including the
  scaffolder-generated footer for new/switched projects).
