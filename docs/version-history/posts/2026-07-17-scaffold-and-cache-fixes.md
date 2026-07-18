---
date: 2026-07-17
categories:
  - Changelog
tags:
  - devpanel
  - email
  - cache
readtime: 1
---

# Assorted fixes: DevPanel cache flush, email audit log, cache adapter

A handful of smaller fixes across the DevPanel, the mailer and the file cache.

<!-- more -->

## Added

- **Email audit log** — `Email::send()` records every outbound attempt (success
  or failure) in the `mails` table, so the admin Emails viewer has a complete
  delivery log. On by default; suppress per send with `$recordToMails = false`.

## Fixed

- **DevPanel "flush all cache"** now returns its JSON for an in-page AJAX
  request instead of navigating the browser to a raw JSON response.
- **File cache adapter** — flushing or cleaning a cache group that was never
  written (or already removed) no longer throws: `listDirectoryFiles()` returns
  no files for a missing directory instead of letting
  `RecursiveDirectoryIterator` raise `UnexpectedValueException`.
