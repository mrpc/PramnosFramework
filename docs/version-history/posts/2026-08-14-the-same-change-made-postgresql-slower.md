---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
tags:
  - testing
  - database
---

# The same change made PostgreSQL slower

Five more test classes moved to schema-per-class, worth another 35 seconds. A sixth was
converted, measured, and reverted — the identical change that made its MySQL sibling **six
times faster** made the PostgreSQL one slower.

<!-- more -->

## The five that worked

| Class | Before | After |
| --- | --- | --- |
| `OrmRelationsMySQLTest` | 10.20 s | **0.91 s** |
| `ModelTest` | 9.05 s | **2.27 s** |
| `MessagingModelsMySQLTest` | 8.38 s | **1.38 s** |
| `TokenActionMySQLTest` | 8.06 s | **1.02 s** |
| `TwoFactorAuthTest` | 5.57 s | **0.90 s** |

What they were doing per test, in classes that assert what a *model* or a *controller* does:

- `MessagingModelsMySQLTest` and `TokenActionMySQLTest` **ran the framework migrations** —
  every test, from scratch;
- `TwoFactorAuthTest` called `User::setupDb()` plus three migrations;
- `OrmRelationsMySQLTest` dropped and created six tables;
- `ModelTest` dropped and created four, then dropped them again in `tearDown()`.

All five now build their schema in `setUpBeforeClass()` and empty rows with `DELETE`.

## The one that was reverted

`MessagingModelsPostgreSQLTest` is the same tests as its MySQL sibling against the other
engine. The same conversion, applied the same way:

| | Before | After the conversion |
| --- | --- | --- |
| `MessagingModelsMySQLTest` | 8.38 s | **1.38 s** |
| `MessagingModelsPostgreSQLTest` | 5.71 s | **7.34 s** |

Measured twice with the change stashed and unstashed, reproducible, and reverted.

The cause was not chased further, and this entry would be dishonest if it implied otherwise.
What is worth carrying forward is the rule it implies, which the numbers from
[the container work](2026-08-14-the-test-database-was-afraid-of-losing-data.md) already
predicted:

| Two tables, per drop-and-create | |
| --- | --- |
| MySQL | 279.6 ms |
| PostgreSQL | **36.0 ms** |

**The conversion pays where DDL is expensive, and PostgreSQL DDL is not.** Avoiding 36 ms of
DDL with per-class machinery is a trade that can lose, and here it did.

`RbacFunctionsCharacterizationTest` (5.95 s, also PostgreSQL) was left alone on that evidence
rather than converted and measured. That is a judgement call rather than a measurement, and
it is marked as one in the
[performance study](../../Pramnos_Test_Suite_Performance.md).

## Not touched, on purpose

`FrameworkMigrations{MySQL,PostgreSQL}Test` — 17.7 s and 14.9 s, and the two most expensive
classes left in the suite. For them the DDL **is** the subject. A test that asserts a
migration builds the right schema has to build the schema.

## Where the suite stands

**477 s delivered** across the study: 56 + 136 + 126 + 78 + 81. No test, database, or
assertion was removed to get any of it.

| Run | Start | Now |
| --- | --- | --- |
| `./dockertest` (coverage on) | 17:02 | **6:58** |
| `./dockertest --no-coverage` | 14:58 | **4:01** |
| Measured test time | 891 s | **228 s** |

The distribution inverted along the way: the `>= 1000 ms` bucket went from 203 tests and 46%
of the run to 19 tests and 12%, and `tests/Unit` from 60 ms per test to 9 ms. Wall clock is now
4:01 against 228 s of test time, so about 13 s of everything else — there is no fixed overhead
left to remove. The study closes with a measured answer to the parallelism question
(bin-packing 547 classes across 10 cores: 8× at 8 workers, ceiling 13×) and a recommendation
**not** to do it yet, because the prize is now a quarter of what it was when the question was
first asked.

## Fixed

- `OrmRelationsMySQLTest`, `ModelTest`, `MessagingModelsMySQLTest`, `TokenActionMySQLTest`
  and `TwoFactorAuthTest` build their schema once per class.
- `MessagingModelsPostgreSQLTest` keeps its per-test schema, because measurement said to.
