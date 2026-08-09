---
date: 2026-08-09
categories:
  - Changelog
  - Fixed
tags:
  - application
  - bootstrap
  - console
  - scaffolding
---

# `pramnos init` works in a freshly created project again

`app/app.php` is now treated as an **optional** file when the kernel boots, so the
console front controller no longer fatals in a project that has not been scaffolded
yet — which is exactly the project `init` is meant to scaffold.

<!-- more -->

## The problem

The documented way to start a new project is:

```bash
mkdir my-app && cd my-app
composer init -n
composer require mrpc/pramnosframework
php vendor/bin/pramnos init
```

The last line died before printing anything:

```
PHP Warning:  require(/path/my-app/app/app.php): Failed to open stream: No such file or directory
              in .../Pramnos/Application/Application.php on line 148
PHP Fatal error:  Uncaught Error: Failed opening required '/path/my-app/app/app.php'
```

`Pramnos\Console\Application::__construct()` builds an internal
`Application::getInstance()` for every command (commands need it for the database,
the app namespace, and so on). That constructor did a bare
`require APP_PATH . '/app.php'` — but in a brand-new project `app/app.php` does not
exist yet, and creating it is `init`'s whole job. A `require` failure is an
uncatchable fatal, so `getInstance()`'s `try/catch` could not soften it either: the
framework could not be bootstrapped into a fresh directory at all.

## The fix

Reading the application configuration moved into a small, tolerant loader:

```php
protected static function loadApplicationInfo($file)
{
    if (!file_exists($file)) {
        return array();
    }
    $info = require $file;
    return (is_array($info) || is_object($info)) ? $info : array();
}
```

Both constructor branches (default app and named app) now go through it. A missing
config yields an empty `$applicationInfo`; a file that returns a scalar (a
half-written config, or one missing its `return`) degrades to the same empty array
instead of assigning a scalar that would break every later
`$app->applicationInfo['…']` read. An existing file — including one returning an
array-like object — is returned exactly as before.

This is safe because every consumer already reads the individual keys defensively
(`isset()` / `??`) with its own default — `MakeCommandBase`, for example, falls back
to the `App` namespace. For a scaffolded project nothing changes: the file exists
and is returned verbatim.

## Result

```bash
php vendor/bin/pramnos list   # works in an empty project
php vendor/bin/pramnos init   # scaffolds, syncs dependencies, prints the summary
```

## Tests

`tests/Unit/Application/ApplicationInfoLoadingTest.php` — missing file → `[]`,
existing file → returned verbatim, object config → preserved, scalar return → `[]`.

## Why it started failing

The bare `require` dates back to 2020 (`96604e22`), but until recently it was never
reached in a fresh project. With no `app/app.php`, `getInstance()` fell back to
`['namespace' => 'Pramnos']`, which resolved to `\Pramnos\Application` — a
*namespace*, not a class. `class_exists()` said no, **nothing was instantiated**, and
`getInstance()` handed back `null`. The console application carried a null
`internalApplication`, `init` never touched it, and the scaffold ran fine.

`4c683339` (*make an app-specific Application subclass optional*, 29 Jul 2026) gave
that resolution a fallback to the base kernel. Correct in itself — but it means the
kernel really is instantiated in a project that has no config yet, so the
constructor's `require` finally ran, and fataled.

The fix restores the fresh-project flow **without** giving up that fallback: the
console now gets a working base kernel instead of a null, and a missing config is
simply an empty config.

## Compatibility

Only previously-fatal cases change behaviour, so no working setup is affected. The
trade-off worth knowing: a deployment that *loses* its `app/app.php` (or has a wrong
`APP_PATH`) no longer dies loudly — it boots with an empty config, meaning no
addons, no configured middleware and no features. That failure is now quiet rather
than immediate.
