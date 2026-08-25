---
date: 2026-08-26
categories: [Changelog]
---

# The admin links that left the admin area

Mount the administration screens under `/admin` and every one of them works — until you
click something. A view that links with a bare `sURL . 'Users'` reaches the same
controller through the *site* layout: no sidebar, no admin chrome, a different page
around the same table. Every row action, "back" link and pagination control in the
bundled views did that.

<!-- more -->

## Fixed

- **242 links across all three bundled themes now go through `adminUrl()`.** With an area
  mounted they stay inside it; with none configured `adminUrl('Users')` is exactly
  `sURL . 'Users'`, so one view serves both kinds of application and no view needs a
  conditional.

  **User-facing links are deliberately left bare.** An administrator clicking "My account"
  wants the public account page, not an admin-framed copy of it, so `account`, `login`,
  `register`, `Passkey` and `TwoFactorAuth` still leave the area. There is a test for that
  direction too — without it a blanket rewrite would have looked like a pass.

- **Bootstrap classes had leaked into the Tailwind theme.** Four tiles on the admin
  dashboard carried `text-bg-primary` and friends, which Tailwind does not define: white
  text on a transparent surface, invisible on a light background, with nothing in any log
  to say so. Three buttons on the token-actions screen carried `btn-outline-*` and rendered
  with no border or colour.

  Both are now Tailwind utilities, and there is a data-provider test that fails on any
  Bootstrap-only class appearing in that theme again. The four empty `<div >` wrappers
  around the tiles — leftovers of a Bootstrap grid column, doing nothing inside a CSS grid
  — went with them.

## Added

- **`adminUrl(string $path = ''): string`** — a global helper over
  `AdminArea::url()`, because a view reads better for it and 242 call sites read a
  great deal better for it.

## Documentation

- [Routing](../../Pramnos_Routing_Guide.md) gains "Links inside the area": the two forms
  side by side, why the bare one is wrong, and which links are meant to stay bare.
