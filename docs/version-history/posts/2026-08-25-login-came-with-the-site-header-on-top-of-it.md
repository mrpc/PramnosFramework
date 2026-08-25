---
date: 2026-08-25
categories: [Changelog]
---

# /login came with the site header on top of it

Reported from a scaffolded Tailwind project. The fix turned out to be a template the
theme layer has been looking for since it was written, and which no theme ever shipped.

<!-- more -->

## Fixed

- **The built-in auth pages render without the site chrome.** `/login` arrived with the
  sticky site header, the whole navigation and a *Sign in* link pointing at the page the
  visitor was already looking at — and then, below all of it, a full viewport of centred
  card. That card is how every built-in auth view is written (`min-h-screen` under
  Tailwind, `min-height: 100vh` in the plain-CSS and Bootstrap themes), so the chrome was
  never intended to be above it.

  `Pramnos\Auth\Controllers\Account` now calls `setContentType('login')` before
  rendering login, the second-factor step, forgot-password and reset-password, and a
  scaffolded theme ships `app/themes/default/login.php`.

  **The mechanism is not new.** `Theme::$elements` has mapped `'login'` to `login.php`
  since the class was written, and `loadtheme()` consults it before falling back to
  `theme.html.php`. No theme had ever shipped the file, so the fallback was the only
  path anyone had seen — which is also the compatibility guarantee: **a hand-written
  theme with no `login.php` keeps rendering exactly as it did.**

- **`project:switch-ui` writes it too**, so switching UI system does not leave a login
  page loading the previous framework's stylesheet.

## Changed

- The head and foot asset lists are built once and used by both layouts, rather than
  copied. That is the whole reason the split exists: those lines change when the UI
  system changes, and a login page quietly still loading Bootstrap after a switch to
  Tailwind does not read as a bug — it reads as a design decision.

## Documentation

- [Theme Guide](../../Pramnos_Theme_Guide.md) gains **`login.php` — the standalone
  layout**, next to the content-type table, covering why `<head>` and `<body>` are
  written out explicitly, why `renderCss()`/`renderJs()` have to stay, and what happens
  to a theme that does not have the file.

  The same table listed the login entry as `login.html.php`. The code has always said
  `login.php`, so anyone who had tried to use this would have created the wrong filename
  and concluded the mechanism did not work.
