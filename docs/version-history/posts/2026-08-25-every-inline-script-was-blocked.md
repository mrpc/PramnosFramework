---
date: 2026-08-25
categories: [Changelog]
---

# Every inline script was blocked and the report said "the button does not work"

Two findings about the CSP nonce, from two different applications. One was a real
breakage with an invisible symptom; the other was a plausible claim that turns out not
to be true, and worth recording for that reason.

<!-- more -->

## Fixed

- **`Application::render()` generates the CSP nonce when `exec()` did not.** `exec()`
  was the only place it was created, so any render that did not go through it produced a
  page whose inline scripts had no nonce, under a policy that then refused to run them.

  An application overriding `exec()` is the ordinary way to end up there, and one did:
  `$cspNonce` stayed `''` for the life of every request and every inline script on every
  server-rendered page was blocked. It was reported as *"the night-mode button does not
  work"*, twice — a blocked inline script is present and correct in the response, on the
  right storage key, and nothing in a test suite can watch a browser decline to run it.

  Deliberately not done on the page-cache hit path: there is no document to stamp,
  `store()` refuses to keep a body containing a nonce, and the policy omits the nonce
  source when there is none.

- **The `no-js` flip script was reported as missing its nonce. It is not** — filed as
  FW-016 from a consuming application, and worth recording because the claim was
  plausible: the script is written inline into the `<head>` markup rather than registered
  through `addScript()`. `Html::render()` post-processes the finished document and
  injects the nonce by tag, not by registration, so that script has always had one.
  Verified against a rendered document, and now guarded by a test — the symptom the
  filing described (*every page stuck in its no-JavaScript styling*) is real, but its
  cause is the missing nonce above.

## Documentation

- Both are documented where the mechanism lives: `Application::ensureCspNonce()` carries
  the incident and the reason it runs where it does, and
  `CspNonceReachesInlineScriptsTest` carries the FW-016 answer as an assertion rather
  than as prose somebody has to find.
