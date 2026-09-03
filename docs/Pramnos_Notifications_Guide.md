---
use_cases:
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

`Pramnos\User\User` already is one. The trait gives you `notify()` and the default
routing below.

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
*suggested* rather than required. Without either it logs once and does nothing.
See the [Push guide](Pramnos_Push_Guide.md).

**`log`** appends one JSON line per dispatch to `LOGS/notifications.log`. For
development: it lets you see what a `notify()` would have sent without a transport.

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
            'mail'  => $this->billingEmail ?: $this->email,
            'push'  => $this->wantsPush ? $this->userid : null,
            default => $this->defaultRouteFor($channel),
        };
    }
}
```

Returning `null` or `''` makes the channel skip. That is the clean way to turn one
channel off for one person — it needs no change to any notification.

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
5. **An earlier channel threw.** See below.

The `log` channel is the fastest way to answer 1 and 2: add it to `via()`, send, and
read `LOGS/notifications.log`.

### An exception in one channel stops the ones after it

`sendNow()` iterates the channels from `via()` and calls each in turn. There is no
`try`/`catch` around the loop, so a channel that throws — not one that skips — abandons
the rest.

This makes the order in `via()` meaningful. Put the channel that must arrive first:

```php
return ['mail', 'push'];       // the durable copy first
```

The built-in channels are written not to throw for missing optional data, so this
mostly matters for a custom channel, and for a `toMail()` that itself raises. If a
notification's payload methods do real work that can fail, catch inside them.

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

Reading and marking read is the application's job — the framework stores, it does not
render:

```php
$db = \Pramnos\Database\Database::getInstance();

$unread = $db->table('notifications')
    ->where('notifiable_id', (int) $user->userid)
    ->whereNull('read_at')
    ->orderBy('created_at', 'desc')
    ->limit(20)
    ->get();
```

Indexes exist on `(notifiable_type, notifiable_id)`, `type`, `read_at` and `created_at`,
so the feed query, the badge count and a retention sweep are all covered.

Two things to decide per application, because the framework takes no position:

- **Retention.** Nothing prunes this table. A busy application should delete read rows
  past some age, or the feed table becomes the largest one in the schema.
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

## Sending many, and sending later

For several recipients, one notification:

```php
(new \Pramnos\Notification\Notifier())->send([$user1, $user2, $user3], new OrderShipped($order));
```

`send()` is a `foreach` over `sendNow()`, so it is per-recipient rendering with the language
switch each time — right for correctness, and linear in the number of recipients.

Within one recipient, push does not multiply that: `PushChannel` queues every subscription and
issues a single `flush()`, so an account with six devices is one batched send rather than six
round trips. It is the recipient count that costs, not the device count.

**Dispatch is synchronous.** `notify()` returns when every channel has been called, so a
mail server pausing is a request pausing. For anything on a user-facing path with more
than a couple of recipients, enqueue the notification instead of sending it inline and let
a worker call `notify()` — see the [Queue guide](Pramnos_Queue_Guide.md) and the
[Workers guide](Pramnos_Workers_And_Daemons_Guide.md).

Pass identifiers through the queue, not the objects. A serialised user model is a snapshot
that may be stale by the time the worker runs; a `userid` is not.

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

## Related guides

- [Email guide](Pramnos_Email_Guide.md) — what `MailChannel` composes: wrappers, unsubscribe, tracking, deliverability
- [Push guide](Pramnos_Push_Guide.md) — VAPID, subscriptions, the push log, service workers
- [Realtime guide](Pramnos_Realtime_Guide.md) — what `BroadcastChannel` hands off to
- [Internationalization guide](Pramnos_Internationalization_Guide.md) — `t()`, catalogues, `Language::using()`
- [Queue guide](Pramnos_Queue_Guide.md) — getting dispatch off the request
- [Security guide](Pramnos_Security_Guide.md) — `SecurityChangeNotifier` and the account's own security mail
- [Authentication guide](Pramnos_Authentication_Guide.md) — the sign-in and second-factor notifications
