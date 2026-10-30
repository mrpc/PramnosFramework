---
use_cases:
  - Letting administrators ask questions about the installation's own figures
  - Letting the AI explain, in plain words, a change in the code behind "it worked yesterday"
  - Giving the analyst its own SQL, files to download and the application's figures
  - Keeping personal data out of what the analyst reads
---

# The administrators' analyst

Part of the [language-model guide](Pramnos_LLM_Guide.md).

**Where its screen is.** Its links — a report's notification, a conversation, a share — lead into
the administration area where the application has one, and otherwise to `admin/` under the
site's address. An application whose whole site is the administration (`/Ai`, `/Users`) says so
with `'llm' => ['analyst' => ['area' => '']]`, or names another place with `'area' => 'backoffice'`;
every link follows (`AnalystAssistant::areaUrl()`).


`/admin/Ai` — *Ask*, the AI item in the admin menu — answers questions about the installation
itself — how many signed up last month, which emails were opened, what failed in the queue, what
the models cost and for whom — from the installation's own tables. The settings are a link away,
at `/admin/Ai/settings`; an application whose settings are reached from its own menu hides the link
under Ask's heading by overriding `settingsLink()` to return `''`. Ask still opens the settings
while no key is set. Both sit behind one gate: the ability `admin.ai` and the usertype in the
application's `Admin\Controllers\Ai::$requiredUserType`. The page is the conversation page at
the analyst's own addresses, with everything `pf-chat.js` does there; its waiting lines are
`'llm' => ['analyst' => ['waiting_lines' => […]]]`.

**Nothing invites a question that can only fail.** Until a provider's key is set:
- *Ask AI* is left out of the user menu, and the widget draws nothing (`Chat::available()`, with
  the asking person's own routing);
- the admin header has no Ask, and the side panel no Ask tab (`Analyst::available()`);
- the admin menu has no *AI* item, and `/admin/Ai` opens the settings. The settings stay one
  click away from Settings (*AI: providers & keys*), where the key is set.

A menu item of an application's own does the same with `when:`, a closure asked each time the
menu is drawn:

```php
new NavItem('user.writer', 'Writer', $base . 'writer', NavSection::User, when: static fn (): bool => Chat::available());
```

An *Ask* link for the admin header, beside the search, drawn only for somebody who may open the
page:

```php
<?php echo \Pramnos\Llm\Analyst\Analyst::headerButton(); ?>
```

A new project's layouts have it; a project's own header takes that line.

## As JSON

The same analyst answers in the conversation API's shape under `/admin/Ai/api/` — `chat`,
`chat/{id}`, `asks/{id}`, `asks/{id}/cancel`, `memory`, `memory/{id}`, `consent` — for a screen of the application's
own, such as the SPA's `components/llm/Chat.svelte`:

```js
const client = chatClient(adminApi, {
    chat: '/admin/Ai/api/chat', asks: '/admin/Ai/api/asks',
    memory: '/admin/Ai/api/memory', consent: '/admin/Ai/api/consent',
});
```

It is the admin area, so it goes with the session's cookie; anything but GET needs the session's
token in the `X-CSRF-Token` header. A JSON body and a form are both read.

## What changed in the code

`recent_changes` reads what changed over a window — the application's git history (each commit's
date, message and the files it touched; a word narrows it) and the framework's own changelog days
from `vendor/` — so "this worked yesterday and not today" can be checked against a change rather
than guessed at. The model is told to answer in plain words: what changed for the person and when,
and whether it is likely the reason — never commit hashes, file names or code. A server deployed
without `.git` has no history to read, and the tool says so. `Analyst\Tools\ChangesTool` is the
class, for an application that wants it in an assistant of its own.

## Kept apart

An administrator's analyst conversations, what it remembers and its queued questions are in the
`admin` **space** (`Chat\Scope`'s third argument): never in their Ask AI list, nor the other way
round. They are outside any organisation, because the analyst reads the whole installation — the
call goes to the router with `organization_id` **null**, purpose `analyst`. The connection is the
`analyst` feature's when the application declares one (`'llm' => ['features' => ['analyst' =>
…]]`), otherwise the installation's. Agreement, when the provider asks for it, is the
administrator's own (a consent row with no organisation), asked on the page. The analyst runs no
scheduled reports.

## Other people's conversations, read only

`/admin/Ai/all` lists the other administrators' Ask conversations — owner, title, last change, how
many messages and what they cost, filtered by owner or a word in the title — for whoever has
`admin.ai.all`: root alone in usertype mode, as `admin.chat.all` is (`Analyst\Review::readsAll()`;
`AiController::readsAll()` is the seam to override). The Ask page links to it from the ⚙ under
its title, beside the settings — a reader with neither link sees no gear. One opens
as its owner sees it, under a banner saying whose it is: the questions, the answers, the tools each
answer used with their input, and the model calls made while it was answered — the call log's
rows, `/admin/Ai/call/{id}`, where what each tool returned is in the messages sent. The calls are
matched by time: those of the owner's analyst between one question and the next. The steps and
tools are folded, as on Ask's page, and `pf-chat.js` draws the answers' charts.

Nothing there writes: no question box, no delete, no memory, no agreement. Each view of somebody
else's conversation is recorded in the audit log as `ai.conversation_viewed`, the owner as its
target and the conversation as its object. Anybody else gets a 404.

The reader's own conversations are left out of the list and the owner filter
(`Review::list(['except' => $id])`, `Review::owners($id)`): they are in the Ask column already, where
they are not read only, and `/admin/Ai/all?c=` with one of them redirects there.

## Its name and picture

The analyst is called *Ask* unless the application gives it a name of its own:

```php
'llm' => ['analyst' => [
    'name'     => 'Naya',                     // the header's button, the side panel, the Ask page, the conversations
    'mentions' => ['naya'],                   // words that call it after @ in a conversation, beside @Ask
    'avatar'   => '/themes/main/img/ai.svg',  // its picture beside the name
]],
```

`Analyst::name()`, `mentions()` and `avatar()` read them, and every place the administration shows
the analyst asks them: the header's button and the side panel (its tab, title, and what a screen
reader hears — *Ask Naya about this page*), the Ask page's title, the conversation with it and its
*… is thinking*, the composer's `@` suggestion, and the *Open in …* card on an answer. In a
conversation `@Ask`, `@Naya` and each mention call it, as a word of its own and ignoring case, and
the mention is drawn as `@Naya`. Its conversation is kept as the bot `Ask` (`Ask::NAME`) whatever it
is called; the JSON gives it a `displayName` and an `avatar`. The model is told its name, so it
answers to it.

## Its living picture

The analyst's picture moves with what it does. The contract is one element and one attribute:

```html
<span class="pf-assistant-avatar pf-assistant--thinking" data-state="thinking" data-durations='{"success":1800,…}'>…the set's markup…</span>
```

The framework changes only `data-state` (and the `pf-assistant--{state}` class), to one of nine:
`idle`, `listening`, `thinking`, `talking`, `success`, `error`, `offline`, `greeting`, `notify`.
Every movement is the set's CSS reacting to `[data-state="…"]`. `success`, `greeting` and
`notify` go back to `idle` after their duration.

| where | when |
|---|---|
| the side panel and the Ask page | `greeting` the first time the panel opens in a session; `listening` while the reader writes; `thinking` from the question to the answer; `talking` as the answer arrives, then `success`; `error` when it fails; `offline` where it cannot answer |
| the header's button | the same, and `notify` when a scheduled task's result arrives |
| the conversations | its picture on its conversation and its answers; `thinking` at a question in its conversation, `talking` as an answer arrives |

`Assist\AvatarSets::render($state, $size)` draws it. Without a set it is the framework's own,
`pf` — a drop with eyes in the theme's accent, its CSS in every theme's `components.css`, still
under `prefers-reduced-motion`. A set of the application's own is its markup and its CSS:

```php
'llm' => ['analyst' => ['avatar_set' => [
    'markup' => APP_PATH . '/themes/admin/assets/naya/naya.svg',   // a file, the markup, or a callable
    'css'    => '/themes/admin/assets/naya/naya.css',              // linked once a page
    'states' => ['success' => 1800, 'greeting' => 2400],           // ms, for those that end by themselves
]]],

\Pramnos\Assist\AvatarSets::register('naya', $markup, '/assets/naya.css', ['greeting' => 2400]);
'llm' => ['analyst' => ['avatar_set' => 'naya']],
```

A plain picture (`avatar`) with no set is drawn in the same element and does not move.

Two properties belong to the contract, and a set respects them: `--pf-assistant-size` (24px) and
`--pf-assistant-color` (the theme's accent). Both are inherited — the element declares neither —
so a parent sizes or colours the picture: `.pf-ask { --pf-assistant-size: 20px; --pf-assistant-color: #fff; }`.
A set's stylesheet is outside any `@layer`, so it outranks layered utility classes; colouring
through the property avoids a fight.

The element carries its width and height inline as `var(--pf-assistant-size, 24px)`, with the size
asked for as the fallback. A page whose theme does not load `components.css` still draws the
picture at its size and not the width of the page, while a parent can still size it through the
property. The framework's own drop also carries its colours as SVG attributes: the text's colour
with white eyes. The stylesheet overrides both.

The set's stylesheet reaches a page once. `render()` puts its `<link>` before the first picture the
page draws. Where the picture is drawn inside a button or a link, `AvatarSets::stylesheet()` gives
the `<link>` to put before it; the side panel does so. A picture that is data rather than the
page — in JSON, a `<template>`, a script's props — is drawn with `render($state, $size, '', false)`:
no `<link>`, and the stylesheet is not counted as written, so whatever puts the data on the page
calls `stylesheet()` (the conversations dock does). A stylesheet on this server is linked with
`?v=` its file's time, so a cache or a CDN does not keep an old one.
`pfAssistant.state('thinking')` sets the state from the application's own script, and
`pf-assistant:state` (`detail.state`) is dispatched on the document at each change.

## What it reads

Every tool only reads, and answers figures over a window — `days` (30 by default, at most 366)
or `from`/`to`, grouped by `day`, `week` or `month`. A day is a day in the analyst's zone:
`'llm' => ['analyst' => ['timezone' => 'Europe/Athens']]`, or the application's default zone. The
prompt names it, and each answer's window says which it used.

| tool | |
|---|---|
| `users` | accounts by type, active and validated; sign-ups and sign-ins per period; never signed in |
| `sessions` | online now (last 15 minutes), people and guests, each hour of the last day |
| `organizations` | by type, active, created per period; the largest by active members (name and count) |
| `mailing_lists` | subscribers per list by status; subscriptions, confirmations and unsubscriptions per period |
| `email` | sent, opened (proxy-only apart), clicked and their rates, by list and period; subjects sent to at least three; the most clicked links without their query strings; and `history`, every message sent by status and module with the failures by module (`Email\SendHistory`, as the dashboard counts it) |
| `push` | sent, delivered and failed, by kind and status; the commonest failures |
| `queue` | tasks by state, waiting by kind, failures by kind with their first error line, run times |
| `llm_spend` | calls, tokens and cost by provider, model and purpose; scheduled tasks' runs apart, by task (`llm_calls.routine_id`); failures; spend per organisation (id and name) |
| `log-analytics`, `log-errors`, `status` | the MCP tools of the same names |
| `user` | one account the administrator names: type, state, dates — its email only with `include_email` |
| `audience_segments`, `audience_rules`, `audience_experiments` | segments with members and movement; rules with status, trigger and how they are doing, one with how many went through each step; A/B tests with their results — offered where their tables are, each behind its screen's ability (`admin.segments`, `admin.rules`, `admin.experiments`) |
| `audience_why` | why one account is or is not in a segment, or would or would not come into a rule — tagged `personal` |
| `audience_draft` | the one tool that writes, and only drafts: a segment comes back as its count and a link that opens the builder filled in, nothing saved; a rule is saved as a draft, which does nothing until an administrator switches it on |

## Personal data

The provider is a third party, so nothing an aggregate answers names a person, and every
result passes through `Analyst\Redactor` before the model reads it: email addresses become
`a***@d***.com`; tokens, payment card numbers (Luhn-checked, and not run into letters, as the
digits inside an id are), phone numbers (international, or
local with separators) and IP addresses become `[token]`, `[card]`, `[phone]`, `[ip]`. A number
without any separator is left alone — it is as likely an order number or a Unix time. A subject
sent to fewer than three recipients is left out, since a personalised one carries a name no mask
can recognise. The `user` tool's answer is tagged `personal`, so a [data rule](Pramnos_LLM_Guide.md#data-rules) can
keep it from a provider that may not have personal data.

## Its own SQL, and files to download

For a question no tool answers, the analyst can write SQL — when the application turns it on:

```php
'llm' => ['analyst' => [
    'sql'         => true,   // `schema` and `sql`: one SELECT, up to 200 rows to the model
    'export'      => true,   // `schema` and `export_csv`: up to 100,000 rows to a CSV file
    'sql_seconds' => 30,     // how long a statement may run
    'schema'      => [       // what the catalogue cannot say
        'orders' => ['about' => 'One customer order.', 'columns' => ['status' => '1 open, 2 paid, 3 refunded']],
    ],
]],
```

`schema` lists the tables, or one table's columns with their keys, and the application's notes,
keyed without the table prefix. `sql` answers rows. `export_csv` writes them to a file. A list of
up to `export_inline` rows (100 by default) also comes back whole, and the model is told to show
it as a table beside the link. A longer one gives the model only the link, the count and five
rows. An optional `title` names the file, in Latin letters when it was written in Greek:
`7-energa-meli-2a91…`, saved as `energa-meli-<date>.csv`. The file has a UTF-8 byte-order mark, so a
spreadsheet reads non-Latin text. It is kept seven days outside the web root, in
`var/analyst-exports/`, and downloads from `/admin/Ai/export/<name>` only for the administrator
it was made for.

The link is built in the queue worker, which has no request to take a host from and often runs the
site's application, not the area's. So the question carries the area's URL as its request saw it
(`Desk::extra()`), and the link is built under it: absolute, and in the area, with nothing to
configure. Without one — a question not asked through a page — it falls back on the configured
prefix under `APP_URL`, or from the host's root (`AdminArea::address()`).

A statement is guarded four times, by `Analyst\ReadOnlySql`:

- it must be one SELECT or WITH with no writing keyword anywhere (`Security\ReadOnlyQuery`) —
  which also refuses `INTO OUTFILE`, `FOR UPDATE` and `LOCK IN SHARE MODE`;
- it may not call a function that waits, locks or reads the server's files — `SLEEP`,
  `BENCHMARK`, `GET_LOCK`, `LOAD_FILE`, `pg_sleep`, `pg_read_file`, advisory locks, `dblink`;
- it runs in a read-only transaction that is rolled back, so the database refuses a write the
  lexer missed;
- it stops after `sql_seconds` — `statement_timeout` on PostgreSQL, `max_statement_time` on
  MariaDB, `MAX_EXECUTION_TIME` on MySQL.

A statement without `LIMIT` is given one. Where the installation set `database_readonly_dsn`,
the statement runs through that account, as `db-inspect` does. Then the database's own
privileges are the boundary.

Rows go through the redaction like every tool's answer. `sql` and `export_csv` are tagged
`personal`, since a statement can read anybody's row.

## From any administration page

The admin header's **Ask** opens the analyst in a panel beside the page, about that page. A
question carries the screen and the record it shows, and the analyst gets the page's help as
background. With the `help` feature on, its `help` tool answers from the installation's help pages. See
[Administration help and the side panel](Pramnos_Admin_Assistant_Guide.md).

## The application's figures

```php
'llm' => [
    'admin_tools' => [App\Llm\PostsPublishedTool::class, new App\Llm\Credits($wallet)],
    'analyst'     => ['assistant' => App\Llm\Analyst::class],
],
```

`admin_tools` are `Pramnos\Mcp\McpToolInterface` — class names built with no arguments, or
instances — beside the framework's, redacted the same way. One that implements `ScopedMcpTool`
is served to MCP clients holding its scope as well (see the
[MCP guide](Pramnos_MCP_Guide.md#a-tool)). They should only read; the framework
cannot check that. An `Analyst\AnalystAssistant` subclass tells the model about the business
through `about()`, or changes the tools through `tools()`.

A `tools()` that cuts what some people may read decides by `$turn->rightsOf()`, not by
`$turn->scope->userId`. The two differ when others read the answer too — `@Ask` in a conversation
(see the [Conversations guide](Pramnos_Conversations_Guide.md)) — where `rightsOf()` is the
conversation's least privileged reader and `scope->userId` is who asked and pays.
`$turn->shared()` says the answer is read by others. There the framework leaves out the asker's
memory and `export_csv`, and tells the model which of the asker's tools it may not use:

```php
public function tools(\Pramnos\Llm\Chat\Turn $turn): array
{
    $reader = ToolAccess::user($turn->rightsOf());

    return array_filter(parent::tools($turn), static fn ($tool, $name): bool => ToolAccess::allows($reader, (string) $name), ARRAY_FILTER_USE_BOTH);
}
```

Whether it looks people up is `peoplePolicy()`. The framework's rule keeps to figures and opens
one account only when the administrator names it. Where finding and counting people by name is
ordinary administration, replace the rule rather than contradicting it in `about()`, so the model
gets one rule and not two that disagree:

```php
protected function peoplePolicy(): string
{
    return 'Finding and counting accounts by name is ordinary administration here; do it when asked.';
}
```

**The same analyst speaks over MCP.** `mcpInstructions()` is what the MCP endpoint sends with
`initialize`, and a remote assistant passes it to its model. It contains:

- `about()`;
- the analyst's time zone;
- `peoplePolicy()`;
- that masked values stay masked;
- which tool to reach for: `toolGuidance()`, or by default "schema and sql are the last resort".

Without the tool line, a model asked about the application's visits writes SQL against the schema
instead of calling the tool made for visits:

```php
protected function toolGuidance(): string
{
    return 'Visits, bookings and their counts: visits_report. Reach for sql only when no tool answers.';
}
```

An application that charges for the
analyst, or limits it, overrides `analystDesk()` on its `Admin\Controllers\Ai` and returns an
`Analyst\Desk` with its own `beforeAsk()`.
