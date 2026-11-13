---
use_cases:
  - Giving a team channels and direct messages in the administration — the dock, the basic page, the menu
  - Building an application's own chat client on the conversations' JSON API
  - Posting to a conversation from code or an integration — a chat webhook's call, a Jira card, a mention
  - Deciding who may use the conversations and who reads them all
  - Importing messages from another chat once, without duplicates on a second run
  - Showing a link's preview card under a message, or turning previews off for some sites
  - Letting people send GIFs from Giphy or Klipy without the key reaching the browser
  - Keeping the team talking when the server is down, with a second server as a beacon
  - Renaming, describing, archiving or deleting a channel, and managing its members and their roles
  - Listing the links and files said in a channel, a page at a time
  - Adding tabs to a channel, or a canvas — a shared document edited one person at a time
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
over its other pages — see [The dock and the basic page](#the-dock-and-the-basic-page).

## Conversations and who is in them

```php
use Pramnos\Conversations\Conversations;

$general = Conversations::create('channel', 'General', [], $me, 'For everybody');   // #general
$finance = Conversations::create('private', 'Finance', [$maria], $me);             // members only
$dm      = Conversations::create('dm', null, [$maria], $me);                       // the same one if they have one
$notes   = Conversations::create('dm', null, [], $me);                           // one's own: one each, one's notes
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
| `dm`      | two people, for good — or one, their own | the two — or the one          |
| `bot`     | one person, and a named bot that writes to them (`SystemPost`) | that person |
| `group`   | three to nine people            | them                                  |
| `system`  | whoever joins; the system writes | everybody who may use conversations  |

A channel, a private channel and a system one have a unique name for `#` — lower case, words
joined by dashes, in any script (`#γενικά-θέματα`). Its maker is its owner and first member.
`Conversations::forPerson($id)` is a sidebar: the conversations somebody is in, newest first, with
how many messages they have not read and how many of those mention them;
`Conversations::browse($id)` lists the open ones to join. A client asks for the sidebar every few
seconds, so it is read in a few queries however many conversations there are: where every
conversation's last read message stands, in one; the unread, counted only in the conversations
whose newest message stands after what was read, in one; the mentions in one; a direct or group
conversation's members in one. `Conversations::counts($id, $person, $after)` gives the same two
numbers for one conversation.

**A group's name.** A group conversation may have a name of its own, up to 80 characters,
neither unique nor a `#` name: `create('group', 'Sales shift', …)`. Its title is then that name;
without one, its title is its members, as before. The sidebar gives every direct and group
conversation `membersTitle` too, so a search can find a named group by its people as well. Any
member renames it with `Conversations::rename($id, $name, $by)` (`rename/{id}`, POST `name`), and
an empty name takes the name away. Somebody not in it is refused (403).
- **What a rename leaves.** A line in the conversation says who renamed it and to what. It is
  shown at once and notifies nobody (`Messages::post(…, ['notice' => true])`).
- **Who is told.** Each member's sidebar is told the new title (`conversation.changed`, with
  `name`).
- **History.** The changelog keeps `conversation.renamed`, with `from` and `to`.
- **A direct conversation** has no name: one given at its making is dropped, and a rename is
  refused.

**Starred, in one's own order.** `Conversations::star($id, $me)` stars a conversation for its
reader; `star($id, $me, true, $before)` places it before another of their starred ones, and
`Conversations::starOrder($me, [$c, $a, $b])` writes their whole order at once — a conversation not
theirs, or not starred, is let be; one not listed has no place. Unstarred, it loses its place.
`forPerson()` gives each starred one its `starOrder` (null where not placed), the same on every
device.

A dm of one person is their own, as Slack keeps one for each: notes, drafts, links kept for later.
There is one per person — made by them or for them, `Conversations::dmBetween($me, $me)` finds it —
and what they write there is never unread and tells nobody, a mention of themselves or `@all`
included. The sidebar names it after its reader with "(you)" beside the name; in the dock it is
started from *New conversation* by choosing oneself, which is never one of a group.

## Messages

```php
use Pramnos\Conversations\Messages;

$id = Messages::post($general, $me, 'Ο <@48211> ζητά επιστροφή — το βλέπει η <@' . $maria . '>;');
Messages::post($general, $maria, 'Το κοιτάζω', ['thread' => $id]);       // a reply, counted on its root
Messages::post($general, $maria, 'Έγινε', ['thread' => $id, 'broadcast' => true]); // ...and sent to the conversation too
Messages::post($alerts, null, '', ['card' => ['title' => 'Payment', 'fields' => ['Amount' => '29,90 €']]]);
Messages::edit($id, $me, 'Ο <@48211> ζητά επιστροφή');                   // its writer only; what it said goes to the changelog
Messages::edits($id);                                                     // what it said before each edit, while the changelog keeps it
Messages::delete($id, $user);                                            // its writer, a moderator (owner, admin, admin.chat.moderate), or root
Messages::react($id, $nikos, ':eyes:');                                  // and false to take it off
Messages::pin($id, $me);

Messages::list($general);                         // the newest top-level messages and replies sent here too, oldest first
Messages::list($general, ['before' => $oldest]);  // further back
Messages::list($general, ['after' => $lastSeen]); // what a reconnect missed, replies too
Messages::list($general, ['thread' => $id]);      // a thread: its root and replies
Messages::around($general, $id);                  // opened at a message: the stream around it, with older/newer
Conversations::markRead($general, $me, $newest);  // never back
Conversations::markUnread($general, $me, $id);    // unread again from that message on (a reply: from its root)
Conversations::counts($general, $me, $lastReadId); // ['unread' => …, 'mentions' => …] after a message
```

**Messages stand by when they were written, then by id** — not by id alone. Every list, the
`before` and `after` cursors, a thread's replies, search, unread counts and the conversation's
newest message (`lastMessageId`) follow that order, so history brought in later, or a dm merged
into another, sits where it belongs. A cursor stays a message's id: the server reads where that
message stands, and two messages of the same second are told apart by id, so a page never skips or
repeats one. What somebody has read is a message too: history written before it is not unread,
and `markRead()` never moves back, by the same order. The index behind it is
`idx_conversation_messages_time` (`conversationid, created_at, messageid`).

**Opening at a message.** A link to a message, or a to-do from one, opens its conversation there:
`Messages::around($id, $messageId, $limit)` gives about half the page up to the message and the
rest after it, with `older` and `newer` saying whether there is more each way — the client then
pages with `before` and `after` as usual. A thread's reply is shown where its thread starts, and
`around.threadRootId` names that start, so the client opens the thread too. A message that is
another conversation's, deleted, or none is no anchor: `around()` answers null, and
`list($id, ['around' => …])` and the endpoint answer the newest instead.

Code that orders or narrows by it uses `Messages`: `position($id)` reads where a message stands,
`positions($ids)` many of them in one query, `standsAfter($a, $pa, $b, $pb)` compares two whose
positions are already read, and `beyond($query, $id)` — or `beyondPosition($query, $id, $position)`
with the position in hand — narrows a query to the messages after (or before) one.

On TimescaleDB the messages are a hypertable in 30-day chunks. A query with no time in it is
planned over every chunk, so history imported from years back makes each one dearer; an
installation with little traffic and a long history can take larger chunks: in `app.php`
(`'hypertables' => ['chat.conversation_messages' => ['chunk_interval' => '1 year']]`) where the
table is still to be made, and on one already made with
`SELECT set_chunk_time_interval('chat.conversation_messages', INTERVAL '1 year')`, which sizes the
chunks made after it — the ones there keep theirs.

A client that keeps its own timeline does the same: sort by `at`, then `id` — `byPosition()` in
`resources/js/conversations/logic.js`, which `upsert()` uses — so a message arriving for the past
goes to its place, not to the end; and start the unread line after the position of the
`lastReadId` message, not at the first higher id. `after` with the newest message you hold fetches
what came after it; history added before it appears on the next full load.

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

**Somebody with no account here** — a colleague who left — is kept in a direct or group
conversation by name, so its history is not lost:

```php
$kostas = ['name' => 'Κώστας Λ.', 'externalid' => 'slack:U123'];   // externalid optional
$dm     = Conversations::create('dm', null, [], $eleni, '', [$kostas]);              // one account and them
$group  = Conversations::create('group', null, [$maria], $eleni, '', [$kostas]);     // 1+ accounts, 3..GROUP_MAX in all
Conversations::dmWithFormer($eleni, 'slack:U123');   // found again — by their id there, else by name
Messages::post($dm, null, $text, ['quiet' => true, 'former' => 'slack:U123']);   // written with their name
```

Creating the same dm again returns the one there is. Such a member is `participanttype = 'former'`
and never reads — no unread, no notifications, no read marker — and is not among
`Conversations::members()`; `Conversations::formers($ids)` lists them, `{former: true, name}`. They
are in the conversation's title, and the HTTP API gives them as `formers` beside `members` on the
sidebar and with the messages. The dock shows them greyed, *Deleted profile* on hover, with no card
or profile to open and nobody to write to; the account in the conversation reads and writes it as
notes.

When such a person turns out to have an account, `Conversations::claimFormer('slack:U123', $userId, $by)`
puts the account in their place wherever they are kept by that id, read up to the newest message,
and makes what they wrote the account's. Where the account is in that conversation already, the
former member just goes; a dm that now duplicates one the two accounts have is merged into the
older one — its messages, mentions, pins and files; a group stays a group. It returns
`['conversations' => …, 'messages' => …, 'merged' => …]`, records `conversations.former_claimed` in
the audit log with `$by`, and tells each member `conversation.changed` `{conversationId, mergedFrom}`
on their own channel, for the sidebar to read again. Calling it again does nothing. A merged
conversation lists by when each message was written, so the moved messages interleave with the
older dm's.

A reaction is a `:shortcode:` or the emoji itself; each different emoji is its own reaction, and the
same one twice is one. On MySQL and MariaDB the column is binary for that
(`make_conversation_reactions_emoji_binary`), as the tables' default collation takes every emoji
for every other.

**A reply also sent to the conversation** (`broadcast`, only with `thread`) stays in its thread and
is listed with the conversation's own messages too, in order, carrying `broadcast` and a
`rootPreview` — the root's id, writer and first words — for a client to show what it answers. It
counts as unread like the conversation's own messages and is the conversation's newest, and it is
told as any message is, `broadcast` marked on the members' `conversation.activity`. The dock shows
it with *Replied to a thread:* above it, which opens the thread.

A listed message carries its writer's name, its HTML, its reactions counted with who, its files and
whether it is pinned; a deleted one keeps its place and nothing else — and the page leaves it out,
unless replies hang from it. Writing a message reads
everything before it, for its writer.

**Text** is markdown-lite (`Pramnos\Conversations\Markdown`): `**bold**`, `*italic*`, `~~struck~~`,
`++underlined++` (not against a letter, so `C++` stays text),
`` `code` `` and fenced blocks, `[links](https://…)` and bare addresses, lists, quotes, tables
(a row of `|`-separated cells over `|---|:---:|---:|`, aligned left, centred or right, scrolling
sideways when wider than the message, styled as Ask's) and `:shortcode:` emoji. Everything is escaped first — a writer's HTML is shown as text, and only
http(s) and mailto links are made.

A table **drawn with lines** — a terminal's or a model's `┌─┬─┐ │ a │ b │ └─┴─┘`, or ASCII's
`+---+ | a |` — shows as the same table, wherever the message came from (pasted, an analyst's
answer, the API, an import). A block of only rules and lines of cells is one when it has a rule and
two lines of cells; the text around it stays as it is, and one inside a fenced block stays code. Its
columns part where the rule crosses, so a `|` written in a cell stays in it; a line not as long as
the rule is split at its bars instead. The first row is the heading, a row drawn over several lines
is one row, and a table ruled only under its heading has a row a line.

**Mentions.** A person is written `<@id>` — what a composer writes for a chosen name, and what an
import writes — and shown as `@` and their name, so a name can change without a message changing.
Each mention is a row, and an event.

**Handles** mention many at once, as Slack's (`Pramnos\Conversations\Handles`). None tells the
writer, nor somebody inactive (see [Access](#access)):

| Handle | Who is told |
|---|---|
| `@here` (`@online`) | the members connected now, but those whose notifications are paused |
| `@channel` (`@all`) | every member, connected or not |
| `@everyone` | in the general conversation, everyone who may use the conversations, members or not; elsewhere it is `@channel` |
| `@sales`, any group's name | the group's people who are members of the conversation |

```php
'conversations' => [
    'everyone_channel' => 'general',                                     // the general conversation: its slug or its id
    'groups'           => ['sales' => fn (): array => Staff::ids('sales'), 'admins' => [2, 7]],
    'quiet'            => fn (array $ids): array => Status::paused($ids), // of these, who is not to be told now
    'handles_may'      => ['everyone' => 97, 'channel' => fn (object $user, array $conversation): bool => …],
],
```

**Who may use a handle** is `handles_may`, by handle — `here`, `channel`, `everyone`,
`group:<name>`, or `groups` for every group: a usertype floor, or a callable given the writer's
account (`userid`, `usertype`, `active`) and the conversation. A handle with no rule is anybody's.
A conversation's owner may always use `@here` and `@channel` in it, and a system post any handle.
A handle its writer may not use stays in the message as text and tells nobody, as Slack does
(`Handles::may()`, `Handles::permitted()`).

*Connected now* is the presence channel (`presence-chat`), asked of the broadcasting server through
its HTTP API (`Pramnos\Broadcasting\Http\ApiClient`): `broadcast:serve` with `http_api.enabled`,
or Pusher. Set `broadcasting.http_api.url` when the application reaches the daemon at another
address than browsers do (`http://127.0.0.1:6001`). Where it cannot be asked, `@here` tells every
member but the paused. `@channel` is one mention row for all the members; the other handles a row
for each person told. The event's `kind` is `person`, `all` (`@channel`, `@all`), `here`,
`everyone` or `group`.

A composer asks before telling many: `GET Conversations/reach/{id}?body=…` answers how many a
message's handles would tell (`Handles::reach()`), and each conversation's messages come with
the `handles` its composer offers the reader — `@everyone` in the general conversation alone, and
only the handles the reader may use (`Handles::offered($id, $reader)`).

**A mention by name counts for members only**: somebody not in the conversation is shown by name but is
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
    'title'  => 'TICKET-6212 · Το link πληρωμής δεν ανοίγει σε iOS',
    'url'    => 'https://example.atlassian.net/browse/TICKET-6212',   // the title links here
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

**A source that is not conversation.** A reminder a bot posts, say, is a message, but not one that
should sit among a person's unread like a colleague's words. The application names those sources:

```php
'conversations' => ['quiet_sources' => ['todo']],
// SystemPost::post($to, 'Υπενθύμιση: γάλα', [], ['source' => 'todo', 'externalid' => 'todo:' . $id]);
```

`unreads` leaves their messages out and does not count them, and does not list a conversation that
has only those unread. `activity` leaves them out too: a reminder stays in its conversation, and
the bell and the to-do list are where it is told. The conversation's own unread count in the sidebar still counts
them: the message is still there to be read.

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
]], ['username' => 'Support Bot', 'source' => 'billing', 'externalid' => 'refund-2291']);

SystemPost::post($userId, 'Σου ανατέθηκε το TICKET-6212', [], ['username' => 'Support Bot']);   // one person
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
- **Who signs it**: `username` is the name over the message, and `avatar` — an http(s) address or
  a path of this site — the picture beside it in place of the system's ⚙
  (`['username' => 'Naya', 'avatar' => '/img/naya.svg']`). Kept on the card as `from` and
  `fromAvatar`.
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
  to answer and is the same conversation `/admin/Ai` lists. If that analyst conversation has been
  deleted, the next question starts a new one. Each answer carries an *Open in Ask*
  card to it. The page shows *Ask is thinking…* until the answer comes — the analyst's own name and
  picture, where the application gives it one. It is listed with the direct conversations.
- **`@Ask` anywhere else** — or the analyst's own name and mentions (`llm.analyst.name`,
  `mentions`; see the LLM guide) — puts the message's question to the analyst, and the answer comes in the
  message's thread. Everybody who reads that conversation reads the answer, so it is given with the
  rights of its least privileged reader (`Ask::readerOf()`): the lowest usertype among its members,
  or, in a channel or system conversation anybody may read, among everybody who may use the
  conversations. It carries nothing of the asker's own — no memory, no CSV file — and the model is
  told which of the asker's tools it may not use there, to say so and suggest asking in the
  conversation with Ask. The cost and the analyst conversation are the asker's.
- **An answer in a thread is seen in the conversation too** when the question is still its newest
  message — nobody has written since — as a reply sent to the conversation (*Replied to a thread*).
  `'llm' => ['analyst' => ['thread_broadcast' => 'if_last']]` is the default; `'always'` and
  `'never'` do as they say.
- **While the analyst answers**, the people there see *… is thinking* — in the thread, or in the
  conversation for its own — told by `assistant.thinking` and let go by `assistant.done`.

```
@Ask πόσες νέες εγγραφές γονέων είχαμε χθες στη Θεσσαλονίκη;
```

**Rules.** With the conversations, a rule's step can *Tell the team in a conversation*
(`conversation_post`): a channel `#name` or an administrator's account id, and a text with the
person's placeholders. The message carries the person as a card linked to their page, signed by the
rule's name; a run retried writes nothing new, and a test send goes to whoever asked for it.

## Link previews

A link in a message gets a card under it, as in Slack: the site and its icon, the title, a few
words, a picture — and, for YouTube, Vimeo and Loom, a player that opens in place.

```php
'conversations' => ['unfurl' => false],                                     // no previews
'conversations' => ['unfurl' => ['deny' => ['ads.example']]],               // not these sites
'conversations' => ['unfurl' => ['allow' => ['youtube.com', 'vimeo.com']]], // only these
```

Writing or editing a message keeps its links on it (`Unfurls::urls()`): http(s), at most three,
each once, none inside code, none that one of the application's `References::match()` patterns
claims, and none to the application's own address. Reading them is the queue's
(`conversation_unfurl`, answered by the worker with no registration), so a message is never held
up by somebody else's site. History brought in quietly (`quiet`) keeps its links too, so a
reader who opens an imported thread later still gets a card; the rest of `quiet` (no live
update, no mentions) is unaffected.

The worker reads each link through `OutboundUrl::fetch()` — public addresses only, five seconds, a
megabyte of page — and takes, in order: the site's oEmbed (YouTube, Vimeo, Loom, X, SoundCloud,
Spotify, TikTok), the oEmbed a page names in its `<head>`, then its OpenGraph and Twitter card and
`<title>`, read in the page's own charset. A title that is only the link — what a consent wall
shows a robot — counts as nothing. What a link shows is kept in `pramnos.link_previews` and read
again after a week (`Unfurls::FRESH`), so the same link in many messages is fetched once. The
picture and the icon are kept beside the conversations' files (`previews/`) and served from here
(`preview`), so a reader's browser never asks the other site. When the cards are ready the
conversation is told `message.unfurled`.

A message gives its cards as `unfurls`:

```json
[{"url": "https://www.youtube.com/watch?v=…", "title": "…", "description": "…", "site": "YouTube",
  "author": "the channel", "icon": "/admin/Conversations/preview?name=….png",
  "image": {"url": "/admin/Conversations/preview?name=….jpg", "width": 480, "height": 360},
  "embed": {"provider": "youtube", "id": "…", "aspect": 1.7778}}]
```

`embed` names the video, never the site's own markup: a client builds the player from it —
`playerUrl()` in the dock's `logic.js` gives YouTube's cookie-free player and Vimeo's without
tracking. Its writer takes a card off a message (`unfurl/{id}` with `url`, `Unfurls::hide()`), and
an edit keeps it off.

`conversations:unfurls-prune` drops a preview row not read again past `conversations.unfurl.keep_days`
(30, by default — a cache, so it prunes even unconfigured), and the picture files no remaining
row still names. Scheduled daily by the framework; `Unfurls::prune()` is the static it calls.

## The team's own emoji

Besides the standard shortcodes (`Markdown::EMOJI`), a team can add emoji of its own, as Slack
does: a picture under a name, written `:name:` in a message and used as a reaction.

```php
use Pramnos\Conversations\CustomEmoji;

CustomEmoji::add('team-heart', $_FILES['emoji']['tmp_name'], $adminId);
CustomEmoji::alias('our-love', 'team-heart', $adminId);   // another name for the same picture
CustomEmoji::rename('team-heart', 'our-heart', $adminId); // its aliases follow
CustomEmoji::remove('our-heart', $adminId);               // its aliases and picture go too
CustomEmoji::all();                                       // name => picture address, aliases included
Messages::react($id, $maria, ':our-heart:');
```

- **Names** are 2 to 32 characters from `a-z 0-9 _ + -`. A name that is a standard shortcode, or
  that is already taken, is refused. The exceptions are the shortcodes in `Markdown::REPLACEABLE`
  (`:kappa:`). A picture under one of those names is shown in place of its emoji.
- **Defaults of the application's own.** These are available everywhere without an upload:
  `'conversations' => ['emoji_defaults' => ['kappa' => '/path/to/kappa.png']]`, name => a picture
  on the server. A default cannot be renamed or removed, because its settings say it is there. If
  the team adds its own picture under the same name, the team's picture wins.
- **`:kappa:`** is a standard shortcode: 😏 until a picture is given, through a default or by the
  team. The framework ships no picture for it, because Twitch's Kappa is Twitch's own.
- **Pictures** are PNG, GIF, WebP or JPEG, never SVG, which can carry a script. They may be no
  larger than a file sent in a conversation, and are kept at most 128 pixels a side with their
  proportions and transparency. A GIF is made smaller frame by frame where the server has the
  Imagick extension, so its animation stays; without it, the GIF is kept as it came. A screen that
  uploads them can crop the picture square in the browser (`\Pramnos\Html\ImageUpload` with
  aspect 1), but not for a GIF, because a canvas would stop the animation.
- **Rendering.** `:name:` is drawn as `<img class="pf-conv-custom-emoji" alt=":name:">`. Code is
  left alone, and a name with no emoji stays as its text. Messages are drawn when they are read,
  so an emoji that is removed or renamed reads as `:name:` text wherever it was written. Its
  reactions are kept as that text.
- **Reactions.** `react()` takes an emoji, a standard `:shortcode:`, or the `:name:` of an emoji
  that exists, and nothing else.
- **Serving.** Pictures are served by `Conversations/emoji?name=` to whoever may use the
  conversations. They are cached for a year, because a new picture gets a new address.
- **The client.** The dock's props carry `customEmoji` (name => address) and `manageEmoji`. Each
  change is told on the presence channel as `emoji.changed`, with the whole new list, so open
  pages update without a reload. In the dock, a `:word` suggests the team's own emoji first, with
  their pictures, and leaves out a shortcode of the same name. The emoji picker has a group of
  them that inserts `:name:`, for a message or a reaction. Reactions are drawn with the picture.
  `logic.js` exports `reactionGlyph()`, `suggestEmoji()` and `customEmojiHtml()` for a page of the
  application's own.
- **The focus.** The dock gives the focus to a window's box only when the person opens that
  window: a click on a conversation or its head, or opening a minimised one. Windows that a new
  page restores, or that open when a message arrives, do not take it. No conversation takes the
  focus after loading while the person is in a field of the page outside the chat, such as an
  input, a text area, a choice or an editable region (`mayTakeFocus()` in `logic.js`).
- **The emoji picker** (`EmojiPicker.svelte`) has a search at the top. It finds a shortcode or
  any emoji by its English name (fire, 🔥), and Enter takes the first. Its tabs are the person's
  frequent emoji (kept in the browser, or a default thirty), eight categories, and the team's own.
  The arrow keys move between emoji. Props: `custom` (`false` leaves the team's own out, for a
  status), `shortcodes`, `anchor`. Its data, `searchEmoji()`, `frequentEmoji()` and
  `rememberEmoji()` are in `emoji-data.js`, an ES module that a chat page of the application's
  own imports from `vendor/` too.
- **The picture viewer** (`lightbox.js`). A message's picture opens large over the page, with a
  blurred backdrop and a dark bar: its name on the left, then the zoom group, Download and New tab
  as icon buttons, and ×. In a narrow window the name is cut before the actions wrap. It closes with ×, Escape or a click outside,
  and a Ctrl, ⌘ or middle click still opens a tab. It zooms with the wheel around the pointer,
  with a double click or double tap (fitted ↔ 1:1, or 200% for a small picture), with two fingers,
  with the bar's − / percentage / + and with the keys + − 0, from fitted to 800%. A zoomed picture
  is dragged to pan. `installLightbox(root, labels)` catches the clicks under an element, and
  `openImage(href, name, {labels, src})` opens one, where `src` is a picture already in hand,
  such as a desktop application's blob URL. The zoom's arithmetic (`fitScale`, `zoomAt`,
  `clampPan`, `toggleScale`) is in pure functions. It is plain DOM, so a chat page of the
  application's own imports it from `vendor/` too.
- **Pop-ups close by themselves.** The GIF picker, the `@`/`:` suggestions and the dock's list
  close on a press outside them or on Escape (`dismiss.js`, a Svelte action that an
  application's own pop-ups can use too). Their own button, marked `data-pf-toggle`, opens and
  closes them itself. The GIF and emoji pickers are never open together.
- **The pickers.** A message's reaction picker floats over the page beside its button, opening
  upward where there is no room below, so the message list neither clips it nor scrolls. Scrolling
  closes it, and a click outside closes any picker.
- **Permissions.** Whoever uses the conversations may use the emoji. Managing them takes
  `admin.chat.emoji` (`Access::managesEmoji()`), which only a superuser has by default. Each change
  is written to the audit log (`conversations.emoji_*`).

The table is `chat.conversation_emoji`, created by the migration `create_conversation_emoji_table`.

## A channel's details

`Pramnos\Conversations\Details` keeps what Slack's "Channel details" shows: the name, the topic and
description, the members and their roles, private or public, archived, deleted for good, the
channel's settings, and the links and files said in it.

```php
Conversations::rename($id, 'Launch Q4', $by);                 // #launch-q4
Details::describe($id, ['topic' => 'Ships on the 20th', 'description' => 'Everything about the launch'], $by);
Details::setPrivate($id, true, $by);                           // channel → private, and back
Details::archive($id, true, $by);                              // for everyone; false brings it back
Details::remove($id, $person, $by);
Details::setRole($id, $person, 'admin', $by);                  // owner, admin, member
Details::roster($id);                                          // [{id, name, role, joinedAt}], owners first
Details::settings($id, ['suggestMembers' => true, 'tabsBy' => 'admins'], $by);
Details::links($id, q: 'figma', before: $messageId, limit: 50);
Files::page($id, q: 'report', kind: 'media', before: $fileId, limit: 50);
Details::destroy($id, $superuser);
```

- **Who.** A channel's owner, one of its admins, or a moderator (`admin.chat.moderate`). A group is
  changed by any of its members, as its name always was. A direct or bot conversation has no
  details to change.
- **The name.** A channel's `#name` is made from the new name as `create()` makes one, and one
  another channel has is refused. A **system channel keeps its `#name`**: the application finds
  it by it (`bySlug()`), so only the name shown changes.
- **Topic and description:** a topic up to 250 characters, a description up to 250; '' clears
  either.
- **Private or public:** a channel and a private channel turn into each other. A system channel
  stays open.
- **Archived for everyone:** it leaves every list (`forPerson()`), is read and not written, and
  `browse($person, archived: true)` finds it to bring back.
- **Members:** `remove()` takes somebody out, and `setRole()` makes them an owner, an admin or a
  member. A channel never loses its last owner. A channel's admin manages it as its owner does.
- **Settings:** `suggestMembers` (on by default) — a message mentioning somebody not in the
  channel answers its writer with them (`notMembers` in the `post` answer), to offer adding them;
  `tabsBy` — who manages its tabs, `everyone` or `admins`.
- **Links and files**, newest first, a page at a time. A link carries its host, path, the card's
  title and image where it has one, the message, who and when; a deleted message's links are
  left out. Files are `media` (pictures and videos) or `files` (the rest), by a part of the name.
- **Deleted for good** by a superuser alone, never a system channel: its messages, their
  reactions, mentions, pins and saves, its files and their files on disk, what was scheduled
  and drafted in it, its members and its picture. The changelog keeps that it was.
- **Told.** Each change is a line in the conversation, an event in the changelog
  (`conversation.renamed`, `.described`, `.privacy`, `.archived`, `.unarchived`,
  `.member_removed`, `.role`, `.settings`, `.destroyed`) and `conversation.changed` to its
  members.

The columns are added by the migration `add_details_to_conversations`.

## Tabs and canvases

**Tabs** are what every member sees across the top of a conversation: the built-in `messages`,
`files` and `pins`, and those added — a canvas (`canvas:<id>`) or a link (`link:<key>`, a title and
a web address or a path) — in an order, some hidden.

```php
Tabs::of($id);                                            // [{id, kind, title, url, canvas, hidden}]
Tabs::add($id, 'link', $by, title: 'Roadmap', url: 'https://…');
Tabs::order($id, ['canvas:4', 'messages'], $by);           // those named first, the rest after
Tabs::hide($id, 'pins', true, $by);
Tabs::remove($id, 'link:9f2c1a7b', $by);                   // a built-in one is hidden instead
```

Who manages them is the channel's `tabsBy`: `everyone` (any member) or `admins` (its owner, its
admins, the moderators). `messages` is never hidden.

**Canvases** are documents beside the messages, as Slack's. Each has a title and a body of rich
text: headings, lists, a checklist, links, pictures from the conversation's files, and mentions.

```php
$id = Canvases::create($conversation, 'Launch plan', $by);  // and a tab for it
Canvases::lock($id, $by);                                    // "Maria is editing"; again every 20 s
Canvases::save($id, $title, $html, $by);                     // a version
Canvases::versions($id); Canvases::version($id, 3);
Canvases::restore($id, 3, $by);                              // version 3 saved as the newest
Canvases::search($reader, 'launch brief');
Canvases::delete($id, $by);
```

- **One editor at a time.** Editing takes a lock, kept by a heartbeat every
  `Canvases::HEARTBEAT` (20) seconds. A lock whose heartbeat stopped for `Canvases::LOCK` (45)
  seconds is free. While one person edits, the others read and are told who is editing. Writing
  at the same time is not offered.
- **Versions.** Each save is a version: its title, body, who and when (`at`, a Unix time).
  Versions are kept as `canvas.saved` events in the changelog, which is what restoring one reads.
  The changelog is written through the `WriteSpool`, so a version is listed once `spool:drain` has
  run. Workers drain it within seconds; an installation with no worker running lists none until
  then.
- **The body** is the rich editor's HTML, cleaned on the server (`SafeHtml::clean()` with a
  canvas's extra markup). A mention is `<span data-type="mention" data-id="5">`. Somebody
  mentioned for the first time is told, in the bell and by push (`CanvasMention`); a later save
  does not tell them again.
- **Who:** those who read the conversation read its canvases; its members edit them; the
  canvas's author, the conversation's owner, its admins and moderators delete one. `search`
  finds canvases beside the messages.
- **Live:** `canvas.changed` `{conversationId, canvasId, what, by}` on the conversation's channel,
  for created, saved, locked, unlocked and deleted.
- **The editor component**, `CanvasEditor.svelte`, is in `resources/js/conversations`, so the dock
  or an application's own chat can use it. On a page, use
  `PfConversations.canvas(element, {base, canvasId, conversationId, me, strings})`. Print
  `MessageEditor::scripts(MessageEditor::RICH)` on the page for the editor's styles, its words in
  the reader's language, and its script: an editor made by `pfRichEditor.create()` takes the words
  that prints. A client that loads the editor later — on demand, or with no PHP page of its own —
  sets `window.pfRichEditorDefaults` to `MessageEditor::richDefaults()` first: the same words, and
  the styles, which the script then puts on the page itself. Its checklist and mentions are a plugin of the rich editor
  (`pfRichEditor.tiptap.TaskList`, `TaskItem`).

The table is made by the migration `create_conversation_canvases_table`.

## A channel's or a group's picture

A channel, a private or system channel, or a group can have a picture of its own, as in Slack.
The dock shows it in its list, its heads and a window's header, in place of #, ⚙ or the member
count. A direct conversation shows its person's picture instead.

```php
use Pramnos\Conversations\Images;

Images::set($conversationId, $_FILES['image']['tmp_name'], $by);   // PNG, WebP, JPEG or GIF
Images::clear($conversationId, $by);
```

- **Who changes it.** In a group, any member, as with its name. In a channel, its owner or a
  moderator (`admin.chat.moderate`).
- **The picture.** It is cropped square from its middle and kept at 256 pixels a side. A GIF
  keeps its animation where the server has Imagick. An SVG is refused, since it can carry a
  script. A new picture replaces the old one's file.
- **Where it is shown.** Each conversation (`Conversations::get()`, the list) carries `image`, a
  versioned address (`/admin/Conversations/image?id=…&v=…`), or null, and `imageOwn`: true for a
  picture set on it, false for the default by its name or none, so a screen offers "Remove
  picture" only when there is one to remove. A change is told to its
  members as `conversation.changed`, and kept in the change log.
- **Through the screen.** `POST Conversations/setimage/{id}` takes the upload `image`, and
  `POST Conversations/clearimage/{id}` takes the picture away. The browser may crop the upload
  first with `\Pramnos\Html\ImageUpload` (aspect 1).
- **The address** is read by whoever may use the conversations. With
  `'conversations' => ['images_public' => true]` it is read without a session too, for a desktop
  application that has no cookie. `image` is a public action that asks itself: a reader who may
  not see the picture gets a 404, never the sign-in page.
- **Defaults.** `'conversations' => ['images' => ['general' => '/path/to/general.png']]` gives a
  channel a picture by its name. A picture set on the channel wins over the default, and clearing
  it shows the default again.

The column is added by the migration `add_image_to_conversations`.

## GIFs

```php
'conversations' => ['gifs' => ['provider' => 'giphy', 'key' => '…', 'rating' => 'pg']],   // or 'klipy'
```

With a key — app.php's, or an administrator's setting `conversations_gif_key`, which wins — the
dock's composer has a GIF button: the trending GIFs, or those a search finds, at most the `rating`
given (`g` unless said otherwise). The search is this server's (`gifs`, `Gifs::search()`), so the
key never reaches a browser; each GIF comes back as `{id, title, preview: {url, width, height},
url}`, Klipy's renditions chosen by weight — a light one for the picker, one that loads quickly
for the message. The picker names the provider, as both require.

`/gif words`, as Slack's `/giphy`, opens the picker already searching for the words, and `/gif`
alone opens it on the trending ones. The command is in the `/` list only where GIFs are set up,
and it is answered by the page, not the server. A page of the application's own reads it with
`gifCommand()` from `logic.js`.

A picked GIF is sent as a file of its message (`gif/{id}` with `url` and `title`, then `post` with
the file; `Gifs::keep()`). Kept, not linked: it stays with the message when the provider removes
it, a reader's browser never asks the provider for it, and it counts in the conversations' space
and leaves with their files. Only a GIF from the provider's own media hosts, over https, is taken
(`Gifs::MAX_BYTES`, 8 MB), so the endpoint cannot be used to make the server fetch anything else.
The picker's own small pictures are loaded from the provider by the browser of whoever is
choosing — by design, not a gap to close: only the person searching ever asks the provider for
them, nobody else in the conversation, and a GIF they do not send is never kept. What *is* sent
goes through `Gifs::keep()` above, so a reader's browser never reaches the provider for it.

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

## A summary

The analyst sums up a conversation for one reader — what they have not read, or the last hours —
and only for them: the summary is answered, not posted.

```php
\Pramnos\Conversations\Summary::of($conversationId, $reader, 'unread', 'el');
\Pramnos\Conversations\Summary::of($conversationId, $reader, ['hours' => 24]);
\Pramnos\Conversations\Summary::of($conversationId, $reader, ['since' => $unix]);
// ['summary' => markdown citing [#id], 'messages' => [the ids it cites], 'from', 'to', 'count', 'cut', 'language']
```

- It reads only a conversation the reader reads (`Conversations::canRead()`); another is a
  `DomainException`, a 404 from `summarize/{id}`.
- Replies in threads are read, deleted messages are not. With `unread`, the reader's own
  messages are left out. Somebody who reads an open channel without being in it has read none
  of it, so they get its last day.
- At most 400 messages and 60,000 characters go to the model, the newest. `cut` says older
  ones were left out, and the summary says so too.
- It is written in the language asked for (`el`, `en`). Without one it is written in the
  messages' own language, decided by their letters alone: Greek where Greek letters are the most,
  English otherwise. Mentions and addresses do not count, and neither do the writers' names. A
  model asked for "the language most messages are in" takes the names for it. The answer's
  `language` says which.
- `messages` holds only the ids among those read, so a link never points at a message the
  reader may not open.
- Nothing to sum up is answered empty, without a call. The call is logged and costed to the
  reader (`purpose` `conversation_summary`), on the analyst's connection, and the summary is
  kept nowhere else.

The analyst has the same as a tool, `conversation_summary`, for "what was said in #sales today?".

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
Files::extensions();                // every extension accepted, for a client's file picker (`accept`) and its drop/paste guard
```

| setting | where | default |
|---|---|---|
| the tree | `'conversations' => ['files_path' => …]` | `var/chat-files` |
| the largest file | the setting `conversations_max_file_mb` (an administrator's), else `'conversations' => ['max_file_mb' => …]` | 25 MB |
| the largest video | the setting `conversations_max_video_mb`, else `'conversations' => ['max_video_mb' => …]` | 100 MB |
| what may be sent | `Files::TYPES`, plus `'conversations' => ['file_types' => ['mime' => ['ext', …]]]` | see below |

**What may be sent** is decided by what the file is, not what it is called: images (JPEG, PNG, GIF,
WebP, HEIC/HEIF, TIFF), video (MP4, QuickTime `.mov`, WebM), audio (MP3, M4A, WAV, OGG, AAC), PDF,
Word, Excel and PowerPoint (both the old, OLE-container `.doc`/`.xls`/`.ppt` and the current ones),
Outlook `.msg` and `.eml`, OpenDocument, CSV/TSV, plain text, JSON, XML, RTF, zip, rar and 7z. A
video is limited by its own setting rather than the files' one, and is kept without a thumbnail. An
application adds its own kinds with `Files::types()`'s setting (`'conversations' => ['file_types' =>
['mime' => ['ext', …]]]`), merged onto `Files::TYPES` rather than replacing it; `Files::extensions()`
reads the merged list back as a flat list, for a client's upload picker and its own guard against a
dropped or pasted file of a kind that will only be refused. The composer checks the same two
megabyte limits before uploading. Uploads also stay within PHP's `upload_max_filesize` and
`post_max_size`, which must be raised for videos that large. A program renamed `invoice.pdf` is
refused, naming the extension it was refused by, and the refusal is logged (the user, the
conversation, the type `finfo` read, the extension and the size) — a refusal is otherwise
indistinguishable from a bug. A name loses any path and any character a disk cannot hold. An image
larger than 400 pixels on a side gets a thumbnail beside it, fitted inside 400×400 with its own
proportions: a PNG for a PNG, so a transparent background stays transparent, a JPEG otherwise,
named `.thumb.png` or `.thumb.jpg` for what it is. A smaller image has none and is shown itself, as
is a GIF — a thumbnail of it would stand still — and one GD cannot read (WebP on most builds). `./pramnos conversations:thumbnails` makes
every image's thumbnail again (`Files::rebuildThumbnails()`).

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
| `conversation.member_joined` | `Events\MemberJoined` | somebody came in — joined, or was added (`addedBy`) — each time they come in, not when they were in already |

Each is a `QueueableEvent`: a queued listener — a notification, a rule, an integration — takes it
in the queue or the event stream like any other.

Listed, alongside every other extension point the framework fires, in the
[Extensibility Guide](Pramnos_Extensibility_Guide.md)'s catalog.

## Notifications — the bell and a push

A new message notifies, in the bell and as a push (`Notify`, `ConversationNotification`), as
Slack does:

| why | who |
|---|---|
| `direct` | every member of a direct or group conversation |
| `mention` | whoever it names with `<@id>`, or a handle tells (`@here`, `@channel`, …) |
| `thread` | a reply: whoever wrote the thread's first message or replied in it |
| `message` | anything else in the conversation, to members whose setting is `all` |

Each member's setting for the conversation decides (`Conversations::notify()`, `notify/{id}`):
`none` nothing, `mentions` everything but `message`, `all` everything. A member starts with `all`
in a direct, group or bot conversation and `mentions` in any other, as Slack does; somebody who
leaves and comes back keeps what they had. A **muted** conversation
notifies only who it names with `<@id>`. Its writer is never notified, and nor is anybody the
application's `quiet` hook says is paused now.

The notifications go out **in the queue** (`conversation_notify`, answered by the worker without
registering): a message to a channel of fifty is fifty pushes, which its writer does not wait for.
Where they open is worked out by the request that wrote the message and carried in the task
(`Notify::page()`: `conversations.page`, else the administration's `Conversations` screen), since
a worker run as the site's application has no area of its own.
Without a running queue worker nothing is sent, and the failure to queue is logged.

```php
'conversations' => [
    'page'         => '/chat',                                   // where a notification opens; `#/c/{id}/m/{message}` follows
    'quiet'        => fn (array $ids): array => Status::paused($ids), // of these, who is not notified now
    'notify_via'   => ['database', 'push'],                      // the default; ['database'] keeps them to the bell
    'notification' => App\Notifications\ChatMessage::class,      // a class of your own, built with ($messageId, $why)
],
```

A push is titled with its writer in a direct conversation, otherwise with the conversation
(`#ops`), and carries the first words — mentions by name, marks left out. Its tag is the
conversation's, so a phone shows a conversation's newest message rather than a stack of them.
Reading a conversation (`read/{id}`) settles its notifications in the reader's bell
(`about: conversation:{id}`).

**Notify anyway.** `post/{id}` answers `paused`: whoever the message addressed — named with `<@id>`,
or the other members of a direct or group conversation — whose notifications are paused. Its writer
may `nudge/{messageId}` one of them (`person`): a notification past the pause, once for each
message and person (`Notify::nudge()`).

**The service worker.** A push reaches a person only through a worker with a `push` handler. The
scaffolded `sw.js` has one. A worker of the application's own — the conversations installed as an
app have their own, scoped to their page — imports the framework's:

```js
importScripts('/assets/js/pf-push-worker.js');   // push + notificationclick
```

A click focuses the page already open at the notification's address and moves it to the message
(it also posts `{type: 'notification-click', url}` to the page), or opens one — on a phone, the
installed app when the address is in its scope. The page subscribes to push through that worker's
registration, as the [Push Guide](Pramnos_Push_Guide.md) describes.

### Told in the client the person is in

The push and the realtime notification go to **the client the person was last active in**, and to
every client once they have been away longer than they chose, as Slack, Teams and Discord do. A
client is a browser tab or the desktop application. The bell is told of every message, always.

- **Which client.** Each tab keeps a random id, `window.pfClientId` (pf-utils.js, kept in
  sessionStorage, so a reload keeps it). It reports "I did something" to `active`: when it opens,
  when it comes to front, and on a key or a click at most once a minute. pf-utils starts this by
  itself where the conversations' dock is on the page; `window.pfActivity(url)` starts it
  elsewhere. The desktop application also sends `idle` (the system's idle seconds) and `locked`.
  The server keeps one row per person, `pramnos.conversation_clients`, with the client, when, how
  idle and whether it was in front (`Clients::active()`).
- **The desktop application** is the client that sends `idle`, every minute. Its last report is
  kept apart from the last client (`app`, `appseenat`, `appidle`), and its minute's report does not
  take the place of a tab in front. While it runs — reported within three minutes, the screen not
  locked, the system not idle longer than the person's minutes (`Clients::appRunning()`) — it is
  the client told, unless a tab is in front now, and **no web push** goes to the browser: the
  application notifies, and a push's click would open the browser instead, as Slack's does.
- **Away.** Nothing reported for longer than the person's minutes, or a system idle or locked for
  longer than that. The minutes are 0, 5 (the default), 10 or 30 (`Clients::awayAfter()`, `away`).
  With 0, "at once", a client that is not in front (a hidden tab, a minimised window) counts as
  away.
- **The push** goes to the subscription made in that tab: the subscribe request sends `client`,
  and `Subscriptions::tagClient()` keeps it. A subscription that names no client — from a page
  older than this — is told as before, so nobody loses a notification. If the person is in a client
  with no subscription (the desktop application), no push goes out and the push log says
  `ELSEWHERE`. When the person is away, every subscription gets the push. While the client the
  person is in is in front — reported in front within the last minute, and not idle
  (`Clients::inFront()`) — no push goes out at all: that client tells of the message itself from
  the realtime connection, and the bell is told as always. A push it would have had to keep from
  showing is one Chrome on Android counts against the subscription, and cancels it after a few.
- **The realtime telling** (`conversation.activity` on the person's own channel) carries `notify`:
  the client that shows a notification, or `all`. A client shows one only when `notify` is its own
  id or `all`; a client with no id shows one always.

`Clients::target($userId)` answers the client a notification goes to, or null for every one.

### Held while unopened

`'push' => ['hold_unopened' => 'mobile']` (or `'all'`) stops a second conversation push from
piling onto a device that still has an earlier one sitting there unopened — a phone buried under
unopened notifications is how Chrome on Android ends up revoking the site's permission outright.
It clears on opening or dismissing the held one, on any activity on any client since it was sent,
or on catching up on that push's own conversation. The full mechanics —
`pushlog.opened_at`/`dismissed_at`, the service worker's report, `ConversationNotification::heldFor()`
— are in the [Push Guide](Pramnos_Push_Guide.md#opened-dismissed-and-the-hold-unopened-rule).

## Scheduled messages

A message written to be sent later, as Slack's *Schedule message* (`Pramnos\Conversations\Scheduled`):

```php
$id = Scheduled::create($general, $me, 'Καλημέρα σε όλους', strtotime('tomorrow 09:00'));
Scheduled::create($general, $me, 'Το είδα', $at, ['thread' => $root, 'broadcast' => true, 'files' => [$fileId]]);
Scheduled::mine($me);                         // what one is waiting to send, and what could not be sent, soonest first
Scheduled::change($id, $me, 'Καλημέρα!', $at); // its text, its time, or both; one that failed is tried again
Scheduled::cancel($id, $me);
```

`conversations:send-scheduled`, which the framework runs every minute, sends what is due with
`Messages::post()` as its writer — notifications, mentions and all — and removes it: the message
is its record. Its writer must still be in the conversation; if not, or the thread it answers is
gone, it stays in `mine()` with its `error` and is not tried again until it is changed. Its time is
Unix seconds and must be in the future. Files are uploaded first, as for any message, and wait
unattached until it is sent.

## Automations

What a conversation does by itself, as Slack's workflows (`Pramnos\Conversations\Automations`):

```php
Automations::add($general, $me, ['kind' => 'repeat', 'body' => 'Stand-up!', 'every' => 'weekdays', 'time' => '09:30']);
Automations::add($general, $me, ['kind' => 'repeat', 'body' => '…', 'every' => 'week', 'days' => [1, 4], 'time' => '17:00', 'sender' => 'creator']);
Automations::add($general, $me, ['kind' => 'repeat', 'body' => '…', 'every' => 'month', 'day' => 31, 'time' => '10:00']);
Automations::add($general, $me, ['kind' => 'welcome', 'body' => 'Καλώς ήρθες!', 'to' => 'channel']);   // or 'person'
Automations::add($general, $me, ['kind' => 'ask', 'body' => 'Πόσες εγγραφές είχαμε χθες;', 'every' => 'day', 'time' => '08:00']);
Automations::of($general);
Automations::change($id, $me, ['time' => '10:00']);
Automations::pause($id, $me);           // pause($id, $me, false) lets it go on
Automations::delete($id, $me);
```

| kind | what it does |
| --- | --- |
| `repeat` | posts the message on its schedule |
| `welcome` | greets whoever comes in, each time they come in: `to` `channel` posts in the conversation mentioning them; `person` reaches them alone, in their conversation with the system signed with the conversation's name, or in a direct message from its creator with `sender` `creator` |
| `ask` | puts the question to the analyst on its schedule, as `@Ask` in the conversation, from its creator: answered in the message's thread with the rights of the conversation's least privileged reader, and costed to the creator. Only somebody who may ask the analyst sets one |

- **The schedule:**
  - `every` is `day`, `weekdays`, `week` (with `days`, 1–7, Monday first) or `month` (with
    `day`; the 31st falls on a shorter month's last day).
  - `time` is `HH:MM` on the installation's clock (PHP's zone), so 09:30 stays 09:30 when summer
    time begins or ends.
  - `conversations:send-scheduled` runs what is due every minute. Each one moves on to its next
    time before it runs, so one that fails is not tried every minute. A run missed while the
    server was down is not made up.
- **From whom:**
  - `sender` `automation`, the default, posts as "Automation".
  - `creator` posts as whoever set it up, while they are still in the conversation. Otherwise it
    keeps an `error` until it is changed.
  - A question is always its creator's.
- **Who manages them:** those who manage the conversation's tabs (`tabsBy`: its admins, or every
  member). Every reader sees them (`automations/{id}`).
- **What is kept:**
  - A row is the automation as it is set now: `chat.conversation_automations`.
  - Adding, changing, pausing, resuming and deleting are `conversation.automation_*` events in
    the changelog, and the members' pages are told (`conversation.changed`).
  - Deleting the conversation deletes its automations; archiving it stops them.

## Drafts

What somebody has begun writing is kept on the server, so it follows them to every device, as
Slack's drafts (`Pramnos\Conversations\Drafts`):

```php
Drafts::save($me, $general, null, 'Καλημέρα, σήμερα…');  // the conversation's; an empty body lets it go
Drafts::save($me, $general, $root, 'Το κοιτάζω');        // a thread's
Drafts::get($me, $general);                             // '' when there is none
Drafts::mine($me);                                      // every one, newest first
```

One for each person, conversation and thread, and only in a conversation they are in. Sending a
message there lets it go. Each change is told on the person's own channel as `conversation.draft`
— `{conversationId, thread, body, updatedAt}`, `body` `''` when it went — so their other devices
show it. A composer saves as its writer types (debounced), and keeps a local copy only as a first
picture until the server answers; `messages/{id}` gives the conversation's draft back as `draft`.

## Live: what browsers are told

A message is written first and told second, through Broadcasting:

| channel | events |
|---|---|
| `private-conversation.{id}` | `message.posted`, `message.edited`, `message.deleted`, `message.reacted`, `message.pinned`, `message.unfurled` (its link previews read, or one taken off) — each with the message as a reader sees it (name, HTML, reactions, files, link previews) |
| `private-conversation.{id}` | `canvas.changed` `{conversationId, canvasId, what, by}`: one of its canvases was created, saved, locked, unlocked or deleted ([Tabs and canvases](#tabs-and-canvases)) |
| `private-conversation.{id}` | `assistant.thinking` `{thread, name, until}` when the analyst begins on a question there — `thread` null for the conversation itself — and `assistant.done` `{thread}` when it has finished, answered or not: the server says it, since a worker cannot whisper |
| each member's own (`private-users.{id}`, or `broadcasting.notifications_channel`) | `conversation.activity`: a message in one of their conversations — its ids, its writer, whether it mentions them — for a sidebar's unread and a window that opens; `conversation.read`: what they have read there moved — read on another page, or marked unread — `{conversationId, lastReadId, unread, mentions}`; `conversation.changed` `{conversationId, mergedFrom}`: who is in it changed under them (`claimFormer()`); `conversation.draft` `{conversationId, thread, body, updatedAt}`: their draft there changed, on another device; `todo.changed` `{id, deleted}`: one of their to-dos was added, changed or deleted ([To-dos](Pramnos_Todos_Guide.md#in-the-conversations)); `activity.seen` `{seenAt}`: they saw their activity on another device |
| `presence-chat` | who of the team is connected, with their name |

For the conversations to be live, three things have to hold; each fails silently when it does not:

1. **The daemon reads the conversations' channels.** `broadcast:serve` ingests
   `private-conversation.*` by itself where the `conversations` feature is on and the backplane is
   Redis pub/sub (`broadcasting.default = redis`), beside every user's notifications channel. On
   `redis-stream` no pattern reads them, and it says so at startup: what a worker writes there —
   an answer, *… is thinking* — would never reach an open page.
2. **The auth endpoint signs them.** The `conversations` feature's provider adds the rules to the
   application's (`broadcasting.channels`): a conversation's channel is heard by whoever reads it,
   the presence channel by whoever may use the conversations. An application with an auth
   controller of its own, which signs only the channels its own `channels()` names, adds them
   there: `\Pramnos\Conversations\Realtime::authorize($channels)`. A channel left unsigned shows
   in `pfRealtime.status().error` as *The auth endpoint did not sign presence-chat*.
3. **Client events are on**, for typing: a `client-typing` event browsers whisper on the
   conversation's channel — `'broadcasting' => ['websocket' => ['client_events' => true]]`.

A conversation whose channel the server has not confirmed — refused, or the socket down — is
caught up every few seconds, as without realtime, until it is. A browser that was disconnected
asks for what came after the last message it has, `Messages::list($id, ['after' => $last])`.
Without broadcasting set up nothing is told, and a driver that fails is logged — never the
writer's failure.

## Access

**Where the settings are read.** Every `conversations` setting is read through
`Conversations::settings()`: the running application's `conversations` block, or — in a process
run as another application whose configuration has none, a queue worker or the scheduler — the
block of `app/admin.php` (`Conversations::AREA`), where the conversations are. So the `quiet` hook,
the groups, `handles_may`, the floor and the files' path are the same for the notifications a
worker sends and the scheduled messages the scheduler posts as for the page that wrote them.

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
ticked — and is not told by a handle (`@here`, `@channel`…); named with `@`, they are still told. Where they
already are, they are greyed with when they last signed in: in a client's list, among a
conversation's members, and in a direct conversation with them a note above the composer says
they will probably not see it. Nothing is removed — no membership, no message — and signing in
makes them active again at once. `People::inactive($ids)` answers who, with the last sign-in
(0 for never); `People::search($term, $limit, $inactive)` leaves them out unless asked.

## Moderation

With the `moderation` feature too, every message and edit goes through it in the scope
`conversation:{id}` — bans and mutes, the slow mode, the rate, the filter — and the moderators of a
conversation have `/mute`, `/unmute`, `/ban`, `/unban`, `/kick` and `/slow`. See the
[Moderation Guide](Pramnos_Moderation_Guide.md).

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
its handles (`@here`, `@channel`, `@everyone` in the general one, the groups), `:` suggests emoji; files are picked, dropped or pasted, uploaded at once and sent
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

**Faces** are initials on a colour of the person's own, unless the application has pictures:
`conversations.avatars`, a callable given the ids and the reader, answers an image's address for
each it has one for (http(s) or a path of the site; anything else is dropped). The JSON carries
them — `avatars` in `conversations`, `avatar` on each of a conversation's members and in a
profile — and the dock draws them, falling back to the initials where a picture is missing or does
not load. `People::avatars($ids, $reader)` asks the hook once for many.

```php
'conversations' => ['avatars' => fn (array $ids, object $reader): array => Photos::urls($ids, 96)],
```

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

**The same actions under the application's API.** A client that is not this page — a desktop
or mobile application, a SPA — reaches every action below with the API's credentials (a bearer
token, or the session), mounted in the application's API routes:

```php
\Pramnos\Conversations\ConversationsApi::routes($r);          // /api/<version>/conversations/…
```

`GET conversations/{action}/{id}` is a read and `POST conversations/{action}/{id}` a write, with
the same values, rights and answers as here; there is one implementation behind both. The answer
is in the API's shape, `{status, data}` or `{status, error}`. The wrong method is a 405, an action
there is not a 404, and the page itself (`display`) is not offered. `init` mounts it for an
application with the `conversations` feature; an application made before adds the line.

An application whose screen is a subclass of its own, with actions and a way of knowing who asks
of its own, gives it as the third argument. Its actions are offered beside the framework's, each
described by its docblock, and it answers as it is, with its own `me()`:

```php
\Pramnos\Conversations\ConversationsApi::routes($r, '/conversations', \App\Controllers\Conversations::class);
```

| action | method | in | out |
|---|---|---|---|
| `conversations` | GET | — | `{me, conversations, browse, people, avatars}`: one's own with unread, mentions and a `title`, the open ones to join, the names of the people in direct and group ones |
| `messages/{id}` | GET | `before`, `after`, `thread`, `around`, `limit` | `{conversation, handles, members, messages, pinned, saved, draft}` — `saved`, which of these messages the reader kept; `draft`, what the reader has begun writing there (in `thread`, when asked for one); with `around` (a message id) the stream around it and `around: {messageId, threadRootId}`, `older`, `newer` — none of the three when the message is no anchor, and the newest are shown |
| `threads` | GET | — | `{threads}`: the threads one takes part in, newest reply first — `{root, latest, replies, newReplies}` |
| `activity` | GET | — | `{activity, seenAt, unseen}`: mentions of one, reactions to what one wrote, replies in one's threads — `{kind, at, message, by, byName, emoji}` — with when one last saw them, kept on the server, and how many came since that one has not read (`unseen`): a mention or a reply read in its conversation, past one's read point there, is not counted; a reaction, which has no read point, counts until the activity is seen. The list itself shows all of them |
| `activityseen` | POST | `at` (now by default) | `{ok, seenAt}`: one saw their activity — every device's badge clears (`activity.seen` on their own channel); never moved back |
| `unreads` | GET | — | `{unreads, activityUnseen}`: every unread message, conversation by conversation, but those of the quiet sources; and how much of one's activity is unseen and unread, for its badge (`Inbox::unseenActivity()`, as `activity`'s `unseen`): fetch it again on `conversation.read` |
| `sent` | GET | — | `{messages}`: what one wrote, newest first |
| `saved` | GET | — | `{messages}`: what one kept for later |
| `files/{id}` | GET | `kind` (`media`, `files`), `q`, `before` (a file id), `limit` | `{files}`: the files sent in a conversation and still kept, newest first, each with `thumbnail`; a page when any of these is given |
| `links/{id}` | GET | `q`, `before` (a message id), `limit` | `{links}`: every address said in it, newest first — `{url, host, path, title, image, messageId, authorId, at}` |
| `members/{id}` | GET | — | `{members}`: `{id, name, role, joinedAt}`, owners first |
| `archived` | GET | — | `{conversations}`: the archived ones the reader reads |
| `describe/{id}` | POST | `topic`, `description` | `{conversation}` |
| `setprivate/{id}` | POST | `on` | `{conversation}` |
| `archive/{id}`, `unarchive/{id}` | POST | — | `{ok}` |
| `removemember/{id}`, `setrole/{id}` | POST | `person`, `role` | `{members}` |
| `settings/{id}` | POST | `suggestMembers`, `tabsBy` | `{conversation}` |
| `destroy/{id}` | POST | — | `{deleted: {messages, files}}`: a superuser's alone |
| `tabs/{id}` | GET | — | `{tabs, manages}` |
| `addtab/{id}` | POST | `kind` (`link`, `canvas`), `title`, `url`, `canvas` | `{tabs}` |
| `removetab/{id}`, `hidetab/{id}` | POST | `tab`, `on` | `{tabs}` |
| `ordertabs/{id}` | POST | `order` (tab ids) | `{tabs}` |
| `canvases/{id}` | GET | — | `{canvases}`: a conversation's, without their bodies |
| `canvas/{canvasId}` | GET | `version` | `{canvas, heartbeat}` with `lockedBy`, `editorName`, `deletable` (whether the reader may delete it); with `version`, `{version: {title, body}}` too |
| `canvasversions/{canvasId}` | GET | — | `{versions}`: `{version, by, at, title}`, newest first |
| `newcanvas/{id}` | POST | `title` | `{canvas}` |
| `lockcanvas/{canvasId}` | POST | — | `{locked, lockedBy, editorName}` — the heartbeat too |
| `unlockcanvas/{canvasId}` | POST | — | `{ok}` |
| `savecanvas/{canvasId}` | POST | `title`, `body` | `{version, canvas}` |
| `restorecanvas/{canvasId}` | POST | `version` | `{version}` |
| `deletecanvas/{canvasId}` | POST | — | `{ok}` |
| `automations/{id}` | GET | — | `{automations, manages}`: each `{id, kind, body, sender, schedule: {every, time, days?, day?}, to, nextAt, paused, error, createdBy, createdByName}` |
| `addautomation/{id}` | POST | `kind`, `body`, `sender`, `every`, `days`, `day`, `time`, `to` | `{automations, manages}` — see [Automations](#automations) |
| `changeautomation/{automationId}` | POST | any of those but `kind` | `{automations, manages}` |
| `pauseautomation/{automationId}` | POST | `paused` (yes by default) | `{automations, manages}` |
| `deleteautomation/{automationId}` | POST | — | `{automations, manages}` |
| `summarize/{id}` | POST | `since` (`unread`, the default, or a Unix time), `hours`, `language` | `{summary, messages, from, to, count, cut, language}`: see [A summary](#a-summary) |
| `people` | GET | `q` | `{people}`: who may use the conversations, by a part of the name |
| `person/{id}` | GET | `show` (`messages`, `mentions`, `files`), `q` | `{person}`: as `People::profile()` answers; with `show`, also what they wrote holding the words of `q`, what mentions them, or the files they sent — 404 for somebody the reader does not see |
| `refs` | GET | `keys` (`user:5,jira:NAN-1`) | `{cards}`: each as the application draws it for the reader, or null |
| `command/{id}` | POST | `text`, `thread` | `{reply: {text, card}}`: the command's answer, for its writer alone |
| `search` | GET | `q`, `conversation` | `{messages}` |
| `create` | POST | `kind`, `name`, `topic`, `members[]` | `{id, conversation}` — the conversation as `messages` gives it |
| `join/{id}`, `leave/{id}` | POST | — | `{ok}` |
| `add/{id}` | POST | `person` | `{ok}` |
| `post/{id}` | POST | `body`, `thread`, `broadcast`, `files[]` | `{id, message, paused}` — `broadcast` with `thread`: the reply is also sent to the conversation; `paused`, who it addressed with their notifications paused |
| `nudge/{id}` | POST | `person` | `{ok}` — "Notify anyway": the message's writer notifies one of `paused`, past the pause, once |
| `schedule/{id}` | POST | `body`, `at` (Unix seconds), `thread`, `broadcast`, `files[]` | `{id, scheduled}` — a message sent later, as its writer |
| `scheduled` | GET | — | `{scheduled}`: what one is waiting to send, and what could not be sent with its `error`, soonest first — `{id, conversationId, body, thread, broadcast, files, at, createdAt, error}` |
| `reschedule/{id}` | POST | `body`, `at` | `{scheduled}` — one's own, changed; one that failed is tried again |
| `unschedule/{id}` | POST | — | `{ok}` |
| `drafts` | GET | — | `{drafts}`: what one has begun writing, everywhere, newest first — `{conversationId, thread, body, updatedAt}` |
| `draft/{id}` | POST | `body`, `thread` | `{ok}` — what one has written so far there; an empty body lets it go |
| `edit/{id}` | POST | `body` | `{message}` |
| `delete/{id}` | POST | — | `{ok}` |
| `react/{id}` | POST | `emoji`, `on` | `{ok}` |
| `pin/{id}` | POST | `on` | `{ok}` |
| `unfurl/{id}` | POST | `url` | `{ok}`: the writer takes that link's preview card off the message |
| `preview` | GET | `name` | a preview card's picture or icon, kept here |
| `gifs` | GET | `q` | `{gifs}`: GIFs for a search, or the trending ones, asked with the application's key; 404 where there are none |
| `gif/{id}` | POST | `url`, `title` | `{id, name, size, mime}`: a GIF the picker offered, kept as a file of the conversation |
| `read/{id}` | POST | `message` | `{ok}` — the conversation's notifications in the reader's bell are read too |
| `unread/{id}` | POST | `message` | `{ok}` — unread from that message on, a reply from its root; 404 to somebody not in it |
| `notify/{id}` | POST | `notify` (`all`, `mentions`, `none`), `muted` | `{ok}` |
| `star/{id}` | POST | `on`, `before` | `{ok}` — `before`, a starred conversation it is placed before, in the reader's own order |
| `starorder` | POST | `ids[]` | `{ok}` — the reader's own order of their starred conversations, all at once; ids not theirs let be |
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
database of its own — keeps an emergency channel, direct messages and the team's own recent
conversations going while the primary does not answer, and gives them back when it does.

**What a person sees** is the application's client to draw. Every day the primary answers, a
client asks for a key for the beacon (`emergencykey`, below) and keeps it. When the primary stops
answering — three requests in a row with no answer, or a proxy's 5xx — the client turns to the
beacon with that key: the emergency channel, a direct conversation with each of the team, and the
conversations they are in (`joined`, below) — text and reactions only, not threads, through the
beacon's own paths below; their history before the outage is whatever the client kept on the
device; it asks the primary again every twenty
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
    'conversation_days' => 60,                            // the conversations that moved within these days go to the beacon
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
| `POST sync` | the primary | the team, their push subscriptions, and the conversations that moved within `conversation_days` with their members — replaced whole each time; a sync without `conversations` keeps those there |
| `POST session`, `GET`/`POST messages`, `POST react` | a browser, `Authorization: Bearer <key>` | the emergency channel (`emergency`), the person's direct messages (`dm:a-b`) and the primary's conversations they are in (`c:<id>`); `session` answers `{me, people, conversations, joined}`, `joined` being `[{c: 'c:12', kind, title}]`; a message once per `client_id` |
| `GET export`, `POST ack` | the primary | what was written, taken back and acknowledged |

**Afterwards.** `conversations:beacon import` (scheduled) takes what was written into the
conversations here — the emergency channel into its system channel, with its writers and those who
reacted joined to it, direct messages into the two people's own, a conversation's (`c:<id>`) back
into it as its writer — or, when they left it in the meantime, into the emergency channel as
*(from #name) …* — each message once, then acknowledges it so it leaves the beacon's log.

The conversations the beacon has are channels, private and system ones and group conversations
whose last message is within `conversation_days` (60) and that are not archived — not direct ones,
which it names by their two people, nor Ask's — with their members on the team, a group titled by
its people (`Primary::conversations()`).

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

The conversations' tables are in a schema of their own, `chat` (on MySQL a name prefix:
`chat_conversation_messages`), and the assistant's in `assist`. Code reads them through the query
builder as `chat.conversation_messages`; raw SQL uses `$schema->quoteTable('chat.x')`.
`Conversations::installed()` says whether they are there.

The tables are `chat.conversations`, `chat.conversation_members`, `chat.conversation_messages`,
`chat.conversation_reactions`, `chat.conversation_mentions`, `chat.conversation_pins` and `chat.conversation_files`,
all declared personal data. What each link shows is in `pramnos.link_previews`, one row per link —
the framework's cache, not personal data. What a message said before an edit is not kept in a table of its own:
an edit is a save's diff, written to the change feed (entity `conversation_message`) and kept as
long as the changelog keeps diffs — thirty days unless app.php says otherwise. Without the
`changelog` feature an edit is marked as one, and what it said before is not kept. Messages are kept for
good: on TimescaleDB `chat.conversation_messages` is a hypertable on `created_at`, its chunks compressed
after 90 days and never dropped (`HypertableRegistry`, overridable in app.php's `hypertables`).
