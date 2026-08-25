---
date: 2026-08-25
categories: [Changelog]
---

# The database password was in three committed files

Read off a real scaffolded project. `.gitignore` had covered `/.env` from the beginning;
there was simply nothing in it.

<!-- more -->

## Changed

- **Scaffolded projects keep their secrets in `.env`.** `app/config/settings.php`,
  `app/config/testsettings.php` and `docker-compose.yml` were all written with the
  database password in plain text, and `'development' => true` beside it — so a
  deployment of that repository served debug output until somebody edited a tracked file
  on the server.

  The settings files now read the environment and are identical in every checkout:

  ```php
  'password'    => (string) envvar('APP_DB_PASSWORD', ''),
  'development' => envvar('APP_DEBUG', false),
  ```

  `docker-compose.yml` interpolates from the same file — `POSTGRES_PASSWORD:
  ${APP_DB_PASSWORD}` — so a credential has one home and one place it can leak from. An
  unset variable interpolates empty and the database image refuses to start, which is
  the right kind of loud: a silently password-less database would be worse.

  `init` writes `.env` with this machine's values and `.env.example` with the same keys
  and the secrets blank. `APP_DEBUG` is `true` in the first and `false` in the second —
  `init` is setting up a development machine, the next place that file is copied might
  not be one.

- **The keys are `APP_DB_*`, not `DB_*`.** A real environment variable beats `.env` by
  design, so a platform that injects `APP_DB_PASSWORD` needs no file. That is also what
  makes the bare names dangerous: they are the ones a hosting image, a CI runner or a
  sibling container is most likely to have set already, for a different database, and the
  result is an application quietly connected to the wrong one.

  Not a hypothesis. This framework's own dev container exports `DB_HOST`, `DB_USER` and
  `DB_NAME`, and the first run of the scaffolding tests after this change read
  `pramnos_test` out of a project configured for `my_auto_app_db`. If it can happen
  inside the repository that introduced the convention, it can happen on a host.

  Defaults in the settings file are the non-secret ones only. A clone with no `.env`
  fails to *authenticate* — which points at the missing file — rather than failing to
  find a database.

## Documentation

- [Getting Started](../../Getting_Started.md) gains **Configuration and secrets**: where
  the values live, why the keys are prefixed, why the password has no default, and what
  to do after a fresh `git clone`.
