---
date: 2026-08-24
categories: [Changelog]
---

# Two bodies, one URL, and no `Vary`

The page cache shipped this morning. A consuming application read it and filed
three things about one method, all correct, within hours.

<!-- more -->

## Fixed — `Vary: Accept-Encoding` was never sent

With `gzip` on, `responseFrom()` chose between the stored plain body and the
stored compressed one by reading `Accept-Encoding` off the request. **Two
different bodies under one URL, and nothing telling anyone.**

Every shared cache in front of the application — a CDN, a corporate proxy, a
reverse proxy — assumes one response per URL unless a `Vary` says otherwise. So
it could store the compressed variant and hand it to a client that never asked
for compression, which receives binary rubbish. Or the reverse. It is the classic
*"the page is broken, but only for some users"* report, and it does not reproduce
on a developer's machine, because a developer's machine has no proxy in front of
it.

`Vary: Accept-Encoding` is now sent on **both** branches. Only tagging the
compressed response would fix half of it: a shared cache that happened to see the
plain copy first has the identical problem in reverse, and it is the same URL
either way.

An application's own `Vary` is merged rather than overwritten — dropping a page's
`Accept-Language` to add ours would break the caching of exactly the pages that
were careful about it — and with `gzip` off nothing is added at all, since there
is then one body per URL and a needless `Vary` costs hit rate in every cache
downstream.

**Why the whitelist did not cover it.** `vary` is on `headerWhitelist`, so a
`Vary` the *application* sends is preserved. But compression is the cache's
decision, not the application's, so the application has no reason to know it must
declare anything.

**Why the tests did not catch it, which is the part worth keeping.** There were
two, one per branch, and each asserted `Content-Encoding` on the response it
produced. Both passed. A test that checks the header it *expects* cannot fail on
the header nobody thought of — and 105 green tests read as thorough coverage of
exactly this method. The replacement asserts `Vary` across four
`Accept-Encoding` shapes including the absent one.

## Fixed — the 304 carried no `ETag`

`Response::make('', 304)` and then the debug headers. RFC 7232 §4.1 says a 304
includes the validator; without it a client cannot tell which of its stored
copies has just been confirmed, and some re-download on the next cycle — losing
the round trip the 304 exists to save.

## Fixed — `If-None-Match` was compared, not parsed

`trim($header) === $etag`. That misses three shapes clients actually send:

| sent | before | now |
| --- | --- | --- |
| `"abc"` | 304 | 304 |
| `W/"abc"` | 200 | 304 |
| `"other", "abc"` | 200 | 304 |
| `*` | 200 | 304 |

None of these was a correctness bug — the answer fell back to a full 200 — which
is why it would have gone unnoticed indefinitely while quietly throwing away the
saving the ETag was added for. A weak validator is the same entity for this
purpose: the cache serves whole stored bytes, so there is no strong comparison to
fail.

## The report itself

Worth recording how it was written, because it made the fix cheap: it quoted the
code, named the method, stated what it checked (*"the string `Vary` does not
appear anywhere in `PageCache.php`, and `Response::send()` does not add it"*),
explained why `headerWhitelist` looked like a defence and was not, identified the
two shipped tests that covered both branches and still missed it, and proposed
the fix including the half-fix to avoid. It also said plainly that it was not
blocking, and gave the workaround — `'gzip' => false`, since Apache's
`mod_deflate` already compresses and emits the correct `Vary`.

That last point is now in the guide as a recommendation rather than a workaround:
if the web server already compresses, let it, and avoid storing two copies of
every page.
