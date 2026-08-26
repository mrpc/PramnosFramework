---
date: 2026-08-26
categories: [Changelog]
---

# A column you could write and not read

PostgreSQL folds an unquoted identifier to lower case. `compileInsert()` and
`compileUpdate()` have always quoted; the read paths never did. So a column named
`parentToken` could be written and never read back: `SELECT … parentToken` asked for
`parenttoken`, PostgreSQL said no such column, and the builder returned nothing.

<!-- more -->

## Fixed

- **A bare column name containing an upper-case letter is now quoted** in `select()`,
  every `where` variant, `whereIn`, the null checks, `whereBetween`, `groupBy`,
  `orderBy` and `having` — with the dialect's own quoting character, so the same code
  works on both engines.

  The reason this went unnoticed for so long is worth stating: a failed query and an
  empty result reach the caller identically. Nothing raises, nothing logs at the call
  site, and code written on top reads as "there were no matching rows". Three endpoints
  had been built on that silence and shipped broken.

  The predicate is deliberately narrow. Only a **bare identifier with an upper-case
  letter** is quoted:

  | Written | Emitted | Why |
  |---|---|---|
  | `parentToken` | `"parentToken"` | Would otherwise fold |
  | `tokenid` | `tokenid` | Folding cannot affect it |
  | `ut.parentToken` | unchanged | Qualified — the builder cannot tell an alias from a schema |
  | `MAX(ut.lastused) AS x` | unchanged | An expression |
  | `*`, `ut.*`, `"parentToken"` | unchanged | Not a bare name, or already quoted |

  Leaving all-lower-case identifiers alone is the point: folding cannot affect them, so
  **no existing generated SQL changes anywhere**. The whole change is invisible except
  where a query was already failing. The suite — 11,206 tests across MySQL, PostgreSQL
  and TimescaleDB — is unchanged by it.

  A qualified camelCase column is still yours to quote, or to avoid by selecting `*` and
  reading the field from the result; the name survives there either way.

- **`/oauth/logout` finds the token it is handed.** The last of the endpoints built on
  the silence above. It resolves the presented value the way `introspect` and `revoke`
  now do — literal first, then the `jti` inside a JWT — and reports `tokens_revoked`,
  counted before the update because `update()` answers a boolean and a caller reading
  that field would always have seen 0.

  That field is not decoration: the endpoint answers success whether or not anything
  matched, so it is the only way to tell a real revocation from a token that was not
  found.

## Tests

- Two `usertokens` fixtures invented a `sid` column and omitted `parentToken` — exactly
  backwards from the real table, and precisely how a production query selecting `sid`
  passed its tests for as long as it did. A fixture that disagrees with the migration is
  not a test of anything.

## Documentation

- [QueryBuilder](../../Pramnos_QueryBuilder_Guide.md) gains "Column names with
  upper-case letters", with the table above and the portable way to read a qualified one.
