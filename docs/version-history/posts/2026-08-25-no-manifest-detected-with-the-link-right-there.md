---
date: 2026-08-25
categories: [Changelog]
---

# "No manifest detected", with the link right there in the source

Reported from a freshly scaffolded project. The manifest was written, linked and served
with a 200 — and no browser had ever read it.

<!-- more -->

## Fixed

- **The theme's head assets reach the document's `<head>`.** `theme.html.php` had no
  `<head>` tag, so `Theme::getheader()` — whose only job is to lift `<head>…</head>` out
  of the theme and hand it to the document — always returned an empty string. Everything
  the theme emitted went through `gethead()` instead, which the document writes **after**
  `<body>`.

  It went unnoticed for as long as stylesheets were the only thing in there: a browser
  hoists `<link rel="stylesheet">` out of the body and applies it. It does **not** honour
  `<link rel="manifest">` outside `<head>`. So the favicon block, the manifest link and
  the Windows tile config were all in the wrong half of the document, and the only
  visible symptom was devtools reporting *No manifest detected* about a link anybody
  could see in the page source.

  `theme.html.php` now writes `<head>` and `<body>` out, and includes a new `head.php`
  element for the document head. The `<body>` tag is the other half of the fix: it is
  what stops the split at `[MODULE]` from emitting the head assets a second time as page
  content.

- **`head.php` and `header.php` are separated**, which is the distinction the single file
  lost. `head.php` is the document head — stylesheets, favicons, the manifest link,
  `renderCss()`. `header.php` is the *visible* site header, the logo and the navigation.
  Only one of them can go in `<head>`, and putting both in one file guaranteed that
  neither did.

  A hand-written theme with neither tag keeps rendering exactly as it did.

  The same shape as `login.php`, which was written with both tags a few commits earlier —
  and reading *why* it needed them is what turned this report into a diagnosis rather
  than a search.

## Documentation

- [Theme Guide](../../Pramnos_Theme_Guide.md) gains **`head.php`, and why `<head>` has to
  be in the layout**, next to the standalone login layout it mirrors.
