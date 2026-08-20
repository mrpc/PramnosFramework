---
date: 2026-08-21
categories: [Changelog]
---

# A broadcast assertion that could not fail

`NullDriver` discards silently. `LogDriver` writes a file a test then has to parse. So a test
asserting "this action broadcasts" either published to a real Redis, or asserted nothing and
passed regardless — and the second kind keeps passing after the broadcast is deleted.

<!-- more -->

## Added

`Broadcasting\Testing\FakeDriver` records instead of publishing.

```php
$fake = FakeDriver::swap();          // becomes the process-default manager

$order->markPaid();

$fake->assertBroadcast('private-order.42', 'order.paid');
$fake->assertBroadcastCount(1);
```

`swap()` installs it as the default, so code that resolves the manager itself is captured
without threading a driver through it. `restore()` puts back exactly what was there: a test
leaving a fake installed would silently swallow every later test's broadcasts, and the failure
would surface in an unrelated file.

Assertions: `assertBroadcast`, `assertNotBroadcast`, `assertBroadcastCount`,
`assertNothingBroadcast`, `assertBroadcastExcept`. The last one earns its place — the `toOthers()`
exclusion is easy to lose, whether to a driver that does not implement it or a socket id that
never left the request, and its only production symptom is one user seeing a duplicate of their
own action.

Two details that decide whether the helper is usable:

**Failures list what *was* broadcast.** An assertion that only says "expected order.paid on
private-order.42" leaves the reader unable to tell a missing broadcast from one on a channel
whose name is built slightly differently, which is the usual cause.

**They report through PHPUnit's `Assert` when it is loaded**, so a mismatch is a *failure* with
a diff rather than an exception. An exception is reported as an error, which reads as "the test
is broken" instead of "the code is wrong" and sends whoever sees it to the wrong file.

Also adds `BroadcastingManager::currentInstance()`: the manager a process has, without creating
one. `instance()` is a factory that builds a Redis-backed manager, so a caller asking what to
restore later would have opened a Redis connection as a side effect of the question — the same
trap `Application::currentInstance()` was added for.

## Documentation

`Pramnos_Testing_Guide.md` gains **Asserting that something was broadcast** with a `use_cases:`
entry; `Pramnos_Realtime_Guide.md` cross-links to it.
