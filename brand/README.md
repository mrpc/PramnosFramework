# Pramnos Framework — Brand Assets

This directory is the **single source of truth** for all Pramnos Framework brand
artwork. Both the documentation site (MkDocs) and the project scaffolder
(`php pramnos init`) consume these files — do **not** duplicate them elsewhere.
Update the master here and let the consumers pull from it.

## Layout

```
brand/
├── logos/            Wordmark lockups (PNG, transparent unless noted)
├── favicons/         Generated web favicon set + manifest.json + browserconfig.xml
└── favicon-source/   High-resolution source art used to regenerate the favicon set
```

## logos/

Naming follows a **background-target** convention:

| File                              | Ink        | Background   | Use on…            |
|-----------------------------------|------------|--------------|--------------------|
| `pramnos-logo-wide.png`           | dark       | transparent  | light backgrounds  |
| `pramnos-logo-wide-inverse.png`   | light      | transparent  | dark backgrounds   |
| `pramnos-logo-square.png`         | dark       | transparent  | light backgrounds  |
| `pramnos-logo-square-inverse.png` | light      | transparent  | dark backgrounds   |
| `pramnos-logo-square-on-white.png`| dark       | solid white  | when transparency is unavailable |
| `pramnos-logo-square-on-dark.png` | light      | solid dark   | when transparency is unavailable |

- **wide** lockups are 500×200 (horizontal mark + wordmark).
- **square** lockups are 500×500.
- `*-inverse` = light ink for dark surfaces.

## favicons/

Standard [realfavicongenerator](https://realfavicongenerator.net/) output —
`favicon.ico`, the `favicon-16/32/96`, Apple touch icons, Android icons,
Windows tiles (`ms-icon-*`), plus `manifest.json` and `browserconfig.xml`.

These files are kept **verbatim** as produced by the generator (icon paths in
`manifest.json` / `browserconfig.xml` are rooted at `/`). The scaffolder rewrites
those paths when it copies them into a generated project — see
`Pramnos\Console\Commands\Init::scaffoldFavicons()`.

## favicon-source/

The high-resolution master art (`pramnos-favicon-master*.png`, 500×500) from
which the `favicons/` set was generated. Kept so the set can be regenerated
consistently if sizes change.

## Consumers

- **Docs site** — `dockerdocs` (and the GitHub Pages workflow) copy
  `logos/pramnos-logo-wide-inverse.png` → `docs/assets/logo.png` and
  `favicons/favicon-32x32.png` → `docs/assets/favicon.png` before every build.
  Wired via `theme.logo` / `theme.favicon` in `mkdocs.yml`. The copies under
  `docs/assets/` are generated and git-ignored.
- **Scaffolding** — `Init::scaffoldFavicons()` copies the favicon set into each
  new project (`www/favicon.ico` + config at the web root, sized icons under
  `www/assets/favicons/`).
