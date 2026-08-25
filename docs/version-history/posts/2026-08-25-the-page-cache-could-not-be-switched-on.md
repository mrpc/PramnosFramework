---
date: 2026-08-25
categories: [Changelog]
---

# The page cache could not be switched on, and a hit lost its CSP

Three findings from a consuming application trying to turn `PageCache` on for its
catalogue pages. The first stopped it working at all, the second was silent and
security-relevant, the third was a default that never matched the cookie it needed to.

<!-- more -->

## Fixed

- **`PageCacheMiddleware` reads `app.php` first.** It read
  `Settings::getSetting('pagecache')` alone, so the `pagecache` block the guide has
  always shown in `app.php` was never seen: the middleware built a `PageCache` on
  `defaults()`, `enabled` stayed `false`, and nothing was cached and nothing said why.
  The settings store is still consulted when `app.php` has no block — and the value it
  returns is now accepted, which it was not: `getSetting()` casts an array to
  `stdClass` on the way out, and the old `is_array()` test discarded it. Both
  documented locations were dead, for two unrelated reasons.

  The pairing — `applicationInfo` first, `Settings` second — is the one
  `Application::lazySessionEnabled()` already used for `'session' => 'lazy'`. The same
  shape of question had two different answers.

- **A cache hit carries a `Content-Security-Policy` again.** `sendCspHeader()` is
  called from `Application::render()`. A hit returns a `Response` before the
  application runs, so `render()` never executed and the page went out with **no policy
  at all**. It could not be replayed from the stored entry either, because the header
  never reached the `Response` — it goes straight out through `header()`.

  This is the worst shape a security regression has. The markup is right and the
  scripts run, and they run because there is no longer a policy to stop them; on a
  framework whose default is `default-src 'none'`, a cached page had lost all of it.
  `PageCacheMiddleware` now builds a fresh policy and attaches it to the hit.

- **A response whose body contains this response's CSP nonce is never stored.** The
  other half of the same problem, and the half a replayed header would have made worse
  rather than better: the framework stamps a per-response nonce into every inline
  `<script>` it writes, so a stored body freezes that nonce and hands the same one to
  every visitor for the whole TTL. A nonce that is reused is not a nonce.

  So a page with nonced inline script and a page cache are mutually exclusive, and
  `store()` now takes the side that fails loudly: the page is simply never cached. An
  application that wants it cached has a clear instruction instead of a mystery — serve
  it without a per-response nonce (a file, a hash-based policy, no inline script),
  which is work only it can decide to do.

- **The session cookie is in `bypassCookies` by default.** The default was
  `['#^(auth|remember|logged)#i']`, which never matched `PHPSESSID`, so an application
  whose signed-in state lives only in `$_SESSION` had nothing on the list to stop it —
  and a signed-in response that sets no cookie is not caught by the `Set-Cookie` rule
  either. Measured in the reporting application: every public page differed when signed
  in, by up to 1,707 bytes.

  It is `session_name()` rather than the literal, so a renamed session is covered. What
  makes it viable as a *default* is `'session' => 'lazy'`, shipped for this same cache:
  with it an anonymous reader carries no session cookie at all, so bypassing on one
  costs no hits. Without lazy sessions the framework set `PHPSESSID` on every response
  and `store()` already refused those — so this takes away no caching that was
  previously happening.

## Added

- **`Application::cspPolicy(): string`** — the policy `sendCspHeader()` sends, as a
  value, for the callers that need to put it somewhere other than `header()`. It also
  generates a nonce when there is not one yet: a path that never reached `exec()` was
  emitting the source expression `'nonce-'`.

## Documentation

- [Page Cache Guide](../../Pramnos_Page_Cache_Guide.md) — *Turning it on* now says
  three things rather than two, because the third was catching people out: the pipeline
  has to return a `Response`, and `Application::render()` returns a string. The symptom
  of getting it wrong is identical to a config block that was never read, which is how
  it stayed unnoticed. New sections cover the CSP interaction and the session cookie,
  the bypass table's rule 7 and the configuration reference are current, and
  *When a page is not being cached* gained the two new answers.
