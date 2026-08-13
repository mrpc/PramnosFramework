---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
  - Added
tags:
  - testing
  - debug
  - documentation
---

# A shared document, seven eight-second tests, and a `?` in the bar

Three things that had been noticed and worked around rather than fixed.

<!-- more -->

## The document was shared between every test

Three failures in one working session had the same cause, and each time the fix *looked*
like a bug in the test that failed: an assertion about the debug toolbar passed on its own
and failed in a full run.

`Document` is a per-type singleton, and it is **mutable** — framework code and tests both
write to `->type` and `->themeObject`. Its instances lived in a `static` local inside
`getInstance()`, so one test's document answered for every test after it. A test that set
`->type = 'json'` on what it thought was its own document was writing to the shared HTML
one, and the toolbar then declined to inject into a page that was HTML all along.

The instance cache is now a property, `Document::reset()` clears it, and a PHPUnit
extension (`DocumentIsolation`) calls that before every test — the same shape as
`RequestIdentityIsolation`, and for the same reason: the state is reached indirectly, so
any list of "tests that need to reset it" goes out of date silently.

`reset()` is not test-only code. A worker serving more than one request in a single PHP
lifetime has exactly this problem, and a document carries a theme, a type and accumulated
output.

## Seven tests waited eight seconds each — and the obvious fix did nothing

The slowest tests in the whole suite each took **8.00 s to the hundredth**. A round 8.00 is
not work, it is a timeout: those tests point a `PDO` at a hostname that is *supposed* not to
resolve, because what they assert is which DSN was built.

A connect timeout looked like the answer and changed nothing. Measured directly:

```
php -r 'gethostbyname("testhost");'   →  8.00s
```

The block is in **`getaddrinfo()`** — DNS, before a socket exists — so no socket option can
reach it. Worth writing down, because the wrong fix was very plausible.

What worked also made the tests better:

- the three tests that asserted *which DSN was built* now assert on the DSN. `buildDsn()`
  and `resolvedHost()` were extracted for them, so a string built from configuration is
  checked as a string rather than inferred from a connection error;
- the tests that assert a *failure* point at `127.0.0.1:9` — an IP literal skips the
  resolver, and the discard port refuses immediately.

| | Before | After |
| --- | --- | --- |
| `BaseTestCaseTest` | 32.0 s | **0.28 s** |
| `TestEnvironmentTest` | 28.3 s | **4.4 s** |

**56 s off the suite**, against an estimate of 49. The connect timeouts stayed in as well,
documented for what they actually cover: a host that accepts a connection and then hangs.

## The toolbar can now explain itself

Everything written about the toolbar so far documents how it works. There was no page that
answered "the request came back wrong — where do I look", and no way to find one from the
place where somebody is standing when they need it.

[Using the debug toolbar](../../Pramnos_Debug_Toolbar_Usage.md) is organised by symptom:
the request came back wrong, it is slow, it worked and then stopped, the deep link 404s,
something broke in the browser, I want to try an endpoint. Each answers with the tab and
the number to read.

The bar carries a **`?`** that opens it in a new tab — losing the page you are debugging in
order to read about the tool would be its own joke. It points at the published site rather
than anything local, because the toolbar ships inside `vendor/` where a relative path means
nothing.

## Documentation

- [Using the debug toolbar](../../Pramnos_Debug_Toolbar_Usage.md) — the new page.
- [Test suite performance](../../Pramnos_Test_Suite_Performance.md) — the measurement, now
  with the DNS correction and the achieved numbers.
