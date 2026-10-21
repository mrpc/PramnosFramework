---
use_cases:
  - Showing a notification bell in a SPA, the site and the administration
  - Marking notifications read when the thing they are about is dealt with
  - Adding a notification bell to an admin theme
  - Showing a person their notifications, unread count and tabs by group
  - Sending the same message over mail, push and an in-app feed at once
  - Writing a notification class for an event your application raises
  - Sending a one-off message without writing a class for it
  - Adding a delivery channel the framework does not ship
  - Choosing which channels an event should go out on
  - Controlling where a notification is delivered, per recipient and per channel
  - Working out why a notification silently never arrived
  - Making a notification arrive in the recipient's language
  - Reading and displaying a stored in-app notification feed
  - Testing that something notifies, without sending anything
---

# Notifications Guide

One event, several places it has to appear. `Notifier` is the piece that turns
"the invoice was paid" into an email, a row in the recipient's in-app feed and a
notification on their phone — written once, delivered to each.

```php
class InvoicePaid implements \Pramnos\Notification\NotificationInterface
{
    public function __construct(private int $invoiceId, private float $amount) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database', 'push'];
    }

    public function toMail(mixed $notifiable): array
    {
        return [
            'subject' => t('Invoice #%s is paid', $this->invoiceId),
            'body'    => '<p>' . t('We received %s. Thank you.', $this->amount) . '</p>',
        ];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return ['message' => t('Invoice #%s is paid', $this->invoiceId), 'url' => sURL . 'invoices'];
    }

    public function toPush(mixed $notifiable): array
    {
        return ['title' => t('Invoice paid'), 'body' => t('Invoice #%s', $this->invoiceId)];
    }
}

$user->notify(new InvoicePaid(42, 150.00));
```

That is the whole model. The rest of this guide is what happens between the last
line and the three deliveries, and the handful of places where the answer is not
what you would guess.

## The three pieces

| Piece | Contract | What it decides |
|---|---|---|
| **The notification** | `NotificationInterface` | *what* is said, and *which channels* it goes out on |
| **The notifiable** | `NotifiableInterface` (+ `NotifiableTrait`) | *where* it is delivered, per channel |
| **The channel** | `ChannelInterface` | *how* one delivery happens |

Only `via()` is required of a notification. Everything else — `toMail()`,
`toDatabase()`, `toBroadcast()`, `toPush()`, `toLog()` — is optional, and each
channel reads its own. That is deliberate: a notification that names a channel it
has nothing for is not an error, the channel skips it. So adding `'push'` to an
existing notification's `via()` and forgetting `toPush()` sends the mail and no
push, rather than failing the request that triggered it.

Make a model notifiable by adding the trait:

```php
class User extends \Pramnos\Application\OrmModel implements \Pramnos\Notification\NotifiableInterface
{
    use \Pramnos\Notification\NotifiableTrait;
}
```

`Pramnos\User\User` already is one — and an application's own user class is too, because
it extends that. The trait gives you `notify()` and the default routing below, which is
what `User` needs unchanged: `mail` reads `$this->email`, `database` reads `$this->userid`.

## `Message` — when there is no event to name

A class per event is the right shape when the event is known in advance. It is the
wrong shape for an operator typing a sentence to one account, a broadcast composed
in an administration screen, or a test send: there is no event, so there is nothing
to name the class after.

```php
use Pramnos\Notification\Message;

$user->notify(
    (new Message('Your export is ready', '<p>It is on your downloads page.</p>'))
        ->to('mail', 'database', 'push')
        ->link(sURL . 'account/downloads')
);
```

`Message` implements `NotificationInterface` and gives every channel a shape it can
use: the subject and body to mail, the same as a stored record to the database, and
a title and a shortened, tag-stripped body to push — because a push notification is
two lines on a lock screen, where HTML is shown as HTML and a paragraph is truncated
by the operating system at a point nobody chose.

Its chained setters are the `Email` capabilities, declared where a notification can
reach them:

| Setter | Effect |
|---|---|
| `to(...$channels)` | which channels (default: `database` alone) |
| `link($url)` | the push target and the stored record's link |
| `list($name)` | makes it non-transactional — see [Transactional or not](#transactional-or-not) |
| `template($name)` | the mail wrapper; `''` for none, `null` for the installation's default |
| `track()` | *requests* open/click tracking |
| `preheader($text)` | the line a mailbox list shows beside the subject |
| `from($address)` | a sender other than the default |
| `action($data)` | a schema.org block — a Gmail action, a brand mark |
| `pushOptions($options)` | icon, tag, action buttons |

## The channels

```php
'mail'      → Channels\MailChannel        reads toMail()
'database'  → Channels\DatabaseChannel    reads toDatabase()
'broadcast' → Channels\BroadcastChannel   reads toBroadcast()
'push'      → Channels\PushChannel        reads toPush()
'log'       → Channels\LogChannel         reads toLog(), else toDatabase()
```

**`mail`** composes an `Email` and sends it. Recipient from
`routeNotificationFor('mail')`, or `$notifiable->email` for an object that is not
notifiable. See the [Email guide](Pramnos_Email_Guide.md).

**`database`** writes one row to `#PREFIX#notifications` — the in-app feed. See
[The stored feed](#the-stored-feed).

**`broadcast`** hands a payload to the `BroadcastingManager` for whoever is
connected right now. `toBroadcast()` may return `channel`, `event` and `payload`
keys; the defaults are `notifications` and `notification.created`, and a return
value with none of those keys is used as the payload itself. See the
[Realtime guide](Pramnos_Realtime_Guide.md).

**`push`** sends web push to every subscription the account has, and does the
bookkeeping that goes with it — a dead endpoint is deleted, a busy service is
retried. It needs VAPID keys and the `minishlink/web-push` library, which is
*suggested* rather than required. Without either it sends nothing, and on every send it
logs why and records the refusal in the push log.
See the [Push guide](Pramnos_Push_Guide.md).

**`log`** appends one JSON line per dispatch to `LOGS/notifications.log`. For
development: it lets you see what a `notify()` would have sent without a transport. Not to be
confused with `notifier.log`, which is where the dispatcher records channels that *failed*.

### Choosing them per recipient

`via()` receives the notifiable, so the list can depend on it — and asking a cheap
question first is worth it:

```php
public function via(mixed $notifiable): array
{
    $channels = ['mail'];

    if (\Pramnos\Push\Subscriptions::exist($notifiable->userid)) {
        $channels[] = 'push';
    }

    return $channels;
}
```

That existence check is indexed. Without it, every account that never granted
permission writes a "nothing subscribed" row to the push log on every send.

## Routing: where a delivery goes

`routeNotificationFor($channel)` is the notifiable's answer to "where", one channel
at a time. The trait's default:

```php
'mail'     => $this->email
'database' => $this->userid ?? $this->id
default    => null
```

Override it for per-account preferences, a billing address that is not the login
address, or to suppress a channel for one recipient:

```php
class User extends OrmModel implements NotifiableInterface
{
    // Aliased so the override can still reach the default. `parent::` would not
    // work here — the default is a trait method, not an inherited one, and the
    // override replaces it outright.
    use NotifiableTrait { routeNotificationFor as private defaultRouteFor; }

    public function routeNotificationFor(string $channel): mixed
    {
        return match ($channel) {
            'mail'     => $this->billingEmail ?: $this->email,
            'database' => $this->wantsFeed ? $this->userid : null,
            default    => $this->defaultRouteFor($channel),
        };
    }
}
```

For `mail` and `database`, returning `null` (or `''` for mail) makes the channel skip —
one channel off for one person, with no change to any notification.

`push` does not behave that way today: `PushChannel` reads `routeNotificationFor('push')`
and uses it only when it is numeric, and otherwise falls back to the notifiable's `userid`
or `id`. A `null` route for push therefore still sends to the account's subscriptions.

### A notifiable does not have to be a user

For the case where a notification must reach an address rather than an account,
`Pramnos\Auth\Notifications\PlainAddress` is a notifiable that is nothing but an
address:

```php
(new Notifier())->sendNow(new PlainAddress('old@example.com'), $notification);
```

The framework uses it for exactly one thing, and the reason is worth borrowing: when
an account's email address is changed, the *previous* address is told. A stolen
session's first two moves are to change the address and then the password, and every
notification after the first goes to the attacker — so mailing the old address is the
only signal the real owner gets. It cannot be routed through the user object, because
the user object now points at the attacker's mailbox.

Note it is a separate class rather than a `User` with its address overwritten. A user
model is live: other code holds it, and a mutation made to send one mail is exactly
the kind that survives into a `save()`.

## The recipient's language

A notification is the one piece of text in an application that is not for whoever made
the request. The language of the request belongs to the person who triggered it — an
operator resetting somebody's password from an English administration area, a queue
worker with no language at all — and the person who reads it is the notifiable.

`Notifier::sendNow()` therefore renders every notification inside
`Language::using($notifiable->language, …)`, so a `t()` call in `toMail()` resolves in
the recipient's catalogue and the previous one is restored afterwards. Nothing is
required of a notification but to use `t()`.

An empty or missing `language` means *change nothing*: an account that never chose a
language is told in the installation's language rather than in a guess.

Two consequences:

- **Do not translate at construction time.** `new InvoicePaid(t('paid'))` resolves in
  the sender's language, before the switch. Pass the data, call `t()` inside `toMail()`.
- **A mail composed by hand does not get this**, because it never goes through the
  Notifier. See the [Internationalization guide](Pramnos_Internationalization_Guide.md).

## Letting an operator rewrite the text

Every mail a notification sends can be replaced by a template an operator writes in
**Administration → Mail templates**, without touching the code.

A notification opts in by declaring the template's category and the variables it offers:

```php
public function storedMailTemplate(): array
{
    return [
        'category' => 'auth.twofactor_code',
        'vars'     => ['code' => $this->code, 'minutes' => 10, 'sitename' => $this->siteName],
    ];
}
```

The operator writes `{code}`, `{minutes}` and `{sitename}` in the subject and body, and
`MailChannel` substitutes them on the way out.

### The rule: empty keeps the default

Each field is answered on its own.

| Template row | What is sent |
|---|---|
| no row for the category | the text compiled into the notification |
| subject and body filled in | both from the template |
| subject filled in, body blank | the operator's subject, the **built-in** body |
| body filled in, subject blank | the built-in subject, the operator's body |

A row whose body is empty is an operator who filled in the subject and left the rest alone
— not an instruction to send an empty email. A channel that read the row wholesale would
send one, silently, to everybody, for as long as nobody looked.

The template's **HTML wrapper** is applied too, and it wins over one the notification asked
for: choosing the wrapper is most of why somebody opens that screen.

### Language, and the one template somebody wrote

The reader's language first, then the site's default, then **any row for the category**.

That last step is deliberate. An operator who wrote one template, in one language, meant it
to be used — and refusing it because the recipient's tag does not match would look exactly
like the feature not working, with no way to tell the two apart.

### What does not change

A notification that declares nothing is never looked up and behaves exactly as before, so
an installation that has written no templates pays nothing. An unknown placeholder is left
**visible** rather than blanked, because `{firstname}` showing up in a test send is a
mistake somebody can see; deleting it silently would make the template look correct in the
editor and arrive wrong.

A lookup that fails — an installation that never migrated the messaging tables — leaves the
composed message alone and logs why. A template is an override; failing to find one is not
a failure to send.

### They are already in the editor

`migrate` seeds one row per category, so **Administration → Mail templates** lists them
without anybody having to know the names. Each is seeded **blank** — which is what makes it
safe: blank means "use the built-in text", so a seeded installation behaves exactly as an
unseeded one until somebody types something.

Seeding the real wording would look friendlier and is a trap. The row would fork from the
class the moment the framework improved a sentence, fixed a translation or added a security
note, and the installation would keep sending the old one for ever with nothing to say why.

The editor shows **the placeholders the category offers**, not only the ones already in the
text — a blank row would otherwise advertise nothing — together with a line saying when the
message is sent, because `auth.new_signin` does not tell anybody whether editing it is safe.
An operator's row is never overwritten: a category that already has one is left alone.

| Category | Sent when | Variables |
|---|---|---|
| `auth.twofactor_code` | a second-factor code is mailed | `code`, `minutes`, `sitename` |
| `auth.new_device_link` | a sign-in link goes to a new device | `url`, `minutes`, `device`, `sitename` |
| `auth.new_signin` | an account is told about a new sign-in | `when`, `timestamp`, `sitename` |
| `auth.security_change` | a password, address or factor changed | `what`, `detail`, `when`, `timestamp`, `sitename` |
| `auth.invitation` | an address is invited to register | `link`, `hours`, `days`, `inviter`, `note`, `sitename` |
| `auth.verify_email` | a new account is asked to confirm its address | `link`, `hours`, `username`, `sitename` |

`{url}` in the second one is the whole message: a template that omits it mails somebody no
way to do the thing it is about.

`Pramnos\Messaging\SystemMailTemplates` is where that table lives in code, and it is what
the editor reads. A test compares it against what the notifications actually supply, in both
directions — a placeholder advertised and not supplied renders as `{like_this}` in somebody's
email, and a category a notification answers to but the registry omits is an override nobody
can find.

### Registering your own category

An application's mail belongs in that screen on the same terms. Register it from a service
provider's `boot()`, or from `Application.php`:

```php
use Pramnos\Messaging\SystemMailTemplates;

SystemMailTemplates::register([
    'shop.order_shipped' => [
        'title'        => 'Order shipped',
        'description'  => 'Sent when an order leaves the warehouse.',
        'placeholders' => ['ordernumber', 'trackingurl', 'sitename'],
    ],
]);
```

The editor then shows your placeholders and your sentence exactly as it shows the
framework's — no subclass, no override.

**The framework's own win a collision.** An application cannot redefine what
`auth.twofactor_code` advertises: the notification supplying those variables is the
framework's, and a registry entry that disagreed would put `{like_this}` in somebody's email
with the screen's blessing.

Registering is about **what the editor shows**. Whether a row exists is a separate question,
answered by a seeding migration — the framework seeds its own and an application seeds its
own, in its own migration, for the same reasons and blank for the same reasons.

**A seed migration runs once**, so a category added later gets no row on an installation that
already migrated. The framework's seeding is one method, `SystemMailTemplates::seedMissingRows()`,
which writes only what is missing; each batch of new built-in categories comes with a dated
migration that calls it, and a test that pins the list fails until that migration exists.

A category nobody registered still works: the editor falls back to the placeholders it finds
in the text, as it always did. What it cannot do is describe a blank one, which is why
registering is worth the three lines.

## Transactional or not

A notification may declare `unsubscribeList(): string`. When it does, two things happen
in `MailChannel`: the address is checked against the unsubscribe records and skipped if
it has opted out, and the message goes out with a `List-Unsubscribe` header, its
one-click companion, and a visible link in the footer.

```php
class WeeklyDigest implements NotificationInterface
{
    public function unsubscribeList(): string { return 'digest'; }
    public function toMail(mixed $notifiable): array { /* … */ }
}
```

Declaring nothing means transactional, and gets none of it. That is the right default:
a password reset must arrive even for somebody who unsubscribed from everything, and an
unsubscribe link on it teaches people the link does nothing.

Suppression happens **before** composition — an address that asked us to stop is a
message not sent, and rendering the body first only wastes the work.

## Why a notification silently did not arrive

Channels skip rather than throw, which is right for delivery and unhelpful for
debugging. In order of how often it is the answer:

1. **The notification has no `to<Channel>()` method.** The commonest one. Adding a
   channel to `via()` is two characters; adding its payload method is not.
2. **Routing returned nothing.** No `email` on the notifiable, no `userid`, or an
   override returning `null`.
3. **The address opted out of the list** the notification declared.
4. **Push has no subscriptions, no VAPID keys, or no library.** All four push refusals
   are recorded in the push log with the notification's name — start there.
5. **An earlier channel threw** and was logged rather than raised — look in `notifier.log`,
   which names both the channel and the notification. See below.

The `log` channel is the fastest way to answer 1 and 2: add it to `via()`, send, and
read `LOGS/notifications.log`.

### A channel that throws, and the ones after it

Each channel is called inside its own `try`, so one that *throws* — as opposed to one that
skips — is logged to `notifier.log` with its own name and the notification's, and the
remaining channels are still tried. The order in `via()` therefore does not decide who gets
what.

That is the same rule `PushChannel` already applies to its own batch one level down, for the
same reason: one failed delivery must not take down whatever else was queued behind it.

For a caller that must know instead — a queue worker deciding whether to retry, an
administration screen that told an operator "sent" — ask for the exception:

```php
(new Notifier())->throwOnChannelFailure()->sendNow($user, $notification);
```

It is off by default because the default has to serve the request path: somebody changing
their password should not be shown a failure because an audit broadcast could not connect.

**An unknown channel name throws either way.** A typo in `via()`, or a channel class that was
renamed, is a mistake in the code and not a delivery that failed — catching it would turn the
one error here that a test would catch into a line in a log nobody reads.

## Adding a channel

Implement `ChannelInterface` — one method, and the same skip-don't-throw discipline:

```php
namespace App\Notifications\Channels;

use Pramnos\Notification\{ChannelInterface, NotificationInterface};

class SmsChannel implements ChannelInterface
{
    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        if (!method_exists($notification, 'toSms')) {
            return;
        }

        $number = $notifiable->routeNotificationFor('sms');

        if (!is_string($number) || $number === '') {
            return;
        }

        // … your gateway call, wrapped so a gateway outage does not abandon
        // the channels listed after this one.
    }
}
```

Then name it from `via()` **by its fully-qualified class name**:

```php
public function via(mixed $notifiable): array
{
    return ['mail', \App\Notifications\Channels\SmsChannel::class];
}
```

Any FQCN implementing `ChannelInterface` is accepted as a channel name, which is what
makes a custom channel possible without touching the framework.

### `registerChannel()` and the trap in it

There is also a short-alias registration:

```php
$notifier = (new Notifier())->registerChannel('sms', SmsChannel::class);
$notifier->send([$user1, $user2], new OrderShipped($order));
```

**The alias lives on that one `Notifier` instance.** There is no shared registry, and
`$user->notify()` constructs its own `new Notifier()` — so an alias registered anywhere
else is invisible to it, and `via()` returning `'sms'` throws
`InvalidArgumentException: Unknown notification channel: 'sms'`.

So: use `registerChannel()` only when you also own the `Notifier` doing the sending, and
the FQCN form everywhere else. The FQCN works through `notify()`, through
`ServiceProvider` bootstrapping, and in a test, because it needs no registration at all.

Channels are constructed by the Notifier as `new $class()`, with no arguments. The
constructor injection the built-in channels offer — `MailChannel(?Email)`,
`DatabaseChannel(?Database)`, `LogChannel(string $path)` — is for constructing them
yourself, in a test or in your own dispatch code. A custom channel therefore needs a
usable no-argument constructor.

## The stored feed

`DatabaseChannel` writes one row per (notifiable, notification) to
`#PREFIX#notifications`, created by the framework's `notifications` feature migration:

| Column | Meaning |
|---|---|
| `id` | UUID v4, one per row |
| `type` | the notification's class name |
| `notifiable_type` / `notifiable_id` | the recipient's class and primary key |
| `data` | the JSON of `toDatabase()` |
| `read_at` | `NULL` means unread |
| `created_at` | when it was dispatched |

The table is created by the framework's own migrations — `./yourapp migrate`. Unlike the other
channels, this one does not skip when its prerequisite is missing: it issues an `INSERT`, so an
unmigrated installation gets a SQL error rather than a quiet no-op. If `'database'` is the channel
that breaks a request, check that the table exists before anything else.

Reading it back is `Pramnos\Notification\Feed` (see [Showing the feed](#showing-the-feed)):

```php
$feed = new \Pramnos\Notification\Feed((int) $user->userid);
$page = $feed->list(page: 1, perPage: 20, unreadOnly: true);   // items, total, unread, page, pages
```

Indexes exist on `(notifiable_type, notifiable_id)`, `type`, `read_at` and `created_at`,
so the feed query, the badge count and a retention sweep are all covered.

Two things to decide per application, because the framework takes no position:

- **Retention.** Say how long a read notification is kept, and `notifications:prune` applies it
  daily: `'notifications' => ['keep_read_days' => 180]`. Unread ones are never removed. With no
  number set, nothing is removed. See [Keeping it tidy](#keeping-it-tidy).
- **Whether a security warning belongs in it at all.** An in-app notification is read by
  whoever is signed in — which, for "your account was signed in to from a new device", is
  the wrong person in exactly the case worth warning about. The framework's own
  `NewSignInNotification` deliberately omits `'database'` for this reason, and uses mail
  and push, both of which reach the *owner* rather than the current session.

### The screen that already does this

`/admin/users/notify` is a built-in administration screen for sending one account a message —
"your export is ready", "we reset your second factor", "your account is locked because". It
composes a `Message`, and it offers only the channels that account can actually receive: mail
needs a valid address, push needs a VAPID pair **and** at least one subscribed browser, and each
unavailable channel says why.

That last part is the point worth copying into your own screens. An operator who presses Send and
is told "sent" is entitled to believe it, and the skip-don't-throw discipline that makes delivery
robust is exactly what makes a silent non-delivery possible. Check reachability before offering the
channel, not after.


## Showing the feed

What the `database` channel stored, shown in the page: a bell in the header with the unread count,
a list, tabs by group, and "mark all read".

### Reading it: `Feed`

```php
use Pramnos\Notification\Feed;

$feed = new Feed((int) $user->userid);

$feed->list(page: 1, perPage: 20, unreadOnly: false, group: 'tasks');
// ['items' => [...], 'total' => 37, 'unread' => 4, 'page' => 1, 'pages' => 2]
$feed->unreadCount();            // 4, in all
$feed->unreadByGroup();          // ['' => 4, 'tasks' => 2, 'system' => 1]
$feed->markRead($id);            // false for somebody else's, or one already read
$feed->markAllRead('tasks');     // how many it marked
```

Each item has `id`, `type`, `group`, `title`, `message` (or `body`), `url`, `read`, `created_at`,
and the whole stored `data`. The bell draws `title`, `message` and `url` from it, so a
notification that returns those from `toDatabase()` needs nothing else:

```php
public function toDatabase(mixed $notifiable): array
{
    return ['title' => 'Task assigned', 'message' => $this->task->name, 'url' => '/tasks/' . $this->task->id, 'group' => 'tasks'];
}
```

Every read and write is that person's own: the id of somebody else's notification marks
nothing.

### Groups, for tabs

A notification's group is, first, the one whose classes `app.php` lists for it:

```php
'notifications' => ['groups' => [
    'tasks'  => [App\Notifications\TaskAssigned::class, App\Notifications\TaskDue::class],
    'system' => [App\Notifications\BackupFailed::class],
]],
```

Otherwise it is the `group` key the notification stored. A configured group is filtered by
`type`, which is indexed; any other group by the stored JSON. A notification in neither has no
group: it shows under "all" and in no tab.

### The endpoint: `/Notifications`

`NotificationsController` answers the signed-in person's feed as JSON, with the page's own session.
A write needs the page's CSRF token, `X-CSRF-Token` as `pfApiHeaders` sends it. It works in any
application with a session, including an administration with a front controller of its own. `init`
writes `src/Controllers/Notifications.php` with the `auth` feature. A project made before adds the
wrapper:

```php
namespace App\Controllers;

class Notifications extends \Pramnos\Application\Controllers\NotificationsController {}
```

| Request | Answer |
|---|---|
| `GET /Notifications?page=1&per_page=20&unread=1&group=tasks` | one page |
| `GET /Notifications/count` | `{unread, groups: {tasks: 2, …}}` |
| `POST /Notifications/read?id=…` | `{read, unread}` |
| `POST /Notifications/readall?group=tasks` | `{marked, unread}` |

Nobody signed in gets 401. A write without the token gets 403 and changes nothing.

### The bell: `Html\NotificationBell`

```php
<?php echo \Pramnos\Html\NotificationBell::render([
    'url'      => sURL . 'Notifications',          // the default
    'groups'   => ['' => 'For me', 'tasks' => 'Tasks', 'system' => 'System'],
    'interval' => 60,                              // seconds between counts
    'channel'  => 'private-users.' . $user->userid, // the default where there is a socket
    'class'    => 'btn btn-ghost btn-circle',
    'all'      => adminUrl('Myaccount/notifications'), // a "See all" link at the foot; none by default
    'allLabel' => 'See all',
    'titleFlash' => true,                          // the tab's title on an arrival (the default)
    'sound'      => false,                         // true: a short tone; or a sound's address
]); ?>
```

An item's title is the notification's `title`, or the `subject` a `Notification\Message` writes.
Nothing is drawn for somebody signed out. The unread count is drawn already, so the page never
shows 0 before the real number. `pf-utils.js` does the rest through the data attributes:

- **Counting:** the count is polled every `interval` seconds while the tab is visible. A tab in
  the background asks nothing, and is counted at once when it comes back.
- **Opening:** opening the bell loads the latest ten, and a tab loads its group.
- **Reading:** a click on an item marks it read, then follows its link. "Mark all read" marks
  the group.
- **Closing:** outside the bell, or Esc.
- **Everything else:** with `all`, a link at the foot of the panel leads to the page listing every
  notification — under the list, and there when the list is empty too.
- **Arriving** (on the realtime connection): the bell refreshes, then reads the new one with the
  page's session and dispatches **`pf:notification`** on the document with
  `{ id, type, title, data, item }` — an application reacts to a type without a channel of its
  own (`if (e.detail.type === 'App\\Notifications\\TodoNotification') …`).
- **News only:** the title and the sound below react only to a notification found by its id
  among the newest and still unread. One written already read — kept for the record, such as a
  change the user made themselves — or read in another tab first, is announced with
  `read: true` and changes nothing on screen.
- **The tab's title** carries "(N) " while N are unread. When one arrives the title alternates
  with its title until the tab is looked at — for ten seconds if it already is — then is put
  back. `'titleFlash' => false` leaves the title alone.
- **A sound**, with `'sound'`, plays once per browser, not once per tab: the shared realtime
  connection names one tab — the visible one, else the one seen last — and without it the visible
  tab plays it. Where the browser refuses to play before a click, nothing plays and nothing
  complains.
- **Refreshing at once:** a `pf:notifications:refresh` event on the document refreshes it, as does
  a `notification.created` event on `channel` where the page has a realtime connection in
  `window.pfRealtime`. See [Arriving at once](#arriving-at-once).

The scaffolded stylesheets style the `pf-notify-*` hooks. A theme can write its own markup with
the same `data-pf-notifications*` attributes. Each item can be its own too, as a
`<template data-pf-notifications-item>` inside the bell whose parts carry
`data-field="title|message|time"` and whose link gets the item's address.

### Arriving at once

With `broadcasting.transport` set to `websocket` (`broadcast:serve`) or `pusher`, a notification
reaches an open page the moment it is stored, with nothing written in the application:

1. `DatabaseChannel`, after writing the row, broadcasts `notification.created` with
   `{"id": "<uuid>"}` on the recipient's channel — `private-users.{id}`, or the pattern in
   `broadcasting.notifications_channel` (`'private-team.{id}'`).
2. A signed-in page carries `<meta name="pf-realtime">` with the client's connection settings and
   the endpoint that signs a private channel (`broadcasting.auth_endpoint`, `broadcasting/auth` by
   default). `pf-utils.js` connects from it and defines `window.pfRealtime` (see the
   [Realtime guide](Pramnos_Realtime_Guide.md#a-server-rendered-pages-connection)).
3. `NotificationBell` listens on that same channel unless `channel` is given, and the default
   `broadcasting.channels` registry admits a user to their own channel and nobody else's.
4. The bell re-reads its count through `/Notifications`, with the page's session. Only the id
   travels over the socket, so nothing private passes through the relay.

The announcement is best effort. With no broadcasting manager nothing is sent; a broadcast that
fails is logged to the `broadcasting` log and the row stays, so the page finds it at its next
count. The polling never stops either way: a connection that is down costs at most one interval.

After a dropped connection comes back, `pf-utils.js` dispatches `pf:notifications:refresh` once,
because pub/sub keeps nothing for a subscriber that was away. A first connection that never
completed its handshake dispatches nothing, since it had nothing to miss. A SPA bell listens to the
same event on the window, so it re-reads too.

SSE is not used for this. On PHP-FPM every open event stream holds a worker for as long as the
tab is open, so a bell on every page would use up the pool. A page whose transport is `sse`, or
which has none, keeps polling and gets no `data-channel`.

**In the scaffolded header** the bell is already drawn for whoever is signed in. One header serves
the site and the administration, so the bell is in both:

```php
<?php echo \Pramnos\Html\NotificationBell::headerButton('<li class="nav-item">', '</li>', ['class' => 'nav-link']); ?>
```

`headerButton()` draws nothing for somebody signed out, or where there is no `notifications`
table. A project scaffolded before it adds the line beside the What's new button in
`header.php`.

**In a server-rendered navigation** the count is a badge, without the bell:

```php
new NavItem('user.notifications', 'Notifications', sURL . 'Notifications', NavSection::User,
    requireAuth: true, badge: \Pramnos\Notification\Feed::badge());
```

### In a SPA: `NotificationBell.svelte` over `FeedApi`

A Svelte SPA draws the same bell as the server-rendered pages. `NotificationBell.svelte` uses the
same `pf-notify-*` classes, so the site, the administration and the SPA show one bell. It reads
the feed through the API:

```php
// src/Api/routes.php — written by init with the auth feature
\Pramnos\Notification\FeedApi::routes($r);
```

| Request | Answer |
|---|---|
| `GET /notifications?page=1&per_page=20&unread=1&group=tasks` | `{status, data: {items, total, unread, page, pages}}` |
| `GET /notifications/count` | `{status, data: {unread, groups}}` |
| `POST /notifications/read` `{id}` | `{status, data: {read, unread}}` |
| `POST /notifications/readall` `{group}` | `{status, data: {marked, unread}}` |

These are the same answers `/Notifications` gives a server-rendered page, with the API's
credentials: a bearer token, or the page's session. Nobody signed in gets 401.

`init` writes the component into every Svelte SPA, and the shell draws it for whoever is signed
in. The bell loads in four cases:
- when it appears;
- when it is opened;
- when the tab is brought back;
- every `interval` seconds while the tab is visible, which is 60 by default. `<NotificationBell interval={0} />` turns this off where notifications are rare.

A `pf:notifications:refresh` event on the window reloads it at once.

## Sending many, and sending later

### Getting the mail off the request

`notify()` returns when every channel has been called, so an SMTP round trip is a round trip
the visitor waits for. A notification can decline to wait:

```php
class WeeklyDigest implements NotificationInterface
{
    public function queueable(): bool { return true; }
    public function toMail(mixed $notifiable): array { /* … */ }
}
```

The message is **composed in this request** — rendered, wrapped, suppression-checked — and
written to the outbox instead of an SMTP connection. `mail:flush` delivers it. See
[the outbox](Pramnos_Email_Guide.md#the-outbox) for what the worker does with a refusal.

Composed now rather than by the worker, and that is the part worth understanding: composition
reads the request's language, its settings and its unsubscribe token, and a worker running an
hour later has none of them. A spool that stored the inputs and rendered later would send a
different message from the one you composed — occasionally, and unreproducibly.

**Only mail defers.** The other channels are already cheap or already batched: the database
channel is one `INSERT`, and push queues every subscription and issues a single `flush()`.

Declaring nothing keeps a notification synchronous, which is the right default and not
timidity:

| | |
|---|---|
| A second-factor code | somebody is watching the screen for it — **never queue** |
| A new-device sign-in link | same |
| An operator pressing Send | they are entitled to be told what happened |
| A security alert, an audit notice, a digest | nobody is waiting — **queue** |

The framework's own `NewSignInNotification` and `SecurityChangeNotification` declare it; its
second-factor and auth-link notifications deliberately do not.

### And for many recipients

```php
(new \Pramnos\Notification\Notifier())->send([$user1, $user2, $user3], new OrderShipped($order));
```

`send()` is a `foreach` over `sendNow()`, so it is per-recipient rendering with the language
switch each time — right for correctness, and linear in the number of recipients. With
`queueable()` on the notification, what that loop costs is one row each rather than one SMTP
connection each.

For an audience rather than a list of accounts — a campaign, an announcement to everybody —
use the mass-message path instead. It resolves the audience once, writes one row per recipient,
and delivers in batches from a command, so a send interrupted halfway resumes without sending
anything twice. `notify()` in a loop over ten thousand users has none of that.

## Testing

A notification is a value object, so its copy is a unit test with no transport:

```php
public function testTheSubjectNamesTheInvoice(): void
{
    // Arrange
    $notification = new InvoicePaid(42, 150.00);

    // Act
    $mail = $notification->toMail(new PlainAddress('a@example.com'));

    // Assert
    $this->assertStringContainsString('42', $mail['subject']);
}
```

For dispatch itself — *did it notify, and on which channels* — a spy channel is enough,
and it needs no registration if `via()` can name it:

```php
class SpyChannel implements ChannelInterface
{
    public static array $sent = [];

    public function send(mixed $notifiable, NotificationInterface $notification): void
    {
        self::$sent[] = $notification::class;
    }
}
```

Reset the static in `setUp()`. For a notification whose `via()` is fixed, construct a
`Notifier`, `registerChannel()` the spy over a real alias, and assert on what it collected
— that is the one case the per-instance alias is exactly what you want, because the
override is scoped to the test.

Assert the language switch by having the spy record `Language::getInstance()->currentlang()`
rather than by inspecting rendered copy; the copy tells you what a catalogue contained, the
current language tells you the switch happened.

See the [Testing guide](Pramnos_Testing_Guide.md).

## Settling what is about something dealt with

A notification can say what it is about, and is marked read when that thing is dealt with.
Otherwise a feed fills with alerts about things already handled, and a reader learns to ignore
the bell.

```php
public function toDatabase(mixed $notifiable): array
{
    return ['message' => 'A comment is waiting', 'url' => '/comments/42', 'about' => ['comment:42']];
}

// The comment was answered: everybody's notifications about it are read.
\Pramnos\Notification\Feed::settleAbout(['comment:42']);
// Somebody opened it: theirs only.
\Pramnos\Notification\Feed::settleAbout(['comment:42'], $userId);
```

`about` is one key or a list, and any one of them settles the notification. A notification that
only mentions a key in its text is not about it. Reading is not deleting: a settled notification
stays in the feed, read.

## Keeping it tidy

```php
// app/app.php
'notifications' => ['keep_read_days' => 180],
```

`notifications:prune` runs daily from the framework's schedule. It removes notifications read
more than that many days ago. An unread notification is never removed: one nobody opened is the
one case where age is not a reason to remove it. Without a number it removes nothing and says how
to set one. `--days=N` runs it once with another number, and `Feed::prune($days)` does the same
from code.

## Related guides

- [Email guide](Pramnos_Email_Guide.md) — what `MailChannel` composes: wrappers, unsubscribe, tracking, deliverability
- [Push guide](Pramnos_Push_Guide.md) — VAPID, subscriptions, the push log, service workers
- [Realtime guide](Pramnos_Realtime_Guide.md) — what `BroadcastChannel` hands off to
- [Internationalization guide](Pramnos_Internationalization_Guide.md) — `t()`, catalogues, `Language::using()`
- [Queue guide](Pramnos_Queue_Guide.md) — getting dispatch off the request
- [Security guide](Pramnos_Security_Guide.md) — `SecurityChangeNotifier` and the account's own security mail
- [Authentication guide](Pramnos_Authentication_Guide.md) — the sign-in and second-factor notifications
