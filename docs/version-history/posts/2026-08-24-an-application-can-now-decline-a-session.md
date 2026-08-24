---
date: 2026-08-24
categories: [Changelog]
---

# An application can now decline a session

Two things started per-visitor state on every request with no way to say no.
Together they were what stopped the page cache — shipped the same day — from ever
storing a page.

<!-- more -->

## Added

```php
// app/app.php
'session'          => 'lazy',   // no session for a visitor who has none
'session_tracking' => false,    // no tracking cookies, no sessions upsert
```

Both default to the behaviour that shipped. Neither is something a minor release
gets to change underneath an application.

## Why the page cache stored nothing

`Application::init()` started a session unconditionally, so every response carried
`Set-Cookie: PHPSESSID` — including a page render for an anonymous visitor who
never reads or writes a thing. `PageCache` refuses to store a response that sets a
cookie, correctly, because such a response is per-visitor in its body too.

So the two features could not both be used as shipped, and the reason was two lines
the application did not write. Reported from a consuming application that had
removed every other cookie it set: one `Set-Cookie` left on an anonymous page, and
it was this one.

```bash
curl -D- -o /dev/null -s https://example.test/ | grep -i set-cookie
```

## "Lazy" had to mean the narrower thing

The obvious implementation — never start a session, let `ensureStarted()` do it on
first use — does not survive contact with the codebase. Around **two hundred**
places in the framework read `$_SESSION` directly, `Session::staticIsLogged()`
among them. Under a never-start rule it would report every signed-in visitor as
anonymous until something happened to call a token helper. Nobody could turn that
mode on.

So lazy means **do not create a session for a visitor who has none**. A request
carrying a session cookie starts one at exactly the point it always did.

| Request | Eager (default) | Lazy |
|---|---|---|
| Anonymous, no cookie | session started, cookie sent | no session, **cacheable** |
| Carrying a session cookie | session started | session started |

Any request that *has* state gets it; only the ones that would have *created* state
for no reason do not.

## The other half: fifty-one writes

`$_SESSION` is written in 51 places across 14 framework files, and PHP will happily
let you write to it with no session started — the value goes into a plain array and
is gone at the end of the request, with no error and no warning.

The ones reachable on a request that may have no session now call
`ensureStarted()` first: signing in, the pending two-factor step, passkey
challenges, validation errors and old input, flash messages and errors, and
`?lang=`. `Auth::login()` is the one that would have hurt most — it sits directly
after `regenerateId()`, which returns false without an active session, so under a
naive lazy mode both the fixation defence and the four writes below it would have
quietly done nothing and nobody would have been able to sign in.

This is why the mode is opt-in. An application with its own `$_SESSION` writes has
to do the same, and the guide says so where the key is introduced.

### Two fixed on the way past

- `FormRequest::failWith()` called bare `session_start()`, which ignores the cookie
  parameters `Session::start()` sets — `secure`, `httponly`, `samesite`. A
  validation failure was the one request that got a laxer session cookie than every
  other one.
- `Base::addError()` and `addMessage()` were guarded by `isset($_SESSION)`, so they
  silently dropped the message when there was no session. That was almost never,
  because `init()` always started one; under lazy mode it would have become common.
  A flash message nobody sees is worse than a cookie on a page that was about to
  redirect anyway.

## Omission was being read as consent

`bootSessionTracking()` ran `SessionTrackingMiddleware` for any application that did
not **name** it in `middleware`. So the supported way to decline a feature was to
declare it, and then arrange not to run it — in two files, each carrying a comment
explaining the other, because either half alone reads as a mistake.

It cost exactly what that shape costs. One application's `app.php` carried the
comment *"NO SessionTrackingMiddleware … session tracking is deliberately NOT
wired"* and it had been running the whole time: two cookies and an upsert into
`sessions` on every request, crawler hits included. They had a passing test named
for the claim while the behaviour was its opposite.

`'session_tracking' => false` is checked **before** the two inference rules, so an
explicit answer is never overruled by a guess about one. It accepts the spellings a
config file actually contains — `false`, `0`, `'0'`, `'false'`, `'no'`, `'off'`,
`''` — because `'false'` from an env-driven config silently enabling the thing it
names would be the same bug again in a new place.

## Documentation

- [Framework Guide](../../Pramnos_Framework_Guide.md) — two new sections,
  "Declining the automatic session" and "Declining session tracking", including
  what a `$_SESSION` write without `ensureStarted()` costs.
- [Page Cache Guide](../../Pramnos_Page_Cache_Guide.md) — the diagnosis section now
  opens with the cookie check and points here.
