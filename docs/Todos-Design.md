---
use_cases:
  - Designing the framework's to-do engine shared by every application
  - Deciding what an application's to-do sources must give the engine
  - Checking that a feature of an application's to-do list survives the move to the framework
---

# To-dos (design, FW-363)

Two applications keep a to-do list of their own: the marketplace and the content platform.
Their engines become one engine in the framework. Neither loses a feature. **Sources stay each
application's**: what should be on the list, and why, is the application's knowledge. The
engine keeps the list in step with them. Each application moves its items once, by its own
script.

## 1. The item

`pramnos.todos` (rule 15: the engine's state). It is state, not history: an item is what is to be
done now. Who changed what goes to the change log (rule 14).

| Column | Meaning |
|---|---|
| `todoid` | |
| `tenant` | The organisation, 0 for none. The marketplace has no organisations; the content platform has one per row |
| `kind` | `own` for a person's item; otherwise the source's kind (`followup`, `goal`, `comments`, …) |
| `source` | What a system item stands for (`ticket:123`), unique per tenant; null for a person's own |
| `title` | Text up to 4000 characters: Markdown and `<@id>` mentions kept. A source's title also up to 4000, compared whole, so a long one no longer retitles itself at every pass |
| `link` | `/…` or `http(s)://…`, up to 500 |
| `status` | `open`, `in_progress`, `done`, `dismissed` |
| `assigned_to` | A person, or null for nobody in particular |
| `assigned_by_hand` | Set when a person chose the assignee: the source no longer overrides it |
| `due_at` | A day, kept as its end in the tenant's zone |
| `remind_at` | A reminder to the minute, cleared once sent |
| `due_told_at` | The once-only "due today" notice |
| `private` | Only its author sees and changes it |
| `sort` | Order set by hand within a day group |
| `messageid`, `conversationid` | The message an item was made from or reminds of, in place of a link that has to be parsed back |
| `created_by`, `created_at`, `updated_at`, `closed_at` | Unix times |

## 2. What it does

- **Add**
  - From a line: the first web address becomes the link; the rest is the title, or the address
    is the title when nothing else is left. The chat keeps the address in the text.
  - Who gets it: the author, someone, or nobody. A private item is always its author's.
  - Optionally a day, a reminder and a link.
  - Only someone who may be given items may be assigned, through a hook (`todos.assignable`):
    staff for the marketplace, the organisation's members for the content platform.
- **Change:** the status, the assignee, the day, the reminder and the link. The title only of
  one's own items. Only the author makes an item private, or public again.
- **Done and dismissed** set `closed_at`; opening an item again clears it. "Hide it" (dismissed)
  takes an item off the list without its being done.
- **Delete** only one's own items. A source's item is hidden, never deleted.
- **Order:** open items first, then by hand (`sort`), then by day. Within a day the order is the
  application's (`todos.newest_first`): oldest first by default, newest first for the content
  platform. Closed items show for 7 days.
- **Who sees what:**
  - everyone sees every public item of their tenant;
  - a private item only its author;
  - a role may only read (the content platform's client), through the `todos.can` hook.

## 3. Keeping in step with sources

`Todos::sync(?int $tenant)` asks each source the application declares,
`Todos::source('followup', fn (int $tenant): iterable)`, for what it wants on the list. Each item
it gives is `source => [kind, title, link?, assigned_to?, due_at?]`.
- **A new source key** opens an item, and tells its assignee, unless the source says it told them
  already (`tell: false`). Assigning a comment already sends its own notice, and two would be one
  too many.
- **A done item still wanted** opens again.
- **A dismissed item still wanted** stays dismissed: "not now" holds while its reason does.
- **The title, the day and the assignee** follow the data, unless a person chose the assignee
  (`assigned_by_hand`).
- **An item no longer wanted** is closed as done. A dismissed one is closed quietly.
- **A source that fails** is logged and skipped; the rest still run.
- **Titles** are written in the application's language (`todos.language`), so passes do not
  retitle each other.
- **When it runs:**
  - from the framework's schedule, for every tenant, every `todos.sync_every` minutes: 5 by
    default, 15 for an application whose sources are heavy;
  - when a list is read, at most once per interval (a cache key).
    `Todos::touch(int $tenant)` clears that key: an application calls it after an act that
    changes a source, such as answering a comment or approving a post, so the item goes at once;
  - and on `goal.turned` (FW-360).

## 4. Telling

- **Given to someone:** a bell and a push (one tag per item), "A to-do for you · from X". Never
  for one's own act.
- **A reminder (`remind_at`)**, to the minute: the bell, a push, and a line from "Reminders" in
  the person's conversation with the system, where Conversations is on. Cleared first, so a
  failure is not sent again every minute.
- **Due today, or overdue:** once per item, at 08:05 in the tenant's zone, to items **with an
  assignee** due before the end of the day (an unassigned item is told nothing). This is the
  content platform's daily notice. It is offered to the marketplace by a setting
  (`todos.due_notice`), off there unless switched on. The assignee must still be someone who may
  be given items, and the tenant must still have to-dos at all (`todos.allowed`, a plan without
  them).
- **Settling:** seeing the list settles its notices, and closing or deleting an item settles its
  own.
- **The person whose account goes** hands their open items to a successor (`todos.successor`,
  the content platform's team removal).
- **An export** gives the rows of its scope, and the application decides the scope: a person's
  own items, or every own item of the organisations exported (`Todos::exportable($tenant,
  ?$person)`).

## 5. With the conversations

- "Add to-do" on a message: the whole message as the title, private, the reader's, linked to the
  message (`messageid`).
- "Remind me about this" at a time: a private item, "Message from X: …", reminded then.
  - Presets: +20 min, +1 h, +3 h, tomorrow 9:00, next Monday 9:00.
- A to-do written in the chat ("What do you have to do?"): several lines, Markdown, emoji and
  mentions, up to 4000 characters, with a reminder chosen before it is added (the same presets).
  Enter adds it. A message's to-do keeps the whole message, also up to 4000.
- Editing in place: a hand-written item's text, its reminder and its day. An automatic or
  message's item opens what it is about instead.
- Where it came from: "Maria in #general", "Direct with Maria", from its conversation and the
  message's writer.
- When: the reminder, else the day, as "due in 20 minutes", "tomorrow at 10:00", or "overdue" in
  red once passed; shown over the message too when it is opened from its item. The answer
  carries both `remind_at` and `due_at`.
- Live: an item added or changed on another device or tab is told (`todo.changed`), and the list
  and the message's mark are drawn again.
- The chat's list (`#/todos`), with In progress and Completed, clear and restore:
  - the reader's items and the unassigned they wrote;
  - automatic items while open, and for 10 minutes after they close;
  - filters (all, messages, automatic, overdue) and two orders;
  - a message's "✓ to-do added" mark;
  - "move to saved".

## 6. Surfaces

- **`/admin/Todo` (`.tpl.php`):**
  - mine, everyone and team views;
  - groups: overdue, today, next 7 days, later, no date; plus "waiting in the system" and
    "closed this week";
  - in-place editing, the date and person chips, the ⋯ menu and the keys;
  - the team view with its charts.
- **The header's urgent five:** mine, or overdue and unassigned. A setting counts any overdue
  item, as the content platform does.
- **A dashboard card.**
- **"To-dos about this"** on a record page: open items whose link is that page.
- **A calendar feed**, with dragging an item to another day.
- **A rule action** `todo` (title, assignee, days) in the audience rules.
- **Ask and MCP tool `todos`** (its name per application: the content platform's assistants
  know `glideday-todo`):
  - `list`: who is me, all or team; a person; about a user or page; closed ones;
  - `add` and `update` as writes, keeping the content platform's meaning: `assigned_to` 0 is
    nobody, a `due` of '' clears it, and a plan without to-dos refuses.
- **JSON API:** `GET/POST/PUT/DELETE` under a path the application chooses, with the list, the
  person's name, and the plan's capability (`todos.allowed`).
- **The account's export** carries a person's own items.

## 7. Order

| Step | What |
|---|---|
| 1 | The table, the item's life, sync, sources |
| 2 | Telling: given, reminders, due today, settling, successor |
| 3 | The conversations: from a message, remind me, the chat's list |
| 4 | The screens, the header, the card, "about this", the calendar, the rule action, the tool, the API |

It comes after FW-360, whose `goal.turned` is one of its sources.
