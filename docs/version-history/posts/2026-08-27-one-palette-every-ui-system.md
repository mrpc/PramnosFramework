---
date: 2026-08-27
categories: [Changelog]
---

# One palette, every UI system

A project's colours used to live wherever its UI system happened to keep them: a daisyUI
`@plugin` block for Tailwind with npm, hand-written custom properties for a buildless one,
Bootstrap's variables under Bootstrap, and a fourth copy inside a SPA's own theme file. Same
palette, four places, and the first thing to go wrong is that they stop agreeing — in
whichever theme nobody develops in.

<!-- more -->

## Added

**`app/theme.css` is the palette**, in the format
[daisyUI's theme generator](https://daisyui.com/theme-generator/) already emits. `pramnos
init` writes it, named after the application rather than `light`/`dark`, and nothing else in
a scaffolded project carries a colour value.

That format rather than a config file of our own for two reasons. It is the one a designer
can produce without this framework existing — pick colours on the site, copy the block,
paste it in. And for a Tailwind project with npm it needs no build step at all: `app.css`
imports the file and the plugin reads the blocks.

**`pramnos theme:build`** is for everybody else. It turns the same blocks into
`www/assets/css/theme-tokens.css` — plain custom properties, which is the whole of what a
buildless Tailwind, Bootstrap or plain-CSS project needs — and
`www/assets/theme-tokens.json`, for a SPA's own components. `--check` fails instead of
writing, for CI: a generated file in a repository can go stale, and a stale palette is
invisible until somebody opens the theme nobody develops in.

Each theme lands under `[data-theme="<name>"]`, the one flagged `default` on `:root` as
well, and the one flagged `prefersdark` inside a `prefers-color-scheme: dark` block **scoped
to `:root:not([data-theme])`**. That scoping is the difference between a theme switch that
works and one that works only for visitors whose operating system is already in light mode.

**`ThemeTokens::token()`** reads one value from PHP, for the places a custom property cannot
reach: `<meta name="theme-color">`, where the browser chrome has to match the page and the
value has to be in the markup, and an HTML email, which has no custom properties at all.

## Fixed

**The scaffolded theme toggle switched to daisyUI's stock themes.** It wrote `light` and
`dark`, so a project with a palette of its own lost it the first time a visitor pressed the
button — and got it back by reloading, which reads as a rendering glitch rather than as a
theme name. It now writes the project's own two.

**A SPA stopped guessing its colours.** `scripts/build-theme.mjs` scraped the
server-rendered theme's `:root` properties and mapped what it recognised — it knew
`--primary-color`, and invented the rest. It reads the palette now, where the token names
are already daisyUI's, and falls back to the old scrape only for a project that predates the
file.

## What this deliberately does not do

Bootstrap's own variables are not generated. Bootstrap 5 wants `--bs-primary` as a hex plus
a `--bs-primary-rgb` triplet, and an `oklch()` value cannot be decomposed into one without a
colour-space conversion that has no business happening at build time. A Bootstrap project
reads the tokens directly; theming Bootstrap's components still means Bootstrap's Sass.

## Documentation

- `Pramnos_Theme_Guide.md` — a new *One palette, every UI system*: the format, the build
  tool, the generated selectors, reading a token from PHP, and what is out of scope.
- `Pramnos_Console_Guide.md` — `theme:build` beside the other project commands.
