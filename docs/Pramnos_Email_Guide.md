---
use_cases:
  - Sending an email from application code
  - Configuring SMTP or another transport
  - Tracking or debugging delivery
  - Offering an unsubscribe link and passing Gmail's bulk-sender rules
  - Understanding the plain-text part, or why a message reads badly in a text-only client
  - Working out which headers a message carries and why
  - Putting a Gmail action button on a message, or finding out why one is not showing
  - Building a one-click action a mail client can perform without a session
  - Deciding whether to track opens, and reading the numbers honestly
  - Sending one account a message from the administration area
  - Giving a notification a wrapper, an unsubscribe list, tracking or a Gmail action
---

# Pramnos Framework - Email System Guide

The Pramnos Framework includes a comprehensive email system built on top of Symfony Mailer that provides a clean, flexible API for sending emails with advanced features like tracking, templates, and multiple transport options.

## Table of Contents

1. [Overview](#overview)
2. [Basic Usage](#basic-usage)
3. [Configuration](#configuration)
4. [Advanced Features](#advanced-features)
5. [Email Tracking](#email-tracking)
6. [A message to one account](#a-message-to-one-account)
7. [Unsubscribing, and what Gmail requires](#unsubscribing-and-what-gmail-requires)
7. [SMTP Configuration](#smtp-configuration)
7. [Error Handling](#error-handling)
8. [Best Practices](#best-practices)
9. [API Reference](#api-reference)

## Overview

The Email system (`\Pramnos\Email\Email`) is a powerful wrapper around Symfony Mailer that provides:

- **Simple API**: Fluent interface for building and sending emails
- **Multiple Transport Options**: SMTP, local mail, and more
- **Email Tracking**: Built-in open tracking with database logging
- **Attachment Support**: File attachments with validation
- **Template Integration**: Works seamlessly with the theme system
- **Error Handling**: Comprehensive error reporting and debugging
- **Priority Support**: Set email priority levels
- **Advanced Headers**: Custom headers, read receipts, unsubscribe links

## Basic Usage

### Sending a Simple Email

```php
use Pramnos\Email\Email;

// Create new email instance
$email = new Email();

// Set email properties and send
$email->setSubject('Welcome to our service')
      ->setBody('<h1>Welcome!</h1><p>Thank you for joining us.</p>')
      ->setTo('user@example.com')
      ->setFrom('noreply@yoursite.com')
      ->send();
```

### Using the Static Method

```php
// Quick static method for simple emails
$result = Email::sendMail(
    'Subject Line',                    // Subject
    '<p>HTML email content</p>',       // Body (HTML)
    'recipient@example.com',           // To
    'sender@yoursite.com',             // From
    '',                                // Attachment path (optional)
    false,                             // Batch mode (optional)
    'reply@yoursite.com'               // Reply-to (optional)
);

if ($result['success']) {
    echo "Email sent successfully!";
} else {
    echo "Error: " . $result['error'];
}
```

### Multiple Recipients

```php
$email = new Email();
$email->setSubject('Newsletter')
      ->setBody('<h1>Monthly Newsletter</h1>')
      ->setTo([
          'user1@example.com' => 'John Doe',
          'user2@example.com' => 'Jane Smith',
          'user3@example.com'  // Email without name
      ])
      ->setCc('manager@example.com')
      ->setBcc('archive@example.com')
      ->send();
```

## Configuration

### SMTP Settings

Configure SMTP settings in your application settings:

```php
use Pramnos\Application\Settings;

// Basic SMTP configuration
Settings::setSetting('smtp_host', 'smtp.yourprovider.com');
Settings::setSetting('smtp_user', 'your-username');
Settings::setSetting('smtp_pass', 'your-password');
Settings::setSetting('smtp_port', 587);
Settings::setSetting('smtp_tls', 'yes');

// Default from address
Settings::setSetting('admin_mail', 'noreply@yoursite.com');
Settings::setSetting('sitename', 'Your Website Name');
Settings::setSetting('admin_replymail', 'support@yoursite.com');
```

### AWS SES Configuration

For Amazon SES (port 587 with STARTTLS):

```php
Settings::setSetting('smtp_host', 'email-smtp.us-west-2.amazonaws.com');
Settings::setSetting('smtp_user', 'your-ses-access-key');
Settings::setSetting('smtp_pass', 'your-ses-secret-key');
Settings::setSetting('smtp_port', 587);
Settings::setSetting('smtp_tls', 'yes');
```

## Advanced Features

### Email with Attachments

```php
$email = new Email();
$email->setSubject('Document Attached')
      ->setBody('<p>Please find the attached document.</p>')
      ->setTo('user@example.com')
      ->setFrom('sender@example.com');

// Set attachment path
$email->attach = '/path/to/document.pdf';

$email->send();
```

### Setting Email Priority

```php
$email = new Email();
$email->priority = 1; // 1 = highest, 5 = lowest (default: 3)
$email->setSubject('Urgent: Action Required')
      ->setBody('<p>This is an urgent message.</p>')
      ->setTo('user@example.com')
      ->send();
```

### Custom Headers

```php
$email = new Email();
$email->addHeader('X-Campaign-ID', 'newsletter-2024-01')
      ->addHeader('X-Mailer', 'Pramnos Framework');

// Built-in header support
$email->organization = 'Your Company Name';
$email->unsubscribe = 'mailto:unsubscribe@yoursite.com';
$email->abuse = 'abuse@yoursite.com';
$email->returnPath = 'bounces@yoursite.com';

$email->send();
```

### Read Receipts

```php
$email = new Email();
$email->sendReceipt = true;
$email->setSubject('Important Document')
      ->setBody('<p>Please confirm you have received this.</p>')
      ->setTo('user@example.com')
      ->send();
```

## Message templates, and the screen that edits them

The `messaging` feature ships `mailtemplates` — a table, a model, and a lookup by
`(category, language, type)`. One notification is several rows: the same category and
channel, one row per language.

```php
$template = (new \Pramnos\Messaging\MailTemplate($controller))
    ->findByKey('auth.passwordreset', 'el', \Pramnos\Messaging\MailTemplate::TYPE_EMAIL);
```

**`/admin/MailTemplates` is where an operator edits them.** Until it existed the table was
reachable only through a database client, which in practice meant the templates were not
edited at all: a project that wanted to change the wording of a password-reset email
changed the code that composes it and left the template unused.

Three things the screen does, each because leaving it out makes the screen decorative:

- **It lists the placeholders**, read from the template's own body and subject — a
  documented list goes stale the first time an application adds one, and an editor that
  shows none is a form where a typo produces a mail with a literal `{nmae}` in it. CSS
  braces are not mistaken for placeholders.
- **It groups the language variants**, so the list answers "is the reset email translated
  into Greek" instead of showing eighty flat rows.
- **It sends a test**, because the only way to know a template renders is to render it.
  Placeholders arrive as `[name]` — visible where each one lands, without invented data
  that would hide a missing one.

The body is stored as written: an email template *is* markup, and a screen that sanitised
it would make the feature useless. It is escaped where it is displayed — into a
`<textarea>` and a `<pre>` — which is the correct half to do it in.

## Which language a message is written in

`Notifier::sendNow()` renders every notification in the **recipient's** language — the
`language` property of the notifiable, which on a `User` is `users.language`. A notifiable
without one (a `PlainAddress`, an account that never chose) is sent in whatever language the
installation is currently using.

That is the only correct answer and it was not the old one: the language of a request belongs
to whoever made it, so an operator resetting a password from an English administration screen
sent an English mail to an account whose every screen is Greek, and a queue worker sent
whatever the default was.

Mail composed outside the notification system asks for the same thing itself — see
`Language::using()` in the Internationalization guide. The framework's own auth mail
(codes, sign-in alerts, security changes, the reset link) is all translatable: the keys are
the English sentences, so an application supplies its language file and nothing else.

## The wrapper a message is sent in

Bodies are fragments — a paragraph, a code, a link — and every application wants the same
shell around all of them: its logo, its colours, a footer with a company name in it. That
shell is a **wrapper**, named rather than derived:

```php
// Every message, from the settings
Settings::setSetting('emailtheme', 'default');

// Or one message
(new Email())->setBody('<p>Your code is 123456</p>')->setTemplate('branded')->send();
```

`mailtemplates.emailtemplate` is the per-template version of the same choice, and the
test-send on `/admin/MailTemplates` uses it — so what arrives in a test is what a recipient
would get.

**Off until it is named.** `emailtheme` is empty on an existing installation and an empty
name wraps nothing: bodies go out exactly as they did before. That matters more than it
sounds — an application whose bodies are already complete documents would otherwise get a
second `<html>` inside the first on an upgrade.

**`null`, `''` and a name are three different things.** `null` (the default) takes the
installation's setting; a name overrides it; and `''` sends *this* message bare, which is
the only way to send an unwrapped one from an installation that wraps everything — a body
that is already a whole document, or one meant to be parsed rather than read.

### Where a wrapper lives

`{name}.html.php`, in the first of these that has it:

| Path | For |
| --- | --- |
| `app/emails/` | the application's own |
| `emails/` at the project root | an older layout |
| the framework's bundled copy | so `default` resolves with nothing published |

Copy the bundled `default` into `app/emails/` and edit it there: the application's file of
the same name wins, so the copy is the customisation and the bundled one stays the
fallback.

Not per theme, deliberately. A theme is a stylesheet and an email cannot use one — HTML mail
is nested tables and inline attributes, because Outlook renders with Word's engine and
Gmail strips `<style>` from anything forwarded. An application that wants two looks names
two wrappers.

### What the file receives

```php
<?php /* app/emails/branded.html.php */ ?>
<!DOCTYPE html>
<html><body>
    <h1><?php echo htmlspecialchars($sitename); ?></h1>
    <?php echo $content; ?>
    <footer><?php echo htmlspecialchars($sitename . ' · ' . $year); ?></footer>
</body></html>
```

`$content` is the body, already HTML. `$subject`, `$sitename`, `$siteurl` and `$year` come
with it, so a wrapper needs no arguments; anything else passed to `EmailTheme::wrap()` is in
scope under its own key. `$content` is the one variable a wrapper cannot replace.

### It fails open, and the name is not a path

A wrapper that is missing, or that raises while rendering, logs to the `email` log and the
message is sent **unwrapped**. A mail whose shell is broken still has to be delivered: the
code in it is what somebody is waiting for, and a missing footer is not a reason to withhold
it. That is also what makes a typo in a settings field cost one unbranded email rather than
every email.

The name is checked against `[A-Za-z0-9_-]` before it reaches a path, because it arrives
from a column an administrator edits. Anything with a separator in it is refused rather than
sanitised — there is no correct number of `..` segments to strip, and a name with a slash in
it was never a wrapper name.

## The inbox a message lands in

`/messages` is where an account reads what was sent to it: a list, one message per page, and
reading it marks it read. Not a mail client — no compose, no reply, no folders. The screens it
does not have are screens nobody has to maintain.

```php
// src/Controllers/Messages.php — what `pramnos init` writes for an application
class Messages extends \Pramnos\Messaging\Controllers\MessagesController {}
```

`MessagesController::unreadCount($userId)` is the number for a badge; it answers zero rather
than throwing, because a badge is not worth an exception on an unrelated page.

**This is the other end of a dead end.** `messages` has been in the schema since the messaging
feature shipped, `MassMessageDispatcher` writes a row per recipient when a broadcast goes out as
an internal message, and `Message::countUnread()` counted them — and nothing displayed any of
it. An operator could compose a message, choose "internal message", watch the progress screen
report every recipient delivered, and no recipient could read a word of it. Every part of the
machinery was working: the insert succeeded, the count was right, the admin screen was honest
about what it had done. Only the reader was missing, and no test notices a reader that was never
written.

`messages.type` carries the state, and it is overloaded — the same column distinguishes an inbox
item from a sent one, an archived one and a deleted one. The listing therefore *names* the states
it wants (`MessagesController::INBOX_TYPES`) rather than excluding the ones it does not: a state
added later must not appear in somebody's inbox because a `NOT IN` list was not updated.

## A message to one account

`/admin/Users/notify/<id>` — the **Send** screen on an account. An operator frequently needs to
*say* something to one person ("your export is ready", "we reset your second factor"), and the
alternative is their own mail client, which leaves no record on the account and uses whatever
address they happen to type.

Three channels, ticked independently:

| | |
| --- | --- |
| **Email** | to the address on the account |
| **Notification** | a row in `notifications` — readable next time they sign in |
| **Push** | on the device, with the browser closed ([guide](Pramnos_Push_Guide.md)) |

**A channel this account cannot receive is disabled, with its reason.** No usable address, no
VAPID pair, no browser subscribed — each says which. An operator who presses Send and is told
"sent" is entitled to believe it, and a channel that silently delivers nothing is invisible from
the outside: nothing errors, the message simply never arrives. "No browser has subscribed" and
"this installation has no key pair" also need different people to fix them.

Every mail option this guide describes is on that screen, because this is where they get *tried*:
a wrapper nobody has rendered and a Gmail action nobody has seen arrive are both things you find
out about from a real message, not from a test.

### `Notification\Message` — the notification with no event

Every other notification is a class per event — `InvoicePaid`, `NewSignIn` — which is the right
shape when the event is known in advance. This is for when it is not: an operator writing a
sentence, an administration screen, a test send. There is no event to name, so there is no class
to write.

```php
use Pramnos\Notification\Message;

$user->notify(
    (new Message('Your export is ready', '<p>It is on your downloads page.</p>'))
        ->to('mail', 'database', 'push')
        ->link(sURL . 'account/downloads')
        ->list('exports')          // → a working unsubscribe, and opt-outs are respected
        ->template('receipt')      // → '' for no wrapper, null for the default
        ->track()                  // → asked for; Tracking still decides
        ->action(Actions::view('Open it', sURL . 'account/downloads'))
);
```

Each channel gets a shape it can use. The push body is stripped of markup and flattened to one
line, because a push is two lines on a lock screen — handed HTML it shows the tags, handed a
paragraph the operating system truncates it at a point nobody chose.

### The mail options are declarations, not calls

`MailChannel` reads four optional methods off a notification — `unsubscribeList()`,
`mailTemplate()`, `trackingRequested()`, `mailStructuredData()`. A notification that declares
none of them is **transactional** and gets none of it: no unsubscribe link, no suppression, no
pixel, no `ld+json`.

That default is the important half. A password reset must arrive even for somebody who
unsubscribed from everything, an unsubscribe link on it teaches people the link does nothing, and
a tracking pixel on it tracks somebody who never agreed to be tracked. Any notification can now
declare these — the capability is not confined to code that builds an `Email` by hand, which is
what everybody did instead.

```php
class WeeklyDigest implements NotificationInterface
{
    public function unsubscribeList(): string { return 'digest'; }
    public function trackingRequested(): bool { return true; }
    public function toMail(mixed $notifiable): array { … }
}
```

## A message to many accounts

`massmessages` and `massmessagerecipients` have been in the schema since the messaging
feature shipped, with a model each and nothing that composed, sent or displayed one. There
is now `/admin/MassMessages`, and behind it two classes:

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
| `TYPE_PUSH` | nothing; the framework has no push transport | every recipient, so it is visible |

Push is refused rather than skipped. An operator who chose it is owed "there is no transport
for this", not a message that reports itself sent to nobody.

### The audience is resolved once

Criteria are stored on the message (`request`, as JSON); the list they meant is stored in
`massmessagerecipients`. Re-resolving at delivery time would silently include accounts
created after somebody approved the send and drop the ones deleted since — so the recipients
would stop matching what was approved.

`usertype_min`, `validated_only` and `active_only`, all defaulting to "every account that can
actually receive something". An application with its own idea of an audience hands
`queue()` its own list of ids instead.

The compose screen counts the audience **before** anybody presses send, because a count is
the one number that changes an operator's mind, and it is exactly the number nobody has when
the send is a loop somebody wrote in a controller.

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

### `isOptedOut()` fails closed

Alone among this framework's reads, it answers **true** when it cannot tell. Sending to
somebody who unsubscribed is the one mistake a provider counts against every future message,
including the transactional mail this is never asked about. A message not sent during a
database outage is a message the next run sends.

### The rest of the compliance list

The parts that are not code:

- **SPF, DKIM and DMARC** on the sending domain. Gmail requires authentication from every bulk
  sender; without DKIM the headers above will not save you.
- **A `From:` domain you own**, matching the DKIM signature. Not a free-mail address.
- **A reply address that a person reads.** `admin_replymail`, and the `mailto:` unsubscribe uses
  it too.
- **Spam complaints under 0.10%**, measured in Google Postmaster Tools — the number the
  unsubscribe link exists to keep down, because the alternative the reader has is the spam
  button.
- Every message goes out as `multipart/alternative` with a plain-text part, which `Email` builds
  from the HTML — see below, because for a long time it built a bad one.

---

## The plain-text part

Every message is `multipart/alternative`, and the text half used to be `strip_tags($body)`. That
produces a part which is technically present and practically useless, in three specific ways:

- **Every link disappeared.** `strip_tags` keeps the anchor *text* and throws the `href` away, so
  «click here to confirm your address» arrived with nothing to click and no address to copy. On a
  confirmation mail that is the entire message gone.
- **The text ran together.** HTML mail is nested tables, and adjacent cells have no whitespace
  between them, so a header, a heading and a paragraph arrived as one line.
- **The stylesheet came along.** `strip_tags` removes the `<style>` *tags* and keeps what was
  inside them, so a reader in a text-only client was shown the CSS.

And a text part that does not match the HTML is a documented spam signal, so the thing meant to
help deliverability was hurting it.

`Pramnos\Email\PlainText` converts instead:

```php
use Pramnos\Email\PlainText;

$text = PlainText::fromHtml($html);
```

```
Example <https://example.com>

Confirm your address

Hello Yannis, please click here to confirm
<https://example.com/confirm?t=abc123>.

- One
- Two

Device | Last seen
Chrome | 28/08/2026
```

Written against `DOMDocument`, and with **no new dependency**: an html-to-text package is a
reasonable choice for an application and the wrong one for a framework, which would impose it on
every project that ever sends a message.

Six decisions in it worth knowing, because each one is a wrong output avoided:

| It does | Because |
|---|---|
| `text <https://…>`, and just the URL when the text already is the URL | A text-only reader has to be able to reach the same place — and `https://… <https://…>` reads as a mistake |
| Drops `<head>`, `<style>`, `<script>` and `<title>` entirely | The old output began with the CSS and then repeated the subject line |
| Reads `role="presentation"` to tell a layout table from a data one | A layout table's cells joined with ` | ` is nonsense; a data table's rows joined with newlines is unreadable. The distinction is already in the markup for screen readers |
| Skips `display:none` and zero-height elements | A preheader is written for the inbox preview and is invisible in the HTML; repeating it in the text is a difference between the two halves |
| `[alt text]` for an image with one, nothing for a decorative one | A line reading `[]` is worse than no line |
| Wraps at 78 columns but never breaks a URL | A wrapped URL is an unusable URL: the client links the first half and leaves the rest as text |

The charset is declared with a `<meta>` tag prepended to the markup rather than with
`mb_convert_encoding($html, 'HTML-ENTITIES', …)`, which is deprecated as of PHP 8.2 — and without
either, libxml assumes ISO-8859-1 and every Greek character becomes mojibake.

---

## The headers a message carries

Four of these are added automatically. None changes what the reader sees; all of them change what
happens to the message — which is why they go missing, and why they went missing here. Nothing in
mail reports a missing header back to the sender, and nothing reports a malformed one either.

| Header | On | Why |
|---|---|---|
| `Auto-Submitted: auto-generated` | every message | RFC 3834. Stops an out-of-office responder replying to a password reset — and then to the reply, which is how a mail loop starts |
| `X-Entity-Ref-ID` | every message | Gmail groups by subject, and «a new sign-in to your account» repeats. Two sign-ins arrived as one thread with the older behind "show trimmed content" — exactly the message somebody needs to see twice |
| `Precedence: bulk` | list mail | Not in any standard, honoured nearly everywhere. The older half of the same idea as `Auto-Submitted`. **Not** on transactional mail: marking a password reset bulk invites a provider to deprioritise the one message the reader is waiting for |
| `List-ID` (RFC 2919) | list mail | A stable identifier, so a client can group, filter and unsubscribe by list rather than guessing from the subject |
| `Feedback-ID` | list mail | Google Postmaster's grouping key. Without it every complaint lands in one bucket and the dashboard can say something is wrong but not what; with it, «the newsletter is marked as spam and the receipts are not» becomes a fact |

**A header you set yourself wins.** These are defaults, not policy — an application with its own
`Feedback-ID` scheme distinguishing campaigns, which is the whole point of that header, must not
have it overwritten by a generic one.

The host in `List-ID` and `Feedback-ID` comes from the `From:` address, falling back to the site
URL; with neither, both headers are **left out** rather than invented, because a stable identifier
for the wrong domain is worse than none. And a list name is reduced to the characters those headers
allow: `Feedback-ID` takes letters, digits, `_`, `.` and `-`, and one bad character or one
over-long field invalidates the whole header.

---

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

`revoke-sessions` — the handler a "this wasn't me" button needs. It ends every session on the
account, and the framework's own new-sign-in alert **does not use it**; see below.

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
promise about the first request, so there was never anything to build for it.

**No `ConfirmAction` on the framework's own mail — but the handler for one now exists.** The
contract is real, and `MailAction` above is how it is met: `revoke-sessions` is registered and
ready. What the framework does not do is *put it in a message*, because the only message it would
belong in is the new-sign-in alert, and that is the paragraph below.

**The new-sign-in alert carries no action at all, and that is a decision.** It contains no link
either: a link in an unexpected security email is the shape of the attack it warns about, and a
button in the message list is the same thing, larger and easier to press. The message tells the
reader to open the site themselves. A one-tap "this wasn't me" would need both a new one-click
revoke endpoint *and* a reversal of that judgement — worth discussing, not worth assuming.

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
sensitive message a system sends, to somebody who agreed to nothing — and until now the framework
would happily have put one there, because `enableTracking()` appended it on the spot.

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

**The destination lives inside the signed token**, never in the URL. A tracker that reads its
destination from a query parameter is an open redirect, and an open redirect on a domain that
sends mail is a phishing kit somebody else gets to use: the link comes from your domain, in a
message that looks like yours, and lands wherever the attacker chose.

### The routes, and the tables

`/emailpixel` and `/emailclick` are bundled controllers — no route to register. The previous
version of this feature asked an application to write both the route *and* the table, in a
doc-block, which is why it never worked anywhere: the pixel pointed at a 404 and the insert failed
into a `catch`.

`emailtracking` holds one row per tracked message; `emailtrackingclicks` holds one row per link
followed, because *which* link is the only question worth asking of a click. Both are created by
a migration.

The pixel always answers with the image, whatever happened behind it — an unknown id, a database
that is away, a message that was never tracked. A broken image in the middle of a message is a
worse outcome than a lost measurement.

### Privacy

This is processing personal data. Disclose it in the privacy policy the list's subscribers agreed
to, keep the unsubscribe working, and do not switch it on for transactional mail — the framework
will not let you, but the reasoning is worth carrying into whatever you build beside it.

### What was actually sent

`/admin/emails` lists every outbound message from the `mails` audit log — recipient, subject,
module, date, status — with the full body and a resend. Tracked messages show their figures in an
**Opens** column, with prefetches marked apart. A message that was not tracked shows a dash, which
is the honest rendering of "nobody measured this".

---

## SMTP Configuration

### Transport Types

The email system automatically selects the appropriate transport based on configuration:

```php
// Port 465 - Implicit SSL (smtps://)
Settings::setSetting('smtp_port', 465);
Settings::setSetting('smtp_tls', 'yes');

// Port 587 - STARTTLS (smtp://)
Settings::setSetting('smtp_port', 587);
Settings::setSetting('smtp_tls', 'yes');

// Port 25 - Plain SMTP (smtp://)
Settings::setSetting('smtp_port', 25);
Settings::setSetting('smtp_tls', 'no');
```

### Debug Mode

Enable debug mode to troubleshoot email issues:

```php
$email = new Email();
$email->setDebug(true)  // Enable debug logging
      ->setSubject('Test Email')
      ->setBody('<p>This is a test.</p>')
      ->setTo('test@example.com')
      ->send();
```

## Error Handling

### Checking for Errors

```php
$email = new Email();
$success = $email->setSubject('Test')
                 ->setBody('<p>Test content</p>')
                 ->setTo('user@example.com')
                 ->send();

if (!$success) {
    // Check for errors
    if ($email->hasError()) {
        $errorMessage = $email->getLastError();
        $exception = $email->getLastException();
        
        echo "Email failed: " . $errorMessage;
        
        // Log the full exception if needed
        if ($exception) {
            \Pramnos\Logs\Logger::log("Email error: " . $exception->getTraceAsString());
        }
    }
}
```

### Common Error Scenarios

1. **Authentication Failed**: Check SMTP credentials
2. **Connection Timeout**: Verify host and port settings
3. **TLS/SSL Issues**: Ensure proper encryption settings
4. **Attachment Not Found**: Verify file paths and permissions
5. **Invalid Recipients**: Check email address formats

## Best Practices

### 1. Configuration Management

```php
// Store sensitive settings securely
Settings::setSetting('smtp_pass', env('SMTP_PASSWORD'));
Settings::setSetting('smtp_user', env('SMTP_USERNAME'));
```

### 2. Template Usage

```php
// Use templates for consistent email design
$email = new Email();
$template = file_get_contents('templates/welcome-email.html');
$template = str_replace('{{username}}', $user->name, $template);

$email->setBody($template);
```

### 3. Batch Processing

```php
// For large email lists, use batch processing
$emails = ['user1@example.com', 'user2@example.com', /* ... */];

foreach (array_chunk($emails, 50) as $batch) {
    foreach ($batch as $recipient) {
        $email = new Email();
        $email->setTo($recipient)
              ->setSubject('Newsletter')
              ->setBody($content)
              ->send();
        
        // Add delay to avoid rate limiting
        usleep(100000); // 0.1 second delay
    }
}
```

### 4. Error Logging

```php
$email = new Email();
$success = $email->send();

if (!$success) {
    \Pramnos\Logs\Logger::log(
        "Email failed to: " . $email->to . 
        " Error: " . $email->getLastError()
    );
}
```

### 5. Testing

```php
// Use a test mode flag
if (defined('EMAIL_TEST_MODE') && EMAIL_TEST_MODE) {
    // Override recipient for testing
    $email->setTo('test@yoursite.com');
}
```

## API Reference

### Email Class Methods

#### Configuration Methods

- `setSubject(string $subject)` - Set email subject
- `setBody(string $body)` - Set HTML email body
- `setTo(mixed $to)` - Set recipient(s)
- `setFrom(mixed $from)` - Set sender
- `setCc(mixed $cc)` - Set CC recipients
- `setBcc(mixed $bcc)` - Set BCC recipients
- `addHeader(string $name, string $value)` - Add custom header

#### Sending Methods

- `send()` - Send the email (returns boolean)
- `static sendMail(...)` - Static method for quick sending

#### Tracking Methods

- `enableTracking(string $id = null)` - Enable email tracking
- `static handleTrackingRequest(string $trackingId)` - Handle tracking pixel requests

#### Error Handling Methods

- `hasError()` - Check if last send operation had errors
- `getLastError()` - Get last error message
- `getLastException()` - Get last exception object

#### Debug Methods

- `setDebug(bool $enable)` - Enable/disable debug logging

### Properties

#### Email Content
- `$subject` - Email subject line
- `$body` - Email body (HTML)
- `$to` - Recipient(s)
- `$from` - Sender
- `$cc` - CC recipients
- `$bcc` - BCC recipients
- `$replyto` - Reply-to address

#### Settings
- `$priority` - Email priority (1-5)
- `$attach` - Attachment file path
- `$batch` - Batch sending mode

#### Advanced Headers
- `$sendReceipt` - Request read receipt
- `$returnPath` - Return path for bounces
- `$organization` - Organization header
- `$abuse` - Abuse report address
- `$unsubscribe` - Unsubscribe link
- `$headers` - Custom headers array

#### Tracking
- `$trackingId` - Tracking identifier
- `$debug` - Debug mode flag

## Related Documentation

- [Framework Guide](Pramnos_Framework_Guide.md) - Core framework concepts
- [Framework Guide](Pramnos_Framework_Guide.md) - Application structure and settings
- [Database Guide](Pramnos_Database_API_Guide.md) - Database operations for tracking
- [Logging Guide](Pramnos_Logging_Guide.md) - Error logging and debugging
- [Theme Guide](Pramnos_Theme_Guide.md) - Email templates and theming

## Troubleshooting

### Common Issues

1. **SMTP Authentication Failed**
   - Verify username/password
   - Check if app-specific passwords are required
   - Ensure SMTP is enabled on your email provider

2. **Connection Refused**
   - Verify SMTP host and port
   - Check firewall settings
   - Ensure TLS/SSL settings match provider requirements

3. **Emails Going to Spam**
   - Set up SPF, DKIM, and DMARC records
   - Use proper from addresses
   - Avoid spam trigger words in subject/content

4. **Tracking Not Working**
   - Verify tracking route is properly configured
   - Check database table exists
   - Ensure tracking pixel URL is accessible

For additional support, refer to the framework's logging system to capture detailed error information.
