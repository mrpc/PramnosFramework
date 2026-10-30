---
use_cases:
  - Adding an Ask AI page, widget or SPA screen to an application
  - Calling the conversations JSON API from a SPA or a mobile app
  - Making the Ask page look like the application's own
  - Giving people a page to see, pause and receive their scheduled AI tasks and reports
---

# Screens for conversations

Part of the [language-model guide](Pramnos_LLM_Guide.md).

Three ways to put conversations in front of people, all over the same hooks.

## The JSON API

One line in the application's API routes mounts every endpoint:

```php
\Pramnos\Llm\Chat\Api\Routes::register($router);

// An application's own controller, and paths of its own for some of them
\Pramnos\Llm\Chat\Api\Routes::register($router, App\Api\Chat::class, [
    'routines' => '/routines',
    'consent'  => '/ai/consent',
]);
```

| path (default) | |
|---|---|
| `GET /chat` | this person's conversations, and the limits a screen shows |
| `POST /chat` | ask — `content`, `conversation_id`, `attachments: [{name, data}]` with the bytes in base64; 202 with `ask_id`, or 200 with the answer under `answer_in_request` |
| `GET /chat/{id}`, `DELETE /chat/{id}` | one conversation with its messages; forget it |
| `GET /chat/artifacts` | this person's artifacts, newest first, without content |
| `GET /chat/artifacts/{id}`, `DELETE /chat/artifacts/{id}` | one artifact with its content; remove it |
| `POST /chat/{id}/share`, `POST /chat/artifacts/{id}/share` | share with `names` (a list, or one string of usernames/emails); `shared` and `unknown` |
| `GET /chat/{id}/shares`, `GET /chat/artifacts/{id}/shares` | who it is shared with |
| `DELETE /chat/shares/{id}` | stop one share |
| `GET /chat/artifacts/{id}/actions`, `POST /chat/artifacts/{id}/actions/{key}` | a list's bulk actions; run one with `params` — `done`, or `queued` for a long list |
| `POST /chat/artifacts/{id}/approve`, `POST /chat/artifacts/{id}/reject` | settle a change the model proposed; approving runs it |
| `POST /chat/messages/{id}/rating` | rate an answer: `rating` 1, -1 or 0, and `note` |
| `GET /chat/artifacts/{id}/csv`, `POST /chat/artifacts/{id}/pin` | an artifact's rows as CSV (`filename`, `csv`); pin it, or `pinned: false` |
| `GET /chat/shared` | what others shared with this person |
| `GET /chat/people?q=` | who matches what is typed in a name field, `{people: [{value, label}]}`: in the analyst's space, active accounts at or above its floor; nobody in a person's own Ask (`SharePolicy::candidates()`) |
| `POST /chat/shared/{id}/open` | open it: the `conversation_id` of this person's own copy |
| `GET /chat/asks/{id}` | a queued question: `waiting`, `answered` with its `conversation_id`, `failed` with `says`, or `cancelled` |
| `POST /chat/asks/{id}/cancel` | stop a question still `waiting`; 404 when there is none |
| `GET /chat/memory`, `DELETE /chat/memory/{id}` | what it remembers; forget one |
| `GET /chat/connections`, `POST /chat/connections`, `DELETE /chat/connections/{name}` | the MCP servers the person connected; connect one (`name`, `url`, `token`; answers `authorize` when it wants a sign-in); remove one |
| `GET`/`POST /chat/routines`, `PUT`/`DELETE /chat/routines/{id}`, `POST /chat/routines/{id}/run` | scheduled tasks, with the templates, cadences, `kinds`, and the `owners` this person may use; `active` in a `PUT` pauses or resumes; `running` while a run waits or is under way; `run` queues one (202) |
| `GET /chat/routines/{id}/runs?limit=` | a task's runs, newest first: its own, or a shared report the person may read |
| `GET /chat/runs/{id}`, `POST /chat/runs/{id}/continue` | one run with its task; open it as a conversation of the person's own (`conversation_id`) |
| `GET /chat/reports` | the shared reports here the person may receive, with `subscribed` and `latest_run` |
| `POST`/`DELETE /chat/routines/{id}/subscribe` | receive a shared report (`email` in the body), or stop |
| `GET`/`POST`/`DELETE /chat/consent?feature=` | a feature's provider (`chat` by default), whether it needs agreement and whether it was given; agree; withdraw |

A refusal is `{status, error}`; one for want of agreement adds `consent_required` and `provider`,
so a screen can ask on the spot. The provider checked — whether it is set up, whether it needs
agreement — is the one the router sends this person to (`purpose` `chat`, or the feature's name),
so an organisation on its own provider or its own key is asked about that one.
`Conversations::connection()` answers it. Each decision an application makes is a protected method of
`Chat\Api\ChatController`, overridden in a subclass:

| hook | |
|---|---|
| `refusal($action)` | called first by every action; a response stops it — a plan that does not include it |
| `beforeAsk($scope, $input)` | after the agreement check: a limit, a charge |
| `storePicture($path, $filename)` | keep an attached picture and answer its address; without it pictures are not kept |
| `extra($input)` | what travels with the question to the assistant's `question()` |
| `limits($scope)` | the limits the screen shows — add this month's use |
| `routinesAnswer($scope, $answer)` | the scheduled-questions list — add a plan's limit on active ones |
| `scope()`, `conversations()`, `asks()`, `routineService()` | where everything comes from |

## The page, without a SPA

`init --features=…,llm` writes `src/Controllers/Chat.php`, extending
`Chat\Web\ChatPageController`, with views in each theme under `views/chat/`. `/chat` lists the
conversations, shows one, and asks. Answers are drawn by `Chat\Markdown::toHtml()`, which escapes
first; a table it draws carries `table table-sm` for Bootstrap and daisyUI and its spacing and
rules inline for any other theme, and scrolls on its own when wider than the answer. Under each, which tools it read and with what. The ⚙ holds the model, the limits, what it
remembers and the agreement; a bar shows how much of the model's context the last answer used.

With `assets/js/pf-chat.js` the page asks in place: Enter sends and Shift+Enter is a new line;
files are picked with 📎, dropped on the box or pasted, and held as chips (ten at most, 5 MB
together, of the kinds the server reads); the question shows at once with a thinking line —
the application's own lines after a few seconds, `'llm' => ['chat' => ['waiting_lines' =>
[…]]]`, and a promise after a minute — and when the answer is there the page's own HTML is
fetched and swapped in, without a reload. Every word the script says comes from the page, so it
is translated with the rest. Without JavaScript the page posts and refreshes itself until the
answer is there.

A question is a bubble on the right and an answer is text on the left, each under a line with
whose it is — "You", or the assistant's name (`'chat' => ['name' => …]`, `'analyst' => ['name' =>
…]`) — and when, in the reader's own zone: "today 22:50", or the date for another day. Under each
answer, ⧉ Copy puts its Markdown on the clipboard, not its HTML. Over each table in an answer,
⧉ Copy table puts its rows on the clipboard tab-separated, which a spreadsheet pastes as cells. On
Bootstrap the question's bubble also carries its colour inline, for an admin theme still on
Bootstrap 3, which has no `bg-body-tertiary`. A question asked in place is drawn
the same way at once.

### How the page looks, as the application's own

An empty conversation is a greeting over one large composer, with the example questions under
it. Once a conversation is open, its title replaces the greeting and the composer stays at the
bottom of the view. The composer has 📎, the files held, a hint and a round send button. Typing
`/` at the start of the composer opens a menu of commands. ↑ and ↓ choose, Enter or Tab runs the
chosen command, and Escape closes the menu. While the menu is open, Enter never sends the half-typed
command as a question.

The application brands the page in `'llm' => ['chat' => […]]`, and the analyst's page in
`'analyst' => […]`:

```php
'chat' => [
    'name'        => 'Naya',                                   // over each answer
    'greeting'    => 'Hi, I am Naya. What shall we look at?',  // over the empty composer
    'placeholder' => 'Ask Naya…',
    'accent'      => '#7c3aed',                                // the send button, focus ring, chosen command
    'suggestions' => ['How did this week go?'],
    'commands'    => [
        ['name' => 'week', 'says' => 'This week in numbers', 'text' => 'How did this week go, against last week?'],
        ['name' => 'help', 'says' => 'How Naya works', 'url' => '/help/naya'],
    ],
],
```

Without a `greeting`, an assistant the application named (`name`) introduces itself: "Hi, I am
Naya. How can I help?". Without a name either, the line is "How can I help?". The framework's
default names ("Ask", "Analyst") belong to a feature, not a persona, so they introduce nobody
(`ChatLook::greeting()`). Without an `accent`, the theme's primary colour is used. The page's own
tools (⚙) sit in the top corner of the conversation column.

`Chat\Web\ChatLook::accent()` accepts only a CSS colour, so a stray `;` cannot add rules to the
`style` attribute. `ChatLook::commands()` puts the page's own commands first: `/new`,
`/scheduled` where the page has it, `/attach` and `/memory`. The application's follow, each with
`text` to put into the composer or a `url` to go to. A command with a name already taken, or with
neither `text` nor `url`, is left out.

The page is one column, the title and the answers' tools included, `--pf-chat-width` wide (1100px,
enough for a report's tables). An application changes the width by setting that property on
`.pf-chat`. The styles are in the theme's `components.css`, under *Ask*. They set shape and layout only, on
the same shared tokens as the other components, and the theme's own buttons, type and colours
still apply. `--pf-chat-accent` on the page (set from `accent`) overrides the theme's primary for
the page. An existing project picks the styles up with `./pramnos project:resync --css`, and the
script with `--js`. Its `views/chat/` are its own copies: compare them with the theme's, because
the composer, the greeting and the `/` menu need the new markup.

### The scheduled page

`/chat/scheduled` (`ChatPageController::scheduled()`, linked from the conversations page, with a
view in each theme under `views/chat/scheduled.html.php`) shows a person what is scheduled for
them, without asking the model:

- **Their tasks:** what each is (question, reminder, report), when it runs and next, whether it is
  paused and why it stopped. Each has buttons to see its runs, run it now, pause or resume, and
  delete it.
- **The shared reports they may receive** (`Routines::reports()`), each with a button to receive
  it or stop.
- **One task's runs**, with `?task=`, newest first. A report's runs have an *Ask about it* link to
  `chat/report/{run}`.
- **A form to schedule one:**
    - what it is and, when the policy lets them share, for whom (`TaskPolicy::canManage()`);
    - whether readers are told every time or only when something is new;
    - a template;
    - the title and the words;
    - how often, at what time, the weekday or the day of the month, which weekday of the month,
      or a moment for a one-off.

A one-off further than ten years ahead is refused.

Its POST actions are `schedule`, `scheduledupdate` (`active`), `scheduleddelete`, `scheduledrun`
and `scheduledsubscribe` (`on`). Each carries the session's token and says what it did, or why
not, as a flash message.

The page is the trait `Chat\Web\SchedulesTasks`, so it can be served elsewhere. A controller
using it answers six hooks:

- `taskScope()`: whose tasks;
- `taskConversations()`: the conversations they are made over;
- `scheduledUrl($action)`: the page, or one of its actions;
- `taskReportUrl($runId)`, `taskChatUrl()` and `taskSignInUrl()`: where a report's run opens, the
  conversations, and where somebody not signed in is sent.
- `taskDenied()`, optional: true when the request stops here, already answered. Every action asks
  it first; `AiController` answers with the admin floor, as its other actions do.
- `scheduledExtras(?array $task): array`, optional: markup of the controller's own — `form`,
  printed inside the form before its button; `task`, on the open task's card. Printed as given, so
  escape what goes in.
- `scheduledCrumbs(?array $task): void`, optional: the page's breadcrumbs. By default these are
  the conversations, Scheduled (a link back to the list when a task is open) and the open task's
  title, named by `taskChatName()` ("Ask AI", or the analyst's name on `AiController`). An
  application whose shell draws its own trail overrides it to add nothing.
- `afterScheduled(array $task, array $input): void`, optional: called with the task just made and
  the whole form, the controller's own fields among it, before the redirect. Not called when the
  form was refused.
- `afterAudienceChanged(array $task, array $before): void`, optional: called when a report's
  readers were changed from its card, with the task as it is and as it was, to subscribe the
  people newly named, for example.
- `static audienceRoles(): array`, optional: the roles readers are chosen by, `role => usertype`,
  lowest first. An application whose roles differ from the framework's returns its own.

A flash message carrying a value is `t('… %s …', $value)`, so a translation catches the whole
message. `next_run_local` is in figures (`19/10/2026 09:00`), which read the same in any language.

The view reads the addresses from `$this->urls`. `AiController` serves the analyst's at
`/admin/Ai/scheduled`, with `/admin/Ai/report/{run}` for its reports. A report's link and an analyst
conversation's are built the same way, by `AnalystAssistant::areaUrl()`: the admin area's address
where the process has one, otherwise `admin/` under the site's — so a link made by the site's cron
still leads into the administration. The analyst's page links
there only where the application lets the analyst schedule, through its `routineRefusal()`. An
analyst that may schedule tells its model so, and counts times on the analyst's clock
(`Analyst::timezone()`).

### Charts

An answer can carry a chart: a fenced block with the info string `chart` and one JSON object.

````markdown
```chart
{"type": "bar", "title": "Sign-ups per month", "labels": ["Jan", "Feb", "Mar"],
 "series": [{"name": "2025", "data": [12, 19, 7]}, {"name": "2026", "data": [15, 22, 30]}],
 "y": "people", "stacked": false}
```
````

The fields:

- `type` is `bar`, `hbar`, `line`, `area`, `pie` or `doughnut`.
- `series` holds numbers only, one per label. A pie or doughnut takes one series.
- `title`, `subtitle`, `x`, `y`, `stacked` and `min` are optional.

How it is drawn:

- `Chat\Markdown::toHtml()` turns a block that is a chart into `<div data-pf-chart="…">` with
  the code inside it. A block that is not a chart stays code.
- `pf-chat.js` draws it as SVG over the code: axes with round steps, a key, and each figure as
  a shape's tooltip.
- **Your own chart library:** set `window.PfChat = {chart: function (element, spec) { …; return
  true; }}` before `pf-chat.js` loads. Return false to leave a chart to the built-in drawing.
- Copy and export keep the original block.

The analyst's prompt tells the model when to chart and to keep the figures as text as well —
`Markdown::CHARTS`, through `AnalystAssistant::charts()`. Return '' from it for an analyst that
never charts. A chat assistant of your own adds `Markdown::CHARTS` to its prompt to chart.

Example questions sit above a new conversation, and a click puts one in the box, to send or to
change first:

```php
'llm' => [
    'chat'    => ['suggestions' => ['Which posts did best this month?', 'Draft a reply to this email']],
    'analyst' => ['suggestions' => ['Who signed up this week?', 'What failed in the queue today?']],
],
```

`pf-chat.js` is one of the framework's page scripts, which `composer update` does not place in
the web root: `php bin/pramnos project:resync --js --all` does, after an upgrade that brings a
page using a new one. Until then the page works as a plain form; the `framework_scripts` health
check and a warning in the log, the first time a page asks for the missing file, both name the
command. The hooks are the API's — `beforeAsk()`,
`storePicture()`, `extra()`, `scope()` — and a refusal comes back as the page's error message.

A question from anywhere on the site: include the widget in the layout. It is drawn only for
somebody signed in.

```php
<?php $this->insert('../partials/chat_widget'); ?>
```

## The SPA components

A Svelte SPA with the `llm` feature gets `components/llm/Chat.svelte`, `Scheduled.svelte` and
`AiConsent.svelte`, with `lib/llm/chat.js` (the API client and what the screens share),
`lib/llm/markdown.js` (`marked`, then DOMPurify) and their tests under `__tests__/llm/` — a
directory of their own, so they cannot land on an application's own chat screen or its tests.
An existing project takes them with `php bin/pramnos project:resync --spa-components --all`, which adds
`marked` and `dompurify` to package.json and leaves every component the project changed as it is
(see the [Console Guide](Pramnos_Console_Guide.md)). They are the project's files from then on.

```svelte
<script>
    import Chat from '../components/llm/Chat.svelte';
    import { go } from '../lib/router.js';

    let { route = null } = $props();
</script>

<Chat
    conversationId={Number(route?.query?.get('c')) || 0}
    onopen={(id) => go('/chat', id ? { c: id } : null, { replace: true })}
    scheduledHref="/scheduled"
    paths={{ routines: '/routines', consent: '/ai/consent' }}
/>
```

`Chat` keeps no address of its own: the open conversation comes in as `conversationId` and goes
out through `onopen(id)`. Everything else an application changes without editing the file:

| prop | |
|---|---|
| `client`, or `api` and `paths` | the API: `chatClient(api, paths)` from `lib/llm/chat.js` by default |
| `extra` | an object, or a function returning one, sent with each question; `extraUsed()` once it went |
| `canAsk`, `onRefusal(err)` | hold the Ask button; take a refusal — return `true` when the application said it itself |
| `suggestions` | questions that fill the box when pressed — they do not ask; shown under the composer of an empty conversation |
| `greeting`, `avatar` (snippet) | an empty conversation's line and picture over the composer; *How can I help?* without a greeting |
| `accent` | a CSS colour for the send button, the composer's focus and the chosen `/` command; the kit's primary without one |
| `commands` | the application's `/` commands, after the screen's own (`new`, `scheduled` with `scheduledHref`, `attach`, `memory`): `{name, says, text}` fills the box, `{name, says, url}` goes there, `{name, says, run}` calls `run()` |
| `toolLabel(tool)` | how a tool an answer read is named under it |
| `t` | the translator — every string goes through it, `lib/i18n.svelte.js`'s by default |
| `consentNotice(consent)` (snippet) | the agreement's wording — legal text the application owns |
| `question` (bindable), `placeholder` | what is in the box — a prompt another screen prepared — and its placeholder |
| `waitingLine(ms)` | a line under *Thinking*, asked each second of waiting; `null` shows nothing. By default a sentence after a minute |
| `onanswer(messages)` | told the conversation each time an answer has been read back — open what it produced |
| `formatDate`, `numberFormat` | how a conversation's date and the limits' figures are shown |
| `scheduledHref` | where `Scheduled` lives; without it there is no *Scheduled* link |
| `privacyUrl` | the privacy policy, linked from the consent notice; without it there is no link |
| `conversationHref(id)` | the conversations become links the application's router follows, and open in a new tab |
| `header` (snippet) | the title, in place of *Ask AI* |
| `message(message, change)` (snippet) | drawn under each answer; `change(patch)` updates the message and calls `onmessagechange(id, patch)` |
| `onmessagechange(id, patch)` | told when `change(patch)` updated a message, to persist it |
| `composer` (snippet) | beside the Ask button: a cost, what the question is about |
| `aside` (snippet), `asideOpen` (bindable) | a panel beside the conversation from `lg`, over it below that, closed with ✕ |

`AiConsent` wraps what needs agreement and draws nothing of it until agreement is given, when the
provider in use asks for it; its `consent` is bindable. `kind` names the feature asked about
(`chat` by default), `notice(consent)` replaces the wording and keeps the buttons.

`Scheduled` does for a SPA what `/chat/scheduled` does for a page:

- It lists, creates (from a template or by hand), pauses, runs and deletes questions, reminders
  and reports, with every cadence: once, daily, weekly, monthly, and a weekday of the month.
- A report offers whom it is for, from the `owners` the API says this person may use, and when
  readers are told.
- It shows a task's runs, and opens a report's run as a conversation of the reader's own through
  `continueRun`, then `onopen(id)`.
- It lists the reports shared with this person, to receive or stop.
- It opens a routine's conversation with `onopen(id)`.
