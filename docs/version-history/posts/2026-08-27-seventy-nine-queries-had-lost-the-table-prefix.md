---
date: 2026-08-27
categories: [Changelog]
---

# Seventy-nine queries had lost the table prefix

`QueryBuilder::table()` substitutes `#PREFIX#` and leaves a bare name exactly as written. So
on any installation with a table prefix — the default — seventy-nine framework queries read
a table that does not exist.

<!-- more -->

## Fixed

**Every framework query names its tables with `#PREFIX#`.** 79 occurrences across 19 files:
`usertokens` (40), `applications` (20), `userdetails` (11), `users` (6), `sessions` (2).

Reported by a consuming application whose suite produced **97 failures** on its first
migration attempt, all `Table '….users' doesn't exist`, the first of them from simply
constructing a user. Ten of the 79 were in `User\User` and **three of those were in its
constructor**, so on a prefixed installation the class could not be instantiated.

**`User\User` now resolves its two configurable tables in one place each.** It computed
`DB_USERSTABLE` into a property and then had ten queries write `'users'` themselves — six
lines referenced the resolved name while ten bypassed it. The same for `DB_USERDETAILSTABLE`
and five more. Both go through a private **static** accessor now, which is also what makes
them usable from `getUsers()` and `getuserid()`, the two static methods among the ten.

A constant that only some queries honour is worse than no constant: it works until somebody
sets it.

Two smaller consequences of the same reading:

- `getTableNames()` reported `null` for the users table when the object had been constructed
  with a user id — the constructor returns early on that path (`return $this->load($userid)`)
  and never reached the assignment.
- Two guide examples taught the mistake: `Pramnos_Database_API_Guide.md` showed
  `->from('users')`. Corrected.

## Why the suite could not catch it

Both test fixtures declare `'prefix' => ''`, which makes `#PREFIX#users` and `users` the
same string. Every test passes either way — so no amount of running the suite tells you a
query has lost its prefix, and 79 of them accumulated under a green suite.

`Pramnos_QueryBuilder_Guide.md` already documented the rule, and even predicted this exact
failure: *"It works on the developer's machine and finds nothing on the installation that has
one."* Writing it down was not enough.

**`tests/Unit/Database/TablePrefixInQueriesTest.php`** is therefore a static check on the
source. It fails on any bare occurrence — in a `table()` / `from()` / join position — of a
name the framework writes with `#PREFIX#` anywhere, and derives that list from the source so
a new table following the convention is covered without editing the test. A second assertion
pins the empty fixture prefix, so nobody reads the first and concludes the suite covers this
by running.

## Documentation

- `Pramnos_QueryBuilder_Guide.md` — *Table prefixes* now says why the suite cannot catch it
  and what does.
- `Pramnos_Database_API_Guide.md` — three examples corrected.
