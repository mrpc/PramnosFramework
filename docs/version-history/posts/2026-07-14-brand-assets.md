---
date: 2026-07-14
categories:
  - Changelog
  - Tooling
tags:
  - branding
  - scaffolding
  - console
  - documentation
readtime: 3
---

# Official brand assets: docs logo, scaffolded favicons

The framework now ships its logo and a complete favicon set from a single source of truth
under `brand/`, consumed by both the documentation site and the project scaffolder.

<!-- more -->

## Added

- **`brand/` — canonical brand assets.** A new top-level directory is the single source of
  truth for framework artwork: `brand/logos/` (wordmark lockups, light/dark variants),
  `brand/favicons/` (the full generated favicon + PWA-icon set with `manifest.json` and
  `browserconfig.xml`), and `brand/favicon-source/` (regeneration masters). See
  `brand/README.md` for the naming convention.
- **Documentation site branding.** The MkDocs site now carries the Pramnos logo and favicon
  (`theme.logo` / `theme.favicon`). The assets are synced from `brand/` into `docs/assets/`
  automatically before every build — by the `dockerdocs` script locally and by the GitHub
  Pages workflow in CI — so there is only ever one copy to maintain. The generated
  `docs/assets/logo.png` / `favicon.png` are git-ignored.
- **Scaffolded favicons.** `php pramnos init` now scaffolds a complete favicon set into every
  new project via `Init::scaffoldFavicons()`. Layout: `favicon.ico`, `manifest.json` and
  `browserconfig.xml` at the web root; all sized app icons under `www/assets/favicons/`. The
  manifest is stamped with the app name and the manifest/tile icon paths are rewritten from
  the generator's root-relative form to the subdir, so they resolve under any base path. The
  matching `<link>` / `<meta>` tags are injected into the theme header for all three UI
  systems (plain-css, bootstrap, tailwind).
- **Header logo placeholder.** The scaffolded theme header now shows a logo image instead of
  the app name as plain text. `Init::scaffoldLogo()` copies both ink variants
  (`www/assets/img/logo.png` and `logo-inverse.png`) into the project, and each theme
  references the variant that reads on its default navbar background (light-ink/inverse on
  bootstrap's dark bar, dark-ink on the white plain-css / tailwind bars). The app name is
  preserved as the image's `alt` text. Replace the files to rebrand.
