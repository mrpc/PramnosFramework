---
use_cases:
  - Sending a notification to a device whose browser is closed
  - Deciding between web push, SSE and WebSockets for an alert
  - Adding push to a project that already has a service worker
  - Setting up VAPID keys, and understanding what rotating them costs
  - Working out why notifications stopped arriving for some users
  - Adding action buttons, sounds or a link target to a notification
  - Estimating the server load of notifying many users at once
---

# Web Push Guide

A notification on someone's phone when your site is not open, their browser is
closed, and your server holds no connection to them at all.

```php
class NewSignIn implements \Pramnos\Notification\NotificationInterface
{
    public function __construct(private string $city) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'push'];
    }

    public function toPush(mixed $notifiable): array
    {
        return [
            'title' => 'Νέα σύνδεση',
            'body'  => 'Ο λογαριασμός σας χρησιμοποιήθηκε από ' . $this->city . '.',
            'url'   => sURL . 'account/sessions',
            'tag'   => 'signin',
        ];
    }
}

$user->notify(new NewSignIn('Θεσσαλονίκη'));
```

That is the whole application-level API. The rest of this page is the setup it
needs once, and the handful of facts that decide whether it works in a year.

---

## How it actually works

**Your server holds no connection to anybody.** This is the thing most worth
understanding, because it is the opposite of what "push" suggests.

The browser keeps **one** connection open to its vendor's push service — FCM for
Chrome, Mozilla autopush for Firefox, APNs for Safari — shared across every site
that browser has ever subscribed to. When you send a notification, the framework
makes **one ordinary HTTPS request** to an address that service handed out, and
the service delivers it over the connection it already has. Then everything
closes.

```
your server  ──HTTPS POST──▶  push service  ──existing connection──▶  browser
  (one request per                (Google /                 (wakes the service
   subscription, then              Mozilla /                  worker, shows the
   nothing)                        Apple)                     notification)
```

Consequences worth planning around:

| | Web push | SSE / WebSockets |
|---|---|---|
| Cost while idle | **Zero** — no connection, no process | One held connection per viewer |
| Works with the tab closed | **Yes** | No |
| Works with the browser closed | **Yes** (the OS holds it) | No |
| Latency | ~1 second | Milliseconds |
| Payload | ~4KB, encrypted | Unlimited |
| Needs HTTPS | Yes, always | In practice |

They are not competitors. Use realtime for "this page should update now" and
push for "tell this person even though they are not here". Most applications
want both, and a notification that names both channels in `via()` gets both.

### Server load

One HTTPS request per subscription, sent with `curl_multi` so they go out in
parallel. The cost is dominated by **TLS handshakes, not encryption** — which is
why the channel builds one batch and flushes it once rather than sending in a
loop.

Rough shape on ordinary hardware: a few thousand subscriptions is seconds, not
minutes. Beyond that, send from the queue rather than from a web request:

```php
$queue->addTask('SendPush', ['notification' => 'NewSignIn', 'user_id' => 42]);
```

Idle cost is genuinely zero. A million subscribers who are not being notified
cost you a million rows and nothing else.

---

## Setting it up

### 1. The key pair, once

```bash
./yourapp push:vapid-generate
```

Writes `app/keys/vapid_private.key` and `app/keys/vapid_public.key`.

> **Rotating the key invalidates every existing subscription.** There is no
> registration and no shared secret with the push provider — the key pair *is*
> your application's identity. A browser that subscribed with the old public key
> cannot be pushed to with the new private one, and nobody finds out until
> somebody notices that notifications stopped. Generate once, back the pair up,
> and treat losing it as losing every subscriber.

The private key must never reach the repository. `pramnos init` writes a
`.gitignore` that keeps `app/keys/*.key` out and re-admits the two public ones;
if your project predates that, check yours before the first commit.

The command warns if no contact subject is configured. RFC 8292 wants one so a
push service has somewhere to write when something is wrong with what you send —
*before* it starts refusing. Set it explicitly in `app/app.php`:

```php
'push' => ['subject' => 'mailto:notifications@example.com'],
```

Failing that it falls back to the `admin_mail` setting as a `mailto:`, and then to
`site_url`. It is never invented: an unconfigured installation gets an empty
subject and a warning, because a guessed address nobody reads is worse than none
— the provider believes it warned you.

### 2. Run the migration

```bash
./yourapp migrate
```

Creates `pushsubscriptions` — one row per browser, unique on
`(userid, endpoint_hash)`.

### 3. A service worker

Push is delivered to a service worker, so a site without one cannot receive
notifications at all. If you scaffolded with `--service-worker=y` the handlers
are already there; see the [Service Worker Guide](Pramnos_Service_Worker_Guide.md)
to add one to an existing project. The stub handles `push`,
`notificationclick` and `pushsubscriptionchange` — the last of which is the one
people forget, and it is covered below.

### 4. The subscribing page

```js
async function subscribe() {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return;

    const registration = await navigator.serviceWorker.ready;
    const { publicKey } = await (await fetch('/push/key')).json();

    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: publicKey,
    });

    await fetch('/push/subscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(subscription),
    });
}
```

**Ask for permission from a click, not on page load.** A prompt that appears
before the visitor has done anything is denied by most people and — in Chrome —
suppressed entirely for visitors who habitually deny them. A denial is close to
permanent: the browser will not ask again, and the person has to find the site
settings themselves.

`userVisibleOnly: true` is not optional in Chrome. A push that shows no
notification is treated as an abuse of the wake-up, and the browser shows *its
own* "This site has been updated in the background" instead.

### 5. The library

RFC 8291's payload encryption — ECDH, HKDF, AES-128-GCM — is not written here:

```bash
composer require minishlink/web-push
```

It is a **suggestion rather than a requirement** so that applications sending no
notifications do not carry a push library in `vendor/`. Without it the channel
logs once and does nothing, which is a better failure than a message that
silently never arrives.

---

## What a notification can do

```php
public function toPush(mixed $notifiable): array
{
    return [
        'title'   => 'Νέο μήνυμα',
        'body'    => 'Ο Γιάννης σας έστειλε ένα μήνυμα.',
        'url'     => sURL . 'messages/42',      // where a click goes
        'icon'    => sURL . 'assets/icon-192.png',
        'badge'   => sURL . 'assets/badge.png', // monochrome, Android status bar
        'tag'     => 'message-42',              // replaces, instead of stacking
        'actions' => [
            ['action' => 'read',  'title' => 'Διάβασέ το'],
            ['action' => 'later', 'title' => 'Αργότερα'],
        ],
        'data'    => ['messageId' => 42],
    ];
}
```

**`tag` is the field worth using deliberately.** Two notifications with the same
tag replace one another rather than stacking, which is the difference between
one "new sign-in" and fourteen.

**Links.** A click opens `url` — but the shipped worker first looks for a tab
that is already on this origin and focuses it, posting the payload to the page
instead of opening a second copy of your site. That is what people expect and it
is three lines; it is in the stub.

**Action buttons.** At most two are shown, and the worker can handle one
*without opening a window at all* — the most useful thing a notification can do,
because the reader acts and the browser never comes to the foreground. Put the
target in `data.actions`, keyed by the same `action` string:

```php
'actions' => [['action' => 'notme', 'title' => 'Δεν ήμουν εγώ']],
'data'    => ['actions' => ['notme' => ['post' => sURL . 'account/lock']]],
```

`post` fires a credentialed POST and dismisses; a `url` opens that address
instead. Anything beyond two buttons is dropped by the browser, so the channel
caps them rather than spending payload bytes on buttons nobody sees.

**Sounds.** Effectively no. `silent: true` suppresses sound; choosing a custom
one is not supported by Chrome or Firefox, and the `sound` property is ignored
everywhere that matters. The notification uses the operating system's own
notification sound, and the person controls it. Plan for that rather than
against it.

**Payload size** is about 4KB *after* encryption. A service that receives more
rejects the message outright — it does not arrive truncated, it does not arrive.
Title and body are capped for you; put an id in `data` and let the page fetch the
rest.

---

## What goes wrong, and what the framework does about it

### A dead subscription must be deleted; a busy service must not be

The two answers that must not be confused:

- **404 / 410** — the subscription is gone. The browser was uninstalled, the site
  data cleared, permission revoked. The row is **deleted**. Retrying is not a
  recovery, and a table of dead subscriptions makes every future send slower for
  ever, because each one is still a full HTTPS round trip.
- **429 / 5xx** — the service is busy or briefly broken. The row is **kept**.

Deleting on a 429 silently unsubscribes live users during exactly the moment the
push service is under load, and nobody reports it, because it looks like nothing
happening. `Subscriptions::recordResult()` is where that decision lives, and it
is the reason this is a class rather than three lines in the channel.

A failure with **no response at all** — a DNS failure, a timeout — is not a 410
either. It counts as one bad attempt. After ten consecutive failures with no 410
among them, the subscription is presumed dead and removed; a single success
clears the count.

### `pushsubscriptionchange`

Browsers rotate a subscription's keys without asking, and the page may never be
open when it happens. If the worker does not re-subscribe and re-register, every
push to the old endpoint returns 410 and the row is deleted — the user simply
stops receiving notifications and has no way to know.

The shipped worker handles it. If you wrote your own, this is the handler to
copy.

### Subscribing on every page load

`subscribe()` resolves instantly when permission is already granted, so a page
that calls it on load calls it on *every* load. Stored naively that is a row per
page view and a notification delivered to the same laptop forty times — which is
why the table is unique on `(userid, endpoint_hash)` and a repeat subscribe is an
update, not an insert.

### Safari

iOS Safari supports web push **only for a site added to the home screen**, and
only over HTTPS. There is no way to subscribe from the browser tab. Neither is a
bug you can fix; both are worth saying on the page that asks for permission,
because otherwise iOS users see a button that does nothing.

### It works locally and not in production

Push requires HTTPS. `localhost` is exempt — every other host is not, including
an IP address on your LAN.

---

## The endpoints

| | |
|---|---|
| `GET /push/key` | The VAPID public key. Public on purpose: it is the half of the pair the browser is supposed to hold. Answers 503 if no pair has been generated. |
| `POST /push/subscribe` | Records `PushSubscription.toJSON()` for the signed-in account. |
| `POST /push/unsubscribe` | Forgets one endpoint, scoped to the signed-in account. |

The last two require a session, because a subscription belongs to an account —
without one there is nobody to notify. (That is deliberately the opposite of the
one-click mail endpoints, where requiring a session would break every request.)

`POST /push/unsubscribe` answers success when there was nothing to forget: the
browser has already unsubscribed by the time it calls, and reporting a failure
for something in exactly the state the caller asked for is not useful.

---

## Storage

A subscription is **a credential for a delivery address**, closer to a session
token or a passkey than to a message: whoever holds the endpoint can send a
notification to that browser. So it is treated like one — never logged, never
returned by an API, deleted the moment the push service says it is gone.

Notification *content* is not stored here. It goes where every other channel's
content goes: a notification that also names `'database'` in `via()` writes its
`toDatabase()` array to the `notifications` table, so the same alert is readable
in the application afterwards. Push is a *delivery*, not a record — a device that
was off for three days lost the notification, and only the table remembers it
happened.

---

## Related

- [Service Worker Guide](Pramnos_Service_Worker_Guide.md) — registering one, and the caching it also does
- [Realtime Guide](Pramnos_Realtime_Guide.md) — SSE and WebSockets, for when the page is open
- [Email Guide → a message to many accounts](Pramnos_Email_Guide.md#a-message-to-many-accounts) — pushing a campaign, and the audience filters
- [Queue Guide](Pramnos_Queue_Guide.md) — sending a large batch off the request
- [Email Guide](Pramnos_Email_Guide.md) — the other channel that reaches somebody who is not here
