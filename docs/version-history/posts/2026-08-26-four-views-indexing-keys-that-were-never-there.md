---
title: Four views indexing keys that were never there
date: 2026-08-26
categories:
  - Bugfix
  - Views
---

# Four views indexing keys that were never there

Every Retry and Delete link on the queue screen pointed at job 0. So did Edit and
Delete on the permissions screen. Editing a permission created a new one. And the
organization member forms posted to organization 0.

<!-- more -->

## What was happening

Four bundled views, in all three themes, indexed a key their controller never
returns:

| View | Indexed | Column the query selects |
| --- | --- | --- |
| `queue/queue` | `$job['id']` | `taskid` |
| `permissions/permissions` | `$p['id']` | `permissionid` |
| `permissions/edit` | `$p['id']` | `permissionid` |
| `organizations/members` | `$this->org['id']` | `organization_id` |

`(int) $job['id']` on a missing key is `0` plus a warning. So the pages rendered,
listed their rows correctly, and every action link on every row addressed record
zero — which does not exist, so clicking one did nothing at all. No error, no
message, nothing in a log except an `Undefined array key` notice that a production
error level hides.

`permissions/edit` had a second one on top. The form posted `name="id"` and
`save()` reads `$_POST['permissionid']`, so even with the value fixed the id would
not have arrived. Editing an existing permission therefore inserted a new one and
left the original alone.

## Why it lasted

The empty state. A fresh database has no queue jobs, no permission grants and no
organization members, so every one of these screens renders its "nothing here yet"
branch — and that branch has no rows, no links, and no keys to get wrong.

It surfaced from a test that seeded one row into each list and re-rendered it. Four
warnings, in four views, in one run.

## Also fixed: the queue screen never said why a job failed

`QueueController` selects `error` for every job. No column rendered it. So the
screen reported that a job had failed and withheld the only piece of information
anybody opens it for, with the answer already loaded.

The failure reason now renders under the job type, truncated with the full text in
a `title`.

## Notes

- **A project that published these views has its own copies**, and they have the
  same bug. Republish (`project:publish-views --group=queue,permissions,organizations
  --force`) or apply the four key changes by hand.
- The `emails` and `services` views index `id` too, and there it is correct: those
  rows really do have an `id`.

## The test worth having

Seed one row into each list and render it. It costs one fixture per screen and it
is the only way to reach the half of these pages that has anything in it — the
empty state is what a test database gives you for free, and it is not the page
anybody uses.

## Documentation

- `Pramnos_Console_Guide.md` — a republish note under `project:publish-views`, with the
  four keys and why the empty state hid them.
