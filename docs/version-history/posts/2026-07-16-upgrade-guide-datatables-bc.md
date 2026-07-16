---
date: 2026-07-16
categories:
  - Changelog
  - Documentation
tags:
  - upgrade
  - datatables
  - bc
  - docs
readtime: 2
---

# Upgrade Guide + DataTables `aaData` → `data` BC note

Added a dedicated [Upgrade Guide (Version-to-Version)](../../Pramnos_Upgrade_Guide.md)
that documents the concrete migration steps between releases, and clarified a
behavioural breaking change from v1.2 that was previously under-documented.

<!-- more -->

## Documentation

- **New: Upgrade Guide.** A version-to-version guide with a general upgrade loop
  (preconditions → steps → rollback) plus per-version sections for `v1.1 → v1.2`
  and `v1.0 → v1.1`, each with a breaking-changes table and a validation checklist.
  Wired into the site nav under **Version History**.

## Fixed / Clarified

- **DataTables server-side AJAX BC note.** The v1.2 reference stated that legacy
  DataTables callers were "unchanged". That is true at the method-signature level,
  but `\Pramnos\Html\Datatable::renderJs()` now makes the **client** send `draw`,
  so `Datasource::getList()` returns rows under **`data`** instead of **`aaData`**.
  Application endpoints that fetch an unencoded result, decorate rows in PHP, and
  re-encode them (the hand-written `getJsonList()` / `data()` pattern) silently
  stop decorating and the grid throws
  `Requested unknown parameter 'N'`. The v1.2 reference now carries a prominent
  warning with the one-line `$rowsKey` fix, and the Upgrade Guide covers the full
  migration plus a regression-test recipe.
