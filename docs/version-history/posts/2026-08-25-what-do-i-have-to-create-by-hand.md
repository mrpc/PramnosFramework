---
date: 2026-08-25
categories: [Changelog]
---

# "I have just cloned this — what do I have to create by hand?"

Nothing answered that, and until today the answer was "nothing", because the credentials
were committed. Moving them out of the repository was right and it left a hole.

<!-- more -->

## Added

- **`project:setup`** — brings a cloned checkout to a working local environment. `init`
  creates a project and refuses to touch one that exists; this one only ever operates on
  a project that already does.

  Seven steps, each skipped when it is already done, so running it twice is safe and
  running it after a `git pull` is a reasonable way to catch up: `.env` from
  `.env.example`, `docker-compose up -d --build`, `composer install`, wait for the
  database, framework migrations, an administrator if you want one, and the front-end
  install and build when there is a front end.

  Three of those deserve their reasons stated.

  **The host user ids are read from this machine rather than asked for.** `.env.example`
  carries `UID=1000` — the first non-root user on a Debian host, wrong on plenty of
  others — and getting it wrong means everything the container writes into the bind mount
  is owned by somebody who is not you. Nobody knows their own ids by heart.

  **An existing `.env` is left alone** unless `--force-env`. It is the one file in a
  project that is not in version control, so overwriting it is the only edit here that
  `git checkout` cannot undo.

  **Waiting for the database is not a courtesy.** `docker-compose up -d` returns as soon
  as the containers are *created*; a fresh Postgres or MySQL volume takes several seconds
  more to accept a connection. Migrating into that window fails with a connection error
  that reads exactly like a configuration mistake.

  It writes no project file other than `.env`. Not one of its steps is a scaffolding step
  — `project:reconfigure` and `project:resync` own that — because a command that both set
  up an environment *and* edited tracked files would be one nobody could safely run on a
  checkout with local changes.

## Changed

- **The spinner moved into a trait**, `Console\Commands\Concerns\RunsProcesses`. `Init`
  had the only copy, and `docker-compose up --build`, `composer install` and `migrate`
  are the same commands whether a project is being created or a clone is being brought
  up. A second implementation would have been a second place for the slow-step escalation
  to be wrong — and that escalation is the part worth having in one place: a spinner that
  spins forever is indistinguishable from a hang, so after a threshold it stops spinning,
  says how long the step has been running, and streams the output. It was written because
  an image pull hung and there was nothing on screen to say so.

  The trait declares `explainDockerFailure()` abstract rather than defaulting it to a
  no-op: a command that runs Docker and cannot explain a Docker failure is the situation
  it exists to prevent, and an empty default would let one be written by accident.

## Documentation

- [Console Commands](../../Pramnos_Console_Guide.md) gains a **`project:setup`** section
  with the step table and the flags, next to the `init` options.
