---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
tags:
  - testing
---

# A suite that only passed in one order

`tests/Characterization` was the last directory nobody had looked at — 17% of the run for
8.5% of the tests. Measuring it turned up four seconds of easy saving and one thing worth
considerably more: **running that suite on its own failed four tests**, while the full run
passed.

<!-- more -->

## The measurement first

36.2 s across 55 classes. The remaining 45 classes, once the top ten are set aside, are 8.1 s
for 681 tests — **12 ms each**, already fine and not worth touching.

| Class | Before | After |
| --- | --- | --- |
| `UserTokenManagementCharacterizationTest` | 5.14 s | **1.31 s** |

It dropped five tables and ran `User::setupDb()` on every test, while its `tearDown()` was
already cleaning up by row. The schema moved to `setUpBeforeClass()` and nothing else changed.

**`UserAdminCreationMySQLCharacterizationTest` was deliberately left alone**, despite being
the worst per-test class in the directory at 802 ms. It asserts on generated key *values* —
that the first admin lands on `userid = 1`, and that a scaffolded admin gets `userid = 2`
because 1 is reserved for the anonymous identity. The schema and its `AUTO_INCREMENT`
behaviour are the subject, which is precisely the documented case for not doing this.

## The part that matters

```bash
./dockertest --testsuite 'Characterization Tests'
```

Four failures in `ApikeyCharacterizationTest`. The full suite: green. Pre-existing — confirmed
by checking out the pre-work version of `tests/` and reproducing it there.

The cause is a trap another class in this repository already documents in its own comments,
met from the other side:

```php
$this->db->query('CREATE TABLE IF NOT EXISTS `applications` (...)');
```

`applications` is a **shared table name**. Several classes create their own version of it,
with different columns — one has `added`, another has `created`; one has `description` and
`organization`, another does not. `IF NOT EXISTS` keeps whichever schema arrived first, and
this class then inserts into a table missing the columns it needs.

In a full run, something else had already left the table in a shape these tests could live
with. Alone, nothing had. Fixed by dropping before creating, so the class always gets its own
schema.

**A suite that only passes in one order is a suite nobody can bisect.** That is worth more
than the four seconds this item saved: the whole point of being able to run one testsuite is
narrowing down a failure, and it does not work if narrowing changes the answer.

It is the same shape as the two singleton leaks fixed earlier in this work — state left
behind by whatever ran first, and a failure that names the wrong test. This one just used a
table instead of a static.

## So the same question was asked of every suite

If one suite could not run alone, the others were worth checking. `Integration Tests` failed
too — **all seven tests of `QueueControllerMySQLTest`**, with
`RuntimeException: No such file or directory` from a MySQL connect that had been given no host
at all.

That error is worth recognising: a filesystem message from a database call means mysqli fell
back to a socket path, because it was handed nothing. Here, `parent::setUp()` boots the
application, which builds the Factory's **database singleton before the class loads the
fixture settings** — so the cached handle points at nothing. The class passed for as long as
some earlier class had already built a correct singleton.

```php
Settings::loadSettings($settingsFile);

$singleton = &Factory::getDatabase();   // discard what parent::setUp() built
$singleton = null;

$this->db = Factory::getDatabase();
```

Every other class that boots this way already did exactly that; this one had never needed to.

**All three testsuites now pass on their own.**

*(The first attempt at this blamed a missing `CONFIG` constant. It made no difference, and the
guard was removed again rather than left in as noise.)*

## Where the suite stands

**481 s delivered.** `./dockertest` is **6:58** against 17:02 at the start, and
`--no-coverage` **4:01** against 14:58.

## Fixed

- `UserTokenManagementCharacterizationTest` builds its schema once per class.
- `ApikeyCharacterizationTest` drops `applications` before creating it, so
  `--testsuite 'Characterization Tests'` passes on its own.
- `QueueControllerMySQLTest` discards the database singleton `parent::setUp()` built before
  its settings were loaded, so `--testsuite 'Integration Tests'` passes on its own.
