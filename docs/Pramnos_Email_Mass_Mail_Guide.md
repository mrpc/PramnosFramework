---
use_cases:
  - Sending one message to many accounts
  - Declaring the kinds of mail an application sends, for people to opt out of
  - Meeting Gmail's one-click unsubscribe requirements
  - Running an opt-in newsletter list
---

# Mail to many: kinds of mail, unsubscribing, mailing lists

Part of the [email guide](Pramnos_Email_Guide.md).

## A message to many accounts

`/admin/MassMessages` composes, sends and reports on a message stored in `massmessages`, with
one row per recipient in `massmessagerecipients`. Behind it are two classes:

```php
$audience = (new MassMessageAudience())->resolve(['usertype_min' => 0]);
(new MassMessageDispatcher())->queue($messageId, $audience);   // once, in the request
(new MassMessageDispatcher())->dispatch(100);                  // over and over, on a timer
```

### Queueing and delivering are separate because they fail differently

`queue()` writes one recipient row per account and returns. `dispatch()` — driven by
`messages:dispatch`, scheduled every five minutes — takes pending recipients in batches and
marks each one as it is attempted.

A send of four thousand emails inside a POST is a request that times out halfway, leaving an
operator with no idea how far it got and a page offering to send again. Here the answer to
"how far did it get" is a row count, and the answer to "is it safe to run again" is yes.

**Queueing is the step that must not repeat.** A message that already has recipients is
refused rather than queued a second time: everything else on this screen is recoverable, and
that one reaches every person on the list.

### What each channel means

| Type | Delivery | Failure |
| --- | --- | --- |
| `TYPE_EMAIL` | one email per recipient, in the recipient's language and this installation's wrapper | the mailer refused it |
| `TYPE_MESSAGE` | a row in `messages` — the account's own inbox | the write failed |
| `TYPE_PUSH` | one web push per subscribed browser ([guide](Pramnos_Push_Guide.md)) | no browser is subscribed |

**An account with no subscription is a failure, not a skip.** It is the ordinary case — most
accounts have never granted notification permission — and recording it as delivered would leave
an operator reading "4,812 delivered" about a message that reached forty people. The same is
true of an installation with no VAPID pair or no encryption library: it is checked before the
send rather than left to the channel, which logs and returns, so every recipient of a message
that was never encrypted would otherwise be recorded as delivered.

Somebody who unsubscribed is the exception, and is recorded as **delivered**: the recipient row
is a record of what happened to a person, and "we honoured their request" is not a failure to
retry on the next run.

### The audience is resolved once

Criteria are stored on the message (`request`, as JSON); the list they meant is stored in
`massmessagerecipients`. Re-resolving at delivery time would silently include accounts
created after somebody approved the send and drop the ones deleted since — so the recipients
would stop matching what was approved.

| Criterion | What it selects |
| --- | --- |
| `usertype_min` / `usertype_max` | a band. A ceiling matters: with a floor alone, "everybody below staff" can only be written as "everybody", which also reaches the operators |
| `validated_only`, `active_only` | on by default — a validated address is the difference between a send and a bounce, and an inactive account is one somebody switched off |
| `language` | the **account's** language. A message in Greek sent to everybody also reaches the people who set their account to English, and they cannot read it |
| `twofactor` | `with` or `without`. Fails closed: on an installation with no `authserver`, "holding a second factor" is nobody, not everybody |
| `last_login_after` / `last_login_before` | the active and the dormant audience. An account that never signed in has `lastlogin = 0`, so it is in the dormant one — which is the correct answer to that question |
| `groups` | in **any** of the chosen groups. Any, not all: "members and volunteers" is a message to both, and the intersection is a smaller audience somebody can name directly |
| `organizations` | in **any** of them. Read from `authserver.user_organizations` by `userid` — or the table and organization column named by `authserver_organization_table` / `authserver_organization_column`, the same settings the organizations screen reads. The membership table is the authserver feature's, so an installation without it matches nobody rather than raising |
| `only_ids` | these accounts and no others — «send this to these three people», the commonest thing anybody wants from this screen |
| `exclude_ids` | everything the rest matched, minus these |
| `exclude_optouts` | a list name. They are skipped at delivery either way; naming it here is what makes the **count** honest |

**Or pick it with segments, groups and a filter.** The compose form also carries the audience
picker ([Audience guide](Pramnos_Audience_Guide.md#the-builder-and-the-picker)): people in any of
some segments and groups, except others, narrowed by a filter for this send, in a consent list.
When anything is picked there, the criteria are `['audience' => <picker state>]` and the table
above is not used; `MassMessageAudience` resolves it with `Picker::final()`, describes it in
words on the message's page, and a state that no longer checks reaches nobody.

**A filter that matches nobody is an empty audience, not everybody.** The dangerous direction:
a group filter falling back to "no filter" is how a message meant for eleven volunteers reaches
every account on the installation, and the operator finds out from the replies.

**`only_ids` does not override the other criteria.** An operator pasting a list from a
spreadsheet has not checked which of those accounts is inactive, unvalidated or unsubscribed,
and a screen that sent to them anyway would be treating a paste as an override of every check
on the page. The preview is where they see which ones dropped out. Ids are read however
somebody has them — commas, newlines or spaces — because all three are the same intention.

### Looking before sending

```php
$preview = (new MassMessageAudience())->preview($criteria);
// ['total' => 4812, 'sample' => [ … 25 rows … ], 'truncated' => 4787]
```

What the criteria *mean* — how many people, and which ones — has to be visible before the
send, because a send to the wrong band of accounts is not something anybody can take back.

**Preview this audience** on the compose form posts the same fields to `MassMessages/preview`,
which resolves them and renders the form again with the answer on it. Nothing is written and
nothing is sent, so an operator can try a filter, look, and change it. It is a form post rather than a fetch on purpose: it has to work identically in
three themes and with no JavaScript.

The sample is a window, not the audience — forty thousand rows are not a thing to render — and
it says how many it is not showing, because a list that silently stopped at twenty-five would
read as an audience of twenty-five.

The last of the criteria is worth dwelling on. The compose screen counts the audience **before** anybody
presses send, because a count is the one number that changes an operator's mind — and a count
that includes nine hundred people who unsubscribed changes it in the wrong direction. An
opt-out from `all` counts for every list: somebody who pressed "stop sending me anything" is not
asking to stay on the announcements list.

An application with its own idea of an audience hands `queue()` its own list of ids instead.

#### Two traps in reading a compose form

Both are in `criteriaFrom()`, both are asserted, and both mail the wrong people when got wrong.

**A false boolean is not an empty value.** `array_filter` cannot tell *the operator unticked
this* from *the operator said nothing*, so the two booleans are written after it:

```php
$criteria = array_filter([...], fn ($v) => $v !== '' && $v !== 0);

$criteria['validated_only'] = (bool) $request->get('validated_only', 0, 'post', 'int');
$criteria['active_only']    = (bool) $request->get('active_only', 0, 'post', 'int');
```

Dropped, `validated_only` reverts to its default — which **excludes** accounts the operator
chose to include, and makes the count on the screen disagree with the send.

**An empty template is a decision.** "No wrapper for this campaign" is a thing somebody chooses
and looks exactly like an empty form field, so the form posts `__default__` for *said nothing*
and `''` for *chose none*. Only the second is stored.

The audit record follows the same rule: no options chosen means **no `options` key**, because an
empty object in the record reads as a decision, and this record is what somebody months later
reads to answer "who was this aimed at, and how".

#### Reading it back

`request` is a JSON column on MySQL and text on PostgreSQL, and **MySQL reformats the document**
— its own key order, spaces after colons. So compare the decoded value, never the serialisation:
a test that matched `'"language":"el"'` passes on exactly one backend.

`criteriaOf()` decodes defensively for the same reason it exists: an older row, or one written by
something else, is not a reason to fail rendering the screen — the screen is how somebody finds
out what happened.

### The send options travel with the criteria

A campaign can also carry a wrapper, an unsubscribe list, open/click tracking and a Gmail
action — the same options the [single-account screen](Pramnos_Email_Writing_Guide.md#a-message-to-one-account) offers, and
they matter more here: a wrapper wrong on one message is a mistake, and wrong on forty thousand
is the send.

They are stored under `options` inside the same `request` JSON, because they are part of the
same decision and a column per option is a migration every time somebody adds one. A row
written before they existed has no `options` key and reads as none.

**Tracking gets its own id per recipient.** One id for the campaign would count the first open
out of forty thousand people as an open, and nothing after it.

The compose screen counts the audience **before** anybody presses send, because a count is
the one number that changes an operator's mind, and it is exactly the number nobody has when
the send is a loop somebody wrote in a controller.

### Sending to a mailing list

The compose form's **Or send to a mailing list** replaces the account criteria with an opt-in
list's confirmed subscribers — with or without an account (criteria key `mailing_list`). The
preview counts and samples them the same way.

- A subscriber who is also an account is **one** recipient, however many addresses it subscribed
  under; a pending address is nobody's recipient.
- Recipients are queued **by address** (`massmessagerecipients.email`, with `userid` 0 when there
  is no account) and each is written to in the language it subscribed in.
- Each message goes out as the list's `MailType`, so the list's unsubscribe header and link come
  with it; a subscriber who left between queueing and sending is skipped and counted as done.
- A list can only be sent an **email** — a subscriber with no account has no inbox here and no
  push subscription. Saving a message addressed to a list makes it one, sets its unsubscribe list
  to the list itself, and stores the list as the whole audience: the account criteria are not
  kept beside it. A message stored otherwise is refused at send.
- On the form, choosing a list hides the account criteria and the opt-out filter, fixes the
  channel to email and fills in the unsubscribe list, read-only. **Track opens and clicks** starts
  ticked on a new message wherever tracking is switched on.

## What kinds of mail this application sends

A *kind* — «password reset», «weekly digest», «sign-in alert» — is the thing a person means
when they say they get too many emails from you, and every feature around mail needs a name
for it: the unsubscribe list, the audit log's name for the message, and the page that shows
somebody the mail they can turn off. A `MailType` is that name, declared once.

```php
use Pramnos\Email\MailType;
use Pramnos\Email\MailTypes;

// Once, at boot — a ServiceProvider or Application.php
MailTypes::register(new MailType(
    'digest',                             // recorded on every send
    'Weekly digest',                      // what a person reads on a preferences page
    'A summary of what happened, every Monday.',
    'digest'                              // the unsubscribe list — omit for transactional
));
```

Then one call at the send:

```php
$mail->type('digest')->setTo($address)->setSubject($subject)->setBody($html)->send();
```

### What the one call decides

Four things have to agree for a message on a list:

| | Without a type | With one |
| --- | --- | --- |
| `List-Unsubscribe` + `List-Unsubscribe-Post` | `offerUnsubscribe('digest')` | from the type |
| The visible link in the footer | the same call | from the type |
| **Not sending to somebody who left the list** | *not checked* — call `Unsubscribe::isOptedOut()` yourself | from the type |
| What the audit log calls it | whatever was typed | the type's name |

The third row is the one that matters. `offerUnsubscribe()` alone puts a working link in the
message and sends it even to an address that already used the previous one — so the reader
unsubscribes twice and decides the sender is lying, which is what the spam button is for. A `type()`d send
to an opted-out address returns `false`, sets `getLastError()` to say why, and **still writes
the `mails` row**: «we did not send this, and this is why» is exactly what an audit log is for.

### The list is what makes it optional

A type with a list is one somebody can turn off. A type without one is transactional and
cannot be — a password reset, a second-factor code, a receipt. Not a judgement about
importance: it is whether the message is a consequence of something the person just did. Those
must arrive for somebody who unsubscribed from everything, mailbox providers do not ask you to
offer an opt-out on them, and offering one anyway teaches people that the link does nothing.

An unknown type name is treated as transactional rather than raising. The thing that would
throw is a send, so a typo would stop a password reset — it means one message goes out without
a link it should not have carried anyway.

### The framework's own

Registered without anybody asking, so a plain installation has a preferences page rather than
only one that thought to declare its types:

| Name | Kind |
| --- | --- |
| `newsignin` | optional — list `newsignin` |
| `second-factor-code` | transactional |
| `device-auth-link` | transactional |
| `security-change` | transactional |

Register the same name to override any of them — the label is what a person reads, and an
application wording it differently, or in another language, should not have to work around the
default.

### The preferences page

After honouring the link, `/unsubscribe` lists every optional type with what it is and whether
this address is receiving it, each row a link carrying its own signed token for that address
and that list — so the page needs no session and cannot be edited into changing somebody
else's settings.

That is the difference between a reader who wanted fewer emails keeping one of four and a
reader who wanted fewer emails receiving none — which a sender would otherwise read as a clean
unsubscribe rather than the failure it is.

`a=in` on a GET turns something back on. It is never honoured for one-click POST: RFC 8058 says
a POST to that endpoint unsubscribes, and a provider that found a parameter turning it into a
subscribe would be right to stop trusting the endpoint.

### Nothing breaks without it

An application that registers nothing keeps working exactly as before. `offerUnsubscribe()`
still takes a list, and mail without a declared type is transactional. This adds a way to say
what you send; it does not require you to.

## Unsubscribing, and what Gmail requires

Gmail and Yahoo require this of anyone sending in volume, and they are not asking for a
gesture. A bulk message must carry `List-Unsubscribe` **and** `List-Unsubscribe-Post`, the
one-click endpoint must work with no login and no confirmation step, and the request must be
honoured within two days. A sender who fails is not told: the mail is quietly filed as spam,
including the mail people wanted.

One call does all of it:

```php
$mail = new \Pramnos\Email\Email();
$mail->to      = 'reader@example.com';
$mail->subject = 'This month at Example';
$mail->body    = $html;
$mail->offerUnsubscribe('newsletter');   // after `to` is set
$mail->send();
```

That sets four things that have to agree — the URL, the `mailto:` alternative, the one-click
promise, and the list name the wrapper renders a visible link from. Set separately they can
contradict each other, and a `List-Unsubscribe-Post` over a URL that shows a confirmation page
is worse than no header at all: a provider follows it, gets a page, and counts the message as
unhandled.

Before sending, ask:

```php
if (\Pramnos\Email\Unsubscribe::isOptedOut($address, 'newsletter')) {
    continue;   // they asked us to stop
}
```

### Notifications say which list they belong to

```php
class WeeklyDigest implements NotificationInterface
{
    public function unsubscribeList(): string { return 'digest'; }
    public function toMail(mixed $notifiable): array { … }
}
```

`MailChannel` then does both halves without being asked: it skips an address that has opted
out, and it offers the unsubscribe on the message it sends.

**A notification that declares nothing is transactional** and gets none of it — no link, no
header, no suppression. That is the right default. A password reset must arrive even for
somebody who unsubscribed from everything, mailbox providers do not ask you to offer an
unsubscribe there, and a link on such a message teaches people that the link does nothing.

The framework's `newsignin` alerts are the one exception, because they have a real preference
behind them: the account can already turn them off on its privacy screen, and honouring an
unsubscribe flips that same checkbox.

### Two records, two jobs

An unsubscribe writes **two** things, and the difference is the whole design:

| | `pramnos.emailoptouts` | `authserver.user_consents` |
| --- | --- | --- |
| What it is | the **suppression list** | the **consent trail** |
| Read by | `isOptedOut()`, before every optional send | somebody answering a GDPR request |
| Shape | current state — opting back in deletes the row | append-only, one row per grant *and* per withdrawal |
| Keyed by | the **address** | `userid` |
| Aged out | never | 7 years, by policy |

Why not keep unsubscribes in `user_consents` alone? Because they need both. As the lookup it
cannot be the consent table: that is append-only, so "may I
send to this address" becomes "find the latest row for this user and this type and read its
`revoked_at`", per send; it is keyed by account, and somebody on a list often has none; and its
seven-year retention would eventually drop a withdrawal and start sending again.

As the **record** it is exactly right — a withdrawal of consent, with a legal basis and an
address, which is what that table exists for. So the event goes there too, when the address
belongs to an account and the `auth` feature is present. Best-effort in every direction: a
consent trail must not be the reason an unsubscribe fails, which is the one failure a mailbox
provider counts against every future message.

It is the same pairing the framework already uses for a mass message — the inbox row in
`messages`, the delivery record in `massmessagerecipients`.

### The token is signed, not stored

```php
$token = \Pramnos\Email\Unsubscribe::token('reader@example.com', 'newsletter');
```

Nothing is written when a message goes out — a million-recipient send would otherwise write a
million rows for links most people never open. The address and the list travel inside the
token, signed with the installation's key, so an edited one fails verification and nobody can
unsubscribe a stranger by changing a URL. There is no expiry, deliberately: people unsubscribe
from a message they found six months later, and "this link has expired" is a sender making its
own problem the reader's.

### The endpoint

`/unsubscribe` is a framework controller and is **public**, which it has to be: a one-click
request arrives from a provider's server with no session, and an address on a list does not
always have an account at all.

| Method | Caller | Behaviour |
| --- | --- | --- |
| `POST` | a mailbox provider, on the reader's behalf | unsubscribes, answers `200`, no confirmation |
| `GET` | a person clicking the footer link | unsubscribes and says so on a page |

It is exempt from `CsrfMiddleware` by default, and that is not an oversight to be tidied away:
Gmail has no token to send. A record that could not be written answers `500` to one-click, so
the provider retries, and says so on the page rather than promising something that did not
happen.

An application that wants its own look declares its own `Unsubscribe` controller, which takes
precedence.

### What a list is

A short name you choose — `marketing`, `newsletter`, `digest` — plus the reserved `all`, which
suppresses everything carrying a link. Records live in `emailoptouts`, keyed on the **address**
rather than a user id: an unsubscribe arrives from a mailbox, and often from somebody with no
account — forwarded to, added to a list, inheriting an address.

For a list backed by a preference somebody can see, say what unsubscribing means:

```php
\Pramnos\Email\Unsubscribe::handle('digest', function (string $email, string $list) {
    Digest::disableFor($email);
});
```

Otherwise a row the profile screen knows nothing about stops the mail while the checkbox still
says it is on — a switch that lies to the person holding it.

A name ending in `*` handles a whole family of lists — `'invoices-*'` for one list per customer —
unless one of them has a handler of its own. The framework's own families: `newsignin` turns the
account's alert off, and `askreport-{id}` — a scheduled report's email — stops that report's email
for the accounts with the address and clears the opt-out again, so the report's subscription is
the one switch (see the [LLM guide](Pramnos_LLM_Scheduled_Tasks_Guide.md#scheduled-tasks-questions-reminders-reports)).

The unsubscribe link, like the wrapper's `$siteurl` where no `site_url` setting is set, is built on
the site's configured address (`APP_URL`), so mail a cron run sends leads to the site; the console
also defines `sURL` from `APP_URL` before falling back to a placeholder.

### `isOptedOut()` fails closed

Alone among this framework's reads, it answers **true** when it cannot tell. Sending to
somebody who unsubscribed is the one mistake a provider counts against every future message,
including the transactional mail this is never asked about. A message not sent during a
database outage is a message the next run sends.

### And everything else fails open, on purpose

The mirror image, and the reason it is worth writing down: **nothing may make an unsubscribe
fail**. The suppression row is written first, and everything after it is best-effort:

| What can go wrong | What happens |
| --- | --- |
| no `authserver.user_consents` table (the `auth` feature is off) | the consent event is skipped; the unsubscribe stands |
| the address has no account | same — the suppression record is keyed by address, and is the whole story for a subscriber with no account |
| an application's `handle()` callback raises | logged; the unsubscribe stands |
| the account's own preference cannot be written | logged; the unsubscribe stands |
| **the suppression row itself cannot be written** | `optOut()` returns **false** |

That last line is the one a caller has to act on. An endpoint answering "done" while writing
nothing leaves a person unsubscribing again, and again, from mail that keeps arriving — and their
next move is the spam button, which is the outcome the whole feature exists to avoid.

### A blank address gets no token

`token('')` returns an empty string, and `url()` and `mailto()` turn that into an **omitted
header** rather than one pointing at nothing. A signed token for the empty address would
verify, the endpoint would call `optOut('')`, be refused, and show the reader a failure for a
link the framework had generated.

### The rest of the compliance list

The parts that are not code:

- **SPF, DKIM and DMARC** on the sending domain. Gmail requires authentication from every bulk
  sender; without DKIM the headers above will not save you. Check yours with
  [`mail:dns-check`](Pramnos_Email_Deliverability_Guide.md#what-dns-says-and-what-the-application-cannot-see).
- **A `From:` domain you own**, matching the DKIM signature. Not a free-mail address.
- **A reply address that a person reads.** `admin_replymail`, and the `mailto:` unsubscribe uses
  it too.
- **Spam complaints under 0.10%**, measured in Google Postmaster Tools — the number the
  unsubscribe link exists to keep down, because the alternative the reader has is the spam
  button.
- Every message goes out as `multipart/alternative` with a plain-text part, which `Email` builds
  from the HTML — see [the plain-text part](Pramnos_Email_Writing_Guide.md#the-plain-text-part).

---

## Opt-in mailing lists: a newsletter

Everything above is **opt-out**: a list's mail goes to everybody who has not left it. That is
right for a digest an account turned on in its settings. A newsletter is marketing, and GDPR and
ePrivacy expect the other default — it goes only to those who agreed, and the agreement has to be
provable. Declare the type `optIn`:

```php
use Pramnos\Email\MailType;
use Pramnos\Email\MailTypes;

MailTypes::register(new MailType(
    'newsletter',
    'Newsletter',
    'News about the product, once a month.',
    list: 'newsletter',
    optIn: true,
));
```

A send of that type goes **only to a confirmed subscriber**; to anybody else it is refused and
recorded, exactly as a send to an opted-out address is (`Not sent: … has not subscribed to
"newsletter"`). `MailTypes::allows()` answers the same way. An opt-in type must name a list —
`new MailType(…, optIn: true)` without one throws.

### Subscribers, account or not

`Pramnos\Email\MailingList` keeps who asked, in `pramnos.mailing_list_subscribers` — one row per
list and address, with the account's id when the address has one. The people waiting for
registration to open are exactly the ones a landing-page form is for, so an account is not needed.

```php
$lists = new \Pramnos\Email\MailingList();

$lists->subscribe('newsletter', $email, [
    'source'   => 'landing',          // form, account, wordpress, import…
    'consent'  => $sentenceShown,     // the words the person agreed to, as shown
    'language' => 'el',               // what to write to them in
    'ip'       => $ip,
]);                                   // pending, and a confirmation mail

$lists->isSubscribed('newsletter', $email);   // true only once confirmed
$lists->unsubscribe('newsletter', $email);
```

The rules it keeps:

| | |
|---|---|
| **Double opt-in** | A new address gets a confirmation mail and stays `pending` until the link is followed. The mail is transactional. |
| **The link is not the confirmation** | It opens a page with a button. Mail scanners follow every link in a message and would otherwise confirm on the reader's behalf. |
| **Signed and expiring** | A `MailAction` token for this list and address, valid seven days. It confirms only a `pending` row, so an old link does not put back an address that left. |
| **At most one more mail an hour** | Asking again for a pending address resends only after `RESEND_AFTER`. |
| **Proved addresses skip the mail** | `'confirmed' => true` for an account's own verified address, or an address arriving on a signed link to it. |
| **Leaving by any route ends it** | `Unsubscribe::optOut()` marks the row unsubscribed — the footer link, one-click, the preferences page; `all` ends every list. |
| **Coming back restores only this list** | Confirming clears an opt-out of *this* list, not of `all`: somebody who left everything and then asked for the newsletter did not ask for everything else back. |

`subscribe()` returns what happened (`confirmation_sent`, `already_pending`, `already_confirmed`,
`confirmed`) for the caller's log. **A public form must answer the same whatever it returns**, or
it becomes a way to find out who is subscribed.

### The form: `/mailinglist/subscribe`

A framework controller, so it answers in every application with no wrapper:

```html
<form method="post" action="/mailinglist/subscribe">
  <?php echo \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(); ?>
  <input type="hidden" name="list" value="newsletter">
  <input type="hidden" name="source" value="landing">
  <input type="email" name="email" required>
  <p>News about the product, once a month.</p>
  <button>Subscribe</button>
</form>
```

- **The consent stored is the type's description**, not anything the form sends — a trail whose
  sentence the visitor's browser supplied proves nothing. Show that sentence beside the button.
- `source` is kept when it is a short identifier (`[a-z0-9_-]`, up to 32), `form` otherwise; the
  language is the page's current one, and the address the request came from is recorded.
- Refused before anything is written: a GET (`405`), a missing or stale form token (`403`), a list
  no opt-in type declares (`404`), a malformed address (`400`).
- **Every valid request gets the same answer** — new, pending, already subscribed, or a failure
  that was logged — so the form cannot reveal who is on a list.
- Ten requests an hour per address; past that, `429`.
- A request with `Accept: application/json` gets `{"ok": …, "message": …, "state": "pending",
  "list": …}`; a browser gets a page.

A single-page application needs `mailinglist` among the paths its web server sends to PHP; `init`
includes it for new projects.

### The confirmation page: `/mailinglist/confirm`

The link in the confirmation mail opens a page with a **Confirm** button; the button confirms.
Two steps, because mail scanners open every link in a message, and confirming on the first request
would subscribe everybody whose provider checks links. The token is the credential, so the button
needs no session. An expired or foreign token, and a row no longer pending, each get a page that
says so.

### Where these pages render, and counting a confirmation

The subscribe, confirm and unsubscribe pages render **in the theme's standalone layout**,
`<theme>/login.php`, when the theme has one (every theme `pramnos init` writes does). That
layout carries the application's `<head>` (its stylesheets, its scripts, Tag Manager, the
cookie banner) and its footer with a way back to the site, without the site header. A theme
without that file gets the self-contained page instead, because its full chrome may need a
signed-in user to render, and somebody arriving from a mail link usually is not one.

**Each page says what happened, for a script to read.** The content sits in an element with
`id="pramnos-standalone"` and `data-` attributes:

| `data-state` | Page |
|---|---|
| `pending` | after the form: a confirmation mail was sent |
| `awaiting-confirmation` | the link opened, the button not pressed yet |
| `confirmed` | the button confirmed the subscription |
| `nothing-to-confirm` | the address was already confirmed, or left |

`data-list` names the list. A Tag Manager trigger on
`#pramnos-standalone[data-state="confirmed"]` counts the double opt-in, which is the one
moment a newsletter's numbers are about.

**On the server**, the confirmation fires `mailinglist.confirmed`, wherever it comes from:

```php
use Pramnos\Email\MailingList;
use Pramnos\Event\Event;

Event::listen(MailingList::EVENT_CONFIRMED, function (string $list, string $email): void {
    // count it, welcome them, tell the CRM
});
```

It fires once per address. A second press of the button confirms nothing and fires nothing.

### Hooks on this page

| Name | Fires in |
|---|---|
| `mailinglist.confirmed` (`MailingList::EVENT_CONFIRMED`) | `Email/MailingList.php` |

See [Extensibility Guide](Pramnos_Extensibility_Guide.md) for the full catalog.

### On the account screen and the preferences page

**Account → Privacy** lists every opt-in type as a checkbox, **off** until the account joins —
consent to marketing is not a default. Ticking it subscribes the account's own address: at once
when the address is verified (`validated = 1`), with the confirmation mail when it is not, and the
screen says a pending list is waiting to be confirmed. Unticking is the same as the unsubscribe
link. The form posts `lists[<list>]`.

`/unsubscribe`'s preferences page shows opt-in types too, **on only for a confirmed subscriber**.
Turning one back on from there subscribes the address outright, with `source = preferences`: the
link is signed for that address, which proves the mailbox as well as a confirmation mail would.

### The administration screen: `/admin/MailingLists`

Every opt-in list, with how many addresses are **waiting to confirm**, **confirmed** and **left**;
choose one to see its subscribers, newest first, fifty to a page, searchable by part of an address
and narrowed by state. Each row says where the person joined (`source`), in which language, and
when they asked, confirmed and left, and links to the account where there is one.

| Action | What it does |
| --- | --- |
| **Send confirmation again** | on a pending row: the confirmation mail once more, whatever the hourly limit a public form is held to |
| **Unsubscribe** | the address leaves the list exactly as by the footer link — the row ends, the opt-out and the consent event are written |
| **Export confirmed (CSV)** | `email, language, confirmed_at, userid` for the list's confirmed addresses |

Both row actions are `POST` with the session's token. Nobody is **added** on this screen: an
opt-in list holds the people who asked, with the proof that they did, and an address typed in by
an administrator has no consent behind it.

The screen is `MailingListsController` (ability `admin.mailinglists`, under **System** in the
menu). `init` writes its wrapper, `src/Admin/Controllers/MailingLists.php`; a project scaffolded
before it existed adds the same three-line class:

```php
namespace App\Admin\Controllers;

class MailingLists extends \Pramnos\Application\Controllers\MailingListsController
{
}
```

In code, the same reads and writes: `MailingList::lists()`, `counts()`,
`subscribers($list, $status, $search, $page, $perPage)`, `resendConfirmation($id)`,
`unsubscribeSubscriber($id)`, `confirmedExport($list)`.

### On an account's administration page

`/admin/users/view/{id}` has an **Email** panel: every kind of mail the person can turn off and
every opt-in list, in its state for the account's address, and whether the address left
everything. What an administrator can do there, each `POST` with the token and written to the
account's activity log:

| For | Off | On |
| --- | --- | --- |
| mail sent unless they say stop | the unsubscribe, `source = admin` | clears **that list's** opt-out only — `Unsubscribe::clearOptOut()` — and records the consent |
| an opt-in list | the unsubscribe | sends the **confirmation mail**; the subscription starts when they confirm |
| everything | the unsubscribe from `all` | clears the `all` opt-out, leaving each list's own |

"On" is for the person's own request — the buttons say so before they act. An administrator can
ask for consent to a list, never give it.

### The consent trail

Each row keeps the sentence the person agreed to, where they agreed (`source`), the address the
request came from, and when they asked, confirmed and left. For an address with an account,
confirming also writes an `authserver.user_consents` event, as leaving does.

The rows are part of the account's data: `Account` exports them under **Mailing lists** and
erases them, found by account **and** by address, so a subscription made before the account
existed goes with it. The table is on the personal-data denial list, with `emailoptouts`.

---
