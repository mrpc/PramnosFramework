---
date: 2026-08-27
categories: [Changelog]
---

# The tailwind scaffold theme is a daisyUI theme

Seventy-one bundled views, rewritten from hand-built Tailwind utilities onto daisyUI
components — and onto daisyUI's tokens, which is the half that makes a dark theme possible.

<!-- more -->

## Changed

**`scaffolding/themes/tailwind` now renders through daisyUI 5.** `btn btn-primary` rather
than a hand-built `px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-sm`;
`card bg-base-100 border border-base-300` rather than `bg-white border border-gray-200`;
`alert alert-error`, `navbar`, `menu`, `table`, `input`, `badge`.

The colours are the point. A component carries whichever theme is active; a utility carries
one palette — so `bg-white` and `text-gray-700` are invisible or unreadable under
`data-theme="dark"`, and nothing in any log says so. The page renders and the text is simply
not there.

- **Light and dark both ship.** A toggle in the header stores the choice; `head.php` applies
  it to `data-theme` before the first paint, inline and synchronous — deferred, it paints
  light and then flips.
- **`auth_brand_primary_color` now reaches the buttons.** It used to be an inline
  `background-color` on one button per auth screen, which is both a CSP-relevant inline
  style and an override of whatever the theme said. It now sets `--color-primary` on the
  card, so every daisyUI `primary` component on that screen follows the brand.
- **`style.css` reads tokens.** The breadcrumb and omnibox blocks had literal greys and
  whites; they now read `--color-base-100`, `--color-base-content`, `--color-primary` with
  literals only as fallbacks.
- **A guard test.** `AdminUrlInViewsTest` now fails when any bundled view carries a
  hardcoded palette class. This theme already had Bootstrap classes leak into it once, by
  the same route — a view edited without the theme in mind.

## No build step

daisyUI 5 is a Tailwind *plugin*, and a plugin needs module resolution, which Tailwind's
browser build cannot do. So `@plugin "daisyui"` is not available to a scaffolded project,
and a scaffolded project has no npm. What it uses instead is the prebuilt stylesheet daisyUI
publishes for exactly this case — every component and both token sets in one file — vendored
locally next to the Tailwind runtime by `init` and by `project:switch-ui`.

Order in `head.php` is load-bearing: the browser build, then `daisyui.css` (components, in a
`daisyui` sublayer of `utilities`), then `style.css`. Tailwind's utilities override a
component's defaults; the project overrides both.

An application in front of real traffic should compile instead —
`@import "tailwindcss"; @plugin "daisyui";` into `www/assets/css/style.css`, with its own
themes as token blocks. The markup does not change, because it was written against tokens
rather than against a palette.

## Documentation

- `Pramnos_Theme_Guide.md` — a new *The bundled scaffold themes*, which is also the first
  time `project:switch-ui` is documented in a guide rather than only in a changelog post.
