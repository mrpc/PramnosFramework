---
date: 2026-08-17
categories:
  - Changelog
  - Added
tags:
  - auth
  - identity
  - spa
---

# Two seams that were already half there

Both requested by a hybrid application, and both pointed at something the framework had
already anticipated and not finished: `seal()` has a slot for an issued token and
nothing issued one, and `seal()` models absence but cannot model
presence-without-an-account.

<!-- more -->

## `SessionExchange` — the browser is signed in; give it a token

The symptom, reported verbatim: *"I am signed in on the site; if I leave it a while and
then open the panel, it asks me to log in again."* A session-authenticated site and a
token-authenticated SPA on one origin, two credentials, two lifetimes. The site knows
exactly who the visitor is. The panel has no way to ask.

`UnifiedAuthMiddleware` solves the **other** direction — an API endpoint accepting a
cookie plus a CSRF token — and the report was right to refuse it. Adopting it means the
API authenticates with cookies, which quietly invalidates every decision an application
made *because* it does not. Their permissive CORS default was the example, and it was
introduced a long way from where it would have broken.

So: an exchange. One direction, one moment, and the API still never reads a cookie.

```php
$token = SessionExchange::issue(minimumUserType: 90, ttl: 43200);
return Response::redirect(SessionExchange::redirectUrl(sURL . 'panel/', $token));
```

### Four decisions, three of them invisible when wrong

That framing is the reporter's and it is the reason this belongs in the framework rather
than in each application:

- **The role is re-read from the database**, not taken from the session. A remember-me
  cookie can outlive a demotion by a fortnight, and a token minted from that session is
  then good for its whole lifetime afterwards.
- **The token travels in the URL fragment.** A fragment is never sent to a server:
  no access log, no `Referer`, no proxy in between. `?token=` works, reviews identically,
  and writes the credential into the log of every hop for as long as logs are kept.
- **Nothing is issued for an anonymous caller** — no implicit token, no partial
  credential.
- **Failure is `null`**, because the caller is a route that has to redirect somewhere
  either way.

The claim set matches the API login's deliberately, so an exchanged token is
indistinguishable to every verifier. A second shape of token would be a second thing to
keep in step, and the one that is not exercised is the one that rots.

The fifth decision stays with the consumer and is documented rather than taken: an SPA
that bounces to the exchange route when it has no token **must record the bounce before
redirecting**. The route redirects back without a fragment when it cannot help, so a
flag written afterwards is an infinite loop — on the one page an operator opens when
something is already wrong.

## `RequestIdentity` had two states and needed three

```php
public static function seal(?object $user, …)
```

A user, or null for anonymous. Right for an API, where anonymous means *no identity at
all*. Not enough for an application whose unauthenticated callers are people: a chat
participant with a nickname and a session, present in a room, mutable, bannable,
addressable, and the same person across requests for as long as they stay. They are not
nobody. They are not an account either.

An application in that position keeps a **second, parallel notion of who the caller
is** — and then every consumer asking *"who is this"* has to know which of two
mechanisms to ask, with a convention between them instead of a type. The framework was
what forced that: the seam admitted one of the two shapes and the application carried
the other.

```php
RequestIdentity::sealGuest($presenceId, 'presence');

RequestIdentity::isGuest();    // true
RequestIdentity::user();       // null
RequestIdentity::subject();    // the id, whichever kind of identity this is
```

`subject()` is the point. One question, one answer, three states — an account's id, a
guest's id, or null for a request that is genuinely nobody.

### The asymmetry is the security-relevant part

**A guest never replaces an account**; the call is refused and logged. **An account does
replace a guest**, because that is a real login.

Symmetry would be the bug. A middleware that seals a guest unconditionally, ordered
after the one that authenticates, would demote the caller — and every permission check
after it would answer for the wrong person while the request looked entirely healthy.
There is a test for each direction, and they are the two worth having.

An empty id is refused too: every such guest would be indistinguishable, so a mute, a
ban or a rate limit keyed on it would apply to all of them at once.

`user()` still returns null for a guest, and that is deliberate — code asking for a user
must not be handed something that merely resembles one. It is why `isGuest()` is a
separate question rather than `user()` returning a shape.

## What neither of these decides

Which identity model an application should use. The reporting application has chosen one
and it stays theirs. Both additions are mechanism for an answer the framework already
half-expressed — which is why the requests were easy to agree with: each named the place
where the existing design stopped short of its own intent.

## Added

- `Pramnos\Auth\SessionExchange::issue()` and `::redirectUrl()`.
- `RequestIdentity::sealGuest()`, `isGuest()`, `guestId()` and `subject()`.

## Documentation

- [Authentication guide](../../Pramnos_Authentication_Guide.md) — *Handing the browser's
  user an API token*, and *Requests that are somebody without being an account*.
