---
date: 2026-07-13
categories:
  - Changelog
  - Tooling
tags:
  - console
  - scaffolding
  - init
readtime: 3
---

# Scaffolding & init tooling fixes

Fixes and a small addition to the project-scaffolding CLI, discovered while exercising the
new-project workflow.

<!-- more -->

## Added

- **`create:controller` / `create:view` — `--full` (`-f`) flag.** The generators can now
  produce the complete CRUD variant of a single artifact — the same output `create:crud`
  makes for that one file. The generation methods always supported this; only the CLI flag
  was missing, so previously-documented `--full` invocations failed with *"The `--full`
  option does not exist"*. Use it when the model already exists and you only need to
  regenerate the full controller or view.

## Fixed

- **`pramnos init` — silent, endless spinner on slow/hung steps.** The progress spinner
  driving long shell steps (`docker-compose up --build`, in-container `composer`,
  migrations) polled forever with no elapsed indicator and buffered all output until exit,
  so a hang was undiagnosable. It now shows an always-on elapsed-time counter
  (`Starting Docker environment / (2m05s)`) and escalates to live subprocess output once a
  step exceeds `Init::$slowStepThreshold` seconds (default `120`; set `0` to disable).

- **`./dockertest` / `./dockerbash` — silent hang when the Docker daemon is wedged.** The
  preflight `docker-compose ps` (and `up -d`, and the `exec` dependency check) had no
  timeout, so a wedged daemon (a common WSL / Docker Desktop failure mode) hung the script
  indefinitely. Both now run a bounded daemon health check and wrap every Docker control
  call in a timeout, failing fast with a clear message and remediation hint. The test run
  itself is intentionally not time-limited. Override the bound with
  `DOCKERTEST_DOCKER_TIMEOUT`. **The same guard is now baked into the `dockertest` and
  `dockerbash` scripts generated for new projects by `pramnos init`**, so scaffolded
  projects inherit the fix.

## Documentation

- Corrected the console command syntax in the setup and console guides: the generators use
  the colon form (`create:controller`, `create:model`, …), not the space form.
- Fixed the log-migration example in the README: the command is `migratelogs <path>` (with
  a required path argument), not `migrate:logs --days=N`.
