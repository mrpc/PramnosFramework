---
date: 2026-08-14
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - console
  - testing
---

# The suite was running `composer update` sixty-one times

`InitCommandUnitTest` was the most expensive class in the suite: **114.5 s for 61 tests**,
1877 ms each. The [performance study](../../Pramnos_Test_Suite_Performance.md) said why —
each test scaffolds a whole project, several hundred file writes — and proposed scaffolding
once per class.

That diagnosis was wrong, and the way it was wrong is the useful part of this entry.

<!-- more -->

## Two measurements

The proposal died first, for a boring reason: those 61 tests use **42 distinct
option-sets**, because what most of them assert is what a *different* set of answers
produces. There is no shared tree to share.

Then the actual measurement:

| | Time |
| --- | --- |
| `init` into an empty directory | 1.9 s |
| `cp -a` of the finished tree (2556 entries) | **0.078 s** |

Producing the files is **25× cheaper than the run that produces them**. The scaffold was
never the cost. A profile, which took two minutes, said where the cost was:

```
 85%  php::usleep          ← runProcessWithSpinner, polling two child processes
 12%  php::file_get_contents (15 calls)   ← downloading library assets, over the network
< 3%  everything that writes a file
```

`init` ends by running **`composer update` and `composer dump-autoload`** as real
subprocesses, and **downloads front-end assets over HTTP**. Every one of the 61 tests did
both, and exactly one of them passed `--no-download`.

So the class was not merely slow. **A unit-test suite depended on the network and on
composer resolving a throwaway `composer.json`** — which also explains why its timings
wandered from run to run.

## The fix is a flag that was missing anyway

Scaffolding files and installing dependencies are separate jobs, and wanting only the first
is a normal thing to want: CI that installs from its own lockfile, a machine with no
network, a project whose `vendor/` is committed.

```bash
php bin/pramnos init --no-install
```

```
  Skipped installing dependencies (--no-install).
  Run composer install before serving the application.
```

Reported twice — where the step would have happened, and again in the closing next-steps
list — because the alternative is a fatal about a missing autoloader with nothing to connect
it to. Under Docker it also skips the framework migrations that follow, since those run the
new application's own CLI and need the autoloader that was not generated.

## What it bought

| Class | Before | After |
| --- | --- | --- |
| `InitCommandUnitTest` | 114.5 s | **0.56 s** |
| `InitCommandTest` | 19.0 s | **0.42 s** |
| `InitOverwriteGuardTest` | 5.3 s | **0.02 s** |

**136 s net** of a 15-minute suite, after subtracting the 2.1 s of the new
`InitNoInstallTest` — which installs once, on purpose, so the default path stays covered.
Two other tests keep the real steps because the steps are their subject: one asserts the
flag's own reporting, and one asserts that a dry run still *names* the commands it would
have run.

`InitSpaScaffoldingTest` did not move at all, and that is the check on the diagnosis: it
already passed `--no-download` and never reached the composer branch, so there was nothing
there to save.

## The lesson, twice on one page

Item 1 of that study was also fixed wrongly on the first attempt — a connect timeout for
what turned out to be a DNS block. Both mistakes have the same shape: reasoning from what
the code *looks* like it spends time on. The profiler disagreed with the reasoning both
times, in under two minutes.

## Added

- `pramnos init --no-install` — scaffold every file, skip `composer update` /
  `dump-autoload`, and say so. See the
  [Console Guide](../../Pramnos_Console_Guide.md#scaffolding-without-installing--no-install).

## Fixed

- The scaffolding tests no longer reach the network or run composer, except where that is
  the subject of the test.
- The [performance study's](../../Pramnos_Test_Suite_Performance.md) item 2 now records the
  correct diagnosis, the measurements that killed the original one, and the achieved
  numbers.
- The [Testing Guide's](../../Pramnos_Testing_Guide.md) list of habits that make a test
  slow gained the one this was: letting the code under test shell out or reach the network.
