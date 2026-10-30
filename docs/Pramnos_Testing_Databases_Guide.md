---
use_cases:
  - Getting a database connection in a test that does not leak into the next
  - Building a test table from the migration that builds it in production
  - Running a test against PostgreSQL, MariaDB or TimescaleDB
  - Owning a schema in a test without paying for it per test
---

# Databases in tests

Part of the [testing guide](Pramnos_Testing_Guide.md).

## Take the connection from `Connection::fresh()`, not from the factory

```php
use Pramnos\Framework\Testing\Connection;

Settings::clearSettings();
Settings::loadSettings($settingsFile);
$db = Connection::fresh();
```

`Factory::getDatabase()` hands back whatever instance already exists, and it was built
from whichever settings were loaded when the **first** class in the run asked for one. A
class that loads its own settings and then calls the factory gets the old object:
`connected` says true while nothing behind it is usable, or `server` is empty and the next
`connect()` falls through to a unix socket —

```
RuntimeException: No such file or directory
```

— reported against a class that has touched none of this. It is order-dependent, so it
appears when a filter or a new test changes what runs first and vanishes when the class is
run alone, which is the worst way for a defect to behave.

**And when you park the singleton to put a mock in its place, park the object.**

```php
$dbRef = &Database::getInstance();
$this->originalDb = $dbRef;      // not: clone $dbRef
```

A clone is a different `Database` whose connection parameters are frozen at clone time and
whose `connected` flag says true. `tearDown()` then restores *that* as the singleton, and
every class after it inherits a corpse.

## Build a table from the migration that builds it in production

```php
use Pramnos\Framework\Testing\Schema;

Schema::table('applications', $this->db);
```

**Never hand-write `CREATE TABLE` for a table the framework migrates.** A fixture that
does is testing against a schema nothing else has, and the difference stays invisible until
it is expensive. `applications` is the standing example: production registers the redirect
URI in `callback`, and only a *view* aliases it as `redirect_uri`. A hand-written fixture
with a `redirect_uri` column sets a field nothing consults, takes the "no redirect URI
registered" branch, and passes.

The cost argument does not survive measurement. On this project's MySQL container, for
`applications`:

| | per call |
| --- | --- |
| hand-rolled `DROP` + `CREATE` | 9.56 ms |
| canonical migrations, `drop` + `up()` | 12.18 ms |
| canonical migrations, table already present | **0.23 ms** |

A migration's `up()` opens with `if (hasTable()) return;`, so the second class to ask pays
0.23 ms instead of building its own 9.56 ms copy.

`Schema::table()` takes a **name**, not a list of migrations, because the list is the part
that gets written wrong — `applications` is created by one migration and then altered by
seven more (`systemuser`, a wider `callback`, `trusted`, token lifetimes…), and a list that
misses one builds a table production does not have. Add a table by putting its recipe in `Schema::RECIPES`. `Schema::ensure([...])` takes
an explicit list for the cases that genuinely need one.

**A stub is replaced, not kept.** For the tables in `Schema::SENTINELS` — `applications`,
`usertokens`, `users`, `userdetails`, `settings`, `authserver.permissions` and
`authserver.oauth2_device_codes` — `Schema::table()` checks for a column every real copy has
(`apikey`, `token`, `username`, `fieldname`, `setting`, `granted_by`, `user_id`). A table that exists without it is a stub that some test
built and left behind, so it is dropped and rebuilt. Otherwise every migration's `up()` would
return early because the table exists, and the next insert would fail on a column the stub
never had.

**Calling it from `setUp()` costs one query.** The first call in a process runs the recipe
and records the table's shape: its columns with their types and nullability, its indexes,
its constraints and its triggers. Later calls compare that shape with the table, in one
catalogue query. A table that was dropped is built again. A table that a test changed after
it was built (a column added or retyped, an index or foreign key dropped, a trigger
installed) is dropped and built again. Running the recipe over it would not be enough,
because every create migration returns early on a table that exists.

So a test may change a recipe table freely, and the next call puts it back. Rows go with
it: a table that comes back has no rows.

Tables a recipe depends on are built first: `usertokens` builds `users` and `applications`,
whose rows its foreign keys point at.

**`User::setupDb()` is these recipes.** It builds `users`, `userdetails` and `usertokens` through
`Schema::table()`, then adds the two group tables and the Guest row at `userid` 1. It is
deprecated for new tests: call `Schema::table()` for the tables a test reads.

**Both create the PostgreSQL schema first.** A migration names the schema its table lives in
(`create_authserver_schema`, `create_pramnos_schema`, `create_applications_schema`) in
`$dependencies`, and `Schema::ensure()` runs that one before it. The migration tests drop
these schemas. Without that ordering, a test that builds `authserver.loginlockouts` would
fail with `schema "authserver" does not exist`, but only in a run where a migration test had
gone first.

**Ensure every table the code under test reads, not only the one it writes.** `User::load()`
reads `userdetails`, `Apikey::getData()` resolves its owner through `users`, and a push
retention policy on MySQL is a row in `pramnos.framework_policies`. A table the test did not
ensure is one that an earlier test in the same run may have dropped.

**Seeding a row means seeding it the way production does.** The canonical `usertokens` has
`token`, `deviceinfo` and `scope` as `TEXT NOT NULL`, and MySQL gives a TEXT column no
default — so a seed that omits one is refused, and a stub that declares them nullable hides
that. A production writer that omits one fails the same way under strict mode, which is
exactly what a fixture built from the migration lets a test catch.

**Two fixtures may still be hand-rolled**, and both are about the schema rather than about
a feature:

- a test for a migration, which has to build the *pre-migration* shape to prove the
  migration changes it (`AddTrustedToApplicationsMigrationTest`);
- a characterization test pinning what an older installation's table looked like.

## A test that rebuilds schema, without breaking the ones after it

Some tests need a table in its **canonical** shape rather than whatever the suite happens
to have left there. `Integration/Auth/OAuth2/FullAuthorizationCodeFlowTest` is one: it
drives the OAuth2 client against the framework's own authorization server, and the server
compares the client's redirect URI against `applications.callback` character for character
— a column a hand-rolled `applications` may not have.

The pattern: **drop that table and rebuild it from its migrations**. `Schema::table()` runs
the table's whole recipe, so nothing in its history is left out:

```php
$this->db->schema()->dropTableIfExists('#PREFIX#applications');
Schema::table('applications', $this->db);
```

`BaseTestCase::runMigrations([...], $this->db)` does the same from an explicit list of
migration classes, when a test needs a shape no recipe describes.

**Drop only what nothing else points at.** That is the whole rule, and it is cheap to get
wrong: `usertokens` carries a foreign key to `applications`, so a test that also drops and
rebuilds `usertokens` leaves the constraint dangling, and tests with nothing to do with
OAuth2 fail afterwards — account changes, permissions, token actions — each pointing at its
own tables, none at the test that caused it. Rebuilding the parent is survivable because it
is put back immediately; rebuilding the child is not.

Check before you drop:

```sql
SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_NAME = 'the_table_you_want_to_drop';
```

Everything else follows the rules below: schema and expensive fixtures once per class, rows
per test. An RSA key pair for an OAuth2 server costs a few hundred milliseconds and is
read-only once generated, so it belongs in `setUpBeforeClass()` like any other.

## The PostgreSQL service carries `mysql_fdw`

The `timescaledb` service is built from `docker/timescaledb/Dockerfile`: the pinned
`timescale/timescaledb:2.31.0-pg18` (the version production runs) with `mysql_fdw` compiled in,
for the tests of
`db:migrate-from-mysql --transport=fdw`. The extension is only made available; a test that wants
it creates it in a database of its own. After pulling a change to that Dockerfile, rebuild once:

```bash
docker-compose up -d --build timescaledb
```

## MariaDB, when a change is dialect-sensitive

```bash
./dockertest --nocoverage --mariadb
```

Starts the profile-gated `mariadb` service (MariaDB 10.11) and runs only the tests in the
`mariadb` group against it. It is **not** part of a normal run: `phpunit.xml` excludes the group,
and `docker-compose up -d` leaves the service off, so the main suite never waits on a third
database.

Run it after touching DDL — a migration, the schema grammar, a `CHECK` — because MariaDB differs
from MySQL where MySQL shows nothing: it refuses `DROP CHECK`, keeps a CHECK written on a column
as part of the column, and takes a column's CHECK only after its COMMENT. A test joins the group
by extending the MySQL one with `#[Group('mariadb')]` and a data provider that points at host
`mariadb` — see `MariaDbOauthGrantsMigrationTest`.

## An application's suite against PostgreSQL, before moving to it

An application on MySQL that is moving to PostgreSQL can measure what breaks before the move:
the same suite, run once against PostgreSQL. `PRAMNOS_TEST_SETTINGS` names the settings file a
run uses in place of the bootstrap's. A relative path is read from the project's root, and a name
that is not a file stops the run, rather than letting it fall back to MySQL without a word.

**1. A PostgreSQL service**, off unless asked for, in `docker-compose.yml`:

```yaml
  pgtest:
    image: timescale/timescaledb:2.26.4-pg17   # TimescaleDB is PostgreSQL; the target, if it is the target
    profiles: [pgtest]
    environment:
      POSTGRES_PASSWORD: secret
    tmpfs: [/var/lib/postgresql/data]          # a test database: nothing to keep
```

**2. Its settings**, `app/config/testsettings.pgsql.php`: a copy of `testsettings.php` with the
database block pointed at it, and the application's MySQL-written SQL translated:

```php
'database' => [
    'type'            => 'postgresql',
    'hostname'        => 'pgtest',
    'port'            => 5432,
    'user'            => 'postgres',
    'password'        => 'secret',
    'database'        => 'app_test',
    'translate_mysql' => true,
],
```

**3. The run**:

```bash
docker-compose --profile pgtest up -d pgtest
docker-compose exec -e PRAMNOS_TEST_SETTINGS=app/config/testsettings.pgsql.php app \
    vendor/bin/phpunit --log-junit var/pgsql.xml
```

`TestEnvironment` creates the test database on PostgreSQL and runs the migrations there, so the
first thing measured is whether they build the schema at all.

**Reading what fails.** Run the suite on MySQL with `--log-junit var/mysql.xml` too, and compare
the two lists. What fails only on PostgreSQL is one of four things:

- **A migration that does not build** on PostgreSQL: the schema builder speaks both dialects, raw
  `CREATE TABLE` does not.
- **`DialectException`**: an upsert whose conflict key the table does not decide. Write its
  `ON CONFLICT (…)` by hand.
- **SQL the translator leaves alone because it would change meaning**: a `LIKE` that relied on
  MySQL ignoring case, a `GROUP BY` that leaves columns out, `"…"` as a string.
  `db:dialect-lint` names each with file and line.
- **A test that assumed MySQL**: an error message, an auto-increment that starts at 1 after a
  truncate, rows in an order nobody asked for.

The data moves separately, with `db:migrate-from-mysql`. See
[Moving from MySQL](Pramnos_Moving_From_MySQL_Guide.md).

## Owning a schema without paying for it per test

`Pramnos\Framework\Testing\DatabaseTestCase` is for integration tests that need their own
tables. Declare three things and the lifecycle is handled:

```php
use Pramnos\Framework\Testing\DatabaseTestCase;

class WidgetsMySQLTest extends DatabaseTestCase
{
    protected static function connectionConfig(): array
    {
        return ['type' => 'mysql', 'server' => 'db', 'user' => 'root',
                'password' => 'secret', 'database' => 'pramnos_test', 'port' => 3306];
    }

    protected static function ownedTables(): array
    {
        return ['widget_parts', 'widgets'];   // children first
    }

    protected static function schemaStatements(): array
    {
        return ['CREATE TABLE `widgets` (...)', 'CREATE TABLE `widget_parts` (...)'];
    }

    public function testSomething(): void
    {
        $this->db->query('INSERT INTO `widgets` ...');   // $this->db is connected
    }
}
```

| When | What happens |
| --- | --- |
| `setUpBeforeClass()` | Drops the owned tables, then runs the DDL |
| `setUp()` | Connects, and `DELETE`s every owned table |
| `tearDown()` | Closes the connection |
| `tearDownAfterClass()` | Drops the owned tables |

**A test that changes the shape calls `$this->schemaChanged()`.** That covers adding a
column, dropping a table and installing a trigger: anything the next test would otherwise
inherit. The next `setUp()` then drops the owned tables and runs the DDL again. Without
it, the tests after the change pass or fail depending on the order they run in. If every
test in a class changes the shape, call it from `tearDown()`.

Foreign keys between owned tables are handled: the drops and deletes run with
`FOREIGN_KEY_CHECKS = 0` on MySQL, and `ownedTables()` is ordered so PostgreSQL is satisfied
without disabling anything. Override `setUp()`/`tearDown()` freely — just call `parent::`.

**Why it is worth converting a class.** Recreating tables per test is most of each test's
time in a class that never asserts anything about a schema; `QueryBuilderMySQLTest` runs on
this base for that reason, about thirty times faster than with per-test DDL and with the
same assertions.

### The one thing that will bite you

**Auto-increment counters do not restart between tests.** A fixture that writes a
hardcoded foreign key —

```php
// product 1 = Apple, product 3 = Carrot
$this->db->query("INSERT INTO qb_tags (product_id, tag) VALUES (1, 'popular'), (3, 'healthy')");
```

— worked only because the table restarted at 1 every time. After converting, it points at
rows that do not exist, and the failure appears in the *join* tests rather than in the
fixture. Look the ids up instead, which is what the literals meant:

```php
$id = $this->db->queryBuilder()->select('id')->from('qb_products')
    ->where('name', 'Apple')->first()->fields['id'];
```

If a class genuinely asserts on the sequence, override `resetAutoIncrement()` to return
`true` — it costs about 9 ms per table, against 0.11 ms for the `DELETE` alone.

### When not to use it

When the DDL **is** the subject. The framework's migration and schema-builder tests keep
building their schema per test, because that is the behaviour they are asserting.

## Two tests, one framework table, two shapes

The trap that costs an afternoon, and it is not exotic: several tests need `usertokens`,
`tokenactions`, `applications` or `sessions`, and each creates the shape *it* needs with

```php
$this->db->query('CREATE TABLE IF NOT EXISTS `tokenactions` (…)');   // a minimal shape
```

`IF NOT EXISTS` means **whichever test runs first decides the columns**, and the loser fails on
an insert naming one the winner did not create. In isolation both pass. Together, one fails, and
which one depends on the filter you happened to use.

Five rules, in order of preference:

1. **Do not create a table you do not read.** The cheapest fix by far. A test whose subject is
   session revocation needs `sessions` and nothing else — creating the other four to satisfy a
   constructor is how the conflict gets introduced.
2. **Drop before you create**, when you do need one:

   ```php
   $this->db->query('DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('#PREFIX#sessions'));
   $this->runMigrations([CreateSessionsTable::class], $this->db);   // from database/migrations/
   ```

   Then the shape is yours whatever ran before, and `runMigrations()` — which returns early when
   the table exists — actually runs.
3. **Build from the real migration, not from a hand-written `CREATE TABLE`.** A hand-rolled shape
   drifts from what production ships, and two hand-rolled shapes drift from each other. The
   framework's own migration is the one definition both tests can agree on.
4. **Include the retrofit migrations, not only the creating one.** Several indexes are added by
   `AddMissingIndexesToExistingTables` rather than by the `create_*` migration — `idx_sessions_userid`
   among them. A table rebuilt from part of its history is not the shipped table, and the cost lands
   on *other* tests: every later query against it scans, and the slowdown shows up in files
   that have nothing to do with the change.
5. **Ask for a name, never spell it.** A table whose name an installation can override —
   `authserver.user_organizations` is one — has a single reader in the framework
   (`Role::membershipTable()`, `Role::organizationColumn()`), and a fixture that writes the literal
   is a third reader with its own opinion. This one bites hardest on MySQL, where a schema qualifier
   is not a schema: the framework folds `authserver.user_organizations` down to
   `authserver_user_organizations`, `hasTable()` knows about the folding and a raw
   `queryBuilder()->table('authserver.user_organizations')` does not — it asks MySQL for a *database*
   called `authserver`. Use the reader, or `schema()->quoteTable()` for raw SQL.
