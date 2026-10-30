---
use_cases:
  - Letting an administrator act on many records at once from a list
  - Offering an action on the users, devices or orders an AI answer listed
  - Adding a list of people to a user group in one go
  - Running a long bulk operation in the queue instead of the request
---

# Bulk actions

A bulk action is something a person can do to a list of records at once — send these people an
email, turn these devices off, put these users in a group. It is declared once per kind of
record and offered wherever such a list appears: under a list an Ask answer kept
([LLM guide — Artifacts](Pramnos_LLM_Conversations_Guide.md#artifacts-tables-lists-and-texts-kept-from-an-answer)),
or on a list screen of the application's own.

## Declaring one

```php
use Pramnos\Bulk\BulkActions;

BulkActions::define('device', 'disable', t('Turn off'), [
    'reason' => ['label' => t('Why'), 'type' => 'text', 'required' => true],
    'until'  => ['label' => t('Until'), 'type' => 'select', 'options' => fn (int $userId) => Periods::choices()],
], function (array $ids, array $params, int $by): array {
    $n = Devices::disable($ids, $params['reason'], $by);

    return ['done' => $n, 'says' => sprintf(t('%d turned off.'), $n)];
}, ability: 'admin.devices', filter: fn (array $ids, int $by) => Devices::ownedBy($by, $ids));
```

| argument | |
|---|---|
| `entity` | the kind of record: `user`, `device`, … — the same word a list artifact's `entity` uses |
| `params` | the fields asked for: `label`, `type` (`text`, `textarea`, `number`, `select`), `options` for a select (value => label, or a callable given the person), `required` |
| `run` | `fn (array $ids, array $params, int $by)`, answering `['done' => n, 'says' => '…']` or just a count |
| `ability` | an administration ability, checked with `AdminAccess` as the screens check it |
| `allowed` | `fn (int $userId): bool`, for a rule of the application's own |
| `filter` | `fn (array $ids, int $by): array`, the ids this person may act on |

Declare actions where the application boots, not in a controller: a long list runs in the queue
worker, which has to know the action too.

## Running one

```php
BulkActions::available('device', $userId);   // what this person may do: key => label and params
BulkActions::run('device', 'disable', $ids, ['reason' => 'Audit'], $userId);
// ['done' => 12, 'queued' => 0, 'says' => '12 turned off.'], or why not
```

- **Who may is asked when it runs**, not only when it is offered, and again for each queued
  chunk — a person whose rights were taken away in between does nothing.
- **Required fields are required**, a `number` field takes a number and a `select` one of its
  options; the answer names the field that is wrong.
- **Up to 200 records run in the request**; above that the ids go to the queue in chunks of
  500 (`bulk.action`, which every worker handles) and the answer says how many were queued. One
  run names at most 10,000.
- **Every run is written to the audit log** as `bulk.<entity>.<key>`, with how many and the
  fields given.

`BulkActions::forget($entity, $key)` removes an action, the framework's own included, for an
application that offers its own instead.

## The framework's own: adding people to a group

`user` → `group_add` puts people in a user group: one chosen from the existing groups, or a new
one by name. It is behind the Groups screen's ability (`admin.groups`), and each person joins
through `Audience\Groups::add()`, so what joining does elsewhere — the changelog, the events a
rule waits for, the roles a group carries — happens here too. A segment, which is defined by
criteria rather than by a list, is drafted by the analyst's `audience_draft` tool instead.

## On a screen of the application's own

The Ask page draws a list's actions with `Pramnos\Llm\Chat\Web\BulkView::forms($actions,
$actionUrl, $listId, $count, $returnTo, $token, $classes)`, and its controller runs them with the
`ActsOnLists` trait. A list screen of the application's own can call `BulkActions::available()`
for what to offer and `BulkActions::run()` with the selected ids.
