---
date: 2026-08-20
categories: [Changelog]
---

# A scheduled command that reported "Done"

`Scheduler::command('spool:drain')` shelled out to the literal `php pramnos`, and threw the
exit status away. In a scaffolded application — whose console is `<cliName>.php` in the
project root, as the scaffolder itself documents — that command does not exist.

<!-- more -->

## Fixed

```
Running: Write rows buffered out of the request path
Could not open input file: pramnos
  ✓ Done
```

Reported from a project running on `dev-main`: all three framework `command` tasks —
`spool:drain`, `timescale:drain`, `queue:cleanup` — had never done anything, on any run,
since the installation existed. `WriteSpool::pending()` stood at 478 with `tokenactions`
empty. The scheduler above it was green the whole time.

**The console is now the one the process is running** — `PHP_BINARY` plus
`$_SERVER['SCRIPT_FILENAME']`, in CLI only — because the process running the scheduler is by
definition a console that knows the commands the scheduler wants to run. A fixed name is a
guess; the running script is a fact. `PRAMNOS_BIN` still overrides it.

**A non-zero exit now throws.** `schedule:run` and `work` already catch per task, print the
failure and count it; the status simply never reached them. This is the half that matters:
either fix alone would have exposed the other, but only this one turns "a bug found by
someone counting rows in a spool file days later" into "a bug found the first minute it
happens".

The overlap lock is released through `run()`'s existing `finally`, so a failing command does
not lock its own task out of every subsequent minute.

## Documentation

`Pramnos_Workers_And_Daemons_Guide.md` — how a `command` task is run, what `PRAMNOS_BIN`
overrides, and what a non-zero exit does.
