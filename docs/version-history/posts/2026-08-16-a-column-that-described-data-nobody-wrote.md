---
date: 2026-08-16
categories:
  - Changelog
  - Fixed
tags:
  - auth
  - sessions
---

# A column that described data nobody wrote

`usertokens.deviceinfo` is declared as *"JSON-encoded device/client information
(browser, OS, IP at token creation)"*. `Token` has decoded it for years. `addToken()`
wrote `''`.

<!-- more -->

## The evidence all pointed one way

The column exists. Its comment says what it holds. `Token::load()` handles both a
serialised value and JSON for it. `Token` exposes `deviceinfo` as an array. Everything
about the code says this is a populated field.

```php
'deviceinfo'  => '',
```

That is every session, every API token, every OAuth exchange, since the table was
created.

The visible cost is the active-sessions list. It exists so somebody can look at their
sessions and recognise which is which — and it had nothing to recognise them by. A
column that is empty everywhere reads as "this installation has no device data", not as
"nothing ever wrote any".

## What goes in it now

```json
{"device": "chrome|windows", "label": "Chrome on Windows", "ip": "203.0.113.9"}
```

Three keys, and the choice of three is the point.

**`device`** is the coarse [`SignInFingerprint`](2026-08-16-an-alarm-that-stays-rare.md),
not the raw user agent. Storing the agent would make every session look like a different
device after any browser update — a list meant for recognition, rendered as a list of
strangers. It is also the longest thing that could go in this column, on a row written
at every login.

**`label`** is the same thing in words, because `chrome|windows` is not what a person
scanning their own sessions needs to read.

**`ip`** is recorded because an administrator investigating an incident needs it. It is
**not** used to decide anything, for the reason given at length in the sign-in alerts:
consumer addresses are dynamic, and comparing them is how a security signal becomes
noise.

## One format, two writers

Worth confirming rather than assuming, because `Token::load()` reads two:

```php
if (Helpers::checkUnserialize($this->deviceinfo)) {
    $this->deviceinfo = unserialize($this->deviceinfo);        // legacy rows
} elseif ($this->deviceinfo && json_decode($this->deviceinfo) !== null) {
    $this->deviceinfo = json_decode($this->deviceinfo, true);  // everything written today
}
```

That reads like a format split. It is not. `Token::save()` writes
`json_encode($this->deviceinfo)` unconditionally, whatever it is handed — array or
object — and `addToken()` now does the same. The `unserialize()` branch is a reader for
rows an older path left behind, and nothing produces that shape any more.

So an application that sets `deviceinfo` itself later in the request — one does, with
`Helpers::getBrowser()`, which returns an object — round-trips through the same encoder
and comes back through the same branch. The framework fills the column at creation; the
application enriches it afterwards; neither has to know about the other.

## Two failure modes closed on the way

`json_encode()` returning `false` would put the literal `false` into a TEXT column that
`Token::load()` then tries to decode — a token that cannot be read back is worse than
one with no device information. And issuing a token must not fail because the request
could not be described: every caller is inside a login or an OAuth exchange, so the
whole thing is wrapped and degrades to an empty string, which is exactly what it wrote
before.

No signature changed. `addToken()` computes this itself, so every existing caller gets
it without knowing.

## The third one this week

This is the same shape as two other findings in the last few days, and worth naming as a
class: **a control described more strongly than it was built.**

- A guide section naming three methods that never existed.
- `class="no-js"` implying a mechanism that had been deleted.
- A column comment describing data nothing ever wrote.

None of the three was found by reading the code — reading it is what makes them
convincing. All three were found by running something and looking at the output.

## Fixed

- `User::addToken()` populates `usertokens.deviceinfo` with the device fingerprint, a
  readable label, and the client IP, for every token type.

## Documentation

- [Authentication guide](../../Pramnos_Authentication_Guide.md) — *What a session record
  contains*.
