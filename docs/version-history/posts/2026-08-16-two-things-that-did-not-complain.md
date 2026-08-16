---
date: 2026-08-16
categories:
  - Changelog
  - Fixed
tags:
  - console
  - debug-toolbar
---

# Two things that did not complain

Reported from consuming the framework in another project. Neither is fatal; both are
the kind that stay invisible because the thing that should object does not.

<!-- more -->

## `project:resync` reported success for a write that failed

```php
file_put_contents($abs, $content);          // return value discarded
$output->writeln("  <info>{$verb}d</info>  {$rel}");
return $exists ? 'updated' : 'created';
```

Run as a user who cannot write the target:

```
Warning: file_put_contents(…/frontend/lib/debug.js): Permission denied
  updated  frontend/lib/debug.js

Done. 0 created, 1 updated, 0 unchanged, 0 skipped.
```

The reporter confirmed with `diff` that the file was byte-identical afterwards. Nothing
was written. The per-file line says `updated`, the summary says `1 updated`, and the
exit status is `0`.

The PHP warning is the only signal, it goes to stderr inside a wall of output, and a CI
job or a habitual `2>/dev/null` throws it away. **A caller checking the exit code — the
correct way to run this — was told the resync had succeeded.**

That inverts the command's entire purpose. `project:resync` exists so a framework-owned
file downstream *is* the framework's current one. A resync that reports success without
writing means a project runs an old copy with confidence, which is precisely what the
command was built to prevent.

Both writes are checked now — the `mkdir()` above it was unchecked too — and a failure
is reported as `failed`, counted in a `FAILED` tally, and exits non-zero. The message
names permissions, because that is by far the likeliest cause and it is fixed by *who*
runs the command rather than by anything in the code.

The `failed` count is printed only when there is one. A `0 failed` on every healthy run
is noise that teaches the reader to skip the line the one time it matters.

### The test, and the first version of it

Reverting the fix must make it fail, or it is watching the wrong thing.

The first version made the target read-only with `chmod 0444` — and the test container
runs as **root**, for whom the mode is advisory. It skipped. A skipped test is green,
which would have left the requested test not running at all.

It now puts a **directory** where the file should be. `file_put_contents()` on a
directory fails for root as well, so it runs everywhere. A second test covers the
`mkdir()` branch by making the parent a regular file. Both fail when the fix is
reverted, with the warnings naming line 230 — the line the report named.

## A duplicate `var` that broke a consumer's build

`debugbar.js` declared `var hasMvcPage` twice in the same scope. Harmless at runtime —
`var` redeclaration is legal and both assigned `false` — and an **error** under
`no-redeclare`, which is in `eslint:recommended`.

That file is not a build artifact. `project:resync` copies it into consuming projects,
where their tooling runs over it. In the reporting project the test runner lints before
it runs, so **1,195 tests stopped running**, none of them about the debug panel. Their
workaround was a per-file ESLint override plus a tripwire to remember to remove it.

The second declaration is gone; the one that remains carries the docblock.

### On preventing the next one — and a guard that was written and thrown away

The report suggested linting the asset in CI, and that is right. This repository has no
`package.json`, no lint configuration and no test workflow at all, so adding ESLint is
infrastructure to decide on rather than something to attach to a bug fix.

A zero-dependency substitute was written instead: scan the shipped assets for an
identifier declared with `var` more than once. It failed on its first run — on
`var rows`, declared in six **different functions**, which is legal and not what
`no-redeclare` means.

So it was deleted. It matched a *name* rather than a *construction*, which is the exact
failure this changelog has been recording all week, committed by the check meant to
prevent one. The reporter had said a unit test for this would be worse than the linter
that already catches it; that turned out to be a prediction rather than an opinion.

**The honest position: the recurrence risk is open.** Real scope analysis needs a
parser, a parser means a dependency, and the dependency means `package.json` plus a CI
workflow — worth doing, and worth doing deliberately.

## Fixed

- `project:resync` checks `file_put_contents()` and `mkdir()`, reports `failed`, tallies
  it, and exits non-zero.
- `debugbar.js` no longer declares `hasMvcPage` twice.

## Documentation

- [Console guide](../../Pramnos_Console_Guide.md) — what a failed resync looks like and
  why the exit code is the thing to check.
