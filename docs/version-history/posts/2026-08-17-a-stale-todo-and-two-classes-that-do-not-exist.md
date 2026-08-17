---
date: 2026-08-17
categories: [Changelog]
---

# A stale `@todo`, and two classes that do not exist

A consumer adopting `app/themes/` reported an empty `<body >` tag and a `@todo Use bodyclasses`
sitting next to a method that collects body classes — reasonably concluding the framework
gathered them and never printed them.

It prints them. `Html::render()` and `Amp::render()` have both emitted the list all along; the
`@todo` was **stale**. That is the defect: a note describing finished work is read as a
statement about the present, and this one sent somebody looking for the missing half of a
complete feature. It is gone.

Checking the surrounding lines found three things that were real.

<!-- more -->

## Fixed

### Body classes were not escaped

Every value in `<head>` has been escaped since a consumer reported station names and
administrator text ending attributes early. The body class list was **missed in that pass**,
because it looked only at head values — and it is the same defect with a wider blast radius:
`addBodyClass()` is reasonably fed a slug, a content type, or a user's chosen theme name, and a
`"` in any of those closes the `class` attribute and adds an event handler to `<body>`. A body
class is usually set once for a whole layout, so that is every page.

`extraBodyTag` stays raw, deliberately — it is documented as carrying markup — and now has a
test whose job is to stop a well-meaning future change from "fixing" it.

Two smaller things in the same lines: the tag is now `<body>` rather than `<body >` when there
is nothing to add, and the variable that joined the classes was named `$comma` while holding a
space, which is enough to make a reader check whether the framework was emitting
`class="a,b"`.

### `Amp::render()` could not build a canonical

```php
\pramnos_request::$originalRequestNoChange
```

A legacy CMS class name, which from a namespaced file resolves to nothing. It sits in the
branch that builds a canonical when the document has none — so **every AMP page that did not
set one explicitly** died on `Class "pramnos_request" not found`, which is precisely the case
the branch exists to handle.

### `Theme::saveSettings()` could never have run

```php
pramnos_settings::setSetting(…)
```

The same shape. A theme's settings form could be rendered and never stored. Its `@return
string` was wrong too — it returns nothing, and never did.

Both had a modern equivalent with the **identical member name**, one namespace away.

## Added

`LegacyClassReferenceTest` guards the class of error rather than the two instances: no file
under `src/` may name a `pramnos_*` class. Three have now been found — this pair plus
`pramnos_theme::getTheme()` in `Theme::getThemeObjects()`, fixed on 14 August — and each one
survived because it sat on a branch nothing exercised, which is exactly what behavioural tests
do not reach.

It matches a class *reference* (`pramnos_x::`, `new pramnos_x`, `instanceof pramnos_x`) rather
than the string `pramnos_`, which appears legitimately in table prefixes and cache namespaces
throughout; it strips comments so the framework can document these names; and it asserts that
its own matcher detects all three historical forms, because on a clean tree "no offenders" is
indistinguishable from a pattern that matches nothing.

`LegacyFatalsFixedTest` is the behavioural companion: it executes the two branches that used to
fatal. Both were verified by reverting the fix and confirming the exact original errors —
`Class "pramnos_request" not found` and `Class "Pramnos\Theme\pramnos_settings" not found`.

## Reported, not changed

**Nothing in `Theme` assigns `$_form`.** The whole theme-settings API — `addSetting()`,
`hasSettings()`, `getSetting()`, `renderSettingsForm()`, `saveSettings()` — calls into that
property, and a base `Theme` has it as null, so all five fatal. It is the residue of the same
retirement: `pramnos_html_form` stopped existing, `loadSettings()` was hollowed out, and the
methods that used the form were left calling into nothing.

Deciding what they should do instead — go inert, or be wired to something real — is a design
question rather than a typo, and the framework has no form builder to wire them to. Recorded
here rather than answered.

## Documentation

The Document Output guide's escaping section said escaping applied to `<head>`. It now says
body classes are covered too, and the "Body Classes and Styling" section states what the
renderers actually emit, names the stale `@todo` as a documentation failure, and points at
`extraBodyTag` for the raw case.
