---
use_cases:
  - Giving a team channels and direct messages in the administration — the dock, the basic page, the menu
  - Building an application's own chat client on the conversations' JSON API
  - Posting to a conversation from code or an integration — a chat webhook's call, a Jira card, a mention
  - Deciding who may use the conversations and who reads them all
  - Importing messages from another chat once, without duplicates on a second run
  - Keeping the team talking when the server is down, with a second server as a beacon
---

# Conversations Guide

One engine for people talking in the application: a team's channels and direct messages, and —
on the same tables — support and the site's own messages later. The `conversations` feature
creates its tables:

```php
// app.php
'features'      => ['conversations'],
'conversations' => ['usertype' => 30],   // who may use them; see Access below
```

The administration gets **Conversations** in its menu: a page at `/admin/Conversations` and a dock
over its other pages — see [The page and the dock](#the-page-and-the-dock).

## Conversations and who is in them

```php
use Pramnos\Conversations\Conversations;

$general = Conversations::create('channel', 'General', [], $me, 'For everybody');   // #general
$finance = Conversations::create('private', 'Finance', [$maria], $me);             // members only
$dm      = Conversations::create('dm', null, [$maria], $me);                       // the same one if they have one
$group   = Conversations::create('group', null, [$maria, $nikos], $me);            // three to nine people
$alerts  = Conversations::create('system', 'Payments', [], null);                  // the system writes here

Conversations::join($general, $nikos);          // an open one: a channel, a system one
Conversations::add($finance, $nikos, $maria);   // a closed one or a group: by one of its members
Conversations::leave($finance, $nikos);         // a dm cannot be left
Conversations::notify($general, $nikos, 'mentions');   // all | mentions | none
Conversations::mute($general, $nikos);
Conversations::archive($group);                 // read, no longer written to
```

| kind      | who is in it                    | who reads it                          |
|-----------|---------------------------------|---------------------------------------|
| `channel` | whoever joins                   | everybody who may use conversations   |
| `private` | whoever its members add         | its members                           |
| `dm`      | two people, for good            | the two                               |
| `bot`     | one person, and a named bot that writes to them (`SystemPost`) | that person |
| `group`   | three to nine people            | them                                  |
| `system`  | whoever joins; the system writes | everybody who may use conversations  |

A channel, a private channel and a system one have a unique name for `#` — lower case, words
joined by dashes, in any script (`#γενικά-θέματα`). Its maker is its owner and first member.
`Conversations::forPerson($id)` is a sidebar: the conversations somebody is in, newest first, with
how many messages they have not read and how many of those mention them;
`Conversations::browse($id)` lists the open ones to join.

## Messages

```php
use Pramnos\Conversations\Messages;

$id = Messages::post($general, $me, 'Ο <@48211> ζητά επιστροφή — το βλέπει η <@' . $maria . '>;');
Messages::post($general, $maria, 'Το κοιτάζω', ['thread' => $id]);       // a reply, counted on its root
Messages::post($alerts, null, '', ['card' => ['title' => 'Payment', 'fields' => ['Amount' => '29,90 €']]]);
Messages::edit($id, $me, 'Ο <@48211> ζητά επιστροφή');                   // its writer only; what it said is kept
Messages::delete($id, $user);                                            // its writer, an owner, or root
Messages::react($id, $nikos, ':eyes:');                                  // and false to take it off
Messages::pin($id, $me);

Messages::list($general);                         // the newest top-level messages, oldest first
Messages::list($general, ['before' => $oldest]);  // further back
Messages::list($general, ['after' => $lastSeen]); // what a reconnect missed, replies too
Messages::list($general, ['thread' => $id]);      // a thread: its root and replies
Conversations::markRead($general, $me, $newest);  // never back
```

**History from another system** is written as it was, through the same calls and with no table
touched by the application:

```php
$id = Messages::find('legacy', $channel . ':' . $ts)                  // brought in already?
    ?? Messages::post($general, $author, $text, [
        'quiet'      => true,                                         // nobody told, no mentions
        'source'     => 'legacy', 'externalid' => $channel . ':' . $ts, // the same pair again is this message
        'at'         => $writtenAt, 'editedAt' => $changedAt,
        'authorName' => $author === null ? 'Κώστας Λ.' : null,          // nobody here: shown by name, as somebody who left
        'thread'     => $root, 'files' => [Files::store($general, $uploader, $localCopy, $name)],
    ]);
Messages::react($id, $person, ':tada:', true, $reactedAt, true);   // dated, and quiet
```

A reaction is a `:shortcode:` or the emoji itself; each different emoji is its own reaction, and the
same one twice is one. On MySQL and MariaDB the column is binary for that
(`make_conversation_reactions_emoji_binary`), as the tables' default collation takes every emoji
for every other.

A listed message carries its writer's name, its HTML, its reactions counted with who, its files and
whether it is pinned; a deleted one keeps its place and nothing else — and the page leaves it out,
unless replies hang from it. Writing a message reads
everything before it, for its writer.

**Text** is markdown-lite (`Pramnos\Conversations\Markdown`): `**bold**`, `*italic*`, `~~struck~~`,
`` `code` `` and fenced blocks, `[links](https://…)` and bare addresses, lists, quotes, tables
(a row of `|`-separated cells over `|---|:---:|---:|`, aligned left, centred or right, scrolling
sideways when wider than the message, styled as Ask's) and `:shortcode:` emoji. Everything is escaped first — a writer's HTML is shown as text, and only
http(s) and mailto links are made.

**Mentions.** A person is written `<@id>` — what a composer writes for a chosen name, and what an
import writes — and shown as `@` and their name, so a name can change without a message changing.
`@all` and `@online` mention every member but the writer. Each mention is a row, and an event.
**A mention counts for members only**: somebody not in the conversation is shown by name but is
not told. To reach a person from a system post, join them first — with `notify` set to
`mentions`, so the channel's other messages do not reach them:

```php
Conversations::join($jira, $assignee);
Conversations::notify($jira, $assignee, 'mentions');
Messages::post($jira, null, 'Ανατέθηκε στην <@' . $assignee . '>', ['source' => 'jira', 'externalid' => $eventId, 'card' => $card]);
```

**Cards.** A system message carries a card, drawn under its text:

```php
Messages::post($jira, null, '', ['source' => 'jira', 'externalid' => $webhookId, 'card' => [
    'title'  => 'NAN-6212 · Το link πληρωμής δεν ανοίγει σε iOS',
    'url'    => 'https://example.atlassian.net/browse/NAN-6212',   // the title links here
    'text'   => 'Κατάσταση: In Progress → Code review',
    'color'  => 'warning',                                          // info, warning, success, error, neutral: the left border
    'fields' => ['Κατάσταση' => 'Code review', 'Ανάθεση' => 'Λέλα Δ.'],   // label → value
    'from'   => 'Jira',                                             // its signature; else the source, capitalised; else "System"
]]);
```

**References** `#type:id` — `#user:48211` — are kept with the message (`refs`) for the
application's cards.

**Imports** pass `source` and `externalid` (and `at`): the same message twice is written once, so
an import can run again for what is new.

## Posting from the application — in place of a chat webhook

`SystemPost::post()` takes what a chat webhook takes — a channel, the text, attachments — so code
that posted to another chat posts here by changing the call:

```php
use Pramnos\Conversations\SystemPost;

SystemPost::post('#administration-log', "Νέα επιστροφή:\n**29,90 €** για [την Ελένη](https://admin.example/u/5)", [[
    'title'      => 'Τιμολόγιο 2291',
    'title_link' => 'https://admin.example/invoice/2291',
    'text'       => 'Premium · διπλή χρέωση',
    'color'      => '#D00000',                 // good, warning, danger, or a hex: its tone by hue
    'image_url'  => 'https://cdn.example/receipt.png',
]], ['username' => 'Nannuka Bot', 'source' => 'billing', 'externalid' => 'refund-2291']);

SystemPost::post($userId, 'Σου ανατέθηκε το NAN-6212', [], ['username' => 'Nannuka Bot']);   // one person
```

- **`#name`** is the system channel of that name, made the first time something is posted to it;
  the team joins it as any open channel.
- **A person** — an account id, or `'@id:48211'` — gets their own conversation with the bot, listed
  with their direct messages under the bot's name, one per bot. `'@name'` is looked up by the
  application's resolver, then as a username:

  ```php
  SystemPost::resolvePeople(fn (string $theirName): ?int => $map[$theirName] ?? null);
  ```

  Nobody, or somebody who may not use the conversations, gets nothing: `post()` returns null.
- **The text** is a message's Markdown, as anywhere else; a line break stays one.
- **An attachment** is a card: `title`, `title_link`, `text` (or `fallback`, after `pretext`),
  `image_url`, `fields` as label and value, `color` as a tone. A second attachment is a second
  message.
- **`username`** signs the message. **`source` and `externalid`** make a retry the same message, and
  the same post twice in one request is written once (`SystemPost::reset()` forgets, for a worker).

A bot's messages count as unread like anybody's, but do not open a window in the dock by themselves:
they are notices, not somebody writing.

## References and commands — what the application adds

**References.** `#user:48211` in a message — and whatever else the application declares a
reference, a Jira key, an address of its own — becomes a card under the message, drawn for the
reader:

```php
use Pramnos\Conversations\References;

References::register('user', function (string $id, object $viewer): ?array {
    $user = Users::find((int) $id);
    if ($user === null || !$viewer->mayRead($user)) {
        return null;                                   // the text stays as written; never an error
    }
    return ['title' => $user->fullName(), 'url' => adminUrl('Users/view/') . $id,
        'text' => 'Γονέας · Αθήνα · Premium έως 20/11', 'image' => $user->avatarUrl(),
        'fields' => ['Πληρωμές 30 ημ.' => '2 × 29,90 €'], 'color' => 'info'];
});
References::match('jira', '/\b(NAN-\d+)\b/');                                   // a Jira key
References::match('jira', '#https://[a-z0-9-]+\.atlassian\.net/browse/([A-Z]+-\d+)#');
References::match('user', '#/admin/users/view/(\d+)\b#i');                       // an address
```

A pattern's first group is the id. Patterns are applied as a message is read, so one declared later
finds older messages too. The browser asks for every card on screen in one request
(`/admin/Conversations/refs`) and keeps them for the page; a resolver that throws shows nothing, and
is logged. A card's links are http(s) or this site's paths.

**Commands.** `/name` at the start of a message runs the application's command instead of posting:

```php
use Pramnos\Conversations\Commands;

Commands::register('jira', 'Νέο ζήτημα στο Jira από αυτή τη συζήτηση', function (string $args, int $conversation, object $person, ?int $thread) {
    $issue = Jira::create($args, $conversation, $thread);
    SystemPost::post('#jira', '', [['title' => $issue->key . ' · ' . $issue->summary, 'title_link' => $issue->url]], ['username' => 'Jira']);

    return ['text' => 'Έγινε το ' . $issue->key, 'card' => ['title' => $issue->key, 'url' => $issue->url]];
}, usage: '<τίτλος>');
```

The composer suggests the commands as `/` is typed. A command answers its writer alone — a line, a
card, or nothing — at the foot of the conversation, marked as only theirs; what everybody should
see, it posts itself. An `InvalidArgumentException` it throws is its answer; anything else is logged
and said to have failed. `/shrug`, or anything no command claims, is posted as written.

**Ask.** Where the application has the analyst (the `llm` feature with a key), whoever may ask it
(`admin.ai`, at the analyst's floor) finds it in the conversations two ways. The answers are
written by the queue worker, as every answer of the analyst is, signed "Ask"; a failure says why.
A chart in an answer is drawn as on Ask's page, by `pf-chat.js`, which the page loads beside
`pf-conversations.js` the first time a message holds one. The *Open in Ask* card is stored in English,
because the worker does not know who reads it, and the page translates it into the reader's language.

- **A conversation with Ask.** *New conversation* offers Ask first, as a bot; it opens the person's
  `bot` conversation named "Ask" (`Ask::conversationFor()`, the `withask` endpoint). Every message
  there is a question, answered in the conversation, not in a thread, with the asker's own rights,
  memory and CSV exports, as on Ask's page. It is one analyst conversation, which goes on from answer
  to answer and is the same conversation `/admin/Ai` lists. Each answer carries an *Open in Ask*
  card to it. The page shows *Ask is thinking…* until the answer comes. It is listed with the direct
  conversations.
- **`@Ask` anywhere else** puts the message's question to the analyst, and the answer comes in the
  message's thread. Everybody who reads that conversation reads the answer, so it is given with the
  rights of its least privileged reader (`Ask::readerOf()`): the lowest usertype among its members,
  or, in a channel or system conversation anybody may read, among everybody who may use the
  conversations. It carries nothing of the asker's own — no memory, no CSV file — and the model is
  told which of the asker's tools it may not use there, to say so and suggest asking in the
  conversation with Ask. The cost and the analyst conversation are the asker's.

```
@Ask πόσες νέες εγγραφές γονέων είχαμε χθες στη Θεσσαλονίκη;
```

**Rules.** With the conversations, a rule's step can *Tell the team in a conversation*
(`conversation_post`): a channel `#name` or an administrator's account id, and a text with the
person's placeholders. The message carries the person as a card linked to their page, signed by the
rule's name; a run retried writes nothing new, and a test send goes to whoever asked for it.

## Search

```php
Messages::search($user, 'τιμολόγιο Μαρτίου');            // in everything they read
Messages::search($user, 'τιμολ', $conversationId);      // in one conversation
Messages::search($user, 'τιμολ', null, 30, $maria);     // what one person wrote — no words: their newest
Messages::search($user, '', null, 30, null, $maria);    // what mentions one person
```

Every word must be in the message, each matching at its start (`τιμολ` finds `τιμολόγιο`).
Results are newest first, as a reader sees them, with the conversation's name (`#general`, or a
direct conversation's people). Only what the searcher reads is searched: the conversations they are
in and the open ones — everything for whoever has `admin.chat.all`. Deleted messages are never
found.

Words of three letters or more go through the full-text index — `FULLTEXT` on MySQL and MariaDB,
a GIN index on `to_tsvector('simple', body)` on PostgreSQL. A shorter word (`ΦΠΑ` is three, `σε` is
two) is not in a MySQL index whose `innodb_ft_min_token_size` is 3, so a search with one runs as
`LIKE` over the readable conversations instead.

## Files

A file sent in a conversation is a file on disk under a tree of its own, `var/chat-files` by
default; the database keeps only its path relative to the tree, `YYYY/MM/<id>-<name>`, so the tree
can move to other storage behind a symlink and nothing in the database changes.

```php
use Pramnos\Conversations\Files;

$file = Files::store($general, $me, $_FILES['file']['tmp_name'], $_FILES['file']['name']);
Messages::post($general, $me, 'Το τιμολόγιο', ['files' => [$file]]);   // linked to its message

Files::send($file, $user);          // to a reader of its conversation; false is a 404
Files::send($file, $user, true);    // an image's thumbnail
Files::usage();                     // bytes per conversation and month, the largest, the oldest, the total
Files::prune(365);                  // remove the ones older than a year: ['files' => 41, 'bytes' => 912345678]
Files::prune(0, $conversationId);   // every one of a conversation
```

| setting | where | default |
|---|---|---|
| the tree | `'conversations' => ['files_path' => …]` | `var/chat-files` |
| the largest file | the setting `conversations_max_file_mb` (an administrator's), else `'conversations' => ['max_file_mb' => …]` | 25 MB |

**What may be sent** is decided by what the file is, not what it is called: images (JPEG, PNG, GIF,
WebP), PDF, Word, Excel and PowerPoint, OpenDocument, CSV, plain text and zip. A program renamed
`invoice.pdf` is refused. A name loses any path and any character a disk cannot hold. An image gets
a JPEG thumbnail beside it, 400 pixels on its longest side.

**Sending** is never a public link: `Files::send()` checks that the person reads the file's
conversation, then sends its own type and name, `inline` for an image or a PDF and as an attachment
otherwise, with `X-Content-Type-Options: nosniff`.

**Removing** a file frees its space and keeps its row: the message still lists it, with `removedAt`
set, so a reader sees that a file was there and when it went. A file that cannot be written to disk
leaves no row behind.

## Events

| event | class | when |
|---|---|---|
| `conversation.created` | `Events\ConversationCreated` | a conversation was made |
| `conversation.message.posted` | `Events\MessagePosted` | a message was written, a reply among them |
| `conversation.mention.received` | `Events\MentionReceived` | somebody was mentioned: `person`, `all` or `online` |

Each is a `QueueableEvent`: a queued listener — a notification, a rule, an integration — takes it
in the queue or the event stream like any other.

## Live: what browsers are told

A message is written first and told second, through Broadcasting:

| channel | events |
|---|---|
| `private-conversation.{id}` | `message.posted`, `message.edited`, `message.deleted`, `message.reacted`, `message.pinned` — each with the message as a reader sees it (name, HTML, reactions, files) |
| each member's own (`private-users.{id}`, or `broadcasting.notifications_channel`) | `conversation.activity`: a message in one of their conversations — its ids, its writer, whether it mentions them — for a sidebar's unread and a window that opens |
| `presence-chat` | who of the team is connected, with their name |

The `conversations` feature's provider adds the rules for these channels to the application's
(`broadcasting.channels`): a conversation's channel is heard by whoever reads it, the presence
channel by whoever may use the conversations. **Typing** is a `client-typing` event browsers
whisper on the conversation's channel — turn client events on for the WebSocket daemon
(`'broadcasting' => ['websocket' => ['client_events' => true]]`). A browser that was disconnected
asks for what came after the last message it has, `Messages::list($id, ['after' => $last])`.
Without broadcasting set up nothing is told, and a driver that fails is logged — never the
writer's failure.

## Access

The conversations are an ability, `admin.chat`, not the administration's floor — a team talks
with people who administer nothing. In usertype mode, `'conversations' => ['usertype' => 30]`
sets the floor (the administration's when unset); with permissions, whoever has the ability.
`admin.chat.all` reads every conversation, closed and direct ones too — root's alone in usertype
mode. `admin.chat.files` manages the files' space — a superuser's by default. A deactivated
account has none, at once. `Conversations::canRead()` and `canWrite()`
answer per conversation; writing takes being a member.

**People who stopped signing in.** `'conversations' => ['inactive_after_days' => 90]` makes
somebody inactive who has neither signed in (`users.lastlogin`) nor registered within that many
days; 0, the default, turns it off. Somebody inactive is not offered in *New conversation* or in
an `@` suggestion — unless their whole name or username is typed, or *Show inactive people too* is
ticked — and is not counted in `@all` or `@online`; named with `@`, they are still told. Where they
already are, they are greyed with when they last signed in: in a client's list, among a
conversation's members, and in a direct conversation with them a note above the composer says
they will probably not see it. Nothing is removed — no membership, no message — and signing in
makes them active again at once. `People::inactive($ids)` answers who, with the last sign-in
(0 for never); `People::search($term, $limit, $inactive)` leaves them out unless asked.

## The dock and the basic page

The framework draws two things on the JSON API: **the dock**, over every page of the administration,
and **a basic page**. A full chat application — a sidebar in sections, threads and activity views,
a quick switcher, its own window — is the application's to build on the same API (*The HTTP API*,
below); everything such a client needs from the server is there, and none of it depends on the
framework's own drawing.

**The dock.** The faces of the recent direct and group conversations are stacked above a launcher,
each with what is unread; the launcher, with the count of everything unread (red when something is
for the reader), opens the list — the reader's conversations newest first, a filter by name, a new
conversation (✎), the open channels to join, the application's links, and *Faces above the button*.
A conversation opens in a small window at the foot of the page — its header has the whole page
(↗, where the application has one), minimise and close — and as many windows sit side by side as
the screen fits, three at most, one on a phone. **A new direct or group message opens its window
by itself.** A face is put away with its × (on hover, or after a long press on a touch screen)
until a newer message there, and nothing takes its place. The windows stay as they were from page
to page, kept in the reader's browser.

In a window: Enter sends, Shift+Enter is a new line; `@` suggests the conversation's people and
`@all`/`@online`, `:` suggests emoji; files are picked, dropped or pasted, uploaded at once and sent
with the message; the others see who is typing. A message is reacted to, answered in a thread,
saved, pinned, edited and deleted from the toolbar over it. What is unread is marked with a line,
and reading it tells the server. With a realtime connection everything arrives at once; without
one, the dock asks every few seconds.

A theme puts the dock in its footer — the scaffolded themes do:

```php
<?php echo \Pramnos\Conversations\Dock::render(); ?>
```

It is drawn only inside the administration, for somebody who may use the conversations, and not on
a page whose body has the class `pf-conv-page` (the conversations' own). `'conversations' =>
['dock' => false]` turns it off; `'conversations' => ['usertype' => 99]` keeps the menu item, the
page and the dock to root while the conversations are being tried. It is drawn by
`pf-conversations.js`, built from the Svelte sources in `resources/js/conversations/` and shipped
ready, so an application needs no build of its own; `project:resync --js` adds it to an application
whose `features` list `conversations`, and refreshes it after. What it starts from is
`Conversations\Client::props($me, 'dock')`: who is reading, where the JSON is, their realtime
channel, the largest file, the emoji, the application's links, and every word it shows, translated
(`Client::strings()`).

**The basic page**, `/admin/Conversations`: the reader's conversations with what is unread, one open
(`#/c/12`) with its messages and earlier ones on demand, and a box to write in — live through
`pfRealtime`, or read again every few seconds without it, with the unread count in the window's
title. It is plain JavaScript with no build (`pf-conversations-basic.js`, from `Client::basic($me)`),
dressed by the theme's own cards and buttons: an example of the API and a page to open on a fresh
installation, not a chat application. An application with its own page puts its controller named
`Conversations` in place of it, as for any framework screen, and keeps the class `pf-conv-page` on
its body so the dock stays away.

**An installable app** is the application's too — its manifest and its worker. `Client::app()` reads
`conversations.app` for it: the name, short name, colours and icons, with defaults, or null.

The looks of both are the `pf-conv-*` rules in each theme's `components.css`, from the theme's own
colours, light and dark. An application that compiles `components.css` into a stylesheet of its own
rebuilds that stylesheet after updating the framework.

**Files and space** — `/admin/ConversationFiles`, linked from the dock's list for whoever has
`admin.chat.files` (a superuser's by default): how many files are kept and the space they take, per
conversation and month, the largest and the oldest; the largest file allowed (the setting
`conversations_max_file_mb`); removing the files older than so many days — of one conversation or
all — with a report of how many went and the space freed, or one file at a time. A removed file's
message says it was removed, and when.

**The application's own links** sit at the foot of the dock's list: `conversations.links`, each a
`label` already in the reader's language, a `url` and an `icon`. One is shown to whoever passes its
`ability` (at `usertype`, or the conversations' floor, in usertype mode), or its own `visible`
check, a callable given the reader:

```php
'conversations' => ['links' => [
    ['label' => t('Old messages'), 'url' => adminUrl('Archive'), 'icon' => '⇄', 'ability' => 'admin.archive'],
    ['label' => t('Your import'), 'url' => adminUrl('Import/mine'), 'visible' => fn (object $me): bool => Import::connects($me)],
]],
```

**A person, as a client shows them** behind a name — a card, a profile. `People::profile($person,
$reader)` answers their name, when they last signed in and whether they are inactive, the offset of
their local time where their account names a time zone (`utcOffset`, seconds; null otherwise), the
direct conversation the two have (`dm`) and whether the reader muted it, the conversations both are
in (`shared`), and what the application adds. Somebody who may not use the conversations — history
brought in, an account whose access ended — is still shown to a reader who sees them anyway, in a
conversation the reader reads or to whoever reads them all, with `canMessage` false and no direct
conversation; to anybody else they are nobody (null, and 404 over HTTP).
`Conversations::shared($a, $b)` and `Files::sentBy($person, $reader)` — the files somebody sent, in
what the reader reads — are there on their own too; the HTTP API's `person/{id}` serves all of it.

The application adds to a profile with `conversations.profile`, a callable given the person and the
reader — filter there what this reader may see. `fields` are label and value, `links` each a `label`,
a `url` (http(s) or a path of the site; anything else is dropped) and an `icon`:

```php
'conversations' => ['profile' => fn (int $person, object $reader): array => [
    'fields' => [t('Role') => Staff::role($person)],
    'links'  => AdminAccess::allows($reader, 'admin.users')
        ? [['label' => t('Account in the admin'), 'url' => adminUrl('Users/edit/' . $person), 'icon' => '👤']] : [],
]],
```

## The HTTP API

`/admin/Conversations/…` answers the chat a browser shows, in JSON: behind sign-in and `admin.chat`, and
only where the application lists the `conversations` feature. Every write is a POST carrying the
session's token in `X-CSRF-Token` (or `_csrf_token`) — one without it is refused with 403 and JSON,
whatever its headers say it accepts, never redirected; a body is a form or JSON. A refusal is
`{"error": "…"}` with 403, a mistake — an empty message, a taken name — 422 with its reason, a
conversation or message the person does not read 404.

| action | method | in | out |
|---|---|---|---|
| `conversations` | GET | — | `{me, conversations, browse, people}`: one's own with unread, mentions and a `title`, the open ones to join, the names of the people in direct and group ones |
| `messages/{id}` | GET | `before`, `after`, `thread`, `limit` | `{conversation, members, messages, pinned, saved}` — `saved`, which of these messages the reader kept |
| `threads` | GET | — | `{threads}`: the threads one takes part in, newest reply first — `{root, latest, replies, newReplies}` |
| `activity` | GET | — | `{activity}`: mentions of one, reactions to what one wrote, replies in one's threads — `{kind, at, message, by, byName, emoji}` |
| `unreads` | GET | — | `{unreads}`: every unread message, conversation by conversation |
| `sent` | GET | — | `{messages}`: what one wrote, newest first |
| `saved` | GET | — | `{messages}`: what one kept for later |
| `files/{id}` | GET | — | `{files}`: the files sent in a conversation and still kept, newest first |
| `people` | GET | `q` | `{people}`: who may use the conversations, by a part of the name |
| `person/{id}` | GET | `show` (`messages`, `mentions`, `files`), `q` | `{person}`: as `People::profile()` answers; with `show`, also what they wrote holding the words of `q`, what mentions them, or the files they sent — 404 for somebody the reader does not see |
| `refs` | GET | `keys` (`user:5,jira:NAN-1`) | `{cards}`: each as the application draws it for the reader, or null |
| `command/{id}` | POST | `text`, `thread` | `{reply: {text, card}}`: the command's answer, for its writer alone |
| `search` | GET | `q`, `conversation` | `{messages}` |
| `create` | POST | `kind`, `name`, `topic`, `members[]` | `{id, conversation}` — the conversation as `messages` gives it |
| `join/{id}`, `leave/{id}` | POST | — | `{ok}` |
| `add/{id}` | POST | `person` | `{ok}` |
| `post/{id}` | POST | `body`, `thread`, `files[]` | `{id, message}` |
| `edit/{id}` | POST | `body` | `{message}` |
| `delete/{id}` | POST | — | `{ok}` |
| `react/{id}` | POST | `emoji`, `on` | `{ok}` |
| `pin/{id}` | POST | `on` | `{ok}` |
| `read/{id}` | POST | `message` | `{ok}` |
| `notify/{id}` | POST | `notify` (`all`, `mentions`, `none`), `muted` | `{ok}` |
| `star/{id}` | POST | `on` | `{ok}` |
| `save/{id}` | POST | `on` | `{ok}` — a message kept for later, or let go |
| `upload/{id}` | POST | `file` (multipart) | `{id, name, size, mime}` — then `post` with `files: [id]` |
| `file/{id}` | GET | `thumbnail` | the file, to a reader of its conversation |

An application's own controller named `Conversations` takes precedence, as for any framework
screen. `/admin/Chat/…` reaches the same screen (`Controllers\Chat` extends it) — unless the
application has the AI chat page, whose site controller is also called `Chat`.

## Bringing history in from another system

Bringing another system's history in is the application's: it reads that system, maps its people
and conversations to the ones here, and keeps what it needs for that in tables of its own. It
writes through the public API, so nothing here depends on where the history came from:
`Messages::find()` and `post()` with `source`, `externalid`, `at`, `editedAt`, `authorName` and
`quiet`; `react()` dated and quiet; `Files::store()` for a file; `Conversations::create()`,
`enroll()`, `markRead()` and `archive()` for the rest (see *Messages*, above). Written once per
`source` and `externalid`, an import run again brings only what is new.

## When the server is down — the beacon

The conversations are where a team talks when something is wrong, so they must not go down with the
server they run on. A **beacon** — a second server with the same code, PHP and Redis, and no
database of its own — keeps an emergency channel and direct messages going while the primary does
not answer, and gives them back when it does.

**What a person sees** is the application's client to draw. Every day the primary answers, a
client asks for a key for the beacon (`emergencykey`, below) and keeps it. When the primary stops
answering — three requests in a row with no answer, or a proxy's 5xx — the client turns to the
beacon with that key: the emergency channel and a direct conversation with each of the team, text
and reactions only, through the beacon's own paths below; it asks the primary again every twenty
seconds, and is itself again when it answers. The dock does not do this; the server side — keys,
the beacon, the sync and the import — is all here.

**Setting it up** — the same `app/beacon.php` on both servers, each with its role:

```php
return [
    'role'           => 'primary',                        // 'beacon' on the beacon
    'secret'         => getenv('BEACON_SECRET'),          // primary ↔ beacon: sync, export, ack
    'watcher_secret' => getenv('BEACON_WATCHER_SECRET'),  // the outside watcher: health, alert, drill
    'signing_key'    => getenv('BEACON_SIGNING_KEY'),     // the primary only
    'kid'            => '2026-10',
    'public_keys'    => ['2026-10' => '…'],               // the beacon: every key id it accepts
    'beacon_url'     => 'https://beacon.example.com',     // the primary syncs to it
    'urls'           => ['https://chat.example.com', 'https://beacon.example.com'],  // browsers try these, in order
    'origins'        => ['https://www.example.com', 'https://chat.example.com'],     // CORS on the beacon
    'redis'          => ['host' => '127.0.0.1'],
    'daemon'         => ['host' => '127.0.0.1', 'port' => 6001],  // the primary's health
    'settings_file'  => 'app/config/settings.php',        // its database; else app/config/ or app/settings.php
    // 'db_check'    => fn (): bool => MyDb::ping(),       // or the application's own check
    'channel'        => 'έκτακτο',
    'drill_url'      => 'https://watcher.example.workers.dev/drill',
    'page'           => 'https://www.example.com/admin/Conversations', // where the push takes the team
    'words'          => ['down' => '⚠ Δεν απαντάμε από τις %s: %s.', 'back' => '✓ Απαντάμε ξανά από τις %s, μετά από %s λεπτά.',
                         'title' => 'Δεν απαντάμε', 'body' => 'Από τις %s — %s. Άνοιξε το #έκτακτο.'],
];
```

`./pramnos conversations:beacon keys` makes the signing pair: the private half to the primary's
`signing_key`, the public half to the beacon's `public_keys`. Both servers serve `/beacon/*` from
`www/beacon.php` — copy `scaffolding/templates/beacon.php.stub` and route `^beacon/` to it before
the application's front controller, letting the `Authorization` header through. It runs without
the application and its database, so it answers when they do not. The constants the
application's own autoloaded files need and `beacon.php` does not define — it defines `ROOT`, `DS`
and `LOG_PATH` — go in `app/beacon.bootstrap.php`, which it requires first when it is there; not the
application's whole settings file, which would define those again. The beacon needs the same VAPID key files as the primary
(`var/keys/vapid…`) to push.

**Keys.** A device's emergency key is the person, the device, an id and thirty days, signed with
Ed25519 by the primary (`GET /admin/Conversations/emergencykey`, renewed daily). Only the primary
holds the private key, so only it makes keys; the beacon holds the public ones and checks them. A
primary without a usable `signing_key` is no beacon to the browsers (`Beacon\Config::ready()`):
they are not told of one, and `emergencykey` answers 404 — set the key, and they are. Every
minute the primary tells the beacon who is on the team (`conversations:beacon sync`, scheduled), so
somebody deactivated is refused within a minute whatever their key says. Key ids let a key be
replaced: a new `kid` on the primary, both public keys on the beacon until the old keys expire.

**Requests between servers** carry `X-Beacon-Time` and `X-Beacon-Signature: sha256=` the HMAC of
the time, a line break and the body, with `secret` or `watcher_secret`; five minutes either way.
The watcher's secret reads health and raises alarms — it cannot sync or export.

| path (`/beacon/…`) | who | does |
|---|---|---|
| `GET health` | the watcher | on the primary `{ok, db, redis, daemon, queue_lag}`, 503 when the database or the daemon is down; on the beacon `{ok, role, primary, since, pending, last_sync_at, people}` — a `last_sync_at` minutes old is a beacon nobody can reach |
| `POST alert` | the watcher | `{down: ["site", …], since}`: once per outage, a push to the team and a line in the emergency channel; `{down: []}`: back, and how long it was down |
| `POST sync` | the primary | the team, their push subscriptions |
| `POST session`, `GET`/`POST messages`, `POST react` | a browser, `Authorization: Bearer <key>` | the emergency channel and the person's direct messages; a message once per `client_id` |
| `GET export`, `POST ack` | the primary | what was written, taken back and acknowledged |

**Afterwards.** `conversations:beacon import` (scheduled) takes what was written into the
conversations here — the emergency channel into its system channel, with its writers and those who
reacted joined to it, direct messages into the two people's own — each message once, then
acknowledges it so it leaves the beacon's log.

**Drills.** `./pramnos conversations:beacon test <account>` sends that person's open page to the
beacon for five minutes, and calls the watcher's `drill_url` so the whole path — the proxy in front
included — is tried. Once a month, so it is known to work before it is needed.

The watcher itself — checking both servers every minute, raising `alert`, and sending
`chat.example.com` to whichever answers — is the application's, outside both servers.

### Opening cold

A browser that has never had the page open since the server went down still has nothing to draw.
An installable app — the application's manifest and its worker, keeping the page and opening it
cold on the beacon — is how a client survives that; `Client::app()` gives it the name and icons
from `conversations.app`. The push the beacon sends when the server goes down carries `page`,
where the application wants the team taken.

## Storage

The tables are `conversations`, `conversation_members`, `conversation_messages`,
`conversation_message_edits`, `conversation_reactions`, `conversation_mentions`,
`conversation_pins` and `conversation_files`, all declared personal data. Messages are kept for
good: on TimescaleDB `conversation_messages` is a hypertable on `created_at`, its chunks compressed
after 90 days and never dropped (`HypertableRegistry`, overridable in app.php's `hypertables`).
