---
date: 2026-08-25
categories: [Changelog]
---

# The policy forbade the worker the framework had just started shipping

`init --service-worker=y` wrote the file and the registration, and the framework's own
default CSP refused it. Reported from a freshly scaffolded project as *"I don't see it
registering the worker"* — which is precisely what it looked like.

<!-- more -->

## Fixed

- **`worker-src` is `'self'`, and reads the `csp` block.** It was `'none'`, hard-coded.
  That directive governs `Worker`, `SharedWorker` **and the service-worker script**, so
  the `register()` promise was rejected by the policy and nothing installed — a feature
  the scaffolder had just started writing, forbidden by the policy the same framework
  sends.

  `'self'` is the tightest value that works: a browser will not accept a cross-origin
  service-worker script anyway. It gives up very little over `'none'`, since the only
  extra thing it permits is a same-origin `new Worker(...)`, and reaching that needs a
  script already on the origin — at which point `script-src 'self'` has been defeated.

  It is consulted from configuration now, like the directives around it. **This is the
  second time a hard-coded value in that list has forbidden something an application
  could not then permit from `app.php`** — the first was `media-src`, which silently
  blocked any `<audio>` or `<video>` whose source was not same-origin, with a console
  message naming a directive the policy did not contain.

- **A refused registration is reported.** The registration snippet discarded the
  rejection, with a comment arguing that a browser which declines to register is simply
  a browser without the cache.

  That was wrong, and it is what turned a one-line misconfiguration into a debugging
  session: the CSP refusal was the only signal, and it had been thrown away by the
  handler written to keep the console tidy. It is a `console.warn` now — for whoever is
  building the site, rather than an unhandled rejection that reads as a broken page to
  everybody else.

## Documentation

- [Service Worker Guide](../../Pramnos_Service_Worker_Guide.md) gains **CSP:
  `worker-src` has to allow it**, plus the two other reasons registration silently does
  nothing and is not CSP's fault: `navigator.serviceWorker` is undefined outside a secure
  context, so `http://192.168.…` short-circuits the guard without logging anything at
  all; and a worker's scope does not extend above the directory it is served from.
