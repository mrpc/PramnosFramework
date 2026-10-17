---
use_cases:
  - Moving an application's data from MySQL to PostgreSQL or TimescaleDB
  - Checking before a move which tables and columns would not arrive
  - Re-running a move that stopped half way without duplicating rows
  - Finding rows whose references point at nothing after a move
---

# Moving from MySQL to PostgreSQL

`db:migrate-from-mysql` moves an application's data from its MySQL database into the PostgreSQL
(or TimescaleDB) database its migrations built.

```bash
# 1. Point the application at PostgreSQL and build the tables
php bin/pramnos migrate

# 2. See what would move
php bin/pramnos db:migrate-from-mysql --source=mysql://user:password@host:3306/app --dry-run

# 3. Move it
php bin/pramnos db:migrate-from-mysql --source=mysql://user:password@host:3306/app --phase=full
```

The source can also be `'migration_source' => 'mysql://…'` in `app/app.php`.

## The target is what the migrations made

The PostgreSQL tables come from the framework's migrations and the application's, so they are
often typed better than the MySQL tables they replace. Every value is converted to what its
target column takes:

| MySQL holds | The target column is | It arrives as |
|---|---|---|
| `1` / `0` in a `tinyint(1)` | `boolean` | `true` / `false` |
| a unix time in an `int` | `timestamptz`, `timestamp`, `date` | the moment, in UTC |
| `0000-00-00…` | a date or a time | `NULL` |
| JSON in `text` | `json` / `jsonb` | the JSON; invalid JSON as `NULL`, counted |
| bytes | `bytea` | the bytes |
| bytes that are not UTF-8, or NUL bytes | text | valid UTF-8, without the NULs |
| `''` or non-numbers | a number | `NULL`; a non-number counted |

A value that had to become `NULL` because it was not what its column takes is counted in the
report. A table's rows go in column by name. A column only MySQL has is not moved, and the dry
run names it.

**Tables in a schema.** MySQL flattens the framework's schemas into prefixed names:
`authserver_things` there is `authserver.things` here. The table prefix on each side is taken off
before the names are matched; `--source-prefix` names the source's when it differs.

**A table no migration makes** (one the application created by hand in MySQL) is created from
its MySQL definition. Its types follow the mapping below, an `AUTO_INCREMENT` becomes an identity
column, and the primary key is the same:

| MySQL | PostgreSQL |
|---|---|
| `int unsigned`, `bigint unsigned` | `bigint`, `numeric(20,0)`: the range fits |
| `tinyint`, `smallint`, `mediumint`, `int` | `smallint`, `smallint`, `integer`, `integer` |
| `decimal(p,s)`, `float`, `double` | `numeric(p,s)`, `real`, `double precision` |
| `datetime`, `timestamp`, `date`, `time`, `year` | `timestamp`, `timestamp`, `date`, `time`, `smallint` |
| `char`, `varchar` | the same, with its length |
| `text` kinds, `enum`, `set` | `text` |
| `blob` kinds, `binary`, `varbinary` | `bytea` |
| `json`, `bit(1)` | `jsonb`, `boolean` |

## How the rows move

- **One snapshot.** Every table is read in one MySQL transaction
  (`START TRANSACTION WITH CONSISTENT SNAPSHOT`), so rows that point at each other were read at
  the same moment. Reads are streamed, not loaded whole.
- **In batches, without duplicates.** Rows go in with `INSERT … ON CONFLICT DO NOTHING`,
  `--batch-size` at a time (5,000 by default, fewer for a wide table). A second run adds only
  what the first did not. A move that stopped half way is run again.
- **A refused batch costs only its bad rows.** The batch is tried again row by row, and each row
  the target refuses is counted, with the first errors shown.
- **Parents first.** Tables load in the order of the target's foreign keys. Tables that point at
  each other in a ring load last and are named.
- **Keys off where allowed.** With a superuser on the target, the load runs with
  `session_replication_role = replica`, so neither keys nor triggers are checked as rows arrive.
  The rows whose references then name nothing are counted afterwards, and `--null-orphans` sets
  them to `NULL` where the column takes `NULL`. Without a superuser, or with
  `--keep-constraints`, the keys are checked and such a row is refused.
- **Sequences continue.** Every sequence behind a moved column is set past the highest id, so the
  application's next row does not collide with a moved one.

The command exits `1` when any row was refused.

## What the dry run tells you

```
5 table(s), in this order. Load with keys off: yes
  users → users  ~3 rows, 7 column(s)
      not moved, the target has no such column: legacy_col
  orders → orders  ~3 rows, 4 column(s)
  only_mysql → only_mysql (created: no migration makes it)  ~2 rows, 3 column(s)
  strict_t → strict_t  ~1 rows, 1 column(s)
      the target requires, and the source has not: must_have — every row will be refused
```

A column the target requires (`NOT NULL`, no default) that MySQL does not have refuses every row:
give it a default, or a value, before the move. A target table with compressed TimescaleDB chunks
refuses rows into them: decompress first.

## Options

| Option | |
|---|---|
| `--source` | `mysql://user:password@host:port/name` |
| `--dry-run` | Report, change nothing |
| `--phase` | `full`: every table, in one snapshot |
| `--tables`, `--exclude-tables` | Source tables to move, or to leave, comma-separated |
| `--batch-size` | Rows per `INSERT` (default 5000) |
| `--source-prefix` | The source tables' prefix, when it is not the application's |
| `--keep-constraints` | Check the target's keys as rows arrive |
| `--null-orphans` | After a load with keys off, set references to nothing to `NULL` |
| `-v` | Report each batch |

`--phase=full` moves everything while the application is stopped, which suits a development
copy or a database small enough to move in a maintenance window. Moving a large production
database while the application runs — the operational tables first, a short cutover, then a
background backfill of history into TimescaleDB chunks — needs the phases that come next.
