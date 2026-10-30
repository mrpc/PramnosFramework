---
use_cases:
  - Giving an assistant a profile of the person, their calendar and a journal, each turned on by itself
  - Letting the assistant book appointments, find free slots and remind before them
  - Keeping an assistant from messaging people at night, too often or while they are busy
  - Keeping what a person confided with a provider you trust with it
---

# The person's own: a profile, a calendar, a journal

Part of the [language-model guide](Pramnos_LLM_Guide.md).

Three capabilities for an assistant that serves one person over time — a CRM's account manager
keeping track of their clients and their day, a clinic's staff assistant, a coaching, HR or
customer-success application. Each is turned on by itself, in a person's own conversations only:
never in the administrators' analyst, never in an answer others read too.

```php
// app/app.php
'llm' => [
    'chat' => [
        'profile'  => true,   // who the person is and the people in their life, on their word
        'calendar' => true,   // their appointments and tasks, today in every prompt
        'journal'  => true,   // a page a day, in their words
    ],
    // When the assistant may reach out on its own: quiet hours, a few pings a day, not while busy.
    'proactivity' => ['quiet' => '22:30-07:30', 'daily' => 3],
],
```

| Capability | Turned on by | What it improves |
|---|---|---|
| [Profile](#a-profile-that-changes-only-on-the-persons-word) | `chat.profile` | Answers that know who the person is and who "Eleni" is, without the model ever deciding that on its own |
| [Calendar](#appointments-and-tasks) | `chat.calendar` | "Book Giorgos at 16:00" that checks for clashes and offers free times; "what do I have this afternoon" with no tool call; reminders before appointments |
| [Journal](#a-journal-a-day) | `chat.journal` | A record of the person's days, kept as they talk |
| [Manners](#reaching-out-on-its-own-with-manners) | `proactivity` | Scheduled questions and reminders that never arrive at night, too often, or in a meeting |

Their tools are in the `profile`, `calendar` and `journal` [skills](Pramnos_LLM_Tools_Guide.md#skills-in-a-conversation), so
with skills on they cost nothing until the model needs them.

### A profile that changes only on the person's word

`Chat\Profile`, in `chat_dossier`, holds what should not change on the model's word alone:

| Area | Changes | |
|---|---|---|
| `identity`, `values`, `goals` | on the person's confirmation | who they are, what they hold to, what they work towards |
| `relationships` | on the person's confirmation | one entry per person in their life, `subject` naming them |
| `mood` | at once, only with consent | how they have felt lately |

Habits and preferences are not here. They are [memory](Pramnos_LLM_Conversations_Guide.md#memory), learned and corrected as the
person talks. The profile changes by proposal:

- the model calls `note_profile`, or [learning](Pramnos_LLM_Conversations_Guide.md#memory) proposes from what the person plainly
  said. The entry holds the proposal as `pending`, and the prompt lists it apart, as not true
  until confirmed;
- the person says yes in a later message and the model calls `confirm_profile`, or no and it
  calls `reject_profile`. A proposal made in an answer cannot be confirmed in the same answer,
  so a wrong guess about who somebody is never becomes a fact in every prompt after it;
- every confirmed change raises the entry's `version`. Its before and after go to the changelog,
  and `Profile::history($id)` reads the versions from there, oldest first;
- `mood` is kept only once the person agreed: their setting `ai_profile_feelings`
  (`Profile::FEELINGS`). Without it the model is told to ask, and nothing is kept;
- `recall_person` finds a person in their life by part of a name, or lists everybody.

**Or kept at once.** Where waiting for a yes is more than the application needs, the profile keeps
what the model notes and what learning finds, as it does with mood:

```php
'llm' => ['chat' => ['profile' => ['confirm' => false]]],
```

Every change is still a version in the log, the person still corrects or forgets an entry with a
word, and `confirm_profile` and `reject_profile` are not offered, two tools fewer in every request.

What is confirmed is in the [second zone](Pramnos_LLM_Conversations_Guide.md#the-context-in-three-zones) of every prompt, up to
`Profile::CONTEXT` (2,000) characters: who they are, what they value and work towards first, the
people in their life after, and a line saying how many more are kept, which `recall_person` finds.
How they feel is not there: it changes with a word, and in the cached zone every change would
make the provider read the whole conversation again, so it goes in the third. A person keeps at
most `Profile::MAX` (60) entries of `Profile::CHARS` (500) characters. The tools are
`writes`, so after reading somebody else's words they wait for the person, and their results are
tagged `personal` for a [data rule](Pramnos_LLM_Guide.md#data-rules).

A screen of the application's own, where the person sees and changes their profile:

```php
use Pramnos\Llm\Chat\{Profile, Scope};

$profile = new Profile(new Scope($user->userid, $organizationId));
$profile->entries();                          // each: dossier_id, area, subject, content, pending, version
$profile->confirm($id);                       // the person's yes, from a button
$profile->reject($id);
$profile->forget($id);
$profile->history($id);                       // [{version, from, to, at}, …]

// Erasing: the rows and their versions in pramnos.changelog_events, in one call.
Profile::erase('organization_id', $organizationId);
```

`Profile::erase()` exists because the versions live in `pramnos.changelog_events`: a delete
written by hand against `changelog_events`, without its schema, matches nothing and says nothing.
Account erasure already calls it for the person.

### Appointments and tasks

`Chat\Calendar`, in `chat_calendar`, keeps the person's own appointments and tasks. Times go in
and come out on their clock (`Y-m-d H:i`, in the assistant's `timezone()`) and are stored as
moments.

| Tool | |
|---|---|
| `calendar_create_event` | an appointment; an hour long when no end is given |
| `calendar_list_events` | from one day to another, inclusive (`parallel`) |
| `calendar_update_event` | move, rename or change notes; a move without an end keeps the length |
| `calendar_delete_event` | cancel one |
| `task_create`, `task_list`, `task_update` | a task, due by a time or not; listed soonest due first; marked done |
| `set_busy` | the person is busy until a time, or no longer: no ping meanwhile |

- **Today is in the prompt.** The day's appointments and the open tasks due by its end are in
  the second zone of every prompt (`Calendar::context()`). "What do I have this afternoon" needs
  no tool call, and the text changes only when the calendar does, so it stays cached.
- **An overlap is an answer, not a failure.** An appointment that would overlap another is not
  made. The tool answers what is there and the nearest free starts before and after, the same
  length, between `Calendar::DAY_STARTS` and `DAY_ENDS` (08:00–20:00), so the model offers those:

  ```json
  {"error": "conflict", "existing": "Accountant", "existing_at": "2026-10-20 15:30", "free_slots": ["14:30", "17:00"]}
  ```

- **Reminders.** `llm:routines` sends one `Calendar::REMIND` (30) minutes before each
  appointment, once, as a notification. With [manners](#reaching-out-on-its-own-with-manners)
  set, they decide whether it goes.
- **On the page.** `feed($start, $end)` is the JSON a [`pf-calendar`](Pramnos_Calendar_Guide.md)
  takes as `data-events`, and `move($body)` is its `data-update`, checked for overlaps the same
  way. Every change is announced on the person's private channel as `calendar.changed`, and a
  `pf-calendar` with `data-channel` refetches: an appointment the assistant makes appears on an
  open page at once.

```php
// Two routes for the page's calendar.
$calendar = new \Pramnos\Llm\Chat\Calendar($scope, $timezone);
return $calendar->feed($_GET['start'], $_GET['end']);   // data-events
return $calendar->move($request->json());               // data-update
```

`Calendar::TEMPLATES` offers a ready-made routine, `daily_plan` (08:00): the day's appointments
and open tasks, kept as an artifact, pinned, and a short good morning.

### A journal a day

`Chat\Journal`: `journal` writes an entry in today's page, a text
[artifact](Pramnos_LLM_Conversations_Guide.md#artifacts-tables-lists-and-texts-kept-from-an-answer) titled `Journal Y-m-d` on the
person's clock. The day's first entry makes the page, the next ones are added to it, each with its
time. It is pinned, shared and exported like any artifact, and tagged `personal`.
`Journal::TEMPLATES` offers `evening_review` (21:30): what got done and how the day went, written
down, and the morning's plan unpinned.

Both templates are offered beside the application's own `routine_templates`, which win by key.

### Reaching out on its own, with manners

An assistant reaches out in two ways: a [scheduled question](Pramnos_LLM_Scheduled_Tasks_Guide.md#scheduled-tasks-questions-reminders-reports)
it asks on its own, and a calendar reminder. With `'llm' => ['proactivity' => …]` set
(`Llm::proactive()`), `Llm::proactivity()` decides before either: now, later, or not this time.
Nothing is spent on a ping that is held: a held question asks no model. Without it, everything
goes at its time, as it always did.

The default, `Policy\QuietProactivity`:

| Rule | Then |
|---|---|
| inside `quiet` (`22:30-07:30`), on the person's clock (`''` for none) | held until quiet hours end |
| `daily` (3) pings already today: questions it asked, reminders sent | not this time |
| in an appointment now, or busy by their word (`set_busy`, the setting `ai_busy_until`) | not this time |

- **A held question.** One held for quiet hours is asked when they end. One held for the limit
  or for being busy waits for its next turn, and a one-off is tried again in an hour.
- **A held reminder** is passed: marked so it is not looked at again, and not counted.
- **What it covers.** Scheduled questions in a person's own conversations, and calendar
  reminders. A report others subscribed to and a run somebody asked for with "Run now" go as they
  always did.
- **Your own rules.** `'llm' => ['proactivity' => MyPolicy::class]` takes a
  `Policy\Proactivity`. Its `when()` returns `$now`, a later moment, or 0, and with `$critical`
  (an alarm the person set) it should return `$now`. A policy that throws lets the question go:
  the person's own schedule is never silenced by an error.

```php
final class WorkingHoursOnly implements \Pramnos\Llm\Policy\Proactivity
{
    public function when(int $userId, string $timezone, int $now, bool $critical = false): int
    {
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($timezone));

        return $critical || ((int) $local->format('N') <= 5 && (int) $local->format('G') >= 9 && (int) $local->format('G') < 18)
            ? $now
            : 0;
    }
}
```

### Sensitive conversations: the stronger model, and data kept close

Nothing here is new machinery; it is the rest of the framework, set for a person who confides:

```php
'llm' => [
    // A delicate personal or emotional conversation goes to the frontier tier; when that provider
    // is down, the fast one answers in its place, warmly first.
    'tiers' => ['fast' => 'chat_fast', 'frontier' => 'chat_frontier'],
    'cascade' => \Pramnos\Llm\Policy\ModelCascade::class,
    // The profile's and the journal's results only to a provider you trust with them.
    'rules' => [App\Llm\PersonalStaysLocal::class],   // a DataRule for the tag 'personal'
],
```

- The [tiers](Pramnos_LLM_Guide.md#a-model-for-each-question-tiers) send a delicate personal or emotional
  conversation to the frontier model. If that provider is down, the fast one answers in its
  place, told to answer warmly first when the person is upset or confiding.
- The profile's tools and `journal` tag their results `personal`. A [data rule](Pramnos_LLM_Guide.md#data-rules) for
  that tag keeps them with the provider it names, a local model for instance.
- How they feel is kept only with their consent (`ai_profile_feelings`).
