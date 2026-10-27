---
use_cases:
  - Deciding whether to subclass, fire an event, or add an action for a new extension point
  - Finding every hook/event/filter name the framework currently fires, and who listens to it
  - Reacting to a model being saved, a mail being sent, a login happening, or a transaction committing
  - Transforming a value — a rendered string, an upload, an object — through an ordered pipeline
---

# Pramnos Framework - Extensibility Guide

The framework lets other code react to, or change, what it does in four distinct ways. None of
them is going away, and none of them is the one true answer — each covers a shape of problem the
others don't. This page is the one place that says which is which, and catalogs every extension
point that exists today.

## Table of Contents

1. [The Four Mechanisms](#the-four-mechanisms)
2. [Deciding Which One to Use](#deciding-which-one-to-use)
3. [Writing a New Extension Point](#writing-a-new-extension-point)
4. [Catalog](#catalog)
5. [API Reference](#api-reference)
6. [Related Documentation](#related-documentation)

## The Four Mechanisms

### 1. Subclass override

One application's own customization of a class it already extends — no registry, no name, just
PHP inheritance. The right tool whenever exactly one application needs exactly one behavior
changed on a class it is already using directly.

```php
class Media extends \Pramnos\Media\MediaObject
{
    protected static function privateStorageModules(): array
    {
        return ['verification_docs'];
    }
}
```

See `Pramnos_Media_Guide.md`'s "Extending `MediaObject`" section for a fuller worked example —
`privateStorageModules()`, `afterLoadThumbnails()` and the `new static()` guarantee that make a
real extend practical.

### 2. `Event::fire()` / `Event::dispatch()`

"Something happened; zero or more independent listeners may care." The framework's actively-used
general-purpose bus: priority-ordered, de-duplicates re-registration (so calling `listen()` again
from a second request/test is a no-op, not a second copy), stops at a listener returning `false`,
and supports `QueuedListener` for async/debounced/idempotent work and typed event objects via
`dispatch()`.

This is what every subsystem the framework has grown since `Event` existed already uses — Auth
(sign-in risk, invitations, account export/erasure), Email (mailing-list confirmation), Database
transaction boundaries, the model change feed, Moderation, Conversations, Audience. New
framework-internal extension points should follow this shape, not reinvent one.

**A mature, real-world example of doing this at scale** (not a framework file, but worth reading
as the reference implementation of "many events, many listeners, cleanly organized"): an
application depending on this framework built a central `EventListeners::MAP` — each event's
`EVENT` class constant mapped to an ordered list of listener classes, with some listeners running
inline and others (`QueuedListener`) deferred to the queue worker. That structure — typed event
objects with an `EVENT` constant, dispatched via `Event::dispatch()`, a central map of what
listens to what — is the pattern to follow.

### 3. `Event::filter()`

"Transform this value, through every registered listener, in order." The one thing `fire()`/
`dispatch()` cannot do: they collect every listener's return value into an array, but never pipe
a single value through the chain. `filter()` does exactly that — each listener's return value
becomes the next listener's input, and the final value is what the caller gets back.

```php
$body = Event::filter('mail.compose.body', $renderedBody, $mail);
```

A `QueuedListener` registered for a filter event is skipped — it cannot return a value
synchronously, so it has nothing to contribute to the chain.

### 4. `Addon::addAction()` / `Addon::addFilter()`

A simple, synchronous hook/filter pair — the action half calls every registered callback and
discards return values (for side effects); the filter half threads a value through every
registered callback in priority order and returns what comes out the other end, same shape as
`Event::filter()` above but older, simpler, with no de-duplication, no queueing, and no typed
events.

This is not legacy to avoid. It is a genuinely useful, actively-used tool for **an application's
own internal pluggability** — not only something the framework calls into application code
through. A real example: an application lets a registered filter adjust an upload object (raise
the size limit for one module) before processing it, entirely in its own code:

```php
// Registered once, during boot
Addon::addFilter('mediauploader_beforeimageupload', [$this, 'changeUploadMaxSize']);

// Applied where the upload actually happens
$media = Addon::applyFilters('mediauploader_beforeimageupload', $mediaObject);
```

Choose this over `Event::filter()` when the need is simple — one application wiring its own code
together — and there is no reason to prefer `Event`'s richer guarantees. Prefer `Event`/`Event::filter()`
for a **new framework-internal** extension point specifically.

### 5. `Addon::load()` / `Addon::triger()` — the addon-instance system

A distinct concern from the tag-based pair above, covered in full in `Pramnos_Addon_Guide.md`:
conventionally-named classes loaded from a configurable directory, with lifecycle methods
(`AppInit`, `SaveSettings`, and any application-defined name) called on every loaded instance by
name. This is the only mechanism offering that shape — a long-lived, discoverable-by-(type, name)
registered instance, rather than a request-scoped callback — and it is load-bearing for several
real applications' authentication, user-management and settings-persistence flows.

## Deciding Which One to Use

| Need | Use |
|---|---|
| One application's own customization of a class it extends | Subclass override |
| A new framework-internal "something happened" moment | `Event::dispatch()`/`fire()` |
| A new framework-internal "transform this value" moment | `Event::filter()` |
| An application's own internal hook/filter, simple and synchronous | `Addon::addAction()`/`addFilter()` |
| A conventionally-loaded, long-lived, by-name-discoverable plugin instance | `Addon::load()`/`triger()` (see `Pramnos_Addon_Guide.md`) |

Middleware (`Pramnos\Http\MiddlewareInterface`, see `Pramnos_Routing_Guide.md`) is a fifth, related
but distinct concept — it composes behavior around one HTTP request/response cycle at a known
pipeline seam, and is the right tool for anything scoped to a request (auth checks, rate limiting,
CORS, CSRF). It cannot stand in for `Event`/`Addon`'s generic "transform an arbitrary PHP value"
capability (`Event::filter()`/`Addon::applyFilters()`), since `MiddlewareInterface::handle()` is
fixed to `(Request $request, callable $next): mixed`, and it only reaches a seam already wired
into one of the framework's four pipeline-construction points (the app-level scaffold, the Router,
a controller action, or `Api::exec()`) — not every lifecycle moment sits at one of those seams.

## Writing a New Extension Point

1. Define an `EVENT` class constant on the event object (or on the class firing a bare-string
   event) — not a bare string literal. Half of today's framework events already do this; make it
   the stated convention so the catalog below stays self-documenting as it grows.
2. Fire it (`Event::dispatch()`/`fire()`/`filter()`) at the moment that matters — after the state
   it describes has actually changed, inside a transaction only via the model change feed's own
   buffering (see `Pramnos_Change_Feed_Guide.md`), never speculatively ahead of a demonstrated need.
3. Add it to the [Catalog](#catalog) below, and to a small "Hooks on this page" table on whichever
   guide page documents the subsystem that fires it.

## Catalog

Every real extension point shipped by the framework today — not a wishlist, not a superset of
what a future version might add. "Fires in" is file:method; "listens today" names what currently
registers, where known.

### `Addon` actions (`doAction()`)

| Name | Fires in | Listens today |
|---|---|---|
| `send_headers` | `Document.php::render()`, `DocumentTypes/Html.php::render()`, `DocumentTypes/Amp.php::render()` | None shipped — an application registers its own via `addAction()` |
| `after_setup_theme` | `Theme.php::init()` | None shipped — see `Pramnos_Theme_Guide.md` for a worked example |

### `Addon` filters (`applyFilters()`)

None fire from framework code today — `applyFilters()` is a general-purpose tool applications use
for their own code, as in the worked example above. There is nothing here to list until the
framework itself calls it somewhere.

### `Addon` addon-instance lifecycle (`triger()`)

| Name | Fires in | Used by |
|---|---|---|
| `AppInit` | `Application.php::init()`, after addons load | Every application that registers addons |
| `SaveSettings` | Each application's own settings controller, after saving | A de facto convention across several independent applications' settings controllers — see `Pramnos_Addon_Guide.md` |

### `Event` names fired by the framework

| Name | Fires in |
|---|---|
| `mailinglist.confirmed` | `Email/MailingList.php` |
| `auth.signin_risk` | `Auth/SignInRisk.php` |
| `auth.signin_country` | `Auth/SignInRisk.php` |
| `invitation.accepted` | `Auth/Invitations.php` |
| `account.data_export` | `Auth/Controllers/Account.php` |
| `account.data_erase` | `Auth/AccountErasure.php` |
| `database.transaction.committed` | `Database.php::commitTransaction()` |
| `database.transaction.rolledback` | `Database.php::rollbackTransaction()` |
| `model.changed` | `Event/ChangeFeed.php`, fed by `Application/Model.php::emitChange()` — see `Pramnos_Change_Feed_Guide.md` |
| `moderation.kicked` (`Events\Kicked`) | `Moderation/Moderation.php` |
| `conversation.created` (`Events\ConversationCreated`) | `Conversations/Conversations.php` |
| `conversation.message.posted` (`Events\MessagePosted`) / `conversation.mention.received` (`Events\MentionReceived`) | `Conversations/Messages.php` |
| `segment.entered` (`SegmentEntered`) / `segment.left` (`SegmentLeft`) | `Audience/Segments.php` |

Model-changed listeners today: `ChangelogWriter` (if the `changelog` feature is enabled) and
`ChangeBroadcaster`.

## API Reference

### `Event::fire(string $event, mixed ...$args): array`

Calls every registered listener in priority order, collects return values. A listener returning
`false` stops propagation. A `QueuedListener` is queued, not called inline.

### `Event::dispatch(object $event): array`

As `fire()`, but the event is an object: its `EVENT` class constant (or its FQCN, if none) names
the event, and the object itself is the sole argument every listener receives.

### `Event::filter(string $event, mixed $value, mixed ...$args): mixed`

Threads `$value` through every registered listener in priority order — each listener's return
value replaces `$value` for the next one — and returns the final value. With no listeners
registered, returns `$value` unchanged. A `QueuedListener` registered for the event is skipped.

### `Addon::doAction(string $tag, mixed $arg = []): false`

Calls every registered callback for `$tag` in priority order, discarding return values.

### `Addon::applyFilters(string $tag, mixed $value, mixed $arg = []): mixed`

Threads `$value` through every registered callback for `$tag` in priority order, same shape as
`Event::filter()` but with no de-duplication and no queueing.

## Related Documentation

- [Addon Guide](Pramnos_Addon_Guide.md) - The addon-instance system, bundled addons, and the
  deprecation policy for the three currently deprecated ones
- [Change Feed Guide](Pramnos_Change_Feed_Guide.md) - `model.changed` in full detail
- [Queue Guide](Pramnos_Queue_Guide.md) - `QueuedListener`, debouncing, idempotency
- [Routing Guide](Pramnos_Routing_Guide.md) - Middleware, the related-but-distinct request-pipeline mechanism
- [Media Guide](Pramnos_Media_Guide.md) - `MediaObject`'s subclass-override hooks, worked in full
