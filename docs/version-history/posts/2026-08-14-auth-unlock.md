---
date: 2026-08-14
categories:
  - Changelog
  - Added
tags:
  - console
  - authentication
---

# `auth:unlock` — lifting a lockout you gave yourself

The progressive login lockout is doing its job when it locks somebody out: three
wrong passwords cost a minute, ten cost an hour. That is right for the internet
and unhelpful for the developer who has just mistyped a fixture password and
cannot test the login flow they are working on.

<!-- more -->

```bash
php pramnos auth:unlock admin              # this identifier, every scope
php pramnos auth:unlock 2 --scope=user     # by user id
php pramnos auth:unlock 10.0.0.5 --scope=ip
php pramnos auth:unlock --list             # who is locked, and for how long
php pramnos auth:unlock --all              # everything (development only)
```

A failed login writes to more than one scope — `identifier` (what the form was
given), `user` (the account it resolved to) and `ip` — so clearing "the lockout"
means clearing all three unless told otherwise.

It reports what it found before clearing it: "nothing was locked" and "a lockout
was lifted" are different answers, and somebody running this wants to know which
one they got.

## What it is not

It clears the counter that says how many times somebody has failed, and nothing
else. A wrong password is still a wrong password afterwards.

`--all` refuses to run outside development, and says why: "clear every lockout on
this server" is precisely what somebody working through a password list would
want, and a command that offers it on a live installation is a hole with a
friendly name.
