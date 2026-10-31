---
use_cases:
  - Scheduling a question to a model every morning, as its owner
  - Sending an organisation a scheduled AI report its members subscribe to, by email too
  - Seeing what each scheduled AI task or report costs
  - Handing a leaving member's scheduled AI tasks to someone else
---

# Scheduled tasks: questions, reminders, reports

Part of the [language-model guide](Pramnos_LLM_Guide.md).

`Chat\Routines` keeps every scheduled AI task in `pramnos.ai_routines`. There are three kinds:

- **`ask`**, a question asked again into its owner's conversation;
- **`reminder`**, the owner's own words sent back as a notification with no model asked, so it
  costs nothing;
- **`report`**, asked without a conversation, with read-only tools, kept as a run and sent to its
  readers — see [Shared reports](#shared-reports).

```php
$tasks = new \Pramnos\Llm\Chat\Routines(new \Pramnos\Llm\Chat\Conversations(\Pramnos\Llm\Chat\Chat::assistant()));
$tasks->create($scope, ['title' => 'Morning', 'prompt' => 'What should I know today?', 'cadence' => 'daily', 'at' => '08:00']);
$tasks->create($scope, ['kind' => 'reminder', 'title' => 'Invoice', 'prompt' => 'Send the invoice to Acme',
    'cadence' => 'once', 'in_minutes' => 60, 'conversation_id' => $asked]);
$tasks->create($scope, ['title' => 'Board pack', 'prompt' => '…', 'cadence' => 'monthly_weekday', 'nth' => 2, 'day' => 2, 'at' => '09:00']);
```

**Cadences:**

| Cadence | When |
|---|---|
| `daily` | Every day at `at` |
| `weekly` | Every week, on `day` 1 (Monday) to 7 (Sunday) |
| `monthly` | Every month, on `day` 1 to 28 |
| `monthly_weekday` | The `nth` (1–4, or 5 for the last) `day` of the month: the second Tuesday, the last Friday |
| `once` | At `run_at` (`YYYY-MM-DD HH:MM`), or `in_minutes` from now |

Times are on the task's clock, its `timezone`. A `run_at` is read on the asker's clock, and one
that has passed is refused. A template in `chat.routine_templates` fills in what a request leaves
out. `mine()`, `find()`, `setActive()` and `delete()` act within a person's scope. `runs($id)` is
a task's history: each run's outcome, content, cost and conversation, newest first.

**What the model can do.** In a conversation, never in a scheduled run (a task that schedules
tasks has no end), the model has four tools:

| Tool | What it does |
|---|---|
| `schedule_task` | A question asked again, or a reminder ("remind me in an hour about X": `cadence` `once`, `in_minutes` 60), linked to the conversation it was asked in, a first question's too, once that conversation exists (`Routines::linkMade()`). `kind` is required, and a call without it is refused: a model that left it out once turned a reminder into a question that cost a call |
| `update_scheduled_task` | Change one by id: its words, time or cadence, or pause and resume it |
| `list_scheduled_tasks` | This person's tasks, with when each runs next and any that stopped and why |
| `cancel_scheduled_task` | Delete one by id |
| `list_reports` | The shared reports here this person may receive, and whether they do |
| `subscribe_report`, `unsubscribe_report` | Receive a shared report by id, or stop. `email: true` sends the report itself by email too ("send it to me by email"); asked again, it turns email on or off on the existing subscription |

`schedule_task` also makes a report (`kind` `report`), shared with the organisation or the
installation (`owner`) when the person asks and the policy lets them, and told only when there is
something new (`notify` `relevant`).

**A duplicate is refused by the tool itself.** A task that already does the same thing (same
kind, same words or title, same time) comes back as an error that names its id and points to
`update_scheduled_task`. A description asking the model not to duplicate is one it can ignore.
`Routines::update($scope, $id, $changes)` is the same change in code, and `PUT …/routines/{id}`
in the chat API takes the same fields.

**How runs happen.** `llm:routines` runs every minute. A task runs with its own space's
assistant: the analyst's (space `admin`) with the analyst, not with the chat assistant the tick
was built with.

1. It **claims** what is due. One statement moves the next run on and holds the task, only if
   the task is still due as it was read, so a second tick or a second host claims nothing and no
   task runs twice.
2. It runs what it claimed there and then. With `'llm' => ['chat' => ['task_queue' => true]]` it
   queues each one instead, and a worker runs it, so a report that reads for a minute holds up no
   other.

**"Run now"** — the scheduled page's button, or `POST /chat/routines/{id}/run` — goes to the
worker, as a question in Ask does, whatever `task_queue` says (`Routines::runNow()`). The API
answers 202. The task is held as a claimed run is (`locked_until`), and says `running` until that
run has finished: the page shows "Running…" and offers no second run. The server-rendered page
reloads every 15 seconds while one is running, and the SPA reads the list again. A second press
meanwhile is refused ("It is already running."), and so is a run the policy would refuse, at once
rather than from the worker. Without a queue worker, `'llm' => ['chat' => ['answer_in_request' =>
true]]` runs it in the request. A queue that will not take it also gets the run in the request,
rather than none.

After each run:

- The run is recorded in `pramnos.ai_task_runs` and the owner is told.
- A question tells the owner with `Chat\RoutineReady`, or the class `chat.routine_notification`
  names, constructed with the title, whether it worked, why not, and the link.
- A report's reader whose subscription asks for email (`subscribe($userId, $id, email: true)`,
  "Receive it by email too" on the scheduled page, `email` on the chat API's subscribe, or asking
  for it in Ask) gets the report itself by email:
  `RoutineReady::toMail()`, with its title, its words drawn from Markdown, and the link to ask
  about it. The notification class is given the report's words and that choice as its fifth and
  sixth arguments, and an application's own class written for four may ignore them. A failed run
  sends no email.
- Every model call a scheduled run makes is marked with its task, in `llm_calls.routine_id`. The
  analyst's spend shows the tasks' runs apart from conversations. The purpose stays the space's,
  because it is what routes the call.
- A reminder tells the owner with `Chat\TaskReminder`, or `chat.reminder_notification`, given the
  words and the link.
- Both go to the bell, and as a push where the owner has a browser subscribed. `'llm' => ['chat'
  => ['notify_via' => ['database']]]` keeps them to the bell; the list names the channels allowed
  (`database`, `push`).
- A one-off is done once it ran.
- A notification's link that was built with no site address, as in a cron run with no `APP_URL`,
  starts from the site's root rather than from the page the notification is opened on.
- Deleting a task removes its readers and the record of its runs.
- Times read back from the database are read as UTC, which is how they are written. MySQL answers
  them without an offset, so `next_run_local` showed a time shifted by PHP's zone.
  `Routines::utc()` does the reading.
- Three failed runs in a row pause a task, and the owner is told why. Resuming it starts the
  count again.

The question a run asks carries the day on the owner's clock.

**Who may read a shared report.** A report carries what was read with its maker's rights, so in
the administrators' space it is never sent to — nor readable by — somebody below the analyst's floor
(`'llm' => ['analyst' => ['floor' => 98]]`, else the administration's default usertype), whatever
its audience names, and the form offers only roles at or above it. `TaskPolicy::readerFloor($task)`
is where an application gated otherwise answers; Ask AI's own reports have no floor.

`'llm' => ['analyst' => ['reports_below_floor' => true]]` lets an application send analyst reports
lower: the form then offers every role, and a reader below the floor — somebody who cannot open
Ask — reads each run **read only**: its link opens the report as it was written, with nothing to
ask and nothing copied into an Ask they cannot use. A conversation or artifact shared with such a
person reads the same way (`Shares::snapshot()`), instead of becoming a conversation of their own.

**A report's email** carries the day it ran in its subject (`Weekly figures — 2026-10-28`), on
the task's clock, so a mailbox of daily reports with one title tells them apart:
`'llm' => ['chat' => ['report_date_format' => 'd/m/Y']]` sets the format, `''` leaves it out.
The day reaches the notification class as its seventh argument; a class of the application's own
written for six ignores it. A report can be emailed from the start: *Email the report to me too*
on the form (ticked), or `email: true` to `schedule_task`.

A chart in a report reaches the email as a small table of its labels and numbers
(`Markdown::forEmail()`): a mail client runs no script to draw it.

Each report's email has its own unsubscribe list, `askreport-{id}`: the link in it stops that
report's email and no other, and is the same switch as *Stop the email* on the page — turning the
email back on from the page, the form or Ask works at once.

**One's own report by email.** The Scheduled page offers *Email it to me* / *Stop the email* on each
of the person's own reports, as it offers *Receive it by email too* on a shared one (`mine()`
carries `email` per task). Where an application shows no notifications — no bell — a run's
database notification is never seen, and email is the only way its owner hears of it.

The schedule form shows only what applies (`data-pf-show-when`; everything without JavaScript):
the time fields for the cadence chosen, *For*, *Tell readers* and the email box for a report, the
readers for a report shared with an organisation or everyone.

**What the tasks cost** is read from `pramnos.ai_task_runs.cost`, the price of each run's model calls
recorded once however many people receive the report (`Chat\TaskCosts`). It shows in two
places with nothing to configure:

- on `/admin/Ai`, a card listing each task's runs and cost over the last 30 days and their
  share of everything the call log priced, once any scheduled task has run;
- on the open task's card of the Scheduled page, its runs, total and each run's cost. A
  controller's `scheduledExtras()` that returns `task` markup replaces it.

`TaskCosts::byTask($since)` gives the same figures to an application's own screen. A run whose
calls were not priced is counted apart, not as zero; a reminder asks no model and costs nothing.

**`Chat\TaskPolicy`**, or the class `chat.task_policy` names, holds the rules around tasks. Every
method has a default, so an application overrides only what it has:

| Method | Default |
|---|---|
| `refusal($scope, $kind, $adding)` | The assistant's `routineRefusal()`. A reminder is asked as a run, never as adding, so it does not count against a limit on active tasks. An application that offers reminders without scheduled questions returns null for them |
| `scope($task)` | The maker, in the organisation and space it was made in |
| `timezone($task)` | The zone it was made in; a task about a site returns that site's |
| `stillAllowed($task)` | `true`. An application with organisations checks the maker is still a member: false pauses the task and tells its maker |
| `canManage($scope, $owner)` | `user` always; `organization` inside an organisation at usertype 80 or more; `global` at 90 or more |
| `canSee($userId, $task)` | Whether a person may read a shared report and subscribe: its maker; for an organisation's, a `member()` of it; then the readers its maker chose (`audience`), or — when none were chosen — a usertype no lower than the maker's, because a report runs with the maker's rights |
| `audienceUsers($names)`, `audienceNames($ids)` | The named readers: found by username or email (`name => userid`), and shown back by username |
| `member($userId, $orgId)` | `true`. An application with organisations answers here |
| `audience($task)` | The maker, and each subscriber `canSee()` still allows, checked at every run |
| `resourceLabel($task)` | `''`. What a task is about as people read it ("GeekDom", not "property 42"), for the notification's title and the report's question |
| `resourceExists($task)` | `true`. False pauses a report and tells its maker, without counting a failure |
| `link($task, $run)` | A report's run: `chat/report/{run}`. Otherwise the conversation |
| `onRan($task, $run)` | Nothing. For a message in another chat, or for charging; what it throws is logged |
| `maxFailures()` | 3 |

### Shared reports

A report is a task of `kind` `report`. Its `owner` says who may read it:

| `owner` | Readers | Who may make one (default) |
|---|---|---|
| `user` | Its maker | Anybody |
| `organization` | Members of its organisation, by subscription | usertype 80 or more, inside an organisation |
| `global` | The whole installation, by subscription | usertype 90 or more |

```php
$tasks->create($scope, ['kind' => 'report', 'owner' => 'organization', 'notify' => 'relevant',
    'title' => 'Weekly bookings', 'prompt' => 'Bookings this week against last week, by property',
    'cadence' => 'weekly', 'day' => 1, 'at' => '08:00', 'resource_type' => 'property', 'resource_id' => 42]);
$tasks->subscribe($userId, $reportId);           // null, or why not
$tasks->reports($scope);                          // the shared reports here, with `subscribed` and `latest_run`
```

**How a report runs.** `Conversations::report()` asks once, as its maker, and keeps nothing as a
conversation:

- The assistant's write tools (`Assistant::writes()`), the memory and the scheduling tools are
  left out. What the maker told the assistant about themselves is not in the prompt either, since
  others read the result.
- The run is kept in `pramnos.ai_task_runs`: content, cost, the model's steps, and who was told.
- The run is charged once, however many people read it.
- A failure tells the maker only. Readers get reports, not errors.

**Told only when it matters.** With `notify` `relevant`, the model decides through a
`report_relevance` tool. It is forced on the round before the last, because a decision asked for
only in words is one a model forgets. A run that decides there is nothing new tells nobody and is
still kept. A run with no decision tells everyone, so a report is never silent by omission.

**Who reads it.** Readers are re-checked at every run through `audience()`, so a member who lost
the right stops receiving it. The default `canSee()` is strict: a reader of lower standing than
the maker would see what the maker's rights read. An application with roles says here who may
read, such as the readers of the property the report is about.

**The maker chooses.** A shared report can name its readers, kept in `pramnos.ai_routines.audience`:
everyone from a role up, people by name, or both. They are still only inside the organisation for
an organisation's report. The choice opens the report to people below the maker's standing, on
purpose: they read what the maker's rights read.

```php
$tasks->create($scope, ['kind' => 'report', 'owner' => 'global', … ,
    'audience_role'  => 'admin',                 // a role, as app.php's `roles` sets it
    'audience_users' => 'maria, nikos@example.com', // usernames or emails
]);
$tasks->create($scope, [… , 'audience' => ['min_usertype' => 90, 'users' => [7, 12]]]);
$tasks->update($scope, $id, ['audience_role' => '', 'audience_users' => '']);   // back to the default
```

The scheduled page offers both fields beside "For" when a report is shared, and the open report's
card lets its maker change them (`scheduledupdate` with `audience`). One person's task has no
readers to choose.

**Asking about a report.** A report's notification links to `chat/report/{run}`
(`ChatPageController::report()`, or `POST …/runs/{id}/continue`). That opens the run as a
conversation of the reader's own, holding the report, through `Routines::continueRun($viewer,
$runId)`. What they ask next runs with their own rights, not the maker's. Opening the same run
again continues the same conversation while it exists (kept per reader in the run's
`extra.opened`).

**Personal data.** `Routines::forUser($userId)` answers what is kept about a person: the tasks
they made, everywhere, and the reports they read.

**When a member leaves.** `ownedBy($orgId, $userId)` lists what a person has in an organisation:
their tasks, and the shared tasks they read. `transfer($orgId, $from, $to, $only = null)` hands
those tasks to another member:

- Each task keeps its schedule and history, but not its conversation.
- Each is checked again as the new owner's, and one they may not run stays paused, saying why.
- The leaver's subscriptions are removed.
- The new owner is told.

```php
// before removing a member, once somebody chose who takes over
$tasks->transfer($orgId, $leaving, $successor);
```

**Asking who takes over.** `handoverChoices($userId)` answers what a leaving person is asked,
organisation by organisation: their `shared` tasks (reports their organisation or the installation
receives), their `personal` ones, and the `successors` who may take them,
`TaskPolicy::successors($orgId, $leaving)`. By default that is the organisation's other active
members, and nobody outside an organisation. `handover($userId, [$orgId => ['to' => $id,
'personal' => true]])` performs the choice: shared tasks go to the successor, the person's own
only with `personal`, and a successor the policy does not offer moves nothing.

The account's delete page asks this and hands over before the erase (see the Authentication
Guide). An application whose member removal is its own flow does the same with two calls:

```php
// app/Controllers/OrganizationMembers.php — removing a member who was asked who takes over
$tasks   = new \Pramnos\Llm\Chat\Routines(new \Pramnos\Llm\Chat\Conversations(\Pramnos\Llm\Chat\Chat::assistant()));
$offered = $tasks->handoverChoices($leaving);        // draw a choice of successor per organisation
$tasks->handover($leaving, [$orgId => ['to' => (int) $_POST['successor'], 'personal' => !empty($_POST['personal'])]]);
// then remove the membership; what was not handed over stays the leaver's, paused by
// TaskPolicy::stillAllowed() at its next run if the application checks membership there
```
