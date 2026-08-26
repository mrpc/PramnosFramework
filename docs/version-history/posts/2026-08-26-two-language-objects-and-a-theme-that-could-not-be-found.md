---
date: 2026-08-26
categories: [Changelog]
---

# Two language objects, and themes that could not be found

Four filings from a consuming application, and three of them are the same mistake wearing
different clothes: a path the class itself does not agree about.

<!-- more -->

## Fixed

### `Language::getInstance()` can be an application's own subclass

Filed as FW-019, from an application running **two** language objects — its own with the
strings loaded, the framework's without. Everything inside the framework that translates,
seven call sites, translated from the empty one. It failed silently, because both objects
return the key unchanged for a missing translation: *"untranslated key"* and *"wrong
instance"* look identical.

`getInstance()` hardcoded `new Language($lang)`. The filing asked for `new static()`, and
that does work — PHP 8.1+ shares a method's static locals with its inherited copies,
verified on 8.5.9. **But which class you get depends on who asks first**, and
`Factory::getLanguage()` is called from seven places inside the framework, so the order is
not the application's to control. That trades a certain bug for a non-deterministic one.

So the class is **declared**:

```php
// app/app.php
'language_class' => '\MyApp\Language',
```

With nothing declared, `\<namespace>\Language` is tried — the convention
`Application::resolveApplicationClass()` already uses — and the base class is the
fallback. A declaration naming a missing class, or one that is not a `Language`, is
ignored rather than fatal. `setInstance()` covers the other question, *here is the object
I already built*, and `resetInstance()` exists because otherwise the first test in a run
decides the language for all of them.

### `onMissingString()` — a hook on the miss path

The second half of FW-019, and not decorative: the reporting application uses it to record
every untranslated key for a translation tool, and to serve a regional dialect kept as a
secondary catalogue. Without it, that region silently reads the wrong dialect.

Whatever the hook returns is **formatted with the caller's arguments**, exactly as a
stored translation is. The legacy filter it replaces returned its result raw, so a supplied
`'Καλώς ήρθες, %s'` lost the argument — harmless there only because none of its languages
used a placeholder. Returning the key unchanged means "nothing to offer"; returning `''`
means "show nothing", because identity against the key is the test rather than emptiness.

### `Language::load()` reached its English fallback

FW-020, and worse than filed. `load()`'s fallbacks named `ROOT/language` while the
constructor resolves `LANGPATH` or `app/language` — and the **English default existed only
under `ROOT/language`**. So on the layout `init` generates, a missing language file did not
fall back to English: it returned `false` and the page rendered untranslated. Both are
searched across every candidate directory now, the requested language first and English
second.

`getFlag()` is deliberately **not** widened the same way. The filing asked for
`$this->languagePath`, which would return a URL for a file no browser can fetch —
`app/language/` is not under the document root. It checks the two *servable* locations and
returns `false` for a flag sitting anywhere else, which is the truth about it.

### Themes are looked for where they are

FW-023: `getThemes()` searched only `ROOT/themes` and returned an empty array **silently**
on the layout `init` creates — an empty theme picker with nothing in any log.
`getThemeObjects()` was worse, opening that directory with no existence check, warning,
getting `false`, and handing `false` to `readdir()`.

Both search `APP_PATH/themes` then `ROOT/themes` now, and `getThemeObjects()` is built on
`getThemes()` rather than repeating the walk — which is what let the two drift.

An existing test was pinning the defect: it passed an explicit `$path` and asserted `[]`,
commented *"not a dir under ROOT/themes → filtered out"*. It was documenting that an
explicit path could never return anything.

### A theme class is checked before it is included

FW-022: `class_exists()` came **after** the `include`, so it could not prevent the fatal it
was there to prevent — `Cannot redeclare class` when the class was already defined.
`include_once` would not have helped: it keys on the resolved path, so two routes to one
file redeclare anyway, and the reporting application has exactly that — a legacy loader
asking for lowercase `theme.php` while Composer loads `Theme.php`.

## Documentation

- [Internationalization Guide](../../Pramnos_Internationalization_Guide.md) gains **One
  language object, and how to make it yours**, **Catching a missing translation**, and
  **Where language files are looked for**.
- [Theme Guide](../../Pramnos_Theme_Guide.md) gains **Where themes are looked for**, with
  the include-order note.
