---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
tags:
  - testing
  - database
---

# The test database was afraid of losing data

126 seconds off the suite, from a configuration file, without touching a single test. The
clue had been sitting in the performance study since it was written.

<!-- more -->

## The clue

The study's table of expensive classes, read again with one question in mind — *why are the
MySQL classes slower than the PostgreSQL ones running the same assertions?*

| | Per test | |
| --- | --- | --- |
| `QueryBuilderMySQLTest` | **401 ms** | 92 tests |
| `QueryBuilderPostgreSQLTest` | 67 ms | 83 tests |
| `FrameworkMigrationsMySQLTest` | **1398 ms** | 50 tests |
| `FrameworkMigrationsPostgreSQLTest` | 269 ms | 59 tests |

Both classes in each pair do the same thing: `setUp()` drops and creates tables, the test
writes rows, `tearDown()` drops them. A 5–6× difference is not "MySQL is slower at SQL", so
the engines got measured directly:

| | MySQL | PostgreSQL |
| --- | --- | --- |
| Connect | 2.3 ms | 5.1 ms |
| 2 × `DROP` + 2 × `CREATE TABLE` | 279.6 ms | 36.0 ms |
| 5 × `INSERT` | **77.0 ms** | 22.0 ms |
| Transaction + `ROLLBACK` | 7.5 ms | 0.6 ms |

**15 ms for a single-row `INSERT`** is not query execution. It is two `fsync` calls per
commit: the InnoDB redo log and the binary log.

## The container was configured for a production it will never be

```
innodb_flush_log_at_trx_commit = 1
sync_binlog                    = 1
log_bin                        = ON
innodb_doublewrite             = ON
```

Full crash durability — for a database whose entire purpose is to be dropped.
`dockertest --resetdb` drops it on request, every integration test creates its own tables,
and nothing in it outlives a run. Nothing replicates from it, and nothing will ever be
recovered to a point in time, so the binary log was a second `fsync` per commit for no
reader at all.

`docker/mysql/my.cnf` now says so, with the measurements in a comment and a line telling
whoever finds it never to copy the file somewhere that holds data they would miss.

| | Before | After |
| --- | --- | --- |
| 5 × `INSERT` | 77.0 ms | **2.1 ms** |
| 2 × `DROP` + 2 × `CREATE TABLE` | 279.6 ms | **112.8 ms** |
| Transaction + `ROLLBACK` | 7.5 ms | **1.1 ms** |

## In the suite

| Class | Before | After |
| --- | --- | --- |
| `FrameworkMigrationsMySQLTest` | 69.9 s | **21.8 s** |
| `QueryBuilderMySQLTest` | 36.8 s | **16.7 s** |
| `TwoFactorAuthServiceMySQLTest` | 31.7 s | **21.2 s** |
| `TokenActionMySQLTest` | 20.4 s | **6.6 s** |
| `SchemaBuilderMySQLTest` | 13.9 s | **2.4 s** |
| *(nine classes over five seconds, total)* | 223.2 s | **97.1 s** |

**126 s**, and all 685 MySQL tests still pass.

## Rebuild, or you keep the old timings

```bash
docker-compose build db && docker-compose up -d db
```

A container built before this config still runs and still passes — it is just two minutes
slower, in a way nothing would ever tell you. So `dockertest` reads
`innodb_flush_log_at_trx_commit` on every run and prints that command when the container is
still durable. An optimisation that depends on a rebuild needs to say when the rebuild has
not happened.

## What this replaced

The study's item 3 proposed a base class — schema per class, data per test in a rolled-back
transaction — for an estimated 150 s. That is still the right idea and it is now worth
40–60 s, because most of what it would have saved was never DDL cost: it was `fsync`.

It is the third time on that page a written plan lost to a measurement, and worth saying
plainly: **profile the class before writing the plan.** The other two took a profiler and
two minutes. This one needed nothing but reading a table that was already there.

## Fixed

- `docker/mysql/my.cnf` — durability settings appropriate to a disposable database, with
  the measurements and the warning next to them.
- `dockertest` reports a container still running the old settings, and names the command to
  fix it.
- The [performance study](../../Pramnos_Test_Suite_Performance.md) records what the
  container change bought, and re-scopes the base class to what is left.
