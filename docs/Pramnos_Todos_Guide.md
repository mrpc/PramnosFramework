---
use_cases:
  - Keeping a to-do list for staff or an organisation's members
  - Putting an item on the list for each ticket, comment or goal that needs someone, and taking it off when it no longer does
  - Assigning a to-do to someone, giving it a day, or hiding it without doing it
  - Deciding who may be given to-dos and who sees which
---

# To-dos

`Pramnos\Todos\Todos` keeps a to-do list in `pramnos.todos`. An item is either a person's own,
or one an application's **source** keeps on the list because something needs doing: a ticket to
answer, a comment to approve, a goal that is behind. What should be on the list is the
application's knowledge; the engine keeps the list in step with it.

## An item

```php
use Pramnos\Todos\Todos;

$id = Todos::add(['title' => 'Call the supplier https://example.test/s/4', 'due' => '2026-11-12'], by: 7);
Todos::update($id, ['status' => Todos::IN_PROGRESS], by: 7);
Todos::update($id, ['assigned_to' => 8, 'due' => ''], by: 7);   // to someone else; no day
Todos::update($id, ['status' => Todos::DONE], by: 8);
Todos::delete($id, by: 7);
```

- **From a line.** The first web address becomes the link and the rest the title. When nothing
  else is left, the address is the title too. A `link` given apart is kept as given: a path from
  `/` or a web address, up to 500 characters. A title is up to 4000 characters.
- **Who it is for.** The author by default; `assigned_to` someone, or 0 for nobody in
  particular. A private item is only its author's, and only they see it.
- **A day** (`due`, `YYYY-MM-DD`) is kept as the end of that day in the organisation's zone. A
  `remind_at` is a time to the minute.
- **Status:** `open`, `in_progress`, `done`, `dismissed`. Done and dismissed set `closed_at`;
  opened again, it is cleared. Dismissed takes an item off the list without its being done.
- **Who changes what:**
  - the status, assignee, day, reminder, link and order: anyone who sees the item;
  - the title: only the author of their own item;
  - private or public: only the author;
  - deleting: only the author of their own item. A source's item is dismissed, never deleted.
- **Assigning someone else** asks `todos.assignable`; nobody is refused without it.
- Every add, change and delete is a `todo` event in the change log.

## The list

`Todos::list($tenant, $viewer)` gives what a person sees:
- every public item of the organisation (0 where there are none), and their own private ones;
- open first, then by the order set by hand (`sort`), then by day, items without a day last;
- within a day, oldest first, or newest with `todos.newest_first`;
- a closed item for seven days.

## Sources

```php
Todos::source('ticket', fn (int $tenant): iterable => [
    'ticket:123' => ['title' => 'Answer Maria', 'link' => '/admin/tickets/123', 'assigned_to' => 4, 'due' => '2026-11-12'],
]);
```

A source returns what it wants on the list now, by a key that names what the item stands for.
`Todos::sync()` keeps the list in step:
- **New:** a key not on the list opens an item.
- **Changed:** the title, the day and the assignee follow the source. An assignee a person chose
  by hand stays.
- **Reopened:** a done item still wanted opens again.
- **Dismissed:** a dismissed item still wanted stays dismissed. "Not now" holds while its reason
  does.
- **Gone:** an item no longer wanted is closed as done.
- **Failed:** a source that fails is logged (`todos` log) and skipped; the rest still run.

It runs:
- from the framework's schedule, `todos:sync`, every `todos.sync_every` minutes (5 by default),
  for each organisation `todos.tenants` lists, or for none;
- when a list is read, at most once per that interval;
- after `Todos::touch($tenant)`, on the next read. An application calls it after an act that
  changes a source, so the item goes at once;
- when a goal turns (`goal.turned`), for that goal's organisation.

## Settings

```php
'todos' => [
    'assignable'   => fn (int $tenant, int $user): bool => …,   // who may be given items
    'tenants'      => fn (): array => […],                      // the organisations a sync runs for
    'newest_first' => false,
    'sync_every'   => 5,                                        // minutes
],
```

The rest of the design (reminders, the due-today notice, the conversations, the screens, the
tool and the API) is in [To-dos (design)](Todos-Design.md), built in order.
