---
use_cases:
  - Tracking opens and clicks, and reading what they mean
  - Adding Gmail actions and a brand mark beside the subject
  - Checking DNS (SPF, DKIM, DMARC) for the sending domain
  - Handling bounces and addresses mail does not go to
  - Sending from a customer's own domain with its own signature and server
  - Building a one-click action a mail client can perform without a session
---

# Deliverability: tracking, Gmail actions, DNS, bounces

Part of the [email guide](Pramnos_Email_Guide.md).

## Gmail actions, and the brand mark beside the subject

Gmail looks for `application/ld+json` in a message and, when it finds a block it recognises,
draws a control **in the message list** — a "Confirm" button beside the subject, before the
message is opened. That is the difference between a confirmation that takes one tap and one that
takes four.

```php
use Pramnos\Email\Actions;

$mail = new \Pramnos\Email\Email();
$mail->setSubject('Confirm your address')
     ->setBody($html)
     ->addStructuredData(Actions::confirm(
         'Confirm address',
         'https://example.com/confirm/abc123'
     ))
     ->setTo($address)
     ->send();
```

| Builder | Draws | Note |
|---|---|---|
| `Actions::confirm($name, $url)` | a one-tap confirm button | The URL **must act on the first request** — see below |
| `Actions::view($name, $url)` | a link promoted to a button | `target`, not `handler`: a place to go rather than something to call |
| `Actions::save($name, $url)` | "save this offer" | |
| `Actions::rsvp(['yes' => …, 'no' => …, 'maybe' => …])` | yes / no / maybe | Three handlers; the answer *is* which URL was called |
| `Actions::sender($name, $logo, $url)` | the brand mark beside the subject | The "highlight" half — same block, not an action |
| `Actions::promotion([...])` | a card in the Promotions tab | Wrong twice over on transactional mail: it is not a promotion, and it invites the classifier to file a password reset where nobody looks |

### Before you conclude it is broken

**Gmail displays none of this until the sending domain is registered with Google.** That is the
first thing anybody hits, it is invisible from inside the application, and it is not a bug in
this code. `Actions::requirements()` returns the list as data for exactly that reason:

- the sending domain must be registered with Google;
- SPF or DKIM must authenticate the `From:` domain, and DMARC must pass;
- the action URL must be HTTPS on a domain you control;
- **a `ConfirmAction` handler must act on the first request** — no confirmation page, no
  sign-in. Gmail sends a POST and does not follow up;
- one action per message: Gmail shows the first it understands and ignores the rest.

Everything is still correct and harmless without registration. Other clients ignore what they do
not understand, and the markup is invisible in the rendered message.

### One-click actions: `MailAction`

A `ConfirmAction` needs a URL that acts on the **first** request, with no confirmation page and no
sign-in, because Gmail issues one POST and does not follow up. `Pramnos\Email\MailAction` is that
endpoint, generalised from the one the framework already had — RFC 8058 one-click unsubscribe — so
an application adds its own in three lines rather than writing a controller, a token format and a
signature check.

```php
// once, in a service provider
use Pramnos\Email\MailAction;

MailAction::register('confirm-order', function (array $claim): bool {
    return (new Order((int) $claim['order']))->confirm();
});

// in the mail
$url = MailAction::url('confirm-order', ['order' => 42], 172800);
$mail->addStructuredData(Actions::confirm('Confirm order', $url));
```

That is the whole integration. `/mailaction` is a bundled controller, so there is no route to
register, and an application that wants its own look declares its own `MailAction` controller,
which takes precedence.

#### The token is the whole authorisation

There is no session and no CSRF token, because the caller is a mailbox provider's server and
neither exists. That is not something introduced here — a password-reset link has always worked
this way — but it decides everything else:

- **Signed**, with a key stored as `mailaction_secret` on first use. Rotating it invalidates every
  outstanding link.
- **Expiring**, and the expiry is *inside* the signed material. An expiry beside the signature is
  one the holder can edit, which makes it advice.
- **Naming one action and one payload.** The payload is readable by anybody holding the token, so
  it carries identifiers, never secrets.
- **`verify()` answers `null` for a forgery, a malformed token and an expired one alike** — one
  answer, because distinguishing them tells somebody probing how close they are. `expired()`
  exists separately so a *page* can say "this link has expired, ask for another", which is useful
  and safe to say.

#### GET does not act, unless you say it may

```php
MailAction::register('verify-address', $handler, actOnGet: true);
```

By default a GET performs nothing and a person following the visible link is shown a page with a
button. That is not ceremony: a GET is issued by things nobody asked for — a link scanner in a
corporate mail gateway, a client prefetching to build a preview, an antivirus proxy — and if a GET
acted, those would act. Gmail sends a POST, so the button works either way.

Opt in when the effect is safe to trigger that way. Confirming an address is the clear case:
whoever holds the message has already proved the point.

#### What the handler owes you

**It must be idempotent.** Gmail retries, a reader may press twice, a client may prefetch.
Confirming an already-confirmed thing is a *success* — returning `false` there turns a second
press into a 500, and on a provider that retries 500s, into a loop.

`false` means "could not, try again": it becomes a 500, which is correct, because the usual cause
is a database that was briefly away and that is exactly what retrying fixes. A thrown exception is
caught, logged and reported the same way, without showing the reader the internals.

| Situation | A machine gets | A person gets |
|---|---|---|
| Done | `200` | a page saying so |
| Invalid token | `400` | "this link is not valid" |
| Expired | `410` | "this link has expired" |
| Registered nowhere | `501`, naming the action | the same, so the cause is findable |
| Needs a POST | `405` | a page with a button |
| Handler failed or threw | `500` | "could not complete this" |

The `501` matters more than it looks: a valid token for an action nothing handles is almost always
a handler registered in a service provider that did not run — a feature switched off, a provider
removed — and answering "not valid" would send somebody to inspect the token instead of the
registration.

#### What ships registered

`revoke-sessions` — the handler a "this wasn't me" button needs, registered by the `authserver`
feature's service provider (so present only when that feature is enabled). It ends every
session on the account, and the framework's own new-sign-in alert **does not use it**; see below.

```php
$url = MailAction::url('revoke-sessions', ['user' => $userId], 3600);
$mail->addStructuredData(Actions::confirm('It wasn\'t me', $url));
```

A short TTL is right for that one: the link ends every session on the account, and a message that
sat in a mailbox for a month should not still be able to.

### Where the framework uses this itself, and where it will not

**The password-reset mail carries a `ViewAction`.** That mail contains exactly one link, which is
its entire purpose, so an action pointing at it exposes nothing the message did not already
expose — and turns four taps on a phone into one. A `ViewAction` needs no handler and makes no
promise about the first request, so there is nothing to build for it.

**No `ConfirmAction` on the framework's own mail — although the handler for one exists.** The
contract is real, and `MailAction` above is how it is met: `revoke-sessions` is registered and
ready. What the framework does not do is *put it in a message*, because the only message it would
belong in is the new-sign-in alert, and that is the paragraph below.

**The new-sign-in alert carries no action at all, and that is a decision.** It contains no link
either: a link in an unexpected security email is the shape of the attack it warns about, and a
button in the message list is the same thing, larger and easier to press. The message tells the
reader to open the site themselves. The `revoke-sessions` handler makes a one-tap "this wasn't
me" possible; adding one to this alert would be a reversal of that judgement — an application's
call to make for its own users, not something the framework assumes.

### Where the block goes

Into the `<head>`, which is where Gmail's own documentation puts it and where a `<script>` cannot
disturb the layout. A body fragment with no head gets it before `</body>`; a message with neither
gets it prepended, so the feature does not depend on which template an application uses.

It is encoded through [`Html\Seo::jsonLd()`](Pramnos_Html_Components_Guide.md#seo) rather than
`json_encode()`. That matters: a `</script>` inside any value ends the block early and everything
after it is parsed as markup, and these values come from record titles and user input. And it
never reaches the plain-text part, because the converter drops `head` and `script` outright.

An empty array is ignored, so `addStructuredData(Actions::rsvp([]))` is safe. A builder with
nothing to describe returns nothing — a `<script>` containing `[]` would be a claim that the
message has no actions, which is a different statement from making no claim.

---

## Tracking: opens and clicks

Tracking is **off** unless three separate things are true. That is the design, not a precaution:

1. `'email' => ['tracking' => true]` in `app.php`. Absent means off.
2. The message belongs to a **list** — `offerUnsubscribe()` was called on it. Transactional mail
   is never tracked at any setting.
3. `enableTracking()` was called on that message.

```php
$mail->setSubject('This month at Example')
     ->setBody($html)
     ->setTo($address)
     ->offerUnsubscribe('newsletter')   // the consent, and what makes it a list
     ->enableTracking()
     ->send();
```

Gate 2 is the one that matters. A pixel in a password reset is a remote image in the most
sensitive message a system sends, to somebody who agreed to nothing — so `enableTracking()` only
*requests* tracking, and the pixel is added at send time only when all three gates hold.

### An open is a weak signal, and getting weaker

This is the part worth reading before deciding the feature is useful.

- **Apple Mail Privacy Protection**, on by default since iOS 15, fetches every remote image
  through Apple's proxy **the moment a message arrives** — whether or not anybody ever opens it.
  Uncorrected, that reports an open for every Apple recipient, minutes after sending.
- **Gmail** proxies and caches images. The fetch comes from Google, so the IP tells you nothing
  about the reader, and later opens may never reach you at all.
- **Many clients block remote images**, Outlook for external senders among them. A real open
  records nothing.

So opens and proxy fetches are counted in **separate columns and never added together**:

| Column | Means |
|---|---|
| `opens` | A fetch that did not come from a known mailbox proxy. The closest thing to a reader. |
| `proxy_opens` | A provider fetched it on delivery. Says the message arrived, not that it was read. |
| `clicks` | Somebody followed a link. **This is a person.** |

A single "opened" figure is how a message nobody read gets reported at a 70% open rate. If you
take one number from this feature, take the clicks.

Proxies are recognised by user agent, plus the two networks that fetch on delivery — Apple's
identifies as Safari, so the user agent alone cannot name it. **A proxy that stops identifying
itself will be counted as a reader**, which is the honest limit of the method.

### Clicks

`Tracking::wrapLinks()` rewrites every `http(s)` link so following it is recorded and the reader
is redirected. Left alone: `mailto:`, `tel:`, in-page anchors, and **the unsubscribe link** — a
reader unsubscribing is exercising a right, and routing that through a tracker is both distasteful
and a way to break the one link a mailbox provider tests.

**A click link works for thirty days from the moment the message was sent as**: the `date` its
`mails` row records, which for a queued message is when it was queued.
`Tracking::linkSentAt()` and `wrapLinksSentAt()` take that moment, and `MailAction::tokenUntil()`
an expiry. The same message, link and moment give the same link, so a stored body can be given
its links again exactly as they were sent. `link()` and `wrapLinks()` count from now.

**The destination lives inside the signed token**, never in the URL. A tracker that reads its
destination from a query parameter is an open redirect, and an open redirect on a domain that
sends mail is a phishing kit somebody else gets to use: the link comes from your domain, in a
message that looks like yours, and lands wherever the attacker chose.

### The routes, and the tables

`/emailpixel` and `/emailclick` are bundled controllers — no route to register.

`emailtracking` holds one row per tracked message; `emailtrackingclicks` holds one row per link
followed, because *which* link is the only question worth asking of a click. Both are created by
a migration.

The pixel always answers with the image, whatever happened behind it — an unknown id, a database
that is away, a message that was never tracked. A broken image in the middle of a message is a
worse outcome than a lost measurement.

### An open or a click is something the person did

When the mail log ties a tracked message to an account (`mails.userid`), a counted open and a
click are also recorded as that person's events — `email.opened` and `email.clicked`, with the
message, its list and the link — so a filter or a rule can ask "opened a newsletter in the last
30 days" ([Audience guide](Pramnos_Audience_Guide.md#what-people-did-person-events)). A proxy
fetch is not an open there either. Best-effort, like the rest of tracking.

### One campaign's opens and clicks

A mass message's page shows its tracked total, how many **people** opened it, how many were
fetched **only by a mailbox provider**, and how many clicked — counted per recipient, so two
clicks by one person are one person who clicked. **Who opened and clicked**
(`/admin/MassMessages/tracking/{id}`) lists the recipients with their opens, provider fetches,
clicks and first times, narrowed to `?show=opened`, `clicked` or `unopened`, and the links
followed with how often and by how many.

A campaign is found through the `mails` rows its sends wrote: `module = massmessage`,
`moduleinfo` = the message's id. Any module can do the same by setting `$email->moduleinfo`
before sending, and read it back with:

```php
Tracking::campaign('massmessage', '42');              // tracked, opened, proxyOnly, clicked, clicks
Tracking::campaignRecipients('massmessage', '42', 'clicked');
Tracking::campaignLinks('massmessage', '42');         // url, clicks, people — most followed first
```

A message whose `mails` row has no `moduleinfo` belongs to no campaign: its opens and clicks
are on each message's own page on the email history screen.

### Privacy

This is processing personal data. Disclose it in the privacy policy the list's subscribers agreed
to, keep the unsubscribe working, and do not switch it on for transactional mail — the framework
will not let you, but the reasoning is worth carrying into whatever you build beside it.

### What was actually sent

`/admin/emails` lists every outbound message from the `mails` audit log — recipient, subject,
module, date, status — with the full body and a resend. Tracked messages show their figures in an
**Opens** column, with prefetches marked apart. A message that was not tracked shows a dash, which
is the honest rendering of "nobody measured this".

The status reads **Sent**, **Queued** or **Failed** (`Mail::STATUS_*`), and a failed badge carries
the reason in its title — read from `extrainfo`: the outbox's `last_error`, an `error` in an
application's serialized array, or the text itself. **Send this email again** is a `POST` with the
session's token. A message that never went is queued again in place and dated now, so the
outbox's deadline counts from the resend; a sent one is not offered at all.

An application shows the screen in its own terms by extending `EmailsController`:

```php
class Emails extends \Pramnos\Application\Controllers\EmailsController
{
    /** The filter's options and the badges' words, as value => label. */
    protected function statuses(): array
    {
        return parent::statuses() + [3 => t('Canceled')];
    }

    /** Columns of its own after Status — shown escaped, and searchable. */
    protected function extraColumns(): array
    {
        return ['From' => 'frommail', 'Sender name' => 'fromname', 'Recipient name' => 'toname'];
    }

    /** "It never arrived": a sent message may go again. */
    protected function mayResend(array $row): bool
    {
        return true;
    }
}
```

`statusBadge(int $status, array $row)` draws one status cell, on the list and on the message's
own page. `extraColumns()` takes column names only — anything else is dropped, because the list
builds its query from them. A resend `mayResend()` allows for a **sent** message queues a new row,
a copy with the same body, names and outbox settings, and leaves the first as it was: the history
says the message went twice, which is what happened.

A message's own page draws the body in a sandboxed frame, scripts off, from
`MessageReport::preview()`: **without the tracking pixel**, so looking at a message is not counted
as its recipient opening it, and with `<base target="_blank">`, so a link opens in a window of its
own. The tracked links stay — following one from the preview is how an administrator checks that
clicks are recorded — and the Tracking tab and its count are fetched again when the reader comes
back to the page (`data-pf-refresh-on-return` in `pf-utils.js`, on any element with an id).
Under the message, the report — tracking, Gmail's structured data, every link and where it really
goes, the text-only part — is translated, its notes from `MessageReport` included, and a link
breaks anywhere rather than running off the page: an unsubscribe link is one word of two hundred
characters.

Every `send()` writes that row, sent or failed. An application that already keeps its own row
for each message in the same table gets two; it turns the framework's off for every send,
including `Email::sendMail()`:

```php
// app/app.php
'mail' => ['record' => false],
```

or for one message with `$email->recordToMails = false`. With recording off, `queue()` has no
outbox row to write and sends at once.

### The account a message was for

```php
(new Email())->setTo($user->email)->forUser($user->userid)->setSubject($s)->setBody($b)->send();
```

`forUser()` records the account in the row's `userid`, and a notification's mail records its
notifiable's account on its own. An account's mail is then its own whatever address it went to:
the account's page counts and lists it, its "See all" opens the history with `?user=`, and the
history names the account in a column — messages logged before the column, or to an address with
no account, are found by the address as before (`Pramnos\Email\MailLog::ofUser()`). A message's
"← Back" returns to the page it was opened from.

The column comes with a migration, `add_userid_to_mails`, which also builds an index
`(userid, date)` over the whole log. A log of tens of millions of rows can leave it for a
maintenance window — `'migrations' => ['exclude' => ['add_userid_to_mails']]` — and until it runs
everything matches by address.

### Where a sent message's body is kept

A sent message's body is not kept in its `mails` row but in the **body store**
(`Pramnos\Email\BodyStore`):

```php
// app/app.php — these are the defaults
'email' => ['bodies' => [
    'store' => 'auto',        // 'database', 'files' or 'content'; auto: database where mail_bodies exists
    'path'  => 'var/emails',  // files: under the application's root, or an absolute folder
    'disk'  => null,          // files on a storage disk instead, by its name in 'storage' — S3, say
]],
```

| Store | Where | The row's `path` |
|---|---|---|
| `database` | `pramnos.mail_bodies`, a compressed hypertable. Its migration creates it only on TimescaleDB | `db:` |
| `files` | `var/emails/2026/10/19/13/<md5>.body.gz`, one per distinct body and hour | relative to the root, or `disk:<name>:<path>` |
| `content` | the row's `content`, as before | empty |

`auto` is `database` where the table exists and `files` elsewhere. Measured on real bodies, the
hypertable keeps them in about a seventeenth of their size. A gzipped file each saves little
once the filesystem's 4 KB blocks are counted.

**What is kept is the body before what differs per recipient.** The tracking pixel and the
tracked links are added as the message is sent. The unsubscribe link the framework issues is
kept as a placeholder naming the list; a link of the application's own is kept as it is. So a
newsletter to a hundred thousand people is one body. The Gmail actions' structured data is kept
in the body. `hash` is the md5 of what is kept.

**Reading gives back what was sent.** `Pramnos\Email\Body::of($row)` puts the reader's
unsubscribe link back, and for a tracked message wraps the links and adds the pixel as of the
moment it was sent (`Tracking::wrapLinksSentAt()`). The result is the HTML that left, byte for
byte, as long as the site's address and the signing secret have not changed.
- **Where it is used:** everything that shows or sends a stored message reads through it — the
  history screen, its report, the outbox worker, the debug panel's sign-in code — and
  `Messaging\Mail::body()` does the same for the model.
- **Bodies an application kept itself:** `content`, or a gzipped `.html.gz` file its `path`
  names relative to the root, are read as they are.
- **What a path may read:** a path is read only from inside the root or the store's folder, and
  only as a body's kind of file (`.gz`, `.html`), so a row cannot point it at anything else.

**A queued message** keeps its body in `content` for the worker, as composed. Once sent, it moves
to the store.

### A templated message: kept once, filled for each

A message that is the same for everybody but a few values hands the framework the template and
the values, rather than the filled body:

```php
$mail->setTo($user->email)
     ->setSubject('Hello {firstname}')
     ->setBodyTemplate('<p>Dear {firstname}, your plan renews on {renewal}.</p>', ['renewal' => '1/11'], $user)
     ->send();
```

The values are the account's (`TemplateValues::forUser()`) with the ones given over them. The
subject's placeholders are filled too. The body kept is the template, wrapped, once for every
recipient of it, and the values go into the row's `bodyvars`. Reading fills it again.

**The order is what makes it exact.** A templated message is wrapped as the template and filled
afterwards, so what was sent is, by construction, the kept template filled. The preheader a
wrapper prints comes from the template, filled, and so does the subject in its `<title>`.

| Placeholder | Value |
|---|---|
| `{firstname}`, `{first_name}`, `{lastname}`, `{last_name}`, `{full_name}` | the account's names |
| `{username}`, `{email}`, `{id}` | the account's |
| `{url}`, `{login_url}` (and `{Login_url}`) | the site, its sign-in page |

The account's values are its own text, so they are escaped: a name with a `<` in it is shown,
never read as markup. An application adds its own values with a provider:

```php
// app/app.php
'email' => ['template_values' => App\Mail\Values::class],   // implements Pramnos\Email\TemplateValueProvider
```

They win over the account's, and go in as given: HTML stays HTML, so a `{photo}` can be an
`<img>`. A value that is the user's own text is the provider's to escape. A placeholder nobody
fills is left as it is, so the mistake shows in a test send.

A queued templated message is kept filled. Without the `bodyvars` column, before its migration
has run, a templated message is kept filled as well. Either way it reads back the same.

**A store that cannot be written** keeps the body in `content` and logs why; a body is never lost
to its store. The application has to be able to write the `files` folder.

**Moving bodies from files into the database.** An installation that kept its bodies on disk,
and has TimescaleDB now, moves them into `pramnos.mail_bodies`:

```bash
php bin/pramnos mail:bodies-to-database --dry-run          # how many rows there are to move
php bin/pramnos mail:bodies-to-database                     # a thousand rows a transaction
php bin/pramnos mail:bodies-to-database --include-content   # bodies kept in mails.content as well
```

- **What it reads:** each row's body as it is kept — an application's `.html.gz`, the store's
  `.body.gz`, a file on a storage disk. It then points the row at `pramnos.mail_bodies` (`path` =
  `db:`), so the row reads exactly as before.
- **Stopping and starting:** a row already moved is not read again, so a run that was stopped is
  finished by the next. A batch the database refuses is rolled back whole, and the run stops,
  saying at which mail.
- **A file that is not there any more** is counted, and its row left as it is.
- **The files are not deleted,** because many rows share one. Once a run finds nothing left to
  move, the folder is yours to remove.

---

## What DNS says, and what the application cannot see

```
./yourapp mail:dns-check
./yourapp mail:dns-check example.com --selector=mail
```

The one part of deliverability that is not in the message. SPF, DKIM, DMARC and BIMI are records
on a domain: this framework can compose a perfect email, set every header a mailbox provider
asks for, and still have it filed as spam — with nothing in any log. The only symptom is mail
quietly not arriving, reported months later as "I never got the password reset".

```
Sending domain: example.com

  ok   SPF — Present.
       v=spf1 include:spf.protection.outlook.com -all

  ??   DKIM — Not checked — a DKIM record lives under a selector, and the selector is chosen
       by whatever signs the mail.

  ok   DMARC — Present, but `p=none` — it asks for reports and enforces nothing.
       v=DMARC1; p=none; rua=mailto:dmarc@example.com

 gone  BIMI — No BIMI record — no logo beside the subject.
```

The command exits non-zero when the domain does not meet the bulk-sender bar, so it can sit in a
deploy check — this is exactly the kind of thing that is correct on the day it is set up and
wrong two domain transfers later.

Three judgements in it are worth stating, because a check that cries wolf is one nobody reads:

- **An unchecked DKIM is not a failed DKIM.** The selector belongs to whatever signs the mail,
  often a relay, so an application frequently does not know it. Pass `--selector` and it becomes
  a real check; without one it reports "not checked" and does not fail the domain.
- **`p=none` clears the bar.** Gmail and Yahoo ask for *a* DMARC record. `p=none` is one, and
  failing everybody at `p=none` would be failing most of the internet — so the verdict passes
  and the finding ("somebody forging this domain is still delivered") stays visible beside it.
- **BIMI is not deliverability.** It is a logo, a Verified Mark Certificate is bought and needs a
  registered trademark, and an installation without one is not misconfigured.

Two states the command reports that a simpler "found / not found" check would call success:

- **Two SPF records**, which is a PermError under RFC 7208 — a receiver gets no result at all,
  so two records authenticate strictly *less* than one. It is a common state, because each was
  added by a different person for a different service.
- **A DMARC record at `p=none`**, which every tool reports as "DMARC found" and which enforces
  nothing — and silently stops BIMI from ever working.

### BIMI, in order

The logo beside the subject, and the only item here that is worth money.

1. **DMARC at `p=quarantine` or `p=reject`.** Not optional and not negotiable; `p=none` disables
   BIMI at every provider.
2. **An SVG Tiny PS** — a restricted SVG profile, square, with a solid background — at a public
   HTTPS URL.
3. **A TXT record** at `default._bimi.<domain>` reading `v=BIMI1; l=https://…/logo.svg`.
4. **A Verified Mark Certificate**, referenced with `a=`. Gmail and Apple show nothing without
   one; a few other providers will show the logo on the record alone. A VMC requires a registered
   trademark and is bought from a certificate authority.

`mail:dns-check` reports each of these separately, so "we published BIMI and nothing happened" has
an answer.

## Bounces, and addresses mail does not go to

An address that does not exist, or that its owner asked to be cut off entirely, receives
nothing — a password reset included. That is the difference from an unsubscribe, which only
stops mail on a list: sending to a dead mailbox is what a mailbox provider counts against the
sender, whatever the message was.

```php
use Pramnos\Email\Bounces;

Bounces::refusal('someone@example.com');   // why mail does not go to it, or null when it does
Bounces::block('someone@example.com', 'Asked by phone to stop all mail');
Bounces::clear('someone@example.com');     // its bounces and its block forgotten
Bounces::record('someone@example.com', soft: false, reason: '550 5.1.1 no such user');
Bounces::recordReport($rawMessage);         // every failed recipient of a delivery report
```

`send()` and `queue()` ask before composing. A refused address is a message not sent: `send()`
returns false, `getLastError()` says why (`Not sent: someone@example.com hard-bounced 1
time(s), last … (550 5.1.1 no such user).`), and the `mails` row is written as not sent with that
reason, as an opted-out one is. A message to several addresses goes to the ones it may go to and
is refused only when none is left. `mail:flush` asks again before sending a queued message, so
an address that bounced after the message was queued does not receive it.

### When an address is refused

- it was **blocked** by hand;
- it **hard-bounced** — no such mailbox, no such domain — once;
- it **soft-bounced** — mailbox full, disabled, the server deferring — 15 times; or
- it soft-bounced **within the last day**: a full mailbox is given time to empty.

Each rule is the application's to set, and 0 turns one off:

```php
// app/app.php
'email' => ['bounces' => ['hard' => 1, 'soft' => 15, 'softWithin' => 86400]],
```

### Where bounces come from

A **delivery report** (RFC 3464) — the message a mail server sends back — is read for every
recipient whose action is `failed`. A status `4.x.x` is soft, and so are `5.2.1` (disabled) and
`5.2.2` (full), the mailboxes that exist; every other permanent failure is hard. A delayed
delivery records nothing.

```bash
php bin/pramnos mail:bounces var/inbox            # every .eml in a folder
php bin/pramnos mail:bounces var/inbox --delete   # … removing each report once recorded
php bin/pramnos mail:bounces < report.eml         # one message on standard input
php bin/pramnos mail:bounces --status=someone@example.com
php bin/pramnos mail:bounces --block=someone@example.com --reason="asked by phone"
php bin/pramnos mail:bounces --clear=someone@example.com
```

### Reading the bounce mailbox over IMAP

```bash
php bin/pramnos mail:bounces --imap               # the unseen messages of the bounce mailbox
php bin/pramnos mail:bounces --imap --limit=50
```

```php
// app/app.php
'email' => ['bounces' => ['mailbox' => [
    'host'       => 'imap.gmail.com',
    'port'       => 993,
    'encryption' => 'ssl',          // 'tls' for STARTTLS on 143, 'none' for a server on this host
    'username'   => 'bounces@example.com',
    'password'   => '…',            // or, for Gmail and Microsoft 365:
    'oauth'      => ['client_id' => '…', 'client_secret' => '…', 'refresh_token' => '…'],
    'folder'     => 'INBOX',
]]],
```

The unseen messages are read without being marked. Each delivery report is recorded and marked
seen. Anything else stays unread, for a person — so a bounce mailbox that also receives replies
needs somebody to read them, or they pile up. `--limit` takes the **newest** unseen messages,
so a new report is reached however many unread replies are older than it. With `oauth` the refresh token is exchanged for
an access token (Google's endpoint unless `token_url` says otherwise), and the mailbox is signed
into with XOAUTH2: Gmail accepts no password from an application, and IMAP needs nothing more.
Without the block, the settings an application already keeps are read:
- `imap_host`, the imap extension's `{imap.gmail.com:993/imap/ssl}INBOX` form included;
- `imap_user` and `imap_pass`;
- `imap_oauth_client_id`, `imap_oauth_client_secret` and `imap_oauth_refresh_token`.

PHP no longer ships its `imap` extension, so this speaks IMAP itself, through
`Pramnos\Email\ImapClient`. An application can use the client directly, for any mailbox it
reads:
- `connect()`, then `login()` or `authenticate()` (XOAUTH2), then `select()`;
- `unseen()` and `seenBefore($day)`;
- `fetch()`, which reads a message without marking it;
- `markSeen()`, `delete()`, `expunge()` and `logout()`.

Standard input makes the command a mail server's delivery target — an alias such as
`bounces: "|php /srv/site/bin/pramnos mail:bounces"` with the site's `Return-Path` pointing at
it records each report as it arrives. A folder is for a mailbox fetched to disk. A message in the
folder that is not a report is left for a person to read.

A server that refuses the address itself while the message is being sent — `5.1.x` in its reply,
with a single recipient — is recorded as a hard bounce there and then: no report follows such a
refusal. A refusal for any other reason (`5.7.1 relaying denied`) is the sender's problem, not
the address's, and records nothing.

### The table, and the screen

`bounces` keeps one row per address, by the sha256 of the lowercased address — the table tells
someone who has an address whether it bounced, and nothing to someone who has only the table.
Its layout is the one applications have kept under that name: the migration creates it where it
is missing and, where an application already has it, keeps its rows and adds `blocked` and
`reason`. Until the migration has run nothing is refused and nothing written.

A message's page in **Email History** says whether mail goes to its recipient, with **Stop all
mail to it** (and a reason) or, for a refused one, **Send to it again**.

## Mail from a customer's own domain: its signature and its server

By default every message leaves through the installation's SMTP server (the `smtp_*`
settings below), and the mail server signs it with DKIM for the installation's domain. A
Virtualmin or Postfix server with OpenDKIM does this, and so does Amazon SES. Nothing here
changes that.

Some mail is sent **from somebody else's address**: a reply to a press release from a customer
organisation's own mailbox, for example. For DMARC to pass, that message needs a DKIM signature
for the customer's domain, which the relay cannot provide because it does not hold the
customer's key. Sometimes it also has to leave through the customer's own mail server. Both
are opt-in, per message.

### Signing for the customer's domain

```php
use Pramnos\Email\DkimKey;

// Once, when the customer sets the feature up:
$key = DkimKey::generate('customer.example', 'pramnos1');
// Keep $key->privateKeyPem with the application's other secrets.
// The customer publishes a TXT record:
//   $key->recordName()   →  pramnos1._domainkey.customer.example
//   $key->recordValue()  →  v=DKIM1; k=rsa; p=MIIBIjANBgkq…

// For each message:
$email->setFrom('press@customer.example')
      ->signWith('customer.example', 'pramnos1', $privateKeyPem)
      ->send();
```

`signWith()` adds the signature last, after every other header, so nothing added later breaks
it. The domain must be the From address's domain, or DMARC does not align. A key that does
not load fails the send, and the key never appears in the error.
`(new \Pramnos\Email\DnsAuthentication())->inspect('customer.example', 'pramnos1')` reports whether the record has
been published (see [What DNS says](#what-dns-says-and-what-the-application-cannot-see)).

The record value is longer than 255 characters at 2048 bits. Most DNS screens split it
themselves. One that does not takes it as several quoted strings, which DNS joins back
together.

### Sending through the customer's server

```php
$email->via([
    'host'     => 'mail.customer.example',
    'port'     => 587,
    'user'     => 'press@customer.example',
    'password' => $password,
    'tls'      => true,
])->send();
```

The five keys mean what the `smtp_*` settings mean, and the scheme follows the same rule: port
465 is implicit TLS, 587 with `tls` is STARTTLS, another port with `tls` is implicit TLS, and
without `tls` the connection is plain. The installation's settings are not read for this
message. The password is never logged. A failure names the server:
`SMTP mail.customer.example:587: Expected response code "235" but got code "535" …`.

`withTransport($transport)` takes any Symfony Mailer transport instead, such as an API
transport or a failover pair.

### Both are sent at once, never queued

`queue()` on a message with `signWith()`, `via()` or `withTransport()` sends it immediately,
in this request. The outbox stores messages as rows and sends them later, so it would have to
store the private key or the server's password in the row.
