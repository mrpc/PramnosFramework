---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
  - Added
tags:
  - console
  - scaffolding
---

# `init` will not scaffold over your application

Named as the dangerous finding of a review, and it was: `init` had no `--force`, no
`--dry-run` and no already-initialised check, and `writeFile()` was a bare
`file_put_contents` with no existence test.

<!-- more -->

## What it could do

Running `pramnos init` in an existing project silently overwrote `app/app.php`,
`composer.json`, `CLAUDE.md`, `README.md`, `Dockerfile`, `docker-compose.yml`,
`dockertest`, `phpunit.xml` and `src/Console.php`, and dropped ~18 stock MVC
controllers into `src/Controllers/` — which in an **attribute-routed** application
become **live routes**, because the loader takes whatever is in that directory.

None of it is recoverable without version control, and a scaffolding tool is exactly
what somebody runs optimistically in the wrong directory.

Three things were already non-destructive by design — the `.gitignore` append, the
`package.json` merge, the screens-registry edit — so the intent existed. It simply
was not applied to the rest.

## What happens now

A directory that already contains `app/app.php` is refused, **before the first
question as well as before the first write** — an interactive run that asks fifteen
questions and then says no is its own kind of unhelpful:

```
This directory already holds an application.
  Found: app/app.php

  Running init here would overwrite files including:
    app/app.php
    composer.json
    …
  and add stock controllers to src/Controllers/, which in an
  attribute-routed application become live routes.

  --dry-run lists everything that would be written, and writes nothing.
  --force   proceeds anyway.
```

The exit status is non-zero, so a script notices.

**`--force`** proceeds, and says on stdout that it is proceeding. Silence there
would be worse than the original behaviour: somebody passing `--force` out of habit
should be told what it just allowed.

## `--dry-run`

Asks the same questions, writes nothing, and prints every file it would create or
overwrite — the two lists kept apart, because they are different news. It is allowed
in an existing project, since a preview is exactly what is wanted there.

It also runs **no external commands** (composer, `docker-compose`, migrations, the
docs build) and prints each one it skipped: "it did not run composer" is part of what
the reader is checking.

And it does not append to `.gitignore`, merge `package.json`, copy brand images,
download assets or generate an RSA key pair. A flag that stopped the templates but
still did those would be a trap rather than a preview — a "dry" run that changes the
working tree. The recording happens in `writeFile()` and one shared guard, so the
report cannot drift from what a real run writes.

## Documentation

- [Console Guide](../../Pramnos_Console_Guide.md) — "`init` will not scaffold over an
  existing application", and the `project:` commands to use instead.
