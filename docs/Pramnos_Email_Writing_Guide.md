---
use_cases:
  - Writing a message template and editing it on the templates screen
  - Choosing the language and the wrapper a message is sent in
  - Sending one account a message, written in the rich editor
  - Getting the preheader, plain-text part, dark mode and headers right
---

# Writing a message: templates, wrapper, editor, the parts

Part of the [email guide](Pramnos_Email_Guide.md).

## Message templates, and the screen that edits them

The `messaging` feature ships `mailtemplates` — a table, a model, and a lookup by
`(category, language, type)`. One notification is several rows: the same category and
channel, one row per language.

```php
$template = (new \Pramnos\Messaging\MailTemplate($controller))
    ->findByKey('auth.passwordreset', 'el', \Pramnos\Messaging\MailTemplate::TYPE_EMAIL);
```

**`/admin/MailTemplates` is where an operator edits them**, so changing the wording of a
password-reset email is a screen, not a code change.

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

The category and the title *are* stripped, and the asymmetry is deliberate: they are labels,
printed where markup is a defect rather than the point.

**Testing a test send.** `MailTemplatesController::mailer()` is a protected seam returning the
`Email` a test send goes through, so the substitution and the wrapper lookup can be asserted
without an SMTP server. Override it and read what was handed over:

```php
protected function mailer(): \Pramnos\Email\Email
{
    return new class extends \Pramnos\Email\Email {
        public function send() { return true; }
        // …record setBody()/setTemplate()/setTo()
    };
}
```

Without overriding it, a test can reach only `test()`'s refusals, not the two things the
action exists to prove.

## Which language a message is written in

`Notifier::sendNow()` renders every notification in the **recipient's** language — the
`language` property of the notifiable, which on a `User` is `users.language`. A notifiable
without one (a `PlainAddress`, an account that never chose) is sent in whatever language the
installation is currently using.

The language of a request belongs to whoever made it: an operator resetting a password from
an English administration screen must still send a Greek account a Greek mail, and a queue
worker has no request language at all.

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

**This is where an internal message is read.** `MassMessageDispatcher` writes a row per
recipient to `messages` when a broadcast goes out as an internal message, and
`Message::countUnread()` counts them; `/messages` is the screen that displays them. Without it
in a project, the progress screen reports every recipient delivered and nobody can read a word.

`messages.type` carries the state, and it is overloaded — the same column distinguishes an inbox
item from a sent one, an archived one and a deleted one. The listing therefore *names* the states
it wants (`MessagesController::INBOX_TYPES`) rather than excluding the ones it does not: a state
added later must not appear in somebody's inbox because a `NOT IN` list was not updated.

Reading a message is what marks it read, and a notification keeps its own read state
(`TYPE_NOTIFICATION_READ`) rather than collapsing into `TYPE_READ` — the pair exists so a screen
can tell *something we told you* from *something somebody sent you*, and collapsing it would lose
that on first read.

### Writing to `messages` yourself

Two things bite, and both differ between backends, so test on each one you support.

**`attachmenttext` has no default.** It is `TEXT NOT NULL`, so an insert that omits it fails —
on PostgreSQL always, and on MySQL under strict mode. Pass `''` explicitly, as
`MassMessageDispatcher` does:

```php
$db->queryBuilder()->table('#PREFIX#messages')->insert([
    // …
    'attachmenttext' => '',   // TEXT NOT NULL with nothing to fall back on
]);
```

**A failed query is not always an exception.** MySQL throws; PostgreSQL answers `false`. So

```php
try {
    $result = $qb->…->get();
} catch (\Throwable $e) {
    return [];          // never reached on PostgreSQL
}

while (($row = $result->fetch()) !== null) {   // fatal: fetch() on false
```

is correct on one engine and a crash on the other. Guard the result as well as the call:

```php
if (!$result) {
    return [];
}
```

Worth stating as a rule rather than a footnote: **a `try`/`catch` around a query is only half the
handling.** Without the guard, the same lines report an error politely on MySQL and crash on
PostgreSQL.

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

The body is written in the framework's own editor (below) and sent as HTML reduced by
`Pramnos\Html\SafeHtml` to paragraphs, emphasis, headings, lists, quotes, links (`http`, `https`,
`mailto`) and `https` images — every other element and attribute is removed. Text posted without
markup is escaped and keeps its line breaks. A push notification gets the text of it.

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

Mail is one channel of several here. The [Notifications guide](Pramnos_Notifications_Guide.md)
covers the dispatcher itself — the contracts, the other channels, routing per recipient, and why
a channel silently delivered nothing.

### The mail options are declarations, not calls

`MailChannel` reads four optional methods off a notification — `unsubscribeList()`,
`mailTemplate()`, `trackingRequested()`, `mailStructuredData()`. A notification that declares
none of them is **transactional** and gets none of it: no unsubscribe link, no suppression, no
pixel, no `ld+json`.

That default is the important half. A password reset must arrive even for somebody who
unsubscribed from everything, an unsubscribe link on it teaches people the link does nothing, and
a tracking pixel on it tracks somebody who never agreed to be tracked. Any notification can
declare these — the capability is not confined to code that builds an `Email` by hand.

```php
class WeeklyDigest implements NotificationInterface
{
    public function unsubscribeList(): string { return 'digest'; }
    public function trackingRequested(): bool { return true; }
    public function toMail(mixed $notifiable): array { … }
}
```

## The editor a message is written in

The body of a mass message is written in whichever editor the application chose; the message to
one account always uses the framework's own.

```php
// app/app.php
'messaging' => [
    'editor'  => 'builtin',   // builtin (default) | rich | tinymce | none
    'rich'    => [            // only for 'rich'; both optional
        'upload' => adminUrl('Media/upload'),   // pictures POSTed here as `file`; answers {"url": …}
        'links'  => adminUrl('search'),         // the link panel searches here (?q=)
        'block_toolbar' => true,                // a toolbar over the block at the cursor; off by default
    ],
    'tinymce' => [            // only for 'tinymce'
        'src'         => sURL . 'assets/vendor/tinymce/7.2.0/tinymce.min.js',  // default: the jsDelivr CDN
        'license_key' => 'gpl',                                              // TinyMCE's requirement
    ],
],
```

| Mode | What it is |
| --- | --- |
| `builtin` | `www/assets/js/pf-editor.js`: bold, italic, two heading levels, lists, a link, an image by its `https` address, clear formatting, and the HTML itself. Pasting inserts text, not the pasted page's styles — the commonest reason a message looks broken in Outlook. No dependency, nothing a Content-Security-Policy has to allow. |
| `rich` | `www/assets/js/pf-rich-editor.js`, TipTap bundled: pictures with alt text and captions, uploaded by drop, paste or choice, a link panel that searches the site, blocks moved with Alt+↑/↓, embeds, and plugins of the site's own. No CDN, MIT licensed. See the [Rich Text Editor guide](Pramnos_Rich_Editor_Guide.md). |
| `tinymce` | TinyMCE, loaded from `src` and initialised with `license_key`. Where it comes from and under which licence are the application's decisions; a CDN source must be allowed by the CSP's `script-src`. |
| `none` | The textarea, as markup. |

A form marks its field and prints the scripts. The field may sit inside its `<label>`: the editor
is drawn after the label, never inside it, and a click on the label's words puts the cursor in the
text.

```php
<textarea name="body" data-pf-editor="<?php echo \Pramnos\Html\MessageEditor::mode(); ?>"></textarea>
<?php echo \Pramnos\Html\MessageEditor::scripts(\Pramnos\Html\MessageEditor::mode()); ?>
```

Headings, lists and quotes in the area are styled by a small scoped stylesheet `scripts()`
prints, so a theme whose CSS reset flattens them (Tailwind's preflight) still shows what the
markup is. On the mass-message form the editor gives way to the bare field when the channel is
**push** — a notification carries text — and comes back for email and an internal message, which
both keep markup; `window.PfEditor.plain(textarea, bool)` does the same for any form.

### Composing a message: saved messages, placeholders, AI, the signature

Above the message forms — "Message one user" and the mass-message composer — sits a toolbar,
`Html\MessageComposer`, for any form with a message body:

```php
<form method="post" action="…" data-pf-composer data-pf-composer-url="<?= adminUrl('Composer') ?>"
      data-pf-composer-scope="user-message"
      data-pf-editor-fields='<?= \Pramnos\Html\MessageComposer::fieldsJson() ?>'>
    <?= \Pramnos\Html\MessageComposer::toolbar() ?>
    <input name="subject" data-pf-composer-subject>
    <textarea name="message" data-pf-editor="<?= \Pramnos\Html\MessageEditor::mode() ?>" data-pf-composer-body></textarea>
</form>
```

It works with whichever editor the body is in — builtin, rich, TinyMCE or none — through
`pf-utils.js`.

- **Saved messages.** A list fills the subject and the body — shown once there is something in
  it; "Save this message" opens a name field under the toolbar, with "For everybody" where the
  account may share: the `messages.share` ability where the application defines it, the
  administration floor where it does not. The same name in the same scope is replaced; "Forget"
  asks by a second press. They live in `savedmessages` (migration `create_savedmessages_table`),
  apart from the mail templates, which are the application's notifications. Placeholders stay as
  written.
- **Fields.** `data-pf-editor-fields` — `[{"token": "{firstname}", "label": "First name"}]` —
  becomes one "Recipient details ▾" dropdown (Greek «Στοιχεία ▾»; the key is specific so an application's own "Fields" translation does not rename it); a choice goes in at the cursor of whichever of the subject and
  the body had the focus last, and the dropdown closes — as on its own toggle or Escape. An entry
  with a `value` inserts the value instead of the token: a
  message to one person reads "Cathy", not a placeholder. `MessageComposer::fieldsJson($fields,
  $values)` builds the list — `fieldsJson(null, MessageComposer::valuesFor($user))` gives the
  framework's own fields with one account's values, which is what "Message one user" passes; the
  mass-message form passes tokens. A token is never filled in the page: the framework's forms fill
  `{firstname}`, `{lastname}`, `{full_name}`, `{username}` and `{email}` when they send, for each
  recipient (`MessageComposer::fill()` with `valuesFor($user)`); an application with its own
  fields fills its own.
  A value put in from the menu is remembered with its token, and **saving puts the token back**:
  "Γεια σου Cathy" is saved as "Γεια σου {firstname}", so the saved message greets each reader
  by their own name. Loading a saved message into a one-person form fills its tokens with that
  recipient's values, remembered the same way. What is sent is the text as it reads. Matching is
  by value, in text and never inside markup — the same word typed by hand next to one put in from
  the menu goes back to the token too.
- **Write with AI**, where a language model is configured, opens a panel under the toolbar: what
  the message should say — or nothing, to improve what is written — and the model answers a
  subject and a body, keeping every placeholder, in `data-pf-composer-language` when the form
  gives one. Undo puts back the subject and the body it replaced. The call is logged and costed as
  every call is (`purpose` `compose`); the body comes back cleaned to what a message may carry.
- **The signature** is the account's own (`usersettings` `mail.signature`), edited at
  `Composer/signature` in the application's message editor. "With my signature" adds it under the
  body when the form is sent, once.

Nothing in the composer opens a browser dialog: questions and answers are in the panels and in
the toolbar's status line. `toolbar(['saved' => false, 'ai' => false, 'signature' => false])`
leaves a part out, and `toolbar(['fields' => 'buttons'])` draws the fields as a row of buttons
instead of the dropdown. Every control carries a `pf-composer-*` hook beside the button and field
classes of the project's `scaffold_theme`; each theme's `components.css` lays them out. The
endpoints are the `Composer` controller's: `saved?scope=`, and the POSTs `save`, `forget` and
`write`, each with the session's token.

Without JavaScript the textarea is simply there. `pf-editor.js` ships with the other `pf-*.js`
files: `init` writes it and `project:resync --js` brings it to a project made before it existed.
`window.PfEditor.enhance(textarea)` enhances a field added after the page loaded.

## The line the inbox shows beside the subject

The second most prominent piece of text in an inbox, and worth choosing deliberately.

Every mailbox list — Gmail, Apple Mail, Outlook — prints the message's first readable text next
to the subject. On a wrapped message that is whatever the wrapper opens with: a logo's `alt`, a
"view this in your browser" link, the first cell of a layout table. Left alone, the line that
decides whether the mail is opened is an accident of the template.

```php
$mail->preheader('Your code is 481920 — it expires in ten minutes.');
```

Or on a notification, beside the other mail options:

```php
(new Message('Your export is ready', $body))->preheader('It is on your downloads page.');
```

**Left unset it is derived from the body's own opening**, cut to 100 characters and flattened to
one line. That is deliberate: "no preheader" is not a neutral state, and the body's first
sentence is at worst a repetition of what the reader is about to see — which is what most
marketing mail does on purpose.

The wrapper renders it hidden three ways, because no single one works everywhere: `display:none`,
`mso-hide:all` for Outlook, and a 1px transparent colour for the rest. It is padded with
zero-width non-joiners so a client does not follow it with the *next* thing it finds, which is
how "Your code is 481920 View this in your browser Unsubscribe" ends up in somebody's inbox.

`PlainText` drops `display:none`, so a hidden preheader does not open the text part either.

## Dark mode, and why the wrapper declares it

```html
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
```

Without these, Apple Mail and Outlook invert the colours themselves. Their inversion is
per-element and knows nothing about images, so a dark logo on the white card it was drawn for
ends up black on near-black, and a hairline border becomes the loudest thing in the message.
Declaring support stops the client guessing and hands the decision to a
`@media (prefers-color-scheme: dark)` block.

**Every colour that block overrides is also inline.** Gmail strips `<style>` from a forwarded
message and several clients drop it outright, so the block is an improvement where it survives
and never the thing keeping the message readable. A wrapper of your own should keep that
property: inline the light palette, and let the stylesheet only *change* it.

## Accessibility in the message

Three things a mail template gets wrong by default, all of them handled in the bundled wrapper:

- **`role="presentation"` on every layout table.** Without it a screen reader announces "table,
  two columns, row one of three" about a message that has no table in it — the layout is read
  aloud as data. A table that really is data should not have the attribute.
- **`lang` on `<html>`**, from the language the message was composed in — which `Notifier` and
  the mass dispatcher both switch to the recipient's before composing. An empty `lang` is worse
  than a wrong one: the reader falls back to its own setting silently, and announces Greek as if
  it were English.
- **16px body text**, not 12. Mail is read on phones, and a small-print footer is the smallest
  thing on the least-read part of the message.

And `alt` on every image, including the decorative ones, where it should be `alt=""` — an empty
`alt` tells a screen reader to skip the image, while a missing one makes it read the filename.

## The plain-text part

Every message is `multipart/alternative`. The obvious way to build the text half,
`strip_tags($body)`, produces a part which is technically present and practically useless, in
three specific ways:

- **Every link disappears.** `strip_tags` keeps the anchor *text* and throws the `href` away, so
  «click here to confirm your address» arrives with nothing to click and no address to copy. On a
  confirmation mail that is the entire message gone.
- **The text runs together.** HTML mail is nested tables, and adjacent cells have no whitespace
  between them, so a header, a heading and a paragraph arrive as one line.
- **The stylesheet comes along.** `strip_tags` removes the `<style>` *tags* and keeps what is
  inside them, so a reader in a text-only client is shown the CSS.

And a text part that does not match the HTML is a documented spam signal.

`Pramnos\Email\PlainText` converts instead, and `Email` uses it for every message:

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

The decisions in it worth knowing, because each one is a wrong output avoided:

| It does | Because |
|---|---|
| `text <https://…>`, and just the URL when the text already is the URL | A text-only reader has to be able to reach the same place — and `https://… <https://…>` reads as a mistake |
| Drops `<head>`, `<style>`, `<script>` and `<title>` entirely | Otherwise the text would begin with the CSS and then repeat the subject line |
| Reads `role="presentation"` to tell a layout table from a data one — and treats a table whose cells hold paragraphs, pictures or tables as layout too | A layout table's cells joined with ` | ` is nonsense; a data table's rows joined with newlines is unreadable. The distinction is already in the markup for screen readers, and a template that does not say still shows it |
| Leaves out a link with nothing to read — a logo, an icon without alt text | A bare address on a line of its own says nothing; a header logo made one the message's first line |
| A run of links — a menu, a footer — one link a line, and `text <url>` never split | Run together, they wrapped between a link's words and its address |
| Leaves out anything marked `data-pf-plain="omit"` | A template's decoration — a header logo, a social bar — belongs to the HTML |
| Skips `display:none` and zero-height elements | A preheader is written for the inbox preview and is invisible in the HTML; repeating it in the text is a difference between the two halves |
| `[alt text]` for an image with one, nothing for a decorative one | A line reading `[]` is worse than no line |
| Wraps at 78 columns but never breaks a URL | A wrapped URL is an unusable URL: the client links the first half and leaves the rest as text |

### Writing the text part yourself

A designed template converted is still the whole layout around the message. An application that
knows better writes the text part itself — the message and a short footer:

```php
$email->setBody($designedHtml)
      ->setTextBody(PlainText::fromHtml($messageBeforeTheTemplate) . "\n\n-- \nExample Ltd · example.com");
```

That is the text part that goes, queued or not. Where the message offers an unsubscribe link and
the text does not carry it, `Unsubscribe: <url>` is added at the end. It is kept **inside the
stored body** (`Pramnos\Email\TextPart`) — as a `<script type="text/plain" data-pf-text>` after
the HTML of the copy kept, never of the message sent — so it is compressed and shared as bodies
are, in every store mode, and no column of `mails` grows by it. The message's page shows the text
that went; sending it again sends it as the text part.

The charset is declared with a `<meta>` tag prepended to the markup rather than with
`mb_convert_encoding($html, 'HTML-ENTITIES', …)`, which is deprecated as of PHP 8.2 — and without
either, libxml assumes ISO-8859-1 and every Greek character becomes mojibake.

---

## The headers a message carries

All five are added automatically — two on every message, three on list mail. None changes what
the reader sees; all of them change what happens to the message — which is why they go missing.
Nothing in mail reports a missing header back to the sender, and nothing reports a malformed one
either.

| Header | On | Why |
|---|---|---|
| `Auto-Submitted: auto-generated` | every message | RFC 3834. Stops an out-of-office responder replying to a password reset — and then to the reply, which is how a mail loop starts |
| `X-Entity-Ref-ID` | every message | Gmail groups by subject, and «a new sign-in to your account» repeats. Without a unique id two sign-ins arrive as one thread with the older behind "show trimmed content" — exactly the message somebody needs to see twice |
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


### A reply in the same thread

Mail clients thread a conversation on `In-Reply-To` and `References`, which hold the message
ids of what came before. A reply goes into its original's thread when it names that message:

```php
// The original, with an id the application chose and stored:
$id = bin2hex(random_bytes(16)) . '@customer.example';
$original->withMessageId($id)->send();

// Later, the reply:
$reply->setSubject('Re: ' . $subject)
      ->inReplyTo($id, $referencesOfTheOriginal)
      ->send();
```

`inReplyTo()` writes `In-Reply-To` and builds `References` as the original's references
followed by the original. Without `withMessageId()` the transport makes an id up, and nothing
records it, so choose and store one for any message that will be replied to. Angle brackets
are optional everywhere. For a message that arrived from outside, its own `Message-ID` header
is the id to pass.

`addHeader('In-Reply-To', …)`, `References` and `Message-ID` go through the same path, in any
letter case. An id that is not `local@domain` is refused when the header is added, with the
header named, not by the transport during the send.

A threaded message can be queued like any other: the outbox keeps its headers (see
[The outbox](Pramnos_Email_Outbox_Guide.md#the-outbox)).
