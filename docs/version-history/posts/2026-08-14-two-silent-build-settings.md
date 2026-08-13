---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
tags:
  - spa
  - scaffolding
  - vite
---

# Two build settings that failed quietly

Both reported from a project building a Svelte admin panel against the scaffolding.
Neither produced an error; both produced a wrong result that looked like a working
one.

<!-- more -->

## `publicDir` is now pinned

Vite's default `publicDir` is `<root>/public`, and the generated `vite.config.js`
lives at the project root. In an application whose web root **is** `public/` — the
legacy admin panel, every upload, the whole site — a build therefore copies all of it
into the SPA's `outDir`.

Nothing warns. The build succeeds, and the output directory quietly grows by the size
of the site.

`spa-vite.config.js.stub` now sets `publicDir: 'frontend/static'` explicitly, with the
reason next to it. That is where a scaffolded project keeps files to be copied
verbatim; the directory need not exist. Projects whose web root is `www/` were never
in danger — for them the default was merely useless — but the setting is pinned for
everyone, because a default that is harmless in one layout and destructive in another
is not a default worth relying on.

## The theme generator warns instead of whispering

`scripts/build-theme.mjs` derives the daisyUI palette from the server-rendered
theme's `:root` custom properties, so the two halves of an application do not look
like two products. When that stylesheet is absent it falls back to the framework's
own colours — and it *did* report which source it used, in a `console.log` phrased
exactly like the success case, among the rest of a build's output.

So a project whose theme lives somewhere the scaffold does not expect built cleanly
and shipped in somebody else's brand colour.

It is now a `console.warn` that says what happened, names the path it looked in,
distinguishes "it does not exist" from "it exists and declares no custom properties",
and gives the fix:

```
⚠ theme: no palette found — the SPA will use the framework's colours, not this project's.
  Looked in: www/assets/css/style.css
  It does not exist.
  Fix: point THEME in scripts/build-theme.mjs at this project's stylesheet, or
  declare --primary-color / --text-main / --text-muted in it.
```

Refresh both in an existing project with `project:resync --scripts` (the theme
script) and by copying the `publicDir` line into your own `vite.config.js`, which is
yours to edit.

## Documentation

- [Application Styles Guide](../../Pramnos_Application_Styles_Guide.md) — "Two build
  settings worth knowing about".
