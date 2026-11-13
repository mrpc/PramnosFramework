---
use_cases:
  - Letting administrators ask questions about the installation's own figures
  - Letting the AI explain, in plain words, a change in the code behind "it worked yesterday"
  - Giving the analyst its own SQL, files to download and the application's figures
  - Keeping personal data out of what the analyst reads
  - Adding a microphone and read-aloud to the Ask AI page
  - Checking from the console how the analyst answers after fixing a tool or the help
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

## Asking it from the console

After fixing one of its tools or a help page, ask the question again from a terminal and see what
it does now:

```bash
php bin/pramnos llm:ask "How many parents paid in October?" --as=5
php bin/pramnos llm:ask "And in September?" --as=5 --conversation=812
php bin/pramnos llm:ask "…" --as=5 --json
```

It is the same analyst as `/admin/Ai`, as the administrator `--as`, with their tools and
abilities, through the path a question from the screen takes (`Assistant::acting()`, then
`Conversations::ask()`). It prints the answer, each tool it called with its arguments, the cost
and the conversation with its address. The conversation is kept and opens on the screen like any
other. Its answers are marked `extra.source = console`, so `help:gaps` leaves them out, and nothing
in it is learned about the person (`Conversations::fromConsole()`).

`mcp:call` (and a local `mcp:serve`) offers the same as the tool `ask` — `question`, `as`,
`conversation_id` — and asks only as root or a super administrator, since every call is a paid
answer. It is never among the tools the public MCP endpoint offers. Code calls
`\Pramnos\Llm\Analyst\ConsoleAsk::ask($userId, $question, $conversationId)`.

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

## The assistants' control centre

`/admin/Ai/all` is the one page for everything the assistants do, on every channel. It is for
whoever has `admin.ai.all`, which in usertype mode is root alone, as `admin.chat.all` is
(`Analyst\Review::readsAll()`; `AiController::readsAll()` is the seam to override). The Ask page
links to it from the ⚙ under its title, beside the settings. A reader with neither link sees no
gear.

- **Channels.** A select picks the channel: the Ask's first, named by the analyst's name and
  *administration*, then each companion persona's
  (`chat`, `chat:<persona>`, named after the persona), then any other space the application keeps
  conversations in (`Review::channels()`). A channel that is none is the Ask's.
- **The front** is a dashboard of a channel, or of every channel (`Analyst\Overview`):
    - **Figures:** people who talked (ever, 30 and 7 days, today), new people, conversations,
      messages and cost (today, 7 and 30 days). For the companion they also include the app's
      subscribers, morning thoughts and diary pages written, and the moods it was left in.
    - **A chart** of the last 30 days' messages.
    - **The people,** not the conversations: each with their chosen persona, last activity,
      conversations and messages by channel, memories and cost. Searched by name or email, and
      sorted by latest, name, messages or cost.
    - **Every persona** is in the channel filter, whether or not it has conversations yet.
- **A person's card** (`?person=<id>`) gives what the companion knows of them
  (`Chat\People::person()`), their conversations by channel to read, their memories by channel
  and topic, and *Reset the experience*, with no conversation needed. Each view is
  `ai.person_viewed` in the audit log.
- **A conversation** (`?c=<id>`) opens as before, beside its channel's conversations
  (`Review::list(['channel' => …, 'q' => …])`), with a link to its owner's card.
- **One opened** reads as its owner sees it, under a banner saying whose it is. It shows the
  questions, the answers, the tools each answer used with their input, and the model calls made
  while it was answered. The calls are the call log's rows, `/admin/Ai/call/{id}`, where what each
  tool returned is in the messages sent. They are matched by time: the owner's calls of the
  channel's purpose between one question and the next. The steps and tools are folded, as on Ask's
  page, and `pf-chat.js` draws the answers' charts. Opening reads the conversation straight from its
  tables, so nothing is marked read and nothing is learned.
- **Beside a companion conversation**, a panel shows what the companion knows of its owner
  (`Chat\People::person()`). For each persona that is the first meeting, mood, voice and style,
  diary, today's thought, memories and routines. Below come the profile, the push subscriptions
  and when they were last started over.
- **Reset the experience.** On that panel, a folded *Reset the experience* puts the person's
  persona, voice and style back to the defaults, so each persona meets them again for the first
  time (`Chat\People::reset()`, see [The person's companion](Pramnos_LLM_Person_Guide.md)). Their
  conversations, memories, profile, diary and routines are kept, and it says so before it is
  confirmed. It is offered to whoever `AiController::resetsCompanion(object $user): bool` allows,
  root by default, and an application overrides it. The form posts to `Ai/companionreset` with the
  session's token. Anybody else gets a 404 there.
- **Everything else held for the person**, below, a folded section each with its count, and an
  empty one left out (`Analyst\Overview::holdingsOf()`):
  - routines, reminders and timers, with when they next and last ran, whether they are on, and
    their last error;
  - their calendar;
  - the artifacts they made, and those shared with them with their role;
  - their shares, their questions and their dossier;
  - their own skills.

  A section whose table the installation has not migrated is left out. Each row shows its title
  first, then its date. The *From* column links to the conversation it came from, or says it
  was made outside one, as a diary page written by a routine is. An artifact's title opens it
  read only inside the administration, at `Ai/artifact/{id}`, for whoever reads everybody's
  conversations. The page shows the trail back to the person's card and a *Print or save as
  PDF* button, which opens its print form (`?print=1`). Each opening is audited as
  `ai.artifact_viewed`. A share's title opens what
  was shared. An application adds sections of its own by overriding
  `AiController::personSections(int $userId): array`, which returns title => rows.
- **Deleting what is held, for good.** Rows of the routines, calendar, artifacts, shares,
  dossier, skills and memories can be selected the same way, and *Delete the selected* deletes
  them. Each goes through what owns it, so nothing is left behind: a routine goes with its runs
  and readers, and an artifact with its members and shares. Each deletion is audited
  (`AiController::DELETABLE` names the events). The form posts `section` and `ids[]` to
  `Ai/holdingsdelete`, behind the same `deletesConversations()`.
- **Deleting conversations for good.** Each conversation on the card can be selected, and a
  folded *Delete the selected conversations* deletes them, after saying it cannot be undone. Their
  messages, their questions, what they made and its members, and their shares go with them
  (`Chat\Conversations::delete()`). Each is audited as `ai.conversation_deleted`. It is offered to
  whoever `AiController::deletesConversations(object $user): bool` allows, root by default. The
  form posts to `Ai/conversationsdelete` with the session's token, and anybody else gets a 404.
  The card shows the flash itself (how many were deleted), since an application's own admin
  header may not.
- **The rest** about the assistants is linked from the same page: answers to review, memories,
  scheduled tasks, and costs and settings.
- **In the application's own menu.** An application that keeps the assistants' management (this
  page, everybody's memory, the answers to review) in a menu of its own says so:
  `'llm' => ['analyst' => ['assistants_in_menu' => true]]`, or overrides
  `AiController::assistantsInMenu()`. The Ask's gear then keeps only what is the Ask's (its skills,
  settings and scheduled tasks), and this page has no link back to the Ask.

Nothing else there writes: no question box, no delete, no memory, no agreement. Each view of
somebody else's conversation is recorded in the audit log as `ai.conversation_viewed`, with the
owner as its target and the conversation as its object. Anybody else gets a 404.

The reader's own Ask conversations are left out of the Ask's list and owner filter
(`Review::list(['except' => $id])`, `Review::owners($id)`). They are in the Ask column already,
where they are not read only, and `/admin/Ai/all?c=` with one of them redirects there. Their own
conversations on another channel are listed and read here like anybody's.

## Its name and picture

The analyst is called *Ask* unless the application gives it a name of its own:

```php
'llm' => ['analyst' => [
    'name'     => 'Aria',                     // the header's button, the side panel, the Ask page, the conversations
    'mentions' => ['aria'],                   // words that call it after @ in a conversation, beside @Ask
    'avatar'   => '/themes/main/img/ai.svg',  // its picture beside the name
]],
```

`Analyst::name()`, `mentions()` and `avatar()` read them, and every place the administration shows
the analyst asks them: the header's button and the side panel (its tab, title, and what a screen
reader hears — *Ask Aria about this page*), the Ask page's title, the conversation with it and its
*… is thinking*, the composer's `@` suggestion, and the *Open in …* card on an answer. In a
conversation `@Ask`, `@Aria` and each mention call it, as a word of its own and ignoring case, and
the mention is drawn as `@Aria`. Its conversation is kept as the bot `Ask` (`Ask::NAME`) whatever it
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
    'markup' => APP_PATH . '/themes/admin/assets/aria/aria.svg',   // a file, the markup, or a callable
    'css'    => '/themes/admin/assets/aria/aria.css',              // linked once a page
    'states' => ['success' => 1800, 'greeting' => 2400],           // ms, for those that end by themselves
]]],

\Pramnos\Assist\AvatarSets::register('aria', $markup, '/assets/aria.css', ['greeting' => 2400]);
'llm' => ['analyst' => ['avatar_set' => 'aria']],
```

A plain picture (`avatar`) with no set is drawn in the same element and does not move.

**A set with more than the nine.** A set can declare states of its own, poses, and transitions
between states:

```php
'llm' => ['analyst' => ['avatar_set' => [
    'markup'      => APP_PATH . '/themes/admin/assets/cat/cat.svg',
    'states'      => ['curious', 'drowsy', 'sleeping', 'success' => 1800],   // a name alone: a state of its own; name => ms: ends by itself
    'poses'       => ['sitting', 'loaf', 'sploot'],                         // data-pose, independent of the state
    'transitions' => ['idle>listening' => ['is-perking-up', 360], 'sleeping>*' => 'is-waking-up'],
]]],
```

The framework still sets only the nine by itself. The set's own states are the application's to
ask for, with `pfAssistant.state('curious')` or a [mood's](Pramnos_LLM_Conversations_Guide.md#the-assistants-mood)
`plays`. A picture whose set does not play a state ignores it. The picture carries
`data-states`, `data-poses` and `data-transitions`. As the state changes, `pfAssistant` puts the
matching transition's class on the picture for its milliseconds (400 when not given), for the
set's CSS to move between the two. It looks for the exact `from>to` first, then `from>*`, then
`*>to`. `AvatarSets::states()`, `poses()` and `transitions()` read them, and
`AvatarSets::register($name, $markup, $css, $states, $poses, $transitions)` takes them for a
registered set.

**A character the framework ships.** Instead of drawing its own, an application can name one of
the framework's characters (`Assist\Characters`):

```php
'llm' => ['analyst' => ['avatar_set' => 'tesla']],
```

Tesla is a slate-grey cat with chartreuse eyes, a white heart on her chest and white front socks.
`Characters::get('tesla')` gives her name, `gender`, a `prompt` saying who she is, the `size` her
whole body wants in a dock (64px), and her set. Her art is in `resources/characters/tesla/`: one
svg with all her poses and expressions, and a stylesheet that shows the ones the picture asks for.
The stylesheet is written into the page once, as a `<style>`, so nothing is copied to the web
root. A set registered under the same name is the application's and wins.

| attribute | what it does |
|---|---|
| `data-state` | the nine, and hers: `curious`, `looking-around`, `wink`, `happy`, `excited`, `drowsy`, `sleeping`, `waking`, `presenting`, `playing`, `rubbing`, `stretching`, `spooked`, `stalking` |
| `data-pose` | `sitting`, `classic`, `slender`, `loaf`, `lounging`, `sleeping`, `rubbing`, `stretch`, `spooked`, `sploot`, `stalking`, `belly-up`; an unknown name leaves her sitting |
| no `data-pose` | a state with a pose of its own takes it — `sleeping`, `drowsy` → lounging, `rubbing`, `stretching`, `stalking`, `playing` → belly-up, `spooked`; any other state sits |
| `data-gait` | `walk`, `trot`, `sprint`, `zoomies`: on four legs and moving, whatever the pose |
| `data-facing="right"` | mirrored; she is drawn facing left |
| `--pf-look-x`, `--pf-look-y` | where her pupils look, -1.35 to 1.35 |

**When touched.** A set may say what its picture does when touched, for the page that notices the
touch (a tap, a long press, a drag) to play: `'reactions' => ['tickle' => ['plays' => 'annoyed',
'buzz' => [60]], 'pet' => ['plays' => 'purr', 'repeat' => true], …]`, read with
`AvatarSets::reactions()` and given with each persona by `GET {chat}/personas`. A touch is never
told to the model. Tesla's are `tickle` → `annoyed` (ears back, the tail lashing, passing by
itself), `pet` → `purr`, `push` → `playing` and `poke` → `curious`.

Her moods (`AvatarSets::moods()`) are the words a [mood](Pramnos_LLM_Conversations_Guide.md#the-assistants-mood)
may use and what each plays: `affectionate` → `rubbing`, `calm` → the `loaf` pose, `sleepy` →
`drowsy`, and so on. `analyst.avatar_moods` replaces them with the application's own.

**The set's own ids.** `{uid}` in a set's markup becomes a different value in each picture drawn,
so a gradient or a mask referenced by id does not point at another picture's, which breaks when
that one is hidden. A set's `css` that is a file on disk rather than an address is written into
the page as a `<style>`, once.

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

## Voice

```php
'llm' => ['voice' => true],   // default false
```

Phase 1 of the Roadmap's voice design: a microphone and a "🔊 Listen" button, no server side —
transcription and speech both run in the browser, over the native Web Speech API. Chrome carries
recognition through Google's speech service; Firefox has none. Off by default; turning it on adds
both buttons to the chat page in every theme and loads two scripts: `pf-speech-sanitizer.js`,
then `pf-voice.js` — which `project:resync --js` adds to the web root where they are missing,
without `--all`, once the voice is on.

**`pf-voice.js`** — `window.PfVoice.createVoiceState()` returns one controller per page,
shared by the mic and every answer's 🔊 button:

- `startDictation(options)` / `stopDictation()` / `toggleDictation(options)` — `options.onTranscript`
  gets `{text, fullFinal, interimPart}` as the reader speaks; a silence stops it on its own and
  tells `options.onSilence` — 1200 ms, long enough for a breath mid-question, or
  `options.silenceMs`. On the chat pages the silence sends what was said, as Enter does; the mic
  pressed again before it stops without sending.
- `speak(id, text, options)` / `stopSpeech()` / `toggleSpeech(id, text, options)` — `id` tells one
  answer's button from another's, so pressing a second answer's 🔊 stops the first rather than
  reading both at once.
- **Barge-in**: while the assistant is speaking, an interrupt word ("σταμάτα", "στοπ",
  "περίμενε") stops it instantly — `options.onBargeIn` gets what the reader said, with the
  assistant's own voice (leaking back into the microphone from the speakers) filtered out first,
  so it is never mistaken for a new question.
- `assistantGender` (`'female'|'male'|'neutral'`) and the chosen synthesis voice persist in
  `localStorage` under `pf-voice-gender` / `pf-voice-preferred` — framework-namespaced keys, not
  an application's own, since two applications can share an origin.
- **A speech server instead of the browser's voice** — `createVoiceState({ server: fn })`, where
  `fn(sentence)` returns a Promise of the audio: a `Blob` (from `POST {chat}/speak` with
  `raw: true`) or the endpoint's `{audio, type}`. `speak()` then reads the text a sentence at a
  time (`PfVoice.splitSentences()`: after `.` `!` `?` `;` `·` `…`, pieces of 20 to 250
  characters), asking for the next sentence while one plays, so the first is heard as soon as it
  alone is synthesised. `stopSpeech()` and barge-in silence it the same way; if a sentence
  fails — no server set up, the network, a clip that will not play — the browser's voice reads
  from that sentence to the end, the whole text when none was heard yet.
  `hasServer` says which is in use. See the Screens guide, "Reading answers aloud", for the server.
- **Dictation by a server, where the browser has none** (Firefox, an installed app on an iPhone):
  `createVoiceState({ listen: fn })`, where `fn(blob, {seconds})` returns a Promise of the words,
  a string or `{text}`, from `POST {chat}/listen`. `startDictation()` then records with
  `MediaRecorder` and asks for the microphone with echo cancellation and noise suppression. A
  silence after speech ends the recording (`silenceMs`, 1200 by default; `silenceLevel`, the RMS
  under which it is quiet, 0.02; `maxMs`, 60 s). The words come back through the same
  `onTranscript`, `onSilence` and `onEnd`. A recording with no speech in it is not sent;
  `stopDictation()` by hand sends what was said. `startDictation({ viaServer: true })` uses the
  server even where the browser recognises speech itself. `canDictate` says whether dictation
  works here at all.
- **The server side**, `Speech\Listening`, follows `Speech`: `'llm' => ['listen' => ['provider' =>
  'google', 'providers' => [...]]]`. `google` is Google Cloud Speech-to-Text (`el-GR`, WebM/Ogg
  Opus at 48 kHz, WAV and FLAC from their header), signed in with the voice's own
  `speech.providers.google` credentials unless given here. `whisper` is an OpenAI-compatible
  `/v1/audio/transcriptions`: OpenAI's own with the language models' OpenAI key, or a server of
  the application's (`base_url`). `POST {chat}/listen` takes `{audio: base64, type, seconds}` or
  a multipart file `audio`, and answers `{text}`, or 501 (not set up), 422 (empty or over 10 MB)
  or 502. Each call writes a `listen` line to the `speech` log, with its bytes, milliseconds, the
  seconds the client reported and their cost at the provider's `per_minute`.
- **Barge-in by voice level** — `speak(id, text, { bargeIn: { threshold, minMs, onBargeIn,
  dictate } })` keeps the microphone open while the voice speaks, with echo cancellation, noise
  suppression and gain control, so the voice itself is not heard. A sound over `threshold` (RMS,
  0.08 by default) for `minMs` (300) stops the voice and calls
  `onBargeIn({ byLevel: true, … })`, and with `dictate` it starts dictation with those options.
  A cough does not stop it. The microphone is let go whenever the voice ends. This works beside
  the interrupt words, and on browsers that have none of their own recognition.
- **The avatar's mouth with the voice** — `createVoiceState({ avatar })`, or `speak(…, { avatar })`
  for one answer: while the voice speaks it writes `--pf-voice-level` (0 to 1) on the avatar and,
  where the avatar's set draws a mouth for each sound (`'visemes' => true`, which renders
  `data-visemes`; Tesla's does), `data-viseme`: `A E I O U M F L S`, or `rest` between words.
  Both are taken off at the end. The sounds come from the text (`PfVoice.toVisemes()`), Greek
  first and its two-letter sounds (μπ, ντ, ου, αι, ει, οι, τσ, τζ) before single letters.
  `PfVoice.addVisemes({…})` adds a page's own. Through the speech server they follow where the
  audio is; with the browser's voice they follow each word as it is spoken.
- **The voice's level, for a mouth** — `speak(id, text, { onLevel })` calls `onLevel(0..1)` while
  it speaks. Through the speech server, the level is read from the audio itself (an analyser on
  the element) every 50 ms. The browser's voice gives a page no access to its sound, so the level
  is a pulse at each word (0.8, back to 0). It always ends at 0.
- **Subtitles in step with the voice** — `speak(id, text, { onPart })` calls
  `onPart({index, count, text})` as each part starts to be heard: with a server, each sentence
  `splitSentences()` cut from the cleaned text; with the browser's voice, which reads it all at
  once, one part at its start. `opts.parts` gives the pieces instead: a list of pieces of the
  original, each said cleaned and told back as given, so a page shows the original's words
  rather than a transliteration it would have to match. A server that fails partway hands the
  browser the rest as one part, with the index where it stopped.
- **Asked aloud, answered aloud.** A question dictated on the chat pages is sent with `voice=1`.
  Every way of asking takes it (`TakesQuestions`), and the assistant is told, just before the
  question (`ContextAssembler::SPOKEN`), to open with one or two plain sentences that stand by
  themselves, then a blank line before any detail. The answer is then read aloud: whole up to 400
  characters, otherwise its opening paragraph followed by "See the report I prepared for you" when
  it made one, or "The rest is written on the screen". The person turns this off with the 🔊 box
  beside the mic. The choice is kept with their account (`answers_aloud`, on by default) by the
  pages' `voicereply` action, and `ReadsAloud::repliesAloud($user)` reads it.
- **The chat pages read through it on their own.** Where a speech server is set up
  (`Speech::config()`), the conversations page and the analyst's draw
  `data-pf-chat-speak-server` and the session's token, and `pf-chat.js` hands the voice a
  `server` that posts each sentence to the page's `speak` action (`chat/speak`, `Ai/speak`) with
  the token in `X-CSRF-Token`. `speak` is a write action of both controllers, from the
  `Chat\Web\ReadsAloud` trait, and answers the audio itself or the JSON of its 501/422/502.

**`pf-speech-sanitizer.js`** — `window.PfSpeechSanitizer`, what makes the spoken text sound
natural rather than spelled out or mis-gendered:

- Reads `ό,τι` as the word: a voice says the comma as a letter's name ("όμικρον τι").
- `PfVoice.stripMarkdownForSpeech()` leaves no two punctuation marks together: a voice reads a
  mark that ends no phrase by its name ("τελεία", "κόμμα"). A line's end becomes a full stop only
  where the line has no mark of its own, and a table reads as its rows, cells separated by commas.
- Strips Markdown and emoji, converts `%`/`&`/`+` to spoken words, and transliterates any
  remaining Latin word to Greek phonetics — without it, a Greek voice spells `"WordPress"`
  letter by letter.
- `getAssistantTitle(gender)` / `getAssistantSelfIntroduction(gender, name)` — the grammatically
  correct Greek title (ο/η βοηθός, or το AI for neutral) and a self-introduction carrying the
  application's own assistant name.
- `addGenderPhrases(malePhrase, femalePhrase, neutralPhrase)` — an application registers its own
  assistant's stock self-description sentences (an LLM answers in the male-grammar form by
  default; this is what corrects it for a female or neutral persona). The built-in title noun
  ("ο βοηθός" → "η βοηθός"/"το AI") corrects on its own; anything more specific an application's
  assistant says about itself needs its own entry.
- The application's `'speech' => ['pronounce' => […]]` list reaches it on its own on the chat
  pages (`data-pf-chat-pronounce`); `addTransliteration()` is for a page that is not one of them.
- `addTransliteration(pattern, replacement)` — an application's own domain vocabulary (product
  names, metrics, jargon) spoken in Greek instead of spelled out; the built-in map carries only a
  handful of universal terms (AI, API, OK, URL).

**`pf-voice-fillers.js`** — `window.PfVoiceFillers.getRandomFiller(delayed)`, 93 immediate and
34 delayed natural Greek phrases ("Μισό λεπτό να το κοιτάξω...") for a voice conversation to say
while an answer is still being worked out, never immediately repeating itself.

**Still to come** (not built): the framework's own chat pages handing the voice a `listen` of
their own, as they hand it a speech `server`; a continuous voice-conversation mode.

## The floating companion

`pf-companion.js` gives the picture above a place to live outside a chat panel — floating on the
screen, aware of whether the reader is still there:

```js
window.PfCompanion.mount({ html: '<span class="pf-assistant-avatar ...">…</span>' });
// or, with no `html`: reads <template data-pf-companion> — the page writes the markup, pf-assist.js's
// own panel uses the same convention for its template.

document.addEventListener('pf-companion:click', () => { /* what a click means is yours to decide */ });
document.addEventListener('pf-companion:activity', (e) => { /* e.detail.active: boolean */ });

window.PfCompanion.setPose('sitting');       // free-form — see below
window.PfCompanion.setPosition('top-left');  // a convenience default; override .pf-companion-dock for anything else
window.PfCompanion.unmount();
```

`mount()` builds a fixed `.pf-companion-dock`, tracks `mousemove`/`keydown`/`scroll`/`click`/
`touchstart` against `idleMs` (default 60000; `0` turns idle detection off entirely), and on
crossing that threshold sets `data-pf-companion-activity="away"` on the dock and dispatches
`pf-companion:activity`; activity resuming sets it back. With `into: element`, the dock is drawn
inside that element, in the page's flow, with no fixed corner (`.pf-companion-dock--inline`) — for
a page of its own inside a shell whose menu and floating buttons would cover a corner. Inline, the
dock takes the size of the avatar it holds rather than the corner's 56px, and the drawing may
reach past its own box, so a 64px avatar with an antenna is not cut. Clicking
the dock dispatches
`pf-companion:click` and does nothing else — not open the assist panel, not anything — because
what a click should do is the application's decision, not this file's.

**What this deliberately does not have: a pose vocabulary, states beyond the 9 above, mini-games,
or any character content.** `setPose(name)` sets `data-pose="<name>"` on the mounted avatar's
root element, completely unvalidated — "sitting", "sploot", a typo, anything. The framework
keeps no list to check it against, because two applications building on this (one with 12
feline poses, one with none at all) need to be free to invent entirely different vocabularies
without waiting on, or being shaped by, a framework-chosen one. An application's own stylesheet
reacts to `[data-pose="…"]` however it likes; that stylesheet is the entire "character", and it
belongs to the application.

### Out of the dock: moving and playing

`pf-companion-play.js` takes the mounted companion out of its dock to move and play. It works
with any set. A set that draws gaits, a facing and a look moves with them, as Tesla does (see
[a character the framework ships](#its-living-picture)). Any other set is carried as it is.

```js
PfCompanionPlay.follow(true);   // the eyes follow the pointer: --pf-look-x/y on the avatar, -1.35 to 1.35
PfCompanionPlay.laser();        // a red dot and a crosshair; a click sends her after it
PfCompanionPlay.yarn();         // a ball on the page; she rolls on her back with it, then comes home
PfCompanionPlay.zoomies();      // a dash across the whole width, and back
PfCompanionPlay.stop();         // whatever is playing ends; she trots home
PfCompanionPlay.playing();      // the game's name, or null
```

- **Moved, never copied.** While she is out, the avatar itself sits in a fixed
  `.pf-companion-runner` and the dock is left empty, marked `data-pf-companion-away`, at its own
  size. Coming home puts the avatar back in the same place in the dock's markup.
- **A run** sets `data-gait` (`walk`, `trot`, `sprint`, `zoomies`, at their own speeds) and
  `data-facing="right"` when she goes right. The gait is gone when she arrives.
- **The laser** takes clicks for itself, so a click is not a click on the page, except on a
  control (a button, a link, a field, `[data-pf-companion-play-stop]`), which works as ever. A
  click sends her at a sprint, followed by a crouch (`stalking`) and a swat. A badge at the top of
  the screen stops it ("✕ Stop the laser (Esc)", in Greek on a Greek page, or `laser({ stopLabel
  })`), as do Esc, a right click, a double click, 30 seconds without a click, and `stop()`.
- **One game at a time:** starting one ends the other. When a question is asked
  (`pf-assistant:state` `listening`, `thinking` or `talking`), any game ends, so the assistant is
  home to answer.
- With `prefers-reduced-motion` she goes where she is going at once, without running.
- Dispatches `pf-companion-play:start` and `pf-companion-play:stop` with `{ game }`. What starts a
  game is the application's choice: a button, a menu, a word in the chat.
- **Without the framework's dock**, in a component of the application's own (a Svelte SPA):
  `attach(element)` plays with the avatar inside that element, and `state(name)` sets its state
  without pfAssistant. A question asked still ends any game. `detach()` on unmount ends the game
  and puts the avatar back in its place at once. When that place is no longer on the page, the
  avatar is removed together with its runner, so nothing is left on the `<body>`.

### A debug window for whatever is mounted

```js
window.PfCompanionDebug.mount();   // a small floating panel; .unmount() to remove it
window.PfCompanionDebug.mount({ into: document.querySelector('#preview') });   // inside the page instead
```

`pf-companion-debug.js` reads the currently mounted dock rather than knowing about any character:
a larger live copy of its avatar, one button per `pfAssistant` state (the 9, unchanged) to
audition the chat-lifecycle animations a set already has, and a free-text box + Apply calling
`setPose()` with whatever is typed — works for any name, because this file has none of its own.
If the mounted avatar's root element happens to carry `data-poses="sitting,sleeping,walking"` (a
plain comma list — entirely optional, read straight from the DOM, nothing enforces it), the panel
turns it into quick-pick buttons too; absent, the free-text box is all there is. A toggle forces
`pf-companion:activity` active/away to preview idle visuals without waiting out `idleMs`.
The buttons are the framework's (`pf-btn pf-btn-sm`), so a theme's reset does not leave them as
bare text. The one playing now is `aria-pressed="true"` and drawn in the accent: the state the
dock shows at mount (`idle` when it shows none), then the one clicked, then whichever
`pf-assistant:state` names when a state ends by itself. The same goes for the pose picked, and
for the toggle while "away" is previewed. Inside the page (`into`) the panel is still a framed
card on the surface, without the floating shadow, and its preview, like the inline dock, is as
large as the avatar.

Not a route: mount it on whatever `/debug` page an application already has, the same way it
would mount anything else. Nothing here adds a new controller or permission surface.

### The picture in every state, on the AI screen

`/admin/Ai/avatar` (opened as the settings are, and linked from them) shows the set in use,
large (`AvatarSets::render('idle', 160)`), with a button per state, the set's own included, and a
button per pose it declares; a state and a pose play together. The one playing is marked
and follows `data-state`, so `success`, `greeting` and `notify` hand the mark back to `idle` when
they end. Below the states are a box for a pose of any name (`data-pose`) and an Away toggle,
which sets `data-pf-companion-activity="away"` on an inline dock around the picture, as idle
does in a corner. A set that declares its own signals gets a table of them, each with the state
or pose it plays and a button to try it:

```php
'llm' => ['analyst' => ['avatar_set' => [
    'markup' => APP_PATH . '/themes/admin/assets/aria/aria.svg',
    'moods'  => ['calm' => 'idle', 'curious' => 'sniff'],   // or 'avatar_moods' beside a registered set's name
]]],
```

Its acts and wear (`Chat\Acts`) get tables of their own beside the moods. A set that draws gaits
(`'gaits' => ['walk', 'trot', 'sprint', 'zoomies']`, as Tesla's does) gets a button per move, a
"Face right" toggle, and the companion's games (laser, ball of yarn, zoomies), tried on the
preview itself through `PfCompanionPlay.attach()`.

**With personas** (see the Conversations guide), the screen shows one at a time: a link to each,
and `?persona=tesla` draws that persona's set (or its character's), called by its name, with its
states, poses, moods, acts, wear and moves. The default persona is shown first. Without personas
it shows the analyst's set, as before.

A picture drawn for a persona or a character is called by that one's name (`aria-label`), not
the analyst's: `AvatarSets::using($set, $run, $name)`.

The dock, the debug panel and this screen carry the theme's tokens themselves, so they are
drawn the same outside a `.pf-ui` element.

### Two different companions, one floor

An application with its own frontend (a Svelte app, say) is not required to write
`<template data-pf-companion>` at all — `mount({html: ...})` is callable from anywhere, including
a component's own mount hook, and `Assist\AvatarSets::render()` still supplies the markup if the
application wants it. An application that already has a different avatar mechanism of its own —
a plain `<img>`, a hand-built face component — loses nothing by not adopting any of this; none of
it is required to use `AvatarSets` or the chat page's own `pfAssistant.state()`.

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
| `metrics` | the application's [metrics](Pramnos_Metrics_Guide.md), where it declares any: the catalogue, a series by period with filters and a breakdown, a period against the previous or a year earlier, the records behind a number; only the metrics the administrator asking may see |
| `log-analytics`, `log-errors`, `status` | the MCP tools of the same names |
| `user` | one account the administrator names: type, state, dates — its email only with `include_email` |
| `audience_segments`, `audience_rules`, `audience_experiments` | segments with members and movement; rules with status, trigger and how they are doing, one with how many went through each step; A/B tests with their results — offered where their tables are, each behind its screen's ability (`admin.segments`, `admin.rules`, `admin.experiments`) |
| `audience_why` | why one account is or is not in a segment, or would or would not come into a rule — tagged `personal` |
| `audience_draft` | the one tool that writes, and only drafts: a segment comes back as its count and a link that opens the builder filled in, nothing saved; a rule is saved as a draft, which does nothing until an administrator switches it on |
| `conversation_summary` | what was said in a team conversation the person reads — by its id or `#name`, the last hours or what they have not read — summed up as the conversation's own Summary sums it up, with the same rights |
| `visits` | the site's own traffic, where it is counted: a period's views, visits, visitors, signed-in and single-page visits and the mean visit, against the period before; broken down by source, campaign, referrer, page (by a part of its address), click out, country, device, browser or the kind of account; robots and pages without JavaScript apart. `visits.tool` renames it |

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
    'sql_rows'    => fn (\Pramnos\User\User $u): int => $u->usertype >= 99 ? 2000 : 200,  // a number, or per role
    'export_rows' => 20000,  // the same ceiling for export_csv, separately — MAX_ROWS otherwise
    'rounds'      => 20,     // tool rounds before the model must answer with what it has — 16 otherwise
    'schema'      => [       // what the catalogue cannot say
        'orders' => ['about' => 'One customer order.', 'columns' => ['status' => '1 open, 2 paid, 3 refunded']],
    ],
]],
```

**Every one of `sql_rows`, `export_rows`, `rounds`, `subagent_rounds` and `subagent_cost`**
(below) takes a plain number for everybody, or a function of the account asking —
`fn(\Pramnos\User\User $u): int|float` — for a ceiling by role, through one place,
`Analyst\Limits::of()`. A function that cannot be resolved (nothing signed in, say) falls back
to the default rather than failing the read it was meant to narrow.

**Credentials are never read.**
- A column whose name says it holds one (`password`, `token`, `secret`, `api_key`, `salt`,
  `otp`…) or one the application names (`'llm' => ['withheld' => ['devices.devkey', 'pin']]`) is
  withheld, as `[withheld]`, from what `SELECT *` brings: in the rows `sql` answers and in the
  file `export_csv` writes.
- A statement that names such a column, under any alias, is refused before it runs.

**A list goes in an artifact, or a file.** A list someone asks for goes in an artifact
(`save_artifact`, a table of up to 1,000 rows). A longer one, or one whose columns come from the
database rather than from a tool's answer, goes in `export_csv`, which writes it in one SELECT.
Never both for the same rows. The two tools' descriptions say so to the model.

`schema` lists the tables, or one table's columns with their keys, and the application's notes,
keyed without the table prefix. `sql` answers rows. `export_csv` writes them to a file. It
answers with its `link`, and with `xlsx`, the same file as a spreadsheet (`?as=xlsx`). A list of
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
the statement runs through that account, as `db-inspect` does, and the database's own
privileges are the boundary. Without one, `'llm' => ['analyst' => ['replica' => true]]` (FW-496)
reads the database's own replica instead — the same `replica`/`read` settings block the
[Metrics guide](Pramnos_Metrics_Guide.md) already reads through, never a second copy of the
connection details. A replica that does not answer falls back to the database itself and says
so in the log; it is where the read lands, not a privilege boundary, so losing it is not a
reason to refuse the read the way a broken `database_readonly_dsn` is.

Rows go through the redaction like every tool's answer. `sql` and `export_csv` are tagged
`personal`, since a statement can read anybody's row.

## Subagents: a multi-step question handed to a model of its own

Some questions are not one tool call but several reads cross-checked against each other — a
trend across tools, a figure one tool disagrees with another about, a list filtered by
something a second tool knows. `'llm' => ['analyst' => ['subagents' => true]]` gives the model
`deep_analysis`, built on `Pramnos\Llm\Subagent`: a task handed to a model of its own, with
this same answer's own read-only tools — never a tool that writes or is gated, and never
`deep_analysis` itself, which would recurse — its own rounds and its own cost ceiling, separate
from the answer's own:

```php
'llm' => ['analyst' => [
    'subagents'       => true,
    'subagent_rounds' => 8,     // Subagent's own default otherwise
    'subagent_cost'   => 0.50,  // USD per deep_analysis call; unbounded otherwise
]],
```

The subagent sees nothing of the conversation that asked it — only the task, given whole — and
answers once, through the same redaction every tool's answer goes through, tagged `personal`
like `sql` and `export_csv` since its own tools can read anybody's row. It never writes: every
tool it is handed is filtered to the ones with no `writes`/`gated` flag first.

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
