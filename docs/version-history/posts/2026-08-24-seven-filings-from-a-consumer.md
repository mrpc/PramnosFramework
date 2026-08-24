---
date: 2026-08-24
categories: [Changelog]
---

# Seven filings from a consumer, and the two more they turned up

A consuming application keeps a folder of filings against this framework: bugs, gaps, and guides
that described something that did not exist. Seven were open. This is all of them, plus two faults
that only appeared because fixing the first seven meant running the tests.

<!-- more -->

Two of the seven were silent — they produced a wrong answer rather than an error — and those are
the ones worth reading first.

## Fixed — the silent ones

### One apostrophe, and every placeholder after it went unbound

`Database::preparedQuery()`'s placeholder scanner knew about string literals and not about
comments. An apostrophe inside a comment — the possessive in `/* a JOIN's clause */` — read as the
start of a literal, so every `:name` after it was left in the SQL unbound. The statement failed,
and because `preparedQuery()` answers `false` rather than throwing, a caller writing
`$result ?: []` could not tell that from an empty table.

It dark-screened a "now playing" page, whose only symptom was a sentence that page is entitled to
say: *nobody has reported a track in the last fifteen minutes*.

Writing an integration test for the fix found the other half. `prepare()` — which every query in
the framework goes through — counted its `%s`/`%d` placeholders with a quote-aware, comment-blind
regex **of its own**. The same apostrophe hid the real placeholder, and the prepare failed on an
argument-count mismatch. Two places were answering *where does this stop being SQL* with two
different, both-incomplete rules.

They now ask one. `maskInertSql()` returns a same-length copy of the statement with literals and
comments blanked out, and both callers read it; `prepare()`'s two regexes collapsed into a single
offset walk over that mask.

The dialects are told apart rather than averaged, because every difference decides whether a
placeholder binds:

| | MySQL | PostgreSQL |
|---|---|---|
| `#` to end of line | comment | operator — still SQL |
| `--` with no space (`5--3`) | arithmetic → `8` | comment → `5` |
| `/* a /* b */ c */` | ends at the first `*/` | nests |
| `/*!40101 … */` | **executed** — placeholders inside bind | ordinary comment |

The family test asks "not PostgreSQL" rather than "is MySQL", so an unknown driver lands on the
stricter rules: wrongly masking live SQL is the worse mistake.

### A lookup that wrote, and reverted the preference it found

`User::getCurrentUser()` promises to say who is signed in. On every call after the first in a
request it also compared `users.language` with the interface language and, when they differed,
overwrote the column and saved the user.

The branch was ordinary, not an edge. The first call caches the user on the application, so every
call after it landed there — and a page whose theme header asks who is signed in and whose
controller asks again reaches it as a matter of course.

Two things followed. `users.language` reads as the user's *stored preference*, and this treated it
as a cache of the interface language for whoever looked at them last: an operator who chose
English in a bilingual admin panel had that choice reverted by opening the Greek-rendered site,
silently, and only on the accounts that had used the feature — which is the population most likely
to be testing it. And on an account with no email address, ordinary for one an admin created, the
save could raise from `_save()`'s address validation, ending a request over a column nobody had
asked about.

The write is gone. Nothing else in the framework writes `users.language`, so it is now only ever
what your application put there. If you want the two kept in step, write the column where the
language is chosen — one place, visible in a diff, and it does not fire on an account that was
merely read.

## Fixed — the loud ones

### A translation with a placeholder could not be looked up

`Language::_()` called `sprintf()` on every translation it found, whether or not the caller passed
anything to format with, and handed it the arguments as a single *array*. Both halves were wrong
and they compounded: looking up a translation containing `%s` with no arguments was
`sprintf('%s', [])`, an `ArgumentCountError` and therefore fatal on PHP 8; a caller that *did*
pass arguments got `Array` printed for the first placeholder.

The untranslated path returns the key unchanged and never had the bug, which is what made this
expensive — a string worked in development against the source language and answered **500** the
day the language file gained the key. The symptom pointed at the language file rather than at the
translator.

Now: format only when arguments are given, and with `vsprintf`, so positional specifiers work and
a translation can reorder what its source string did not. A mismatch between a translation's
placeholders and a call site's arguments is caught and logged rather than raised — language files
are content, edited by translators, and a stray `%s` must not be able to take a page down.

The i18n guide showed `$lang->_('Welcome, %s!', $username)` before it worked. It now describes what
actually happens.

## Added — the HTTP client can stop reading, and can read several things at once

### `headersOnly()` and `maxResponseBytes()`

`Client` read a response to completion or to its timeout, and offered no way to say *the headers
are all I need* or *stop after N bytes*. Against an endpoint that never stops sending — an Icecast
mount, an SSE feed, a `tail -f` over HTTP — those are the same thing, and neither is the answer
the caller wanted.

Two measured failures. A live endpoint was reported unreachable: the server answered
`200 audio/mpeg` in milliseconds and all of it was discarded. And a faster endpoint never reached
the timeout at all — three seconds of a fast stream is a quarter of a gigabyte in `memory_limit`,
which is not a recoverable failure.

```php
// Is this stream up, and what is it serving?
$r = Client::get($url)->connectTimeout(2)->timeout(3)->headersOnly()->send();
$r->status();               // 200
$r->header('content-type'); // 'audio/mpeg'
$r->truncated();            // true — we stopped on purpose

// The first 16 kB of an endless body: enough for the ICY metadata block.
$r = Client::get($url)->header('Icy-MetaData', '1')->maxResponseBytes(16 * 1024)->send();
```

The second is not an approximation of the first: a caller that needs the headers *and* a bounded
prefix had no way to say so, and a consuming application had written the same cURL workaround
twice.

Reaching the ceiling is a **normal outcome**. The response carries a complete status and complete
headers, `body()` holds what was read, and `ClientResponse::truncated()` says whether anything is
missing — it answers *is something missing*, not *was a limit set*, so a body that fits, or a
`204`, comes back untruncated.

There is **no default ceiling**, deliberately: a default would silently truncate every caller that
legitimately downloads something large, and a body that quietly loses its tail is worse than one
that fails loudly.

`headersOnly()` is not `head()` — a great many servers answer HEAD with 404 or 405 on a path they
serve happily over GET (17 of 30 on one catalogue of streaming endpoints), so a prober built on
HEAD reports live services as dead.

### `Client::pool()`

Polling a catalogue one endpoint at a time is not a cadence, it is a backlog. 200 status endpoints
at ~1.1 s each is 218 seconds for one pass, so a poller promising a thirty-second tier was reaching
each station every four minutes and reporting otherwise. Almost all of that second is spent waiting
on somebody else's server — exactly the wait that overlaps.

```php
$responses = Client::pool([
    'aroma'  => 'https://one.example/status-json.xsl',
    'kosmos' => Client::get('https://two.example/stats?json=1')
        ->connectTimeout(2)->timeout(3)->maxResponseBytes(64 * 1024),
], concurrency: 8);
```

Keyed in, keyed out. **A failure is a value** — a dead host's entry is a `ClientException` in the
array and the pool itself never raises, because half these endpoints are down at any moment and
one of them must not abandon the other seven. Per-request options come from passing a configured
`Client`; `retry()` is honoured in rounds; fakes work, so a test of a batching caller does not
quietly become a live network test.

It is a facade over `curl_multi`, not a second transport: `execute()` was split so a pooled request
gets the same TLS defaults, redirect handling and header normalisation as the same request sent
alone.

### Two things fixed on the way

Response headers **accumulated across redirect hops**, so a redirected request answered with the
redirect's `Location` and `Content-Type` mixed into the final response's headers. And `execute()`
carried `@codeCoverageIgnore` — *"requires a live network endpoint"* — which left the only method
in the class that speaks HTTP as the only one nothing checked. A forked socket server is a live
network endpoint. `Client` and `ClientResponse` are now both at 100% line coverage.

## Added — the router can say a path exists for another method

`getMatchedRoute()` answers for the request's own method, so a `GET` on a `POST`-only endpoint fell
through exactly as a path nobody declared did. An application could only answer **404** for both —
honest, and unhelpful: it tells an integrator to check the address when the address was right.

```php
$allowed = $router->allowedMethodsFor($request);   // ['GET', 'HEAD', 'POST'], or []

if ($allowed === []) {
    return $this->notFound();                      // 404
}
header('Allow: ' . implode(', ', $allowed));
return $this->methodNotAllowed($allowed);          // 405
```

RFC 9110 §15.5.6 makes `Allow` mandatory on a 405, which you cannot send without knowing the set.
It lives on the router because matching a URI *pattern* against a path — placeholders, optional
segments, the query-string forms — is the router's own rule; re-deriving it from
`getRoutesWithPermissions()` would be a second spelling of the matcher.

HEAD is reported wherever GET is, because [that is what the router actually
does](../../Pramnos_Routing_Guide.md#head-is-answered-by-get). An `Allow` header without it would
deny a request about to be served.

## Fixed — two faults the work turned up

Neither was filed. Both were found by running the tests.

### A safety check that raised an error to say "no"

`Helpers::checkUnserialize()` exists to answer, safely, whether a string is serialized data. It
answered by handing the string to `@unserialize()`. The `@` suppresses the notice for *output*, but
the error is still **raised** — a `set_error_handler` sees it, and so does anything counting lines
in an error log.

The bill arrived when `usertokens.deviceinfo` began holding JSON instead of an empty string:
`unserialize('')` is silent, `unserialize('{…}')` raises *Error at offset 0*, and that column is
read on every token check on every request. It now pre-screens on the serialization format's own
grammar — every serialized value starts with a type letter and a colon — and the answer is
unchanged for every input.

### One `define()` decided the whole test run was "developing"

Two DevPanel test files called `define('DEVELOPMENT', true)` in `setUp()`. A constant cannot be
undefined, so from that point on every test in the process ran as if the application were in
development.

Two middleware tests had grown to depend on it without saying so: they assert that a JWT exception
message reaches the client as `data`, which is true only while developing, and neither arranged
that condition. They passed in a full-suite run and failed whenever their own class was run alone
— the run somebody makes while working on that file.

Underneath, the branch that matters in production had **no test at all**. Nothing asserted that the
detail is *withheld* when not developing, and that absence is exactly why the dependency went
unnoticed: no test claimed the state mattered, so nothing broke when it silently changed. Both
tests now set `APP_DEBUG` explicitly and restore it, the DevPanel files run in their own processes,
and the missing negative test exists.

Three `UnifiedAuthMiddleware` tests that reach the database also run in their own processes now.
`Database::getInstance()` caches one instance per name in a static built from whichever settings
were loaded first, so their `bootDatabase()` helper could not reach an instance that already
existed — under `--filter User` they inherited another connection and mysqli went looking for a
local socket.

## Documentation

- **A guide for the HTTP client.** It was documented only in `1.2-new-features.md`, which is frozen
  — precisely the shape the docs rules warn about, where a feature exists but the page describing
  it is one nobody is sent to. [Pramnos_Http_Client_Guide.md](../../Pramnos_Http_Client_Guide.md),
  in the nav, with `use_cases`.
- **`preparedQuery()` was undocumented.** The [Database API
  Guide](../../Pramnos_Database_API_Guide.md) now covers it, including what counts as SQL and what
  does not, and the dialect table above.
- **`users.language` is the user's preference**, and the framework does not touch it — stated in
  the [Authentication Guide](../../Pramnos_Authentication_Guide.md) so the removal above is a
  contract rather than an absence.
- **What the scaffolded SPA API client assumes.** `frontend/lib/api.js` speaks the framework's own
  API contract — an `apiKey` header, an `accessToken` header, `/account/login` — which an
  attribute-routed app authenticating with `Authorization: Bearer` shares none of. The
  [Application Styles Guide](../../Pramnos_Application_Styles_Guide.md) now tables what to replace
  and what is worth keeping. Legitimate divergence, but a reader meeting it should not have to
  derive that from the code.
- The i18n and routing guides gained the sections described above.
