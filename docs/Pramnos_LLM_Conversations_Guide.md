---
use_cases:
  - Giving users conversations with a model that remember them
  - Keeping a table or list an AI answer produced, to open or act on later
  - Laying out the prompt so the provider's cache keeps most of it
  - Letting the AI propose changes that a person approves, and rating its answers
  - Sharing an AI conversation or one of its results with a colleague
  - Turning an attached Word, OpenDocument or email file into text for a model
  - Asking users' agreement before their data goes to a provider
---

# Conversations

Part of the [language-model guide](Pramnos_LLM_Guide.md).

`Pramnos\Llm\Chat` keeps conversations: what was asked, what the model answered, the tools each
answer called and what it cost, what it remembers about each person, questions waiting in the
queue, and questions asked again on a schedule. What a conversation is *about* — its prompt, its
tools, what an answer must have done — is the application's, in an **assistant**:

```php
// app/app.php
'llm' => ['chat' => [
    'assistant' => App\Llm\Analyst::class,   // Pramnos\Llm\Chat\Assistant
    'memory'    => ['max' => 40, 'chars' => 300],
]],
```

```php
use Pramnos\Llm\Chat\Chat;

$chat   = Chat::conversations();               // the configured assistant, as the person signed in
$answer = $chat->ask(null, 'How did last week go?');

if ($answer['ok']) {
    $id = $answer['conversation_id'];          // continue it with $chat->ask($id, '…')
} else {
    echo $answer['says'];                      // why: scope, empty, missing, tags, consent, limit, provider
}

$chat->list();          // this person's conversations, latest first
$chat->find($id);       // one, with its messages, as the assistant presents them
$chat->delete($id);
```

The tables are `chat_conversations`, `chat_messages`, `chat_memory`, `chat_asks` and `ai_consents`,
with the installation's table prefix (`#PREFIX#chat_conversations` in a query of your own).
Scheduled tasks live in `pramnos.ai_routines` instead — the engine's schema, not the
application's; see the Scheduled Tasks guide. A table that already exists under its name is left as it is, except
for what the framework needs to write to it: `tags`, `extra` and `space` are added, and an
`organization_id` that refuses a null is made to take one — a conversation outside any
organisation, the analyst's always, is written with none. The `llm_tables` health check says
when an application's own tables still differ. 

## Whose conversations

A `Chat\Scope` is one person and, for an application that has them, the organisation they act
in. `Scope::current()` reads the request through the router's `context()`; a queued or scheduled
run builds it from its row. Inside an organisation only that organisation's rows are seen, and
outside one only the rows without one — a conversation written outside an organisation never
shows inside one.

## The assistant

Extend `Chat\BaseAssistant`, which answers every hook with a default:

| hook | |
|---|---|
| `connection(Scope)` | the connection answers go out on — the `chat` feature's by default |
| `systemPrompt(Turn)`, `context(Turn)` | the instructions, the same for every question; and what the model is told about the person, with what it remembers added after |
| `volatileContext(Turn)` | what is true only for this question, such as the page the person is on, sent just before it |
| `tools(Turn)` | this answer's tools; the memory's and the schedule's are added by the framework |
| `due(Turn)`, `verify(Turn)` | what an answer must end with, and what it is checked against — see `askWithTools()` |
| `writes()` | the tools that change something: a later answer is told which an earlier one called |
| `question(Turn, $q)` | the question as the model reads it, with what the caller passed in `extra` worked in |
| `answered(Turn, $message)` | after it is stored: attach what the answer produced, and decorate the message |
| `present(Scope, $messages)` | stored messages, decorated for the screen |
| `acting(Scope, $run)` | a queued or scheduled answer, run inside whatever the application's tools need set up |
| `heldRefusal($tag)` | said when a conversation holds data of a tag nowhere available may take |
| `routineRefusal(Scope, $adding)` | why this person may not have scheduled questions, or null — `$adding` true when one more becomes active (created or resumed), where a limit on active ones belongs |
| `timezone(Scope)` | the clock "every morning at 8" is meant on — the server's by default |
| `conversationUrl($id)` | where a conversation is read, for a notification |
| `rounds()`, `turns()` | tool rounds per answer (16), turns sent back (30) |

A `Chat\Turn` carries the scope, the conversation, whether it is a scheduled run, the messages,
the documents attached in the conversation, the caller's `extra`, and `bag` — the application's,
for the length of the turn: what `question()` puts in it reaches `tools()` and `answered()`.

A turn sends back the conversation, never starting on an answer, each earlier answer
followed by a note of the tools that wrote in it; without the note, a later answer sees no result
for a change it made and says it changed nothing. A note the model copies into its reply is taken
out. A conversation whose earlier answers carried data of a tag (`tags`, see
[Data rules](Pramnos_LLM_Guide.md#data-rules)) is moved to a connection that may take it before anything is asked,
or refused with `heldRefusal()`. Only an answered turn is stored — question and answer together.

**A long conversation is summarised, not cut.** Once it passes `turns()` turns and half as many
again, its older part — whole exchanges, never an answer without its question — is folded into a
summary by one short call, kept on the conversation (`summary`, up to `summary_upto`), and sent
with the system prompt; what came after is sent as it was said. So a summary costs one call every
few turns, not every turn. **Or by size:** when the last answer left the window more than
`Conversations::FULL` (60%) full, all but the last exchange are folded, however few the messages,
because a handful of long ones fill it as surely as many short ones. A summary that cannot be written marks nothing: that turn sends the last
`turns()` only, and the next one tries again. `'llm' => ['chat' => ['summarize' => false]]` sends
the last `turns()` and leaves the start out.

**Housekeeping runs on the cheapest model.** The summary and memory learning go through
`Client::askCheaply()`: the cheapest model in the provider's list that has prices
([the call log and costs](Pramnos_LLM_Guide.md#the-call-log-and-costs)), on the same provider and key; where that model fails, the call is asked again
on the connection's own.

An attachment is a document's words: `ask(…, attachment: ['name' => 'release.docx', 'text' => …])`.
`Pramnos\Llm\DocumentText::from($name, $bytes)` reads `.docx`, `.odt`, `.txt`, `.md` and `.eml`
(the email's subject, sender and words, and the documents it carries); `pictures()` takes a
document's photos, and `keepPictures($name, $bytes, $store)` keeps them through
`$store(string $path, string $filename): ?string` — the application's media library — and lists
their addresses after the words.

## The context, in three zones

`Chat\ContextAssembler` lays an answer's context out so a provider's prompt cache keeps as much
of it as it can from one question to the next:

| Zone | Sent as | Holds | Changes |
|---|---|---|---|
| 1, static | the system prompt | `systemPrompt(Turn)` | with the code only |
| 2, semi-static | a system message ahead of the history | `context(Turn)`, what is remembered, what is pinned, the summary | when one of those does |
| 3, dynamic | a system message just before the question | `Now: Friday 30 October 2026, 14:05 (Europe/Athens)`, `volatileContext(Turn)`, how the person has felt lately (with the profile on), the notice for an attached document | every question |

A cache matches a request from its start. With the time and the page last, two questions in a
row share everything up to the one before. With them in the system prompt they would share
nothing, and every question would pay for its whole context again. So an assistant's
`systemPrompt()` holds nothing that changes between questions: no date, no time, no page, no
count. Those belong in `volatileContext()`. The time is added for every assistant, on its
`timezone()`.

```php
final class SupportAssistant extends \Pramnos\Llm\Chat\BaseAssistant
{
    // Zone 1: the same bytes for every question, so the provider's cache keeps it.
    public function systemPrompt(Turn $turn): string
    {
        return 'You help the support team answer customers. Be brief.';
    }

    // Zone 2: changes rarely — kept in the cache until it does.
    public function context(Turn $turn): string
    {
        return 'Open tickets assigned to this person: ' . Ticket::countFor($turn->scope->userId) . '.';
    }

    // Zone 3: changes with every question — last, so everything before it stays cached.
    public function volatileContext(Turn $turn): string
    {
        return 'The person is looking at ticket #' . (int) ($_GET['ticket'] ?? 0) . '.';
    }
}
```

With the [profile](Pramnos_LLM_Person_Guide.md#a-profile-that-changes-only-on-the-persons-word) or the
[calendar](Pramnos_LLM_Person_Guide.md#appointments-and-tasks) on, what the person confirmed and today's appointments join
zone 2.

Two checks watch it, both logged to the `llm` log:

- **The static prompt.** `checkPrefix()` keeps the hash of zone 1 per conversation. When it
  changes between two questions while neither the assistant's class nor the `llm` settings did,
  it logs `Cache invalidation: …` with the first line that differs, before the call is sent.
- **The hit ratio.** `cacheReport()` reads how much of an answer's prompt the provider served
  from its cache (DeepSeek's `prompt_cache_hit_tokens`, or OpenAI's `cached_tokens`). It logs
  when a question that followed others got less than `ContextAssembler::HIT_RATIO` (80%) of
  it. A prompt under `CACHE_FLOOR` tokens is not cached by providers and is not reported.

Changes made during an answer, such as an artifact updated, reach the model as that tool's result
and are in zone 2 from the next question. Moving zone 2 in the middle of an answer would break the
cache.

## Memory

The model is given `remember`, `forget`, `search_conversations` and `read_conversation`, and told
what it remembers in every prompt, each memory dated. At most `memory.max` entries of
`memory.chars` characters. `Chat\Memory` lists and forgets them for a screen.

**A memory is corrected, not doubled.** When what the person says changes something remembered,
the model passes its id as `replaces` and the memory is rewritten — the latest word wins, rather
than two memories disagreeing. In code, `Memory::keep($kind, $content, $replaces, $about,
$source)`; `remember()` is the same for a new one.

**Learning, in batches** — on by default; `'llm' => ['chat' => ['memory' => ['learn' => false]]]`
turns it off. A short call reads what the person said beside what is remembered, and answers a list
of changes: keep what they *stated* about themselves, their role or their work, or how they want
answers; correct what changed; forget what they took back. Not after every answer: once
`learn_every` (5) of the person's messages have gathered in a conversation, or when it has been
quiet for `learn_idle` seconds (900) — `llm:routines`, every minute, looks for those
(`Conversations::learnQuiet()`). The conversation remembers how far it was read (`learned_upto`);
an answer others read, a scheduled run and a conversation opened from a report are marked read, not
learned from, and so is one whose data no connection may take. At most ten changes a pass, within
the limits; what it cannot read changes nothing, and a failure never fails the answer. What it
keeps is marked `learned` and shown so on the memory list, where the person forgets it like any
other. With the [profile](Pramnos_LLM_Person_Guide.md#a-profile-that-changes-only-on-the-persons-word) on, the same call
proposes to it what the person plainly said about who they are and the people in their life: a
proposal, which the next answer asks them to confirm.

**What the person asks to be remembered is remembered at once.** "Remember that I moved to
Athens", "forget that": the model calls `remember` or `forget` in the same answer, with no batch to
wait for and no second call.

**An organisation's memory** — on by default (`'memory' => ['organization' => false]` turns it
off; `organization_max`, 40). Inside an organisation, the prompt also carries what the organisation
keeps about its shared work, read by everybody in that organisation and space and shown apart from
the person's own. **Read by all, written by few:** only an account that `Memory::mayShare()` — a
root account, or one given the `admin.ai.memory` ability — keeps, corrects or forgets it, and only
when it asks to (`remember` with `about: organization`, which is not even offered to anybody else).
Learning never writes it: one person's words, picked up without being asked, would otherwise reach
every prompt in the organisation. Outside an organisation there is nobody to share with, and the
option has no effect. Searching is case-insensitive; on
MySQL it also ignores accents, as its collation does, and on PostgreSQL it does not.

## Artifacts: tables, lists and texts kept from an answer

The model is given `save_artifact`, and uses it when an answer is a table of more than a few
rows, a list of records the person may want to act on, or a document they asked for. It gives
the data itself, not Markdown to be read back out of its answer:

| kind | fields | |
|---|---|---|
| `table` | `columns`, `rows` | up to 50 columns and 1,000 rows; a short row is padded, an object cell kept as JSON |
| `list` | `entity`, `ids`, `labels` | what the ids are (`user`, `device`…), up to 10,000 of them, each once; a label per id or none |
| `text` | `text` | Markdown, up to 50,000 characters |
| `chart` | `chart_type` (bar, line, pie, doughnut), `labels`, `series` (`label`, `data`) | up to 100 labels and 10 series, one number per label; drawn with `Html\Chart` and a table of the numbers |

What does not fit is refused with the reason, so the model can correct itself. An artifact is
kept in `chat_artifacts`, linked to the conversation and the answer it came with, carrying the
data tags the conversation held; the answer comes back with it as `artifacts`, on `ask()` and on
`find()`. It goes with its conversation, and with its owner's account on erasure. A scheduled
run is offered no such tool: it has no conversation to keep one in.

`Chat\Artifacts` is the same in code, within a `Scope` — a person reaches only their own:

```php
$artifacts = new \Pramnos\Llm\Chat\Artifacts($scope);
$made = $artifacts->create(['kind' => 'list', 'title' => 'Dormant buyers', 'entity' => 'user', 'ids' => [4, 9]]);
$artifacts->find($made['artifact_id']);   // with its content, or null when not theirs
$artifacts->list();                        // newest first, without content
$artifacts->delete($made['artifact_id']);
```

The page and the SPA component draw each one under its answer — a table, a list's first twenty
entries and how many more, a text as Markdown — each with `data-pf-artifact` and its id.
`Chat\Web\ArtifactView::html($artifact, $frameClass, $tableClass, $mutedClass)` is that markup,
escaped, for a view of an application's own. Without the table (migrations not run) there is no
tool and nothing to read, and the health check names it.

**Download, print, pin.** Under each artifact: *Download CSV* for a table, a list or a chart's
numbers — UTF-8 with a byte-order mark, so a spreadsheet opens it as it is, and a cell that starts
like a formula written as text, so a value somebody typed cannot run in the program that opens it;
*Print or save as PDF*, the artifact on a page of its own for the browser's print dialog; and *Pin
to dashboard*, which puts it on the person's **Pinned from Ask** card (`ai.pinned`), up to twelve,
as it was kept. `Artifacts::csv()`, `Artifacts::filename()`, `->pin($id, $on)`,
`Artifacts::pinned($user)` are the same in code. A pinned artifact is the result as it stood, not a
query run again: a figure that should stay current is a scheduled report.

**A workspace across conversations.** Besides `save_artifact`, the model is given tools that
work on what this person kept earlier, from any conversation:

| Tool | Does |
|---|---|
| `read_artifact` | reads one by `id`, or by `title`, preferring an exact title to one that only contains the words; at most `Artifacts::READ` characters |
| `update_artifact` | changes one: `mode` `replace` (its kind's fields anew), `append` (table rows, list ids and labels, or text at the end), or `patch` (a new `title`; `find`/`replace` in a text, exactly once; `remove` ids from a list; `remove_rows` from a table, numbered from 1) |
| `list_artifacts` | finds them by `kind`, `entity`, `tag` or words in the title (`query`), newest change first |
| `pin_artifact` | pins one, or takes it off (`pinned: false`) |

A change is checked whole, against the same limits as a new artifact. A list that would name an
id twice, or a table past 1,000 rows, is refused with the reason and left as it was. A chart is
changed only whole (`replace`), the entity of a list stays what it was, and the framework's own
kinds, such as a proposal, are not changed here. `->update($id, $mode, $input)`,
`->search($filters, $limit)` and `Artifacts::asText($artifact, $limit)` are the same in code.

**A pinned artifact is in front of the model in every question.** What this person pinned in
this space goes into the context of each question as it stands at that moment, after what is
remembered about them. Each pinned artifact gets up to `Artifacts::PINNED_EACH` characters and
all of them together up to `PINNED_ALL`, each with its id, so the model can read one whole. A
change made during an answer reaches the model as that tool's result, and is in the context from
the next question on. Changing the context in the middle of an answer would break the cached
prompt, so the next question is when it shows. An open page hears of it at once: a change or a pin
is announced on the person's private channel as `artifact.changed` (`Artifacts::CHANGED`, with
`{id, pinned?}`), so a page showing the artifact can fetch it again.

**Acting on a list.** A list's records can be acted on in bulk — put the people in a user group,
or whatever the application declared for that kind of record — from under the list on the page
and in the SPA component, or `GET /chat/artifacts/{id}/actions` and
`POST /chat/artifacts/{id}/actions/{key}` with `params`. What is offered, and who may run it, is
[Bulk actions](Pramnos_Bulk_Actions_Guide.md).

## Rating answers, and the queue of bad ones

Under every answer, on the page and in the SPA component, the person rates it 👍 or 👎. Pressing the
chosen one again takes it back, and rating again replaces it. The rating comes back on each message
as `rating` (1, -1 or null).

A bad answer carries a note of what was wrong, asked for after 👎 is pressed. On the page the first
press shows the note beside the button, and the next press, or Enter in the note, sends it. The
note is on view from the start when the page runs without JavaScript. The SPA asks for it in a
prompt.

On the page and in the side panel, `pf-chat.js` sends a rating in the background: the buttons
change in place, the conversation keeps its scroll position, and "✓ Thank you — noted." (or the
refusal) shows beside them for a moment. The action `rate/{id}` answers a request with
`Accept: application/json` with `{ok: true, rating, message}`, or `{ok: false, error}` and a 422.
Without the script, or when the answer is not JSON, the form posts and the page comes back with
the message flashed. Each form carries `data-pf-rating-value` (1 or -1), so the script knows what
a button means once its hidden `rating` holds 0, which takes the rating back. An application with
its own copy of `pf-chat.js` refreshes it with `php bin/pramnos project:resync --js`.

A bad answer waits in **Answers to review** (`/admin/Ai/reviews`, linked from the analyst's ⚙
menu) with the question it answered and the note, until a reviewer marks it reviewed — recorded in
the audit log as `ai.answer_reviewed`. A new verdict puts it back. The screen is for whoever reads
everybody's Ask conversations (`admin.ai.all`), since it shows their questions, and opens an
analyst conversation read only. `Chat\Ratings` is the same in code: `rate($scope, $messageId,
$rating, $note)`, `queue()`, `reviewed($messageId, $reviewer)`, `summary($days)`.

## Changes the model proposes, and a person approves

**Off by default.** `'llm' => ['chat' => ['propose_actions' => true]]` turns it on. Then the model
is given two tools, and only when the person has something they may change:

- `list_actions` — what this person may do, by kind of record: the
  [bulk actions](Pramnos_Bulk_Actions_Guide.md) the application declared and they are allowed;
- `propose_action` — one of them, on records by id, with its fields and a one-line reason.

A proposal **changes nothing**. It is kept as an artifact of kind `proposal` under the answer,
showing the action, how many records, its fields and the reason, with *Approve and do it* and
*Reject*. Approving runs it through `BulkActions::run()` as the person approving — their right to
it asked again, the audit log written, a long list queued — once; the artifact then shows what came
of it. Rejecting leaves it marked rejected. Only its owner settles it, and a scheduled run is
never given these tools, since nobody is there to approve.

What may be proposed is exactly what the application declared as a bulk action, so one
declaration serves list screens, Ask lists and the model alike. In code:
`(new Chat\Proposals($scope))->approve($artifactId)` / `->reject($artifactId)`; on a page of the
application's own, the `ActsOnLists` trait has the `approve/{id}` and `reject/{id}` actions and
`Web\BulkView::decision()` the buttons.

## Sharing a conversation or an artifact

A person shares one of their conversations or artifacts with others by username or email —
`Share` on the conversation page, under the open conversation and under each artifact, or the
API. The recipient is told (the bell, and a push where they have one), and finds it under
"Shared with you".

The analyst's conversations and artifacts go only to people at or above the analyst's floor.
Anybody below it is answered as somebody who does not exist ("Nobody here is called …"), so the
share neither tells them a conversation exists nor names its title.

In the analyst's space, the *With* field and a report's readers field suggest people as a name is
typed, asked from the API each time. The page names the address (`data-pf-people-url`, the
`people` action, or `GET /chat/people?q=` for a SPA). `SharePolicy::candidates($scope, $term)`
answers the active accounts at or above the floor whose username, email or name holds the term,
minus the person sharing, ten at most. `pf-utils.js` asks for the name after the last comma once
it is two characters long, after a 300 ms pause. A newer request cancels the one before it, an
answer is kept for its term, and an answer is shown only while the field still says that term.
Typing a whole name is one request. A person's own Ask suggests nobody, because there the
accounts are the site's users and a list of them is not one user's to read. An application whose
Ask is used among colleagues overrides `candidates()`.

**A conversation is shared as it stands.** The recipient sees the messages up to the moment it
was shared, not what is said after; sharing it again with the same person moves that point on.

**The recipient opens it by taking a copy.** The snapshot becomes a conversation of their own —
carrying the original's data tags, so a provider that may not receive that data is still not
asked about it, and each answer's artifacts — which they read on the ordinary screens and can go
on asking in, with their own rights. An artifact arrives the same way, inside a conversation of
its own. Opening it again returns to that copy. Withdrawing a share, or deleting what was
shared, stops it; a copy already taken stays the recipient's.

What a conversation shows was read with the rights of the person who asked, and the person
sharing chooses who sees it. `Chat\SharePolicy`, or the class `chat.share_policy` names, is where
an application narrows that:

| Method | Default |
|---|---|
| `refusal($scope)` | null: anybody signed in may share |
| `recipients($scope, $names)` | `name => userid` for each username or email that is somebody, never the person sharing |
| `link($share)` | `chat/shared/{id}`, or `Ai/shared/{id}` in the administration area for the analyst's |

`chat.share_notification` names a notification class of the application's own, constructed with
the words and the link. `Chat\Shares` is the same in code:

```php
$shares = new \Pramnos\Llm\Chat\Shares();
$shares->share($scope, Shares::CONVERSATION, $conversationId, ['maria', 'nikos@example.com']);
// ['shared' => ['maria'], 'unknown' => ['nikos@example.com']], or why not
$shares->sharesOf($scope, Shares::ARTIFACT, $artifactId);   // who has it
$shares->revoke($scope, $shareId);
$shares->withMe($userId);                                   // what others shared
$conversationId = $shares->open($shareId, $conversations->as($viewerScope));
```

A page of an application's own gets the actions with `Chat\Web\SharesConversations`
(`share/{id}`, `unshare/{share}`, `shared/{share}`) and the markup with `Chat\Web\ShareView`.

## Asking in the background

An answer with tools can take a minute. `Chat\Asks::start($scope, $conversationId, $question,
$attachment, $extra)` writes a waiting row and queues a `chat_ask` task, which the queue worker
answers without being registered — as its author, through `acting()` — and marks answered or
failed. The screen follows it with `find($scope, $askId)`. `Asks::onAnswered(callable)` is told
each answer with the job, `extra` included — where else the answer should go. A listener is
registered in the process that answers, so one a provider registers is missing from a worker that
boots no providers. An ask with `extra['conversations']` needs none: `run()` posts it back to its
conversation itself, once per ask.

While the worker writes the answer, `run()` streams it (`Conversations::streaming()`) and writes
the text so far to `chat_asks.partial`, at most every `Asks::PARTIAL_EVERY` seconds (0.5).
`find()` returns it as `partial`, and both screens show it as it grows: `pf-chat.js` and the SPA
look at the ask every 700 ms while there is text, instead of every few seconds. Once the ask is
answered or fails, the column is emptied, because the answer is kept with the conversation.
Without the column (migration `2026_10_29_000001_add_partial_to_chat_asks` not run), nothing is
streamed, and the answer arrives whole, as before. A summary folded or a memory learned beside the
answer is never streamed.

### Stopping an answer

`Asks::cancel($scope, $askId)` stops a question that is still `waiting`: the row becomes
`cancelled`, and the worker, which looks before every round and before every tool
(`Conversations::stoppable()`, `Client::cancelWhen()`), stops there. A question stopped before the
worker reaches it is never sent to a provider; one stopped mid-answer pays for the call already
under way and no more. Nothing is kept: neither the question nor a part of the answer goes into a
conversation. `cancel()` is false for a question that is not this person's, or no longer waiting.

Both screens draw a Stop button while they follow a question. `pf-chat.js` posts to the page's
`cancel/{id}` (the `asked/` address with its last word changed, so the conversations page and the
analyst's page under `/admin/Ai` need nothing in their templates), with the form's hidden fields
for the session's token. The SPA calls `chatClient().cancelAsk(id)`, and `waitForAnswer()`
returns `{status: 'cancelled'}`. The button's words are the view's `stop` and `stopped`.

A `Client` used directly takes the same hook:

```php
$answer = $client->cancelWhen(fn (): bool => $stopRequested())->askWithTools(...);
// stopped: ['ok' => false, 'cancelled' => true, 'says' => Client::STOPPED, 'usage' => …, 'calls' => …]
```

## Agreement to a provider

`Policy\StoredConsent` keeps who agreed to their data going to which provider, and checks it only
for a provider whose entry says `requires_consent`:

```php
'llm' => ['consent' => \Pramnos\Llm\Policy\StoredConsent::class],

$consent = new \Pramnos\Llm\Policy\StoredConsent();
$consent->give($orgId, $userId, 'deepseek');      // $orgId null in an application without organisations
$consent->withdraw($orgId, $userId, 'deepseek');
```

A call for a person needs that person's agreement; a background call with no person needs
anybody's in the organisation; a call with neither is refused — unless
`'consent_options' => ['allow_without_organization' => true]` says calls outside an organisation
carry nobody's data.
