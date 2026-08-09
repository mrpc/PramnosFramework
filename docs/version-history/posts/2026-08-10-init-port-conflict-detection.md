---
date: 2026-08-10
categories:
  - Changelog
  - Fixed
tags:
  - scaffolding
  - docker
  - console
---

# `pramnos init` checks both Docker ports before proposing one

The port suggested for a Docker environment now accounts for the database tool's
port too, and it is verified by actually binding it — so init no longer proposes a
port pair that `docker-compose up` cannot bring up.

<!-- more -->

## The failure

Deep into an init run, after the images had been pulled:

```
Error response from daemon: failed to set up container networking: driver failed
programming external connectivity on endpoint testapp_adminer:
Bind for 0.0.0.0:8081 failed: port is already allocated
Starting Docker environment: FAILED (Exit Code: 1)
```

A generated `docker-compose.yml` publishes **two** host ports: `$port` for the
application and `$port + 1` for Adminer/PHPMyAdmin. The wizard only ever looked at
the first one, so with 8080 free and 8081 taken it cheerfully suggested 8080 — and
the environment died on the tool container minutes later.

## Two separate holes

**Only half the ports were checked.** The scan advanced past a busy application port
but never considered `$port + 1`, even though the compose file it was about to write
publishes it.

**The check asked the wrong question.** It attempted a *connection*:

```php
@fsockopen('localhost', $port, $errno, $errstr, 0.1)
```

That only finds a port something is listening on *and accepting connections from us*.
It misses a port held on another interface, and on a host where `localhost` resolves
to `::1` first it misses an IPv4-only bind — which is exactly how Docker publishes
ports by default. `isPortAvailable()` now binds `0.0.0.0:$port` instead, the same
operation Docker will perform, and reports failure as unavailable.

## What init does now

- `busyPorts($port)` returns which of `$port` / `$port + 1` are taken, so messages
  can name the offender and say what each port is for.
- The suggested default comes from `findAvailablePortPair()`: the first base port
  whose **whole pair** is free.
- An interactive answer is validated, not trusted — a taken port is rejected and
  asked again (bounded, so a stubborn answer still gets through with a warning).
- An explicit `--docker-port` is still honoured (the conflict may be about to clear)
  but is reported up front:

```
Warning: port 9801 already in use; "docker-compose up" will fail unless it is
freed first (9800 = application, 9801 = database tool).
```

## Tests

`tests/Unit/Console/InitPortSelectionTest.php` binds real sockets to reproduce each
case: the reported bug (base free, tool port taken), both ports busy, a free pair,
the suggestion stepping over a conflicted pair, and an explicit busy port being
warned about while still ending up in the generated `docker-compose.yml`.
