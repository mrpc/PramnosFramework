---
date: 2026-08-11
categories:
  - Changelog
  - Added
tags:
  - security
  - http
---

# A human check that prices spam instead of pretending to detect it

The framework now ships a proof-of-work check for public, unauthenticated
writes. It is not a CAPTCHA, and the reason is the same reason this codebase
keeps finding bugs: a control that appears to work and does not is worse than
no control at all.

<!-- more -->

## Why not a CAPTCHA

Distorted text is solved by commodity OCR, and by any vision model, at rates
that make it decorative. An image grid needs a labelled dataset nobody has.
Either would be defeated for free by a script while every application that
adopted it believed itself protected — and the cost would be paid by real
users, disproportionately those using a screen reader.

Hosted options solve the detection problem by putting a third party's script on
the first page a visitor ever sees, and sending their traffic there. For an
application that wanted to answer "must we take a third party onto the signup
page?" with a permanent *no*, that is not an option either.

## Added

**`Pramnos\Security\HumanCheck`** — proof-of-work. The client is given a
challenge and must find a nonce whose SHA-256 begins with a required number of
zero bits. There is no shortcut: solving it *is* paying the cost.

```php
$check     = new HumanCheck(difficultyMs: 300);
$challenge = $check->challenge();           // hand to the page

// on submit
if (!$check->verify($submitted['challenge'], $submitted['solution'])) {
    // refuse
}
```

The properties are the opposite of a puzzle's. It is arithmetic rather than
perception, so there is nothing a model can recognise better than an honest
client can compute. Nothing leaves the server. It runs in a Web Worker while
the visitor is still typing, so by submit time it is normally already done. And
there is nothing to perceive, so it is accessible by construction.

**What it does not do, stated in the class docblock as well as here:** it does
not stop spam, it prices it. An attacker with a botnet and free CPU still gets
through. What changes is that a thousand signups cost real compute instead of
nothing. That is the right defence against volume and no defence at all against
a targeted attack — code reading `human_check: true` must not conclude more
than "this submission cost something", and in particular must not conclude that
a human was involved.

**It costs the visitor battery.** Difficulty is therefore expressed in
milliseconds of work on a mid-range phone rather than as a leading-zero count
nobody can reason about, defaults to a modest 300ms, and is set per call site —
a signup form and a login form do not deserve the same cost. The assumed hash
rate is deliberately pessimistic: guessing high would make "300ms" mean several
seconds on the slowest devices, which are the ones least able to spare it.

**The challenge stores nothing.** It is HMAC-signed and carries its own
difficulty and expiry, so handing one out costs a hash and cannot be used to
fill a cache. Editing the difficulty invalidates the signature — without that,
a client simply asks for zero work.

**It is single-use, atomically.** A solved challenge replayed a thousand times
is the obvious bypass, and without this the check costs an attacker one unit of
work in total. The claim goes through `Cache::increment()`, so first-use-wins is
one indivisible operation rather than a read-then-write that two simultaneous
replays would both pass. On adapters that cannot count atomically the fallback
closes the replay window but not the race — an installation using this to do
security work belongs on Redis or Memcached, and the docblock says so.

**`scaffolding/assets/js/pf-humancheck.js`** — the client, dependency-free and
served from the application's own origin. A proof-of-work widget that loads from
a CDN has given back the property it existed to provide. The worker is built
from a blob of the file's own source, so there is no second file to keep in
step, and it reports progress every 20,000 hashes for the slow devices where
this takes a visible moment.

## Not built, deliberately

A signed single-use form token and a submission-timing floor were considered
alongside. Both are nearly free and both are honest only if labelled as
*filters* rather than controls — each is trivially bypassed by anyone who looks.
The form token shares its entire implementation with the challenge above, so an
application wanting one can mint a `HumanCheck` challenge at difficulty zero and
verify it on submit. A honeypot field is the weakest of the three and is worth a
line of markup, never a line in a security report.
