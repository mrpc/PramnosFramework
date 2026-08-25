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

- **`PageCache::serveEarly()` reads `app.php`, and its hit carries a policy.** The
  config was a required argument, so the `pagecache` block had to be copied by hand
  into `www/index.php` beside the one in `app.php` — two declarations of the same
  rules, and the early path is the one that answers first. Change `bypassCookies` in
  `app.php`, forget the copy, and the early serve keeps handing out a signed-in page
  from a rule set that exists nowhere else: the hole above, reopened by a stale copy.

  Reading the file also gets the `csp` block, which is what lets this path send a
  policy at all — it has no `Application` to ask, which is the entire point of it. A
  `require` of an array literal is not the bootstrap `serveEarly()` exists to skip;
  what it skips is `Application::init()` and its database, session, language and
  theme. `serveEarly($config)` still works and still wins.

  When there is no `app.php` to read, no policy is sent rather than a guessed one: the
  framework default is `default-src 'none'`, and sending that to an application whose
  `csp` block adds hosts would break the page it was protecting.

- **A policy with no nonce omits the nonce source** instead of emitting `'nonce-'`.
  Browsers reject an empty nonce as an invalid source and drop it, which happens to be
  the safe direction — but it cost a consuming application a working night-mode button
  and two rounds of debugging, because a blocked inline script is *present and correct*
  in the response. Its own `exec()` override had stopped generating the nonce; the
  policy it produced said nothing about that.

  Omitting is right rather than a workaround: `Document\DocumentTypes\Html` and `Raw`
  stamp a nonce into inline `<script>` only when there is one, so a response with no
  nonce has no nonced element for the source to match. This is the ordinary case on a
  cache hit.

## Added

- **`Application::cspPolicy(): string`** and **`Application::buildCspPolicy(array $csp,
  string $nonce = ''): string`** — the policy `sendCspHeader()` sends, as a value, for
  the callers that need to put it somewhere other than `header()`. The static one takes
  the `csp` block directly, because the caller that needs it most —
  `PageCache::serveEarly()` — has no instance by design.

- **`Application::readApplicationConfig(?string $app): ?array`** — `app.php` as an
  array, with nothing constructed: no defines, no database, no session. `null` rather
  than `[]` when there is no file, because "there is no configuration to read" and
  "the configuration says nothing" lead to different decisions.

## Documentation

- [Page Cache Guide](../../Pramnos_Page_Cache_Guide.md) — *Turning it on* now says
  three things rather than two, because the third was catching people out: the pipeline
  has to return a `Response`, and `Application::render()` returns a string. The symptom
  of getting it wrong is identical to a config block that was never read, which is how
  it stayed unnoticed. New sections cover the CSP interaction and the session cookie,
  the bypass table's rule 7 and the configuration reference are current, and
  *When a page is not being cached* gained the two new answers.
  *Serving before the application boots* now shows the no-argument call and says what
  reading the file buys. The one path still without a policy is named as such: with
  `'writer' => 'static'` a rewrite rule serves the file and PHP never runs, so the
  header has to come from the web server — which is all a static policy needs, the
  nonce half being provably absent from anything stored.
