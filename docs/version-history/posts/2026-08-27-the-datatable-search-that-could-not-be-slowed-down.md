---
date: 2026-08-27
categories: [Changelog]
---

# The datatable search that could not be slowed down, and the columns that were all searched the same way

Two filings from a consuming application migrating off its legacy datatable classes, both
blocking: 709 lines with 117 constructions in one case, 571 lines with 128 calls in the other.

<!-- more -->

## Added

**`Datatable::$searchDelay`** (default `500`). Emitted in both places the table debounces:
the footer filters' own `keyup` handler, where `var ms = 500` was hardcoded, and DataTables'
`searchDelay` option for the global box, which was not emitted at all.

It is not one number for every table. The application that asked sets `1200` on its heaviest
admin list — six `LEFT JOIN`s — and `600` on two reports. With a fixed 500 the query rate on
exactly the heaviest lists is the one nobody can lower.

**Three parameters on `Datasource::addField()`** — `ignoreOnOthertypes`, `min`, `max` — and
**`$groupBy`** as the thirteenth parameter of `render()` and `getList()`.

The three had to be *declared*, not merely tolerated: `render()` passes a field's details
back through `call_user_func_array()`, and an associative array becomes **named arguments**
on PHP 8. So a key the signature does not name is `Unknown named parameter`, and the
application's suite failed on `$ignoreOnOthertypes` the moment it tried the modern class.

## Fixed

**The global search treated every column the same way.** It was `LIKE '%term%'` on every
searchable column, which ignored everything `addField()` had been told. Now each column
decides whether the term applies to it:

| `format` | Applies when | How |
| --- | --- | --- |
| `email` | the term is a valid address | `LIKE` |
| `phone` | it looks like a phone number | `LIKE` |
| `numeric` / `number` / `int` | numeric, and within `min`/`max` by **value** | `=` |
| anything else | unless `ignoreOnOthertypes` and the term is a number or an address; within `min`/`max` by **length** | `LIKE` |

Each is a real cost, not a nicety. `LIKE '%5%'` on a numeric column matches 5, 15, 50 and
1523 — a search for an id returning a page of unrelated rows, which is what "the search box
does not work" looks like. Searching every free-text column of a wide list for `12345` costs
a scan each and returns noise. And a column's `startWildcard`/`endWildcard` were **stored and
then ignored**: a leading `%` is what makes an index unusable, so a caller turning it off was
asking for something real and getting the opposite.

**A grouped query's count counted the wrong thing.** `QueryBuilder::count()` preserves a
`GROUP BY` — it documents that — so `COUNT(*)` returns the size of the *first group* rather
than the number of groups, and a pager built on it promises pages that are not there. The
counts are now taken before the grouping is applied, as
`COUNT(DISTINCT <the grouped columns>)`. This is why `$groupBy` is a parameter rather than
something to smuggle into the `where` string: the application that asked had **two forks** of
this class whose only difference was that argument.

## Documentation

- `Pramnos_Html_Components_Guide.md` — a new *Datatable, and its Datasource*, and a
  *Breadcrumb* section for the heading reset a theme has to carry.
