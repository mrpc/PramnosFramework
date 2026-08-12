---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
  - Security
tags:
  - authentication
  - api
  - session
---

# An API request is not a browser session

A website knows its visitor from a session cookie. An API knows its caller from
the credential presented on the call — that is what makes it an API rather than
a website that returns JSON. The framework's API middleware wrote its answer
into `$_SESSION` anyway, and in an application serving both from one origin the
two share that cookie.

<!-- more -->

Both directions were live, and both were found by using the debug toolbar on a
real application:

- **Writing.** A call authenticated with one user's token set
  `$_SESSION['user']` and `['logged']`, so the browser's *next page* belonged to
  that user — whoever was signed in on it.
- **Erasing.** An anonymous call ran
  `unset($_SESSION['user'], ['logged'], ['uid'])`, to be sure a cookie could not
  authenticate it. That achieved the goal by destroying the session: one
  unauthenticated poll from a widget signed the reader out of the website.
- **Reading.** With the writes removed, `getCurrentUser()` fell through to the
  session — so a website login authenticated API calls that presented nothing,
  and `logout` could not work at all: revoking the token left the cookie
  answering for it.

## Fixed

**`Pramnos\Http\RequestIdentity`** — a request settles who is calling, possibly
nobody, and that answer stands. `User::getCurrentUser()` consults it first and
**stops there when it is sealed**, instead of falling through to the session.

```php
RequestIdentity::seal($user, 'accessToken');   // this request is $user
RequestIdentity::seal(null);                   // this request is anonymous
```

The distinction between *sealed-and-anonymous* and *never-asked* is the whole
mechanism: only the first stops the session being consulted.

`ApiAuthMiddleware` and `UnifiedAuthMiddleware` both seal instead of writing the
session. The session path of `UnifiedAuthMiddleware` still *reads* it — that path
is the session, and its `X-CSRF-Token` requirement is what makes it safe — but it
no longer decides anything for the rest of the request either.

The session-path asymmetry is deliberate and worth stating: **an application that
needs both** (an authserver whose own web UI calls its own endpoints) should use
`UnifiedAuthMiddleware`, which accepts a Bearer token *or* a session cookie plus
a CSRF token. A flag that simply let cookies authenticate an API would be the
same thing without the protection — a browser sends cookies by itself, so any
site could then make authenticated calls on the user's behalf.

## Also fixed

`$_SESSION['uid']` was never set on the token path, while
`Session::staticIsLogged()` requires `logged` **and** `uid`. So
`getCurrentUser()` answered false for a perfectly valid token: `/me` returned 401
to a signed-in user, and a SPA showed a login button to somebody who had just
logged in. It is moot now — nothing on that path writes the session — but it is
why the investigation started.

## Documentation

The Authentication guide taught the broken pattern. Twelve places across two
guides showed `if (!isset($_SESSION['user']))` as the way to check
authentication in an API controller — so an application that followed the docs
inherited the cross-wire. They now use `User::getCurrentUser()`, with a table
separating the two ideas and a warning explaining what reading the session costs.

## Tests

`ApiAuthSessionIsolationTest` holds the line in both directions: an anonymous
call leaves the session byte-identical, and a website cookie does not identify an
API request. The old `testNoTokenClearsAmbientSessionIdentity` **asserted the
bug** — it is now `testNoTokenMeansAnonymousWithoutDestroyingTheSession`.

A PHPUnit extension resets the request identity before every test. It is
request-scoped by design, and a test run is the one place where that assumption
fails: thousands of "requests" share one process, so an identity sealed by one
test answered for every test after it — 135 failures in tests that had nothing to
do with authentication. Doing it centrally rather than in each `setUp()` matters,
because the state is reached indirectly and any list of "tests that need it"
would go quietly out of date.
