---
date: 2026-08-26
categories: [Changelog]
---

# A password you can set from a shell

`user:create` existed; nothing changed a password. Asked for, and it turns out to be four
writes rather than one.

<!-- more -->

## Added

- **`user:password <user>`** — sets a password with no email round trip. The argument is a
  username, an email address or a numeric user id, tried in that order; `--by` restricts
  it, which settles the only real ambiguity of a numeric username.

  ```bash
  php bin/pramnos user:password alice                 # prompts, hidden, twice
  php bin/pramnos user:password alice --generate       # prints one you can hand over
  php bin/pramnos user:password 42 --by=userid --password='…'
  ```

  **It is four writes, and three of them are the ones a manual reset forgets.** The hash
  goes through the `User` model, so it is salted with `md5(securitySalt . userid)` — a raw
  `password_hash()` would store one login could never match, and the account would simply
  stop working with a correct-looking row in the database. Then:

  - **pending reset tokens are cleared**, or a link mailed out ten minutes ago still works
    and the account has two valid passwords, one of them held by whoever received the mail;
  - **a brute-force lockout is lifted**, because a locked-out account refuses the *correct*
    password with the same message as a wrong one — indistinguishable from "the reset did
    not work", and the first thing reported back;
  - **the change is recorded in the activity log**, since a credential set from a shell
    leaves no other trace, which is the whole argument for having one.

  **Sessions are left signed in unless `--revoke-sessions` is passed.** The ordinary reason
  to run this is that somebody cannot get in; signing them out of every other device turns
  one problem into several. The flag is for the other reason — a suspected compromise —
  and the output names it, so the choice is visible rather than assumed.

  The policy is the one the self-service form applies: eight characters, a digit, a symbol.
  `--generate` produces one that passes and prints it. `--force` accepts one that would be
  refused and says so in the scrollback.

## A test that found a real defect

`--force` recorded `policy_waived: true` from the *presence of the flag* rather than from
whether anything was waived, so forcing a strong password left a false record of a security
decision in the audit log. The test asserting that `--force` is quiet when nothing was
waived is what caught it; the flag and the waiver are now computed once and separately.

## Documentation

- [Console Commands](../../Pramnos_Console_Guide.md) gains a **`user:password`** section:
  the four writes and why each is there, why sessions survive by default, and what
  `--force` does and does not record.
