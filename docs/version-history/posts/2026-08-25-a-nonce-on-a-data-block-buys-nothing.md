---
date: 2026-08-25
categories: [Changelog]
---

# A nonce on a data block buys nothing and costs the cache

Two inline scripts the framework itself emitted were the only thing keeping an otherwise
static page out of `PageCache`. Neither could use the nonce it was given.

<!-- more -->

## Changed

- **A `<script>` whose declared `type` is not JavaScript no longer gets a nonce.**
  `script-src` gates script *execution*, and a `<script type="application/ld+json">` is a
  data block the browser never runs — there was nothing for the policy to allow, so the
  nonce was inert. Embedding data in `application/json` is a well-known way to sidestep
  CSP for exactly this reason.

  Harmless until `PageCache::store()` began refusing any body carrying the request's
  nonce, because a nonce reused across visitors is not a nonce. From then on an inert
  nonce was the difference between a page that could be cached and one that could not.
  Filed with measurements: after a consuming application moved its own inline script into
  a file to comply, what was left on its catalogue pages was 248 bytes of JSON-LD and 96
  bytes of framework `<head>` script — both the framework's, neither removable by the
  application.

- **`importmap` and `speculationrules` keep their nonce, and the filing was wrong about
  them.** It listed both as non-executable alongside `application/ld+json`. They are not:
  an import map needs an inline allowance like any other script, and speculation rules are
  gated by `script-src` so specifically that CSP has a dedicated
  `'inline-speculation-rules'` keyword for them — other frameworks have open issues about
  precisely this.

  Following the list literally would have broken both under a nonce policy, silently,
  because nothing reports it until somebody first tries an import map. So the decision is
  an **allow-list of executable types**, not a deny-list of data ones: wrong in the
  allow-list direction costs an unnecessary nonce, wrong the other way costs a working
  page. Any type naming javascript or ecmascript in a spelling the list happens not to
  carry keeps its nonce too.

  Inline `<style>` is unchanged. `style-src` genuinely gates inline styles, so those
  nonces are doing work.

- **The `no-js` flip is allowed by hash instead of by nonce.** The 96 bytes in `<head>`
  that turn `class="no-js"` into `js` are a fixed string, so `script-src` now carries
  `'sha256-…'` for it and the tag goes out without a nonce. It is frequently the only
  inline script on a page, so nonced it was the whole of what stood between an otherwise
  static page and the cache.

  A hash rather than an external file, because the script has to run before the first
  paint: a blocking request in `<head>` to answer *does JavaScript exist* is the very
  thing the `no-js` class exists to answer without one. The hash is computed at runtime
  from the constant the script is emitted from, never written down — a hash and the bytes
  it covers must agree exactly, and a hardcoded one would go stale the moment somebody
  edited the script, as a blocked flip and a page permanently in its no-JavaScript
  styling.

  `unsafe-inline` still suppresses both the nonce and the hash: a browser ignores
  `unsafe-inline` as soon as either is present, so emitting one would quietly cancel what
  the application asked for.

  Together with the change above, **a scaffolded page with no inline script of its own is
  now cacheable out of the box** rather than after an audit of the framework's markup.

## Fixed

- The nonce injector is one implementation on `Document`, not two identical copies in
  `Html` and `Raw`. For a security-relevant regex that has to agree with itself, one copy
  was one too many — and this change had to be made in both.

## Documentation

- [Page Cache Guide](../../Pramnos_Page_Cache_Guide.md) gains **which of your inline
  scripts actually needs a nonce**, and *When a page is not being cached* now hands over
  the one-line diagnostic:

  ```bash
  curl -s https://example.test/the/page | grep -o 'nonce="[^"]*"[^>]*' | head
  ```

  Everything it lists is the application's own, which is what makes it actionable.
