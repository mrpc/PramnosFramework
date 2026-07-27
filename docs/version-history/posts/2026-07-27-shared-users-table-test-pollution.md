---
date: 2026-07-27
categories:
  - Changelog
  - Testing
tags:
  - tests
  - mysql
  - foreign-keys
  - bugfix
---

# Auth controller tests no longer drop the shared `users` table

Three unit test classes dropped `#PREFIX#users` in `tearDown()` and replaced it
with a three-column stub in `setUp()`. Because that table is shared state with
live foreign keys pointing at it, the whole suite became order- and
history-dependent — most visibly as `TwoFactorAuthTest` failing on MySQL with
"table `users` doesn't exist / already exists".

<!-- more -->

## Fixed

`TwoFactorAuthTest`, `TokensControllerTest` and `TokenActionsControllerTest` each
did, in effect:

```php
// setUp()
$db->query("DROP TABLE IF EXISTS `#PREFIX#users`");
$db->query("CREATE TABLE `#PREFIX#users` (userid, username, email)");
// tearDown()
$db->query("DROP TABLE IF EXISTS `#PREFIX#users`");
```

`#PREFIX#users` is not private test state. `User::setupDb()` creates
`#PREFIX#userstogroups` and `#PREFIX#usertokens` with
`FOREIGN KEY (userid) REFERENCES #PREFIX#users (userid)`, and MySQL keeps those
constraints when the parent table disappears. After these classes ran, the test
database was left with child tables whose parent was gone:

```
mysql> SHOW TABLES LIKE 'user%';
userdetails
usergroups
userstogroups        -- FK → users
                     -- (no `users`)

mysql> INSERT INTO userstogroups (userid, groupid) VALUES (2,1);
ERROR 1452 (23000): Cannot add or update a child row: a foreign key constraint
fails (`pramnos_test`.`userstogroups`, CONSTRAINT `userstogroups_ibfk_1`
FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) …)
```

Which test blew up — and whether the error read *doesn't exist* or *already
exists* — depended purely on execution order and on state left behind by the
previous run, since `User::setupDb()` uses `CREATE TABLE IF NOT EXISTS` and
therefore never repairs a stub table it finds in place. Running a class in
isolation passed; the full suite did not, reproducibly only for some orderings.

All three classes now:

- build the users schema idempotently through `\Pramnos\User\User::setupDb()`
  (the real production schema, single source of truth) instead of a hand-rolled
  stub, and never drop it;
- own only their fixture row — `DELETE FROM #PREFIX#users WHERE userid = …` in
  both `setUp()` and `tearDown()`;
- use `DELETE` instead of `TRUNCATE` on that table, since `TRUNCATE` is rejected
  by MySQL while the `userstogroups` foreign key references it;
- drop and recreate their minimal `#PREFIX#usertokens` fixture *after*
  `setupDb()`, so the fixture schema still wins.

Each class' assertions and coverage are unchanged. The test database is now left
in a consistent state after every run: `users` present, no dangling foreign keys.
