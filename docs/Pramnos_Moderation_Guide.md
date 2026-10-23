---
use_cases:
  - Banning or muting somebody — an account, a visitor, an address — in one place or everywhere
  - Checking a message before it is written: bans, slow mode, a rate limit, a word and link filter
  - Letting people report a message, and dealing with the reports
  - Giving the moderators of a conversation /mute, /ban, /kick and /slow
---

# Moderation Guide

`Pramnos\Moderation` keeps who may not be somewhere and who may be there but not write, checks
what somebody writes before it is written, and writes down everything done about whom. It knows
nothing of what is moderated: a **scope** is the application's word for where — `conversation:12`,
`comments:article:9` — and the empty scope is everywhere. The conversations use it when the
application has the feature; anything else people write can use it the same way.

```php
'features' => ['moderation'],   // app.php — its tables come with the feature's migration
```

## Who: subjects

A **subject** is a kind and a value, so somebody with no account is moderated as an account is:

```php
use Pramnos\Moderation\Subject;

Subject::account(48211);           // a signed-in person
Subject::visitor($key);            // somebody with no account, by the key their session holds
Subject::ip('203.0.113.4');        // an address
Subject::nickname('Μαρία');        // a nickname — compared trimmed and lower-case
```

## Bans, mutes and kicks

```php
use Pramnos\Moderation\Moderation;

Moderation::mute(Subject::account(48211), 'conversation:12', 600, 'Ύβρεις', $me->userid); // ten minutes
Moderation::ban(Subject::ip('203.0.113.4'), '', null, 'Spam');                          // everywhere, for good
Moderation::lift(Subject::account(48211), 'conversation:12', 'mute', $me->userid);        // or null: both
Moderation::kick(Subject::account(48211), 'conversation:12', 'Αρκετά', $me->userid);

Moderation::restriction([$account, $ip, $nickname], 'conversation:12');
// ['id' => …, 'effect' => 'ban'|'mute', 'scope' => …, 'until' => ?int, 'reason' => …] or null
Moderation::restrictions('conversation:12');   // what is in force there, newest first
```

- **A ban** keeps a subject from writing in a scope; **a mute** is a timeout — they still read.
  Both last for so many seconds, or for good with null.
- A restriction in the empty scope holds in every scope.
- `restriction()` takes everything that identifies the person asking — their account or visitor
  key, their address, their nickname — and answers the strongest in force: a ban over a mute, then
  the one that lasts longest. A ban on an address holds whoever signs in from it.
- **A kick** puts somebody out now and leaves nothing behind. It is told as `moderation.kicked`
  (`Events\Kicked`, with the subject, the scope and the reason), for the realtime layer to close
  what they have open there.

## A message on its way: the gate

```php
use Pramnos\Moderation\Gate;

$verdict = Gate::check([Subject::visitor($key), Subject::ip($ip)], 'room:lobby', $text);
if (!$verdict['allowed']) {
    return ['error' => $verdict['message'], 'retryAfter' => $verdict['retryAfter']];
}
$text = $verdict['text'];   // masked where the filter masks
```

In this order:

1. A ban or mute on any of the subjects, there or everywhere: refused, with when it ends.
2. The scope's **slow mode** — `Moderation::slowMode('room:lobby', 30)`, 0 turns it off: one
   message per writer every so many seconds.
3. The **rate**: a token bucket per writer, `moderation.rate` (ten at once, then ten a minute).
4. The **filter** (below): refused, or let through masked.

The first subject is the writer, whose slow mode and rate are counted; the others are looked for
among the restrictions. `reason` is `banned`, `muted`, `slow`, `rate` or `filter`; `message` is
the reason in the writer's language. A refusal by the rate or the filter is written in the log.

The slow mode and the rate are counted in the application's cache, so a cache that is off counts
nothing and lets everybody through; the restrictions and the filter hold without it.

## The filter

```php
'moderation' => [
    'rate'   => ['messages' => 10, 'per' => 60],
    'filter' => [
        'words'  => ['βλάκας', 'idiot'],    // masked letter for letter: ******
        'refuse' => ['κάνε κλικ εδώ'],      // the whole message refused
        'links'  => 'allow',                // 'allow', 'deny' (each masked ***), or 'only' the sites below
        'sites'  => ['example.com'],        // with 'only': these and their subdomains
    ],
],
```

Words and phrases compare whole and ignoring case — `idiots` is not `idiot`. Markup that would run
where a message is shown — a script, a frame, an event handler, a `javascript:` address — is refused
whatever the rules; the page's own escaping is still what keeps text from running, and this keeps
it out of every other place a message is shown. `Filter::apply($text, $rules)` runs it alone.

## Reports

```php
use Pramnos\Moderation\Reports;

$id = Reports::file(Subject::visitor($key), Subject::account($author), 'conversation:12', 'message:' . $messageId, $body, 'Ύβρεις');
Reports::open('conversation:12');               // waiting, oldest first, with what was said then
Reports::close($id, 'resolved', $me->userid);   // or 'dismissed'
```

A report keeps what was said as it was when it was reported. The same reporter reporting the same
content again is the first report. A report restricts nobody by itself: a moderator reads it and
decides.

## The log

Everything is written to the audit log (`authserver.audit_log`) — `moderation.ban`, `mute`,
`lift`, `kick`, `refuse`, `report`, `resolve`, `slowmode` — about the subject (an account is the
`user` it is; a visitor, an address or a nickname its own kind), in the scope (`*` for everywhere),
by whom (null for the rules themselves), with why and the details. Moderation keeps no log of its
own: the audit log is the operators' record of actions, and how long it is kept is the
application's policy (`audit:prune`).

`Moderation::history('conversation:12')`, or `history(null, $subject)` for one person, newest
first, reads it back; `Moderation::log()` writes an action of the application's own;
`AuditLog::query(['event' => 'moderation.*', 'target_type' => 'ip', 'target_id' => '203.0.113.4'])`
asks the audit log directly.

## In the conversations

With both `conversations` and `moderation` among the features, every message and edit in a
conversation goes through moderation in the scope `conversation:{id}`:

- **A new message** goes through the gate as the writer's account and the request's address.
- **An edit** is refused to somebody banned or muted, and filtered.
- **History brought in** (`quiet`) is written as it was.

A refusal is the message's error, in the writer's language.

The moderators' commands are registered with the conversations' others:

| command | does |
|---|---|
| `/mute @Name [10m] [why]` | they read, but do not write here — ten minutes unless said |
| `/unmute @Name` | they write again |
| `/ban @Name [1d] [why]` | they do not write here — for good unless said |
| `/unban @Name` | lifted |
| `/kick @Name [why]` | put out now |
| `/slow 30s` · `/slow off` | one message per person every so often |

A duration is `45s`, `10m`, `2h` or `3d`. Whoever moderates the conversation runs them — its
owner, or whoever has `admin.chat.moderate` (a superuser's by default, `Access::moderates()`);
anybody else is told so. The answer is the moderator's alone, and the log keeps what was done.

## Storage

| table | what |
|---|---|
| `moderation_restrictions` | a ban or a mute: scope, effect, subject, reason, by whom, when, until, lifted |
| `moderation_reports` | reporter, subject, content, snapshot, reason, status |
| `moderation_scopes` | a scope's slow mode |

What was done is the audit log's, above. The subjects and what they wrote are personal data,
declared in the personal-data registry. Times are Unix seconds.
