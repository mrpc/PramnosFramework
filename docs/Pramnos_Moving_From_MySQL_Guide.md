---
use_cases:
  - Moving an application's data from MySQL to PostgreSQL or TimescaleDB
  - Moving a live production database with a cutover of a few minutes
  - Backfilling years of time-series history into TimescaleDB hypertables
  - Checking before a move which tables and columns would not arrive
  - Re-running a move that stopped half way without duplicating rows
  - Checking after a move that every row arrived as MySQL holds it
  - Finding rows whose references point at nothing after a move
  - Moving DATETIME columns into timestamptz without shifting them by the server's offset
  - Repairing Greek or other text stored double-encoded (Î±Î²Î³) in MySQL
  - Finding the logins and lookups that relied on MySQL comparing without regard to case
---

# Moving from MySQL to PostgreSQL

`db:migrate-from-mysql` moves an application's data from its MySQL database into the PostgreSQL
(or TimescaleDB) database its migrations built. The source can be MySQL or MariaDB: every test of
the command runs against both.

```bash
# 1. Point the application at PostgreSQL and build the tables
php bin/pramnos migrate

# 2. See what would move
php bin/pramnos db:migrate-from-mysql --source=mysql://user:password@host:3306/app --dry-run

# 3. Move it
php bin/pramnos db:migrate-from-mysql --source=mysql://user:password@host:3306/app --phase=full
```

The source can also be `'migration_source' => 'mysql://…'` in `app/app.php`.

**The data is half of a move; the SQL is the other.** An application whose code holds
MySQL-written SQL can run it on PostgreSQL while it is converted, with
`'database' => ['translate_mysql' => true]`: see
[MySQL's SQL on PostgreSQL](Pramnos_Database_API_Guide.md#mysqls-sql-on-postgresql-databasetranslate_mysql).

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

**Columns the target generates.** A key the migrations made `GENERATED ALWAYS AS IDENTITY`
refuses an id it is given, so it is inserted with `OVERRIDING SYSTEM VALUE`. The ids are kept,
because the rows that point at them name them, and the identity continues past them. A column
the target computes (`GENERATED ALWAYS AS (…) STORED`) takes no value at all: it is left out of
the move, named in the dry run, and PostgreSQL computes it.

**A table no migration makes** (one the application created by hand in MySQL) is created from
its MySQL definition. Its types follow the mapping below, an `AUTO_INCREMENT` becomes an identity
column, and the primary key is the same. Once its rows are in, it gets MySQL's other indexes,
unique keys and foreign keys, with their `ON DELETE` / `ON UPDATE` rules:

- An index over a prefix (`name(50)`) covers the whole column. A unique one stays unique, which
  the prefix already guaranteed.
- A `FULLTEXT` or `SPATIAL` index, or one over an expression, has no plain PostgreSQL equivalent.
  The dry run names it, and it is not made.
- An index or key is named `<table>_<MySQL name>`. PostgreSQL keeps 63 bytes of a name, so a
  longer one is cut to 54 and ends in `_` and 8 hex characters of a hash of the whole name. Two
  names that share their first 63 bytes stay two.
- A foreign key points at wherever the referenced table went: one the migrations made, or one
  made in the same run. The table loads after the tables it points at.
- A foreign key that the moved rows break is added `NOT VALID`, so it holds for new rows, and the
  run prints the `VALIDATE CONSTRAINT` to run once those rows are dealt with. With
  `--null-orphans`, the references that can be NULL are set to NULL first, and the key is then
  validated.
- A run that stops after making a table and before its indexes and keys leaves it marked, in
  `pramnos_migration_progress`, as made by this tool. The next run finishes it, and the dry run
  says so. A table the migrations made carries no such mark and is never touched.

**`schemaversion` never moves.** It is this database's own record of which migrations ran on
*it* — step 1 above, not the source's history. Left out of every plan by default, even where
the source has one with rows (a long-lived MySQL-origin install does); `--tables=schemaversion`
names it explicitly for the rare case of wanting it by hand. Copying it in would make `migrate`
believe history that happened on a different database — exactly the trap of running this command
*before* step 1 instead of after: `migrate` on an empty target, finding a `schemaversion` table
already seeded by your own flat copy, adopts every row in it as a legacy version already applied
(see [Coming from the legacy version ledger](Pramnos_Migration_Guide.md#coming-from-the-legacy-version-ledger))
and then does nothing — no `authserver`/`pramnos` schemas, no hypertables, everything still flat.
Always run `migrate` to completion first; `db:migrate-from-mysql` then backfills data into the
structure it already built correctly.

| MySQL | PostgreSQL |
|---|---|
| `int unsigned` | `bigint`: the range fits |
| `bigint unsigned` | `bigint`: a `numeric` could be neither an identity column nor a foreign key's target. The dry run counts the rows above its range |
| `tinyint`, `smallint`, `mediumint`, `int` | `smallint`, `smallint`, `integer`, `integer` |
| `decimal(p,s)`, `float`, `double` | `numeric(p,s)`, `real`, `double precision` |
| `datetime`, `timestamp`, `date`, `time`, `year` | `timestamp`, `timestamp`, `date`, `time`, `smallint` |
| `char(n)`, `varchar(n)` | `varchar(n)` — PostgreSQL's `char` pads what it reads back with spaces, MySQL's does not, and PHP would see `'gr '` where it had `'gr'` |
| `text` kinds, `enum`, `set` | `text` |
| `blob` kinds, `binary`, `varbinary` | `bytea` |
| `json`, `bit(1)` | `jsonb`, `boolean` |

An `enum` becomes `text` with a `CHECK` that keeps its list. The list also takes `''`, because
outside strict mode MySQL stores `''` for any value not on the list, and old rows hold it. A `set`
stays plain `text`, since any combination of its words is a value.

MariaDB stores a `JSON` column as `longtext` with a `json_valid()` check; the check is read and
the column is created as `jsonb` all the same. Defaults are kept where PostgreSQL can say the same
thing: the current time (`CURRENT_TIMESTAMP`, or MariaDB's `current_timestamp()`), a number, a
literal, bytes and a bit. A `binary(n)` default is the bytes MySQL stores, padded with zero bytes
to its length (`DEFAULT ''` is eight of them in a `binary(8)`), as `'\x…'::bytea`. A `bit(1)`
default is `true` or `false`. A zero date and an expression PostgreSQL has no word for are left
out.

## How the rows move

- **One snapshot.** Every table is read in one MySQL transaction
  (`START TRANSACTION WITH CONSISTENT SNAPSHOT`), so rows that point at each other were read at
  the same moment. Reads are streamed, not loaded whole.
- **In batches, without duplicates.** Rows go in with `INSERT … ON CONFLICT DO NOTHING`,
  `--batch-size` at a time (5,000 by default, fewer for a wide table). A batch also stops at
  `--batch-mb` megabytes of values (32 by default), so a table of large BLOBs goes a few rows at
  a time and the PHP process stays near that size. A read by key range starts with one row to
  weigh, and sizes each next range by what the last one weighed. A second run adds only
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
- **Indexes out of the way, on request.** Loading millions of rows into a table with half a
  dozen indexes keeps each of them up row by row. `--defer-indexes` (for `full` and
  `operational`) drops a migration-made table's plain indexes for the load and makes them again
  after.
    - Each definition is recorded in `pramnos_migration_progress` before its index is dropped.
      A load that dies leaves what is needed, and the next run of any phase makes them again,
      with or without the option.
    - Unique indexes and those behind a constraint stay, because `ON CONFLICT` and the keys need
      them.
    - The backfill never sets indexes aside: the application is already reading the table.
- **Sequences continue.** Every sequence behind a moved column is set past the highest id, so the
  application's next row does not collide with a moved one.

The command exits `1` when any row was refused.

**Values the target cannot hold as they are** are converted, never guessed, and every one is
counted in the result:

- **MySQL's zero date** (`0000-00-00`) becomes NULL. In a column that takes no NULL it would
  refuse its row, so `--zero-date='1970-01-01 00:00:00'` names what it becomes there instead.
  Such a table moves through PHP, whatever the transport.
- **A NUL byte in text** is dropped, since PostgreSQL text cannot hold one. The result counts the
  values that had one.

## Text, character sets and double encoding

The source is read in `utf8mb4`, which the migrator sets on the connection itself rather than
leaving to the client library's default. Greek, emoji and every other script arrive as they are.
A column that is `latin1` (or any other character set) in MySQL is converted to UTF-8 by MySQL as
it is read, and the dry run names it.

**Double-encoded text** is the one problem a correct read cannot fix. An application that wrote
UTF-8 through a `latin1` connection had each byte stored as a character of its own, so `Ελλάδα`
sits in the table as `Î•Î»Î»Î¬Î´Î±`. MySQL returns it exactly as stored, and it would move like
that. The dry run reads up to 2,000 rows of each table and names every column where it finds
such values, with one of them repaired:

```
legacy: 37 of 2000 sampled value(s) look double-encoded — "Î•Î»Î»Î¬Î´Î±" would be "Ελλάδα";
--repair-encoding=greek.legacy repairs them
```

`--repair-encoding=table.column,…` repairs those columns value by value. Each character is turned
back into the byte `latin1` (Windows-1252) made of it, and the value is replaced only when the
result is valid UTF-8 that differs from it. So `café` in a genuine `latin1` column, or proper
Greek, is left alone. A table with a column to repair moves through PHP, even with
`--transport=fdw`. Give `--phase=verify` the same option, so it compares the values as they were
written.

### What MySQL filled in out of strict mode

A MySQL server running without strict mode, as many production servers do, fills a `NOT NULL`
column that an insert leaves out with `''` or `0`. PostgreSQL refuses the insert. The application's
code was never told it relied on this. The dry run reads the server's `sql_mode` and, out of
strict mode, counts the target's columns that rely on it: `NOT NULL`, no default, and no default
in MySQL either. Keys are not counted, since the application always gives a key.

```
MySQL runs out of strict mode: 1809 NOT NULL column(s) with no default take an insert that leaves
them out there (as '', 0 or a zero date), and refuse it here. --implicit-defaults gives 1650 of
them that default; the rest — dates — need one chosen in the migration: users.born, …
```

`--implicit-defaults` gives those columns MySQL's default: `''` for text, `0` for a number,
`false` for a flag, an empty `bytea`. A date's default in MySQL is the zero date, which PostgreSQL
has no value for, so it is named for the migration to decide. It is applied after the tables are
made, not before — one pass gives it to a table this same run creates too, not only one that was
already there.

A `--dry-run` against tables that do not exist on the target yet counts none of this: there is
nothing there yet to compare the source's columns against, so the line above is silent until
something — the real move, or an earlier dry run after it — has made the tables once.

### Text kept in a blob

An old schema often keeps text, or JSON, in a `blob`. Moved as `bytea`, it is bytes the
application then has to decode. The dry run reads up to 200 rows of each table's blob columns and
names those whose values are all text:

```
doc keeps text in a blob: 200 of 200 sampled value(s) are UTF-8, all of them JSON — jsonb in the
migration takes them as they are
```

Make such a column `text` or `jsonb` in the migration, and the values arrive as the text they are.
For a table the command makes, `--blob-as-text=table.column,…` creates it as `text`. A blob of
real bytes is not named. Write those through `Pramnos\Database\Binary`; see the
[Database API guide](Pramnos_Database_API_Guide.md#bytes-binary-and-reading-a-bytea).

### Comparisons without regard to case

MySQL's `_ci` collations (`utf8mb4_general_ci`, `utf8mb4_0900_ai_ci`, MariaDB's
`utf8mb4_uca1400_ai_ci`) compare text without regard to case, and PostgreSQL compares it exactly.
After the move, `WHERE email = 'Maria@example.com'` no longer finds `maria@example.com`, and a
unique key that refused the second spelling now takes it. The dry run names every indexed text
column where that changes something:

```
email is unique without regard to case in MySQL (utf8mb4_general_ci): here 'A' and 'a' are two
values, both allowed, and = matches one — citext, or lower() in the index and the query, keeps
MySQL's answer
```

What to do is the application's decision, made in its migrations before the move:

- **`citext`** keeps MySQL's behaviour column by column.
- **A unique index on `lower(email)`**, with the lookups written `lower(email) = lower(?)`, keeps
  it in the queries that matter.

A column the target made `citext` is not named, and neither is one whose MySQL collation is
binary.

## Dates, times and zones

MySQL has two kinds of moment, and they move differently:

- **`TIMESTAMP` is a moment.** MySQL stores it in UTC and shows it in the session's zone. The
  source is read with the session in UTC, as `mysql_fdw` reads it, so a `TIMESTAMP` arrives as
  the moment it is, whatever the server's zone.
- **`DATETIME` is a wall-clock time.** It has no zone: it is whatever the application's clock
  said. It names a moment only once a zone is given, so going into a `timestamptz` it is read in
  `--source-timezone`, which defaults to the application's zone (`date_default_timezone_get()`).

| Source | Into `timestamptz` | Into `timestamp` / `date` |
|---|---|---|
| `DATETIME`, `DATE` | the moment it names in `--source-timezone` | as it was |
| `TIMESTAMP` | the moment | the wall-clock time in `--source-timezone` |
| a unix time in an `int` | the moment | the wall-clock time in `--source-timezone` |

The dry run lists every `DATETIME` that goes into a `timestamptz`, and warns when the MySQL
server's own zone is not `--source-timezone`:

```
DATETIME values going into timestamptz are read as Europe/Athens time: orders.created
The MySQL server runs at UTC+00:00, Europe/Athens is at UTC+03:00: if the application wrote them
in the server's time, name that zone with --source-timezone.
```

That is the one question to settle before a move: which clock wrote these values. An
application that set its own zone wrote in that zone; one that used `NOW()` in SQL wrote in the
server's.

The same zone is used where a phase compares with a `DATETIME`. The cutover finds changed rows by
their change column, and the backfill cuts its windows, in that zone. Compared in the wrong zone,
a zone behind UTC would miss every change made in the last hours.

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

Triggers, views, procedures, functions and events are not moved. They carry the application's
logic in MySQL's dialect, and making them again belongs to the migrations. The dry run names each,
with whether the target already has one of that name:

```
Not moved — MySQL logic the migrations are to make again:
  trigger orders_bi (on orders) — the target has none
  view big_orders — the target has one of that name
```

A column the target requires (`NOT NULL`, no default) that MySQL does not have refuses every row:
give it a default, or a value, before the move. A target table with compressed TimescaleDB chunks
refuses rows into them: decompress first.

## Options

| Option | |
|---|---|
| `--source` | `mysql://user:password@host:port/name` |
| `--dry-run` | Report, change nothing |
| `--phase` | `full`, `operational`, `cutover`, `backfill`, `verify` — see below |
| `--tables`, `--exclude-tables` | Source tables to move, or to leave, comma-separated |
| `--batch-size` | Rows per `INSERT` (default 5000) |
| `--batch-mb` | Most megabytes of values per batch, whatever the row count (default 32) |
| `--source-prefix` | The source tables' prefix, when it is not the application's |
| `--zero-date` | What MySQL's zero date becomes in a column that takes no NULL (default: NULL, and the row is refused) |
| `--implicit-defaults` | Give the target the defaults MySQL filled in out of strict mode: `''`, `0`, `false` |
| `--blob-as-text` | A table made here gets these blob columns as `text`, not `bytea` — `table.column` |
| `--repair-encoding` | Repair double-encoded text in these columns, `table.column` — see [Text, character sets and double encoding](#text-character-sets-and-double-encoding) |
| `--source-timezone` | The zone the source's `DATETIME` values were written in (default: the application's) — see [Dates, times and zones](#dates-times-and-zones) |
| `--keep-constraints` | Check the target's keys as rows arrive |
| `--null-orphans` | After a load with keys off, set references to nothing to `NULL` |
| `--changed-column` | `cutover`: the column a changed row is told by |
| `--with-deletes` | `cutover`: delete the rows deleted in MySQL since the copy |
| `--append-only` | `cutover`: tables without a change column read only for new rows, not compared |
| `--verify` | `full`, `cutover`: compare every row with MySQL once the phase is done; `backfill`: digest each window as it is written |
| `--defer-indexes` | `full`, `operational`: drop the target's plain indexes for the load and make them again after |
| `--no-snapshot` | `full`, `operational`: read each table a range of keys at a time, no transaction held open |
| `--time-column` | `backfill`: the column windows are cut on |
| `--chunk-interval` | `backfill`: a window's length (default `1 month`) |
| `--throttle-ms` | `backfill`: a pause after each batch (default 50) |
| `--compress-after-chunk` | `backfill`: compress a window's chunks once it is done |
| `--until` | `backfill`: where the newest window ends (default: the cutover's moment) |
| `--restart` | `backfill`: forget the windows done; `operational`: copy again the tables an earlier run finished |
| `--transport` | `php` (default), or `fdw`: rows moved by PostgreSQL through `mysql_fdw` |
| `--fdw-host` | `fdw`: the MySQL host as the PostgreSQL server reaches it |
| `-v` | Report each batch |

## A live production move, in three phases

`--phase=full` moves everything while the application is stopped. That suits a development copy,
or a database small enough to move in a maintenance window. A large production database moves
in three phases, with the application stopped only for the middle one:

```bash
# 1. While the application runs on MySQL: the operational tables
php bin/pramnos db:migrate-from-mysql --source=… --phase=operational --exclude-tables=devicedata,itemlogs

# 2. Writes stopped (maintenance mode), a few minutes: what changed since, checked, then switch the app
php bin/pramnos db:migrate-from-mysql --source=… --phase=cutover --exclude-tables=devicedata,itemlogs --with-deletes --verify

# 3. The application runs on PostgreSQL: history, in the background
php bin/pramnos db:migrate-from-mysql --source=… --phase=backfill --tables=devicedata,itemlogs \
    --time-column=date --chunk-interval="1 month" --compress-after-chunk
```

The progress is kept in the target, in `pramnos_migration_progress`: one row per table, phase
and window. Moments there are Unix times.

**Operational.** The same copy as `full`, in one snapshot, and the snapshot's moment (T0) and
each table's highest integer id are recorded. Leave the large append-only tables (readings,
logs, mail) to the backfill.

**A copy that stops is taken up where it stopped.** Run the same command again:

- A table the earlier run finished is not read again.
- The table it was copying is read on past the highest id already in the target, when its key
  is one integer column. It keeps the moment (T0) and highest id of the attempt that began it,
  so the cutover reads changes from the earlier moment, which takes in everything since.
- `--restart` copies every table again.

A snapshot is one MySQL transaction held open for the whole copy. On a busy source that is hours
of undo history kept for it. `--no-snapshot` reads each table instead a range of keys at a time
(`WHERE (key) > (last) ORDER BY key LIMIT n`), each range its own statement, so no transaction is
held open. The price is that tables are read at different moments, and a row may point at one
read before or after it. T0 and the highest ids are taken before the first read, so the cutover
still catches everything written since, and the verification after it finds whatever was not
caught.

**Cutover.** What changed in MySQL since each table's copy is written over the target, with
`ON CONFLICT (primary key) DO UPDATE`. A primary key of several columns works the same as one.
How a table is caught up depends on what it has:

- **A change column** (`updated_at`, or `modified`, `last_modified`, `changed_at` and the like,
  or the one `--changed-column` names). The rows changed at or after T0 are read, together with
  the rows past the highest id the copy saw. A Unix time in an integer column counts.
- **No change column.** Every row is compared with the target's row of the same key, a range of
  keys at a time, and the rows that are missing or differ are written. This reads the whole
  table, so it suits the small and medium tables that make up the operational set.
- **A big table that only ever grows** (a log, a ledger), named in `--append-only`. Only the rows
  past the highest id are read. The command says that rows which changed since the copy are not
  seen.

With `--with-deletes`, the target is walked a range of keys at a time, MySQL is asked which of
those keys it still has, and the rest are deleted. Memory stays at one range whatever the table's
size. A key whose text differs between the servers (a moment, a float) cannot be looked up across
them: the command names it and deletes nothing in that table.

A table without a primary key, or one the operational phase never copied, is named and left: copy
it again with `--phase=full --tables=…`. Sequences move past the highest id, as in `full`.
`-v` shows how each table was caught up.

**Verify.** `--phase=verify`, or `--verify` after `full` or `cutover`, checks that the target
holds what MySQL holds:

```
Compared with MySQL:
✓ users: 18204 row(s) in MySQL, 18204 here
✗ log: 2 row(s) in MySQL, 2 here, 1 different — e.g. key 1
✗ pairs: 3 row(s) in MySQL, 4 here, 1 MySQL has not — e.g. key 9, q
```

- Each table's rows are counted on both sides.
- Every source row is converted as the move converts it, and compared with the target's row of
  the same key by a hash PostgreSQL computes for both. A flag that became a `boolean`, a
  `datetime` that became a `timestamptz` and JSON reformatted by `jsonb` therefore compare equal.
- The target is walked for rows MySQL no longer has.

**A hypertable is verified by time window.** Its rows usually arrive without MySQL's `id`, so
there is no key to compare by. `--phase=verify --time-column=time --chunk-interval="1 month"`
compares each window instead, from `--until` (default: now) back to the first row. Each window's
rows are counted on both sides and digested: the sum of a hash of every row, as the move writes
it, so the order rows come in does not matter. The windows that differ are named:

```
✗ metrics: 2401003 row(s) in MySQL, 2401002 here
      27 window(s) compared
      window 2026-03-01 00:00 → 2026-04-01 00:00: 32 in MySQL, 31 here
      window 2026-04-01 00:00 → 2026-05-01 00:00: 30 in MySQL, 30 here, different
```

A window with fewer rows here often means MySQL held two rows the target's unique key takes as
one. "Different" with equal counts means a value did not arrive as written.

Nothing is changed, and the command exits `1` on any difference, naming up to five keys. Run it
with writes stopped: rows the application writes to PostgreSQL afterwards count as differences.
So does a reference that `--null-orphans` set to NULL. A table without a primary key is counted
but not compared.

**Backfill.** A table's history moves in windows of `--chunk-interval` on `--time-column`, newest
first. The first window ends at `--until`, by default the moment the cutover finished, so what
the application has written to PostgreSQL since is not touched.

- **A window done is remembered.** A backfill that stops — a deploy, a failure, a
  Ctrl-C — continues from the windows not done. `--restart` forgets them, and the rows already
  there are left as they are (`ON CONFLICT DO NOTHING`). The windows are counted from `--until`,
  so keep it the same between runs.
- **Into a hypertable:**
    - The compression policy is removed for the backfill and put back after, with its
      `compress_after`. A run that stops leaves it remembered, and the next run puts it back.
    - Chunks already compressed in a window are decompressed before rows go in, since TimescaleDB
      refuses rows into a compressed chunk.
    - With `--compress-after-chunk`, each window's chunks are compressed as soon as the window is
      done, so the disk does not fill with uncompressed history.
- **The time column needs an index in MySQL.** A window is a range on it; without an index that
  starts with it, every window reads the whole table, which for years of history means days.
  The dry run with `--time-column` names such a table, with the `CREATE INDEX` to run first.
- **Each window is checked as it is written.** Its rows are counted on both sides, and a window
  that lost rows is named at once, with the run exiting `1`. With `--verify`, each window is
  also digested, as `--phase=verify` digests it. That costs a second read of the window.
- **Beside live traffic.** `--batch-size` rows at a time, and `--throttle-ms` between batches, so
  WAL and replicas keep up. Rows of a past window land in past chunks, never in the chunk the
  application is writing to.

An interval that does not move back in time (`0 days`, or a typo) is refused before anything is
touched.

## Faster: through `mysql_fdw`

With `mysql_fdw` installed on the PostgreSQL server, `--transport=fdw` lets PostgreSQL read
MySQL itself. Each table, or each backfill window, moves as one `INSERT … SELECT` from a foreign
table, and the rows never pass through PHP. For years of history that is the difference between
hours and days.

```bash
php bin/pramnos db:migrate-from-mysql --source=mysql://u:p@mysql.internal/app --phase=backfill \
    --tables=devicedata --time-column=date --transport=fdw --fdw-host=10.0.0.5
```

- **What the run makes, and drops.** A foreign server, a user mapping and a schema of foreign
  tables (`pramnos_mysql`) are made for the run and dropped after it. MySQL is reached from the
  PostgreSQL server, not from PHP: `--fdw-host` names it as that server sees it, when that is not
  the `--source` host.
- **The same conversions, in SQL.** A flag becomes a boolean, a unix time a moment, a zero date
  `NULL`, JSON only when it is JSON (`IS JSON`, PostgreSQL 16 and later), a number only when it
  is one.
- **PHP takes over where SQL cannot.** Bytes that are not UTF-8, a NUL byte, or a value the
  wrapper refuses fail the statement. That table, or that window, then moves through PHP, which
  repairs them, and the report says so. Values made `NULL` on the way are counted only through
  PHP.
- **No shared snapshot.** Each table is read in its own MySQL transaction, not in one snapshot
  for all of them. That is no loss for a backfill of history that is only appended to. For an
  operational copy of tables that point at each other, prefer `--transport=php`.
- **The cutover always moves through PHP.** Its rows are few, and they are written over the
  target, which `ON CONFLICT … DO UPDATE` needs PHP for here.

`mysql_fdw` is not packaged for every distribution. The framework's own test image builds it on
the pinned TimescaleDB (`docker/timescaledb/Dockerfile`), and that Dockerfile is a recipe for a
server of your own.
