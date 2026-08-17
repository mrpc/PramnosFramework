---
date: 2026-08-17
categories: [Changelog]
---

# A guard for a null that could not happen

`Application::getInstance()` is a factory. Given no instance for the key it reads `app.php`,
defines constants and runs the whole constructor — database, language, session.
`currentInstance()` is the lookup, returning null instead.

The rule was already written down twice: in the Framework Guide, and in `currentInstance()`'s
own docblock together with the incident behind it — `Session::getFingerprint()` began asking
for the trusted-proxy list, and a reference application's login tests started failing on
valid tokens because a second application was being constructed underneath them.

Nine call sites were using the factory anyway, including the two places where it does the
most damage: the identity lookup, and the database layer's own error reporting.

<!-- more -->

## Fixed

The nine, now looking up rather than building:

| File | What it wanted |
| --- | --- |
| `User/User.php` — `getCurrentUser()` | **to answer who is signed in** |
| `User/User.php` — `getUser()` | a namespace, to pick a class name |
| `User/User.php` — `legacyMd5Allowed()` | one boolean setting |
| `Database/Database.php` — `displayError()` | **somewhere to report a database error** |
| `Http/Middleware/ApiAuthMiddleware.php` | to record the authenticated user |
| `Http/Middleware/UnifiedAuthMiddleware.php` | the same |
| `Auth/Drivers/DatabaseAuthDriver.php` | two boolean settings |
| `Auth/Controllers/ApiAccount.php` (×2) | to record a login, and clear it on logout |

The two in bold are the ones worth naming individually.

**`User::getCurrentUser()`** is the worst placement in the framework: asking *who is signed
in* constructed an entire application — database, language, session — which is precisely the
shape of the incident quoted in `currentInstance()`'s own docblock.

**`Database::displayError()`** was self-defeating. Building an application builds `Settings`,
which queries the database — the connection that just failed. It also made the method's
`else` branch unreachable, so the `error_log()` fallback written for "no application" could
never run and a database error outside a request went nowhere at all. Same class of cycle
`ConnectionPathPurityTest` guards on the connect path, one step further along: not while
opening the connection, but while complaining about it.

**Every one of them was already written as `if ($app)`** — a guard for a null the factory
cannot return. So the guard was dead and the construction was live, and the source had been
saying so the whole time. Nothing here is a behaviour change in a real request, where the
application exists and the two calls give the identical answer; what goes away is an
application, a database connection and a session being built as a side effect of writing one
property, in the middle of an authentication decision.

The first of these was found and fixed in `SessionExchange` earlier the same day. This is the
rest of the pattern, from reading for it rather than waiting for it.

## Added

Two tests, because they catch different things and only one of them is durable.

`NoApplicationIsConstructedTest` is behavioural: with the application registry emptied, each
path does its work and `currentInstance()` is still null afterwards. It also asserts the
converse — that an application which *does* exist is still found and used — because otherwise
deleting the reads entirely would pass.

`ApplicationFactoryPurityTest` is structural, and it is the one that matters: no file under
`src/Pramnos/Auth/`, `src/Pramnos/Http/Middleware/`, `src/Pramnos/User/` or
`src/Pramnos/Database/` may call `Application::getInstance()`. The behavioural test proves the
known sites; this is what stops the next one, which is the real failure mode, because
`getInstance()` is the name one remembers. Same technique as `ConnectionPathPurityTest`, which
guards the connection path — this is the wider rule that path is one instance of.

Three details it inherits from mistakes already made in this repository:

- it asserts that it **scanned a non-trivial number of files**, and names two of them. A
  wrong `dirname()` depth produces an empty scan, and an empty scan satisfies "nothing calls
  the factory" perfectly. A structural guard here once did exactly that and passed.
- it strips comments with `token_get_all()` before matching, so a file may *explain* the rule
  without violating it — this post's own subject matter would otherwise be unwritable in a
  docblock.
- exemptions are **enumerated and currently empty**. A file cannot become exempt by being
  added; somebody has to write its name and the reason, which is the conversation the
  exemption is for.

## Worth knowing if you convert a call site yourself

`currentInstance()` declares `?Application`; `getInstance()` declares no return type and
returned whatever the registry held. Three integration tests installed a plain `stdClass` as
a fake application, which had always worked, and started failing with a `TypeError` the moment
the authentication code moved to the lookup — eight tests across two drivers.

That is the correct type being enforced rather than a regression: the registry is meant to
hold applications. But it is the first thing a conversion surfaces, and the fix is a real
subclass with an empty constructor rather than a loosened signature.

## Not changed

`Api::deriveAuthenticationKey()` still derives `md5('edge')` when `sURL` is undefined — a
constant every installation in that state would share. Confirmed by the framework's author as
a non-issue: `sURL` is required for the system to function at all, so no such setup exists.
`SessionExchange` refuses to mint under it regardless, since refusing costs nothing.

Twenty-seven `Application::getInstance()` calls remain outside the guarded directories, and
most are legitimate: console commands, the scaffolder's generated templates, and the document
types, all of which run where wanting an application is the point.

Not audited one by one. The guard covers the directories where the factory is a hazard rather
than a choice; anywhere else, a call that builds an application is doing what its caller
asked.
