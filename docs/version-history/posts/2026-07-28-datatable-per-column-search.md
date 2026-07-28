---
date: 2026-07-28
categories:
  - Changelog
tags:
  - datatables
  - html
  - bugfix
---

# Per-column search works again on server-side DataTables

`Html\Datatable` emits a DataTables 1.10+ client config (`serverSide` + `ajax`),
so the browser sends `columns[N][search][value]` for column filters instead of
the legacy `sSearch_N`. `Datatable\Datasource` translated the modern paging,
ordering and global-search parameters into the legacy names it reads internally,
but not the per-column ones — so every column filter widget, footer search box
and `fnFilter(value, N)` call silently returned the unfiltered set.

<!-- more -->

## Fixed

The modern → legacy translation in `Datasource::render()` now also maps:

| Modern parameter | Legacy parameter |
| --- | --- |
| `columns[N][search][value]` | `sSearch_N` |
| `columns[N][orderable]` | `bSortable_N` |

An `sSearch_N` the caller pre-set in `$_POST` before calling `render()` is never
overwritten by the client's value — applications inject their own server-side
column filters that way (mapping one visible filter onto a different database
column, for instance).

Column boolean flags are normalized through a single helper, so `searchable` /
`orderable` map to the same `"true"` / `"false"` strings whether they arrive as
form-encoded strings, real booleans or `0` / `1`.

The "request already translated" marker moved off `sEcho` onto a dedicated
`Datasource::MODERN_REQUEST_FLAG` key. The translation writes `sEcho` itself, so
using it as the marker meant a second `render()` in the same request looked like
a legacy call and answered a modern client with `aaData` / `iTotalDisplayRecords`
instead of `data` / `recordsFiltered`.

Legacy DataTables 1.9 clients are unaffected: they send `sSearch_N` directly and
never enter the translation branch.

## Tests

Five tests added to `tests/Unit/Pramnos/Html/Datatable/DatasourceTest.php`: modern
per-column search filters the result set, is ignored for a non-searchable column,
does not clobber a pre-set `sSearch_N`, and the response format stays modern
across repeated `render()` calls in one request.
