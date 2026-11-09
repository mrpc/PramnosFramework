---
use_cases:
  - Keeping a to-do list for staff or an organisation's members
  - Putting an item on the list for each ticket, comment or goal that needs someone, and taking it off when it no longer does
  - Assigning a to-do to someone, giving it a day, or hiding it without doing it
  - Deciding who may be given to-dos and who sees which
  - Reminding someone of a to-do, or telling them what is due today
  - Handing a leaving person's to-dos to someone else
  - Making a to-do of a chat message, or being reminded of one
  - Building a chat's to-do list on the conversations' JSON API
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

## Telling

- **Given to someone.** An item assigned to a person — when added, when its assignee changes, or
  when a source opens it — is told in their bell and by push: "A to-do for you · from X". Never
  for one's own act. A source that told its assignee already gives `'tell' => false`.
- **A reminder.** `remind_at` is sent to the minute by `todos:remind`, to the assignee, else the
  author: the bell, a push, and a line from "Reminders" in their conversation with the system
  where the conversations are installed. It is cleared before it is sent, so a failure is not
  repeated every minute. A closed item's reminder is cleared without a word.
- **Due today, or overdue.** With `todos.due_notice` on, each item with an assignee is told once,
  from 08:05 in the organisation's zone, on its day or after it. Not to somebody who may no
  longer be given items (`todos.assignable`), nor in an organisation without to-dos
  (`todos.allowed`). Changing the day lets it be told again.
- **Settled.** A notice is about its item (`todo:ID`): closing or deleting the item settles it in
  every bell. `Todos::seen($viewer)` settles a person's to-do notices; a screen showing the list
  calls it.
- **Where it leads:** `todos.url`, the list at `/admin/Todo` by default.

## In the conversations

`Pramnos\Todos\Chat` makes to-dos from the chat:

```php
Chat::fromMessage($messageId, $reader);                 // "Add to-do": the whole message, private, the reader's
Chat::fromMessage($messageId, $reader, preset: '1h');   // "Remind me about this": "Message from Maria: …"
Chat::write($reader, "Book the room\n- for **ten**", preset: 'tomorrow');
Chat::list($reader, filter: 'overdue', order: 'when');
```

- **From a message.** Only from a message the reader can read. The item keeps the message's whole
  text and its `messageid` and `conversationid`, in place of a link.
- **Reminder presets:** `20m`, `1h`, `3h`, `tomorrow` (9:00), `monday` (next Monday, 9:00), in the
  organisation's zone; `Chat::presets()` gives their labels and times.
- **Written in the chat.** Several lines, Markdown, emoji and mentions, up to 4000 characters, kept
  as written; its first web address is also its link.
- **The chat's list.** The reader's items and the unassigned they wrote; automatic items while
  open, and for ten minutes after they close. Filters `all`, `messages`, `automatic`, `overdue`;
  order `when` (the reminder, else the day, soonest first) or `newest`. Each item carries `from`
  ("Maria in #general", "Direct with Maria"), `when`, `overdue` and `editable` (a hand-written
  item's text can be edited in place; a message's or an automatic one opens what it is about).
- **The mark.** `Chat::messageIds($reader, $ids)` lists the messages with the reader's open
  to-do: the "✓ to-do added" mark.
- **Move to saved.** `Chat::toSaved($id, $reader)` saves the message for later and the item goes.
- **Live.** Every add, change and delete is told as `todo.changed` (`{id, deleted}`) on the
  people's own channels, so a list and the marks are drawn again on every device and tab.

Through the conversations' screen (JSON, behind sign-in, writes with the session's token):

| Action | What |
|---|---|
| `GET Conversations/todolist?filter=&order=` | The list and the presets; settles the reader's to-do notices |
| `POST Conversations/addtodo` | `message` and/or `preset`/`remind_at`; or `text` |
| `POST Conversations/changetodo/{id}` | `status`, `title`, `due`, `preset` or `remind_at` |
| `POST Conversations/deletetodo/{id}` | One's own item |
| `POST Conversations/savetodo/{id}` | Move to saved |

The `messages` answer carries `todos`, the marks of the messages it gives. The dock offers "Add
to-do" and "Remind me about this" on each message, and shows the mark.

## An account that goes

`AccountErasure` calls `Todos::handOver($user)`: the open items assigned to the person go to
`todos.successor` — told, as any item given — or to nobody in particular; their private items
go with them. The account's export carries the items they wrote for themselves, and
`Todos::exportable($tenant, ?$person)` gives an organisation's own items, or a person's, for an
export of the application's.

## Settings

```php
'todos' => [
    'assignable'   => fn (int $tenant, int $user): bool => …,   // who may be given items
    'tenants'      => fn (): array => […],                      // the organisations a sync runs for
    'newest_first' => false,
    'sync_every'   => 5,                                        // minutes
    'url'          => '/admin/Todo',                            // or fn (array $item): string
    'due_notice'   => false,                                    // "due today" at 08:05
    'allowed'      => fn (int $tenant): bool => …,              // an organisation has to-dos at all
    'successor'    => fn (int $tenant, int $user): ?int => …,   // who takes a leaving person's items
],
```

The rest of the design (the conversations, the screens, the tool and the API) is in [To-dos (design)](Todos-Design.md), built in order.
