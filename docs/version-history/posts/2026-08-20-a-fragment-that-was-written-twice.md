---
date: 2026-08-20
categories: [Changelog]
---

# A fragment that was written twice

`$qb->raw('NOW()')` returns an `Expression`, and the grammar puts the fragment itself
where a placeholder would go — `getPlaceholder()` returns the SQL, not `%s`. The builder
then appended the Expression object to the bindings as well, so the compiled statement
carried one value more than it had placeholders.

<!-- more -->

## Fixed

```php
$qb->from('authserver.loginlockouts')->where('lockoutuntil', '>', $qb->raw('NOW()'))->get();
```

```
PostgreSQL: bind message supplies 1 parameters, but prepared statement "…" requires 0
MySQL:      mysqli_stmt::bind_param(): Argument #1 ($types) must not be empty
```

Neither driver ran it. The expectation going in was that MySQL would tolerate the
surplus and only PostgreSQL would object; MySQL prepends the statement's type string to
the arguments, and a statement with no placeholders has an empty one, so it threw before
the query reached the server. The integration tests cover both engines for that reason.

`insert()`, `update()`, `insertOrIgnore()` and `upsert()` each filtered Expressions out
of their own value map, so the same fragment worked in a value map and threw in a
`WHERE` — which is why the defect survived: the documented `update(['last_login' =>
$qb->raw('NOW()')])` is fine, and the equally documented
`where('expires_at', '<', $qb->raw('NOW()'))` was not. `where()`, `orWhere()`,
`having()`, `whereIn()` and `whereBetween()` had no such filter.

It now lives in `addBinding()`, the one method every clause binds through, and covers
both the scalar and the array paths (`whereIn(['a', $qb->raw('…')])`, a `BETWEEN` with
one literal endpoint and one fragment).

Found while looking into an empty DevPanel: the login-lockout panel queried
`lockoutuntil > NOW()` and its exception was swallowed into a "could not load" line, so
the panel had been blank on every PostgreSQL installation since it was written.

## Fixed — test suite

`HumanCheckTest::testAWrongSolutionIsRefusedWithoutSpendingTheChallenge` answered its
challenge with a fixed string. The difficulty floor is 4 bits, so a fixed wrong answer
satisfies it by accident one run in sixteen — and the nonce is fresh per run, so it was a
fresh coin toss per run rather than a stable pass or a stable failure. It now searches
for a candidate that provably fails `meetsDifficulty()` for the challenge in hand.

## Documentation

`Pramnos_QueryBuilder_Guide.md` — what a raw value does in a `WHERE`, that scalars around
it keep their positions, and which clauses this covers.
