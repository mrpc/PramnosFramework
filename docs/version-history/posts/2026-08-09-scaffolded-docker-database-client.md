---
date: 2026-08-09
categories:
  - Changelog
  - Fixed
tags:
  - docker
  - scaffolding
  - testing
---

# Scaffolded Docker images ship the database CLI client

`pramnos init` now installs `postgresql-client` or `default-mysql-client` — whichever
matches the project's database — into the generated `Dockerfile`, so a schema dump
import actually runs instead of silently doing nothing.

<!-- more -->

## The silent no-op

`TestEnvironment::setup()` — called by every scaffolded project's
`tests/bootstrap.php` — imports an optional schema dump by shelling out to the
database's command-line client:

```php
'PGPASSWORD=%s psql -h %s -p %s -U %s -d %s -f %s > /dev/null 2>&1'
```

Note the redirect. If the client is not installed, the shell's *"command not found"*
goes to `/dev/null`, `shell_exec()` returns nothing, no exception is raised — and the
test database is simply left empty. Every later test then fails somewhere else, for
reasons that have nothing to do with the real cause.

Neither the generated image nor the framework's own dev image had ever installed
those clients (the PHP *drivers* — `pdo_pgsql`, `pdo_mysql`, `mysqli` — were always
there, and they are what everything else uses). The gap stayed invisible because the
tests covering the import branch asserted nothing at all: they wrapped the call in
`try { … assertTrue(true); } catch (\Exception) { assertTrue(true); }`, which passes
whatever happens.

## What changed

- **Generated projects:** `scaffoldDocker()` adds the client matching the selected
  engine — `postgresql-client` for postgresql/timescaledb, `default-mysql-client` for
  mysql. Only one of the two, so the image does not grow for nothing. It also makes
  `./dockerbash` a usable place to inspect the database by hand.
- **This repository's dev image:** the same two packages, so the framework's own
  suite exercises the import for real.
- **The tests that hid it:** the assertion-free `test_real_setup_*` trio is gone.
  In its place are tests that assert an observable effect — the dump now creates a
  probe table, and the test checks that the table exists in the freshly created
  database. A dump of `SELECT 1;` (what they used before) leaves no trace at all, so
  it could never tell an import that ran from one that did nothing.

## Rebuilding

Existing environments need one rebuild to pick the client up:

```bash
docker-compose build php-apache-environment   # this repository
docker-compose build app                      # a scaffolded project
```

Until then the import tests skip with an explicit *"the 'psql' client is not
installed in this container"* message rather than failing — the missing binary is an
environment gap, not a regression in the code under test.
