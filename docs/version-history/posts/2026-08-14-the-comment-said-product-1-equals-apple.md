---
date: 2026-08-14
categories:
  - Changelog
  - Added
tags:
  - testing
  - database
---

# The comment said `// product 1 = Apple`

`Pramnos\Framework\Testing\DatabaseTestCase` gives an integration test a schema that belongs
to the class and rows that belong to the test. The first class converted went from **16.8 s
to 0.56 s**. It also broke six tests, in a way that turned out to be a bug they had been
hiding.

<!-- more -->

## The measurement that reshaped the plan

With the four items of the [performance study](../../Pramnos_Test_Suite_Performance.md)
done, the distribution had changed completely:

| | Original | Now |
| --- | --- | --- |
| Tests ≥ 1000 ms | 203 — **46%** of the run | 19 — **8.5%** |
| Largest single class | 114.5 s | 17.7 s (5.5% of the total) |

The concentration is gone. What is left is spread: 890 tests at ≥ 100 ms account for 82% of
the time, and `tests/Integration` sits at 150 ms per test across 1150 tests. That number is
the shape of the remaining problem — a per-test floor, not a few expensive classes.

The floor is DDL. Written the obvious way, an integration test drops and creates its tables
in `setUp()` and drops them again in `tearDown()`. On this project's MySQL container that is
about 113 ms for a drop-and-create pair, and `QueryBuilderMySQLTest` did three of them per
test — 170 ms of the 183 ms each test took, in a class that never asserts anything about a
schema.

## The base class

```php
class WidgetsMySQLTest extends DatabaseTestCase
{
    protected static function connectionConfig(): array { /* engine, host, credentials */ }
    protected static function ownedTables(): array       { return ['widget_parts', 'widgets']; }
    protected static function schemaStatements(): array   { return ['CREATE TABLE ...']; }
}
```

| When | What happens |
| --- | --- |
| `setUpBeforeClass()` | Drops the owned tables, then runs the DDL |
| `setUp()` | Connects, and `DELETE`s every owned table |
| `tearDown()` | Closes the connection |
| `tearDownAfterClass()` | Drops the owned tables |

`DELETE`, not `TRUNCATE` — [measured last time](2026-08-14-truncate-is-slower-than-dropping-the-table.md),
`TRUNCATE` is an implicit DDL statement and slower than recreating the table. Foreign keys
between owned tables are handled, and the engine differences (backticks against double
quotes, `SET FOREIGN_KEY_CHECKS` against nothing, `ALTER TABLE` against `ALTER SEQUENCE`) are
in one place instead of fifty.

| Class | Before | After |
| --- | --- | --- |
| `QueryBuilderMySQLTest` | 16.8 s | **0.56 s** |
| `QueryBuilderPostgreSQLTest` | 5.1 s | **1.47 s** |
| `QueryBuilderTimescaleDBTest` | 5.1 s | **1.49 s** |

## And then six tests failed

Converting the first class broke `testInnerJoin`, `testLeftJoin`, `testJoinRaw`,
`testWhereExists`, `testWhereNotExists` and `testSelectSubCorrelatedSubquery` — all of them
returning zero rows where they expected five.

The fixture:

```php
private function seedTags(): void
{
    // product 1 = Apple, product 3 = Carrot
    $this->db->query("INSERT INTO `qb_tags` (product_id, tag) VALUES
        (1, 'popular'), (1, 'sweet'),
        (3, 'healthy'), (3, 'organic'),
        (5, 'rare')
    ");
}
```

Those ids worked only because the products table was recreated for every test and
auto-increment restarted at 1. With the schema built once per class, the counter keeps
climbing and the tags point at products that do not exist.

**The comment says what the code meant.** So the fixture now looks the ids up by name, which
is both the fix and what `// product 1 = Apple` was there to explain. The identical fixture in
the PostgreSQL class had the same latent dependency, and broke in the same five tests.

This is the trap the base class introduces, and it is documented as such in the
[Testing Guide](../../Pramnos_Testing_Guide.md#the-one-thing-that-will-bite-you): counters do
not restart, so a hardcoded foreign key in a fixture becomes a join that silently matches
nothing. `resetAutoIncrement()` exists for classes that genuinely assert on the sequence, and
is off by default — it costs about 9 ms per table against 0.11 ms for the `DELETE`.

## Testing the thing other tests trust

A base class like this fails quietly: a schema that is not created makes some *other* class
fail, and a table that is not emptied makes some other class pass for the wrong reason. So
the lifecycle is asserted directly, on both engines — including two tests that call
`setUpBeforeClass()` and `tearDownAfterClass()` from inside a test body, because PHPUnit
collects coverage per test and code that only runs in those hooks is executed but never
attributed.

## Two classes that could not use it, and an 85-millisecond surprise

`TokenTest` and `UsersControllerTest` reach the database through `Factory::getDatabase()`,
because that is what the code under test does — so they got the *pattern* by hand rather than
the base class.

That took `TokenTest` from 15.8 s to 6.0 s, and the remaining 180 ms per test led somewhere
worth writing down:

| Per call | |
| --- | --- |
| `Settings::clearSettings()` + `loadSettings()` | 0.01 ms |
| `Application::getInstance()` | 0.00 ms |
| Drop the database singleton, reconnect | 0.45 ms |
| **`$db->cacheflush()`** | **84.77 ms** |

`cacheflush()` is a **file-cache directory scan**, not a flag. `TokenTest` called it once per
test; `UsersControllerTest` called it **three times** per test — 255 ms each — in classes
where no `query()` call opts into the SQL cache at all, since `$cache` defaults to `false`.
The call defends against what an *earlier class* left behind, which one call per class
handles.

| Class | Before | After |
| --- | --- | --- |
| `TokenTest` | 15.8 s | **3.19 s** |
| `UsersControllerTest` | 10.65 s | **0.50 s** |

Only four test files call `cacheflush()`, so this is not a sweeping win — but it is worth
knowing what it costs before putting it in a `setUp()`.

## Where the suite stands

**442 s delivered** — 56 + 136 + 126 + 78 + 46. Seven more classes over five seconds are
candidates for the same treatment; the two migration classes are not, because for them the
DDL is the subject.

## Added

- `Pramnos\Framework\Testing\DatabaseTestCase` — schema per class, rows per test, engine
  differences in one place.
- `QueryBuilder{MySQL,PostgreSQL,TimescaleDB}Test` converted onto it.
- `TokenTest` and `UsersControllerTest` given the same treatment by hand, since they use the
  Factory's connection, and stripped of a `cacheflush()` that cost 85 ms per call.

## Fixed

- The tag fixtures in the query-builder integration tests no longer depend on
  auto-increment restarting at 1.
