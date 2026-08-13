---
date: 2026-08-14
categories:
  - Changelog
  - Changed
  - Fixed
tags:
  - debug
  - architecture
---

# The toolbar injects through the response, not an output buffer

Two reports of the same failure — `200` with an empty body — and the second one
measured it off the socket: 523 header bytes, zero body bytes. Both fixes before this
one were guards around a design decision. This removes the decision.

<!-- more -->

## What was wrong with the buffer

The debug provider installed a process-wide `ob_start()`. That caught output from any
code path, including an application that simply `echo`es — which is why it existed.
The price was structural: booting the toolbar **added an output-buffer level**, so
code that cleared "its" buffer cleared the framework's, with the response inside it.

Measured on a real server, with the provider booted and a page echoed into the
buffer:

| What the request does after the echo | Body delivered |
| --- | --- |
| nothing | 281,650 bytes ✅ |
| `while (ob_get_level()) { ob_end_clean(); }` | **0 bytes** ❌ |
| `ob_get_clean()` — one level, no matching `ob_start()` | **0 bytes** ❌ |

Both idioms are ordinary in a kernel that drops stray output before responding. With
`APP_DEBUG=0` there is no second level and the same code works perfectly, which is
what made the toolbar look like the *cause* rather than the casualty.

## What it does now

`DebugBar::injectInto()` is the one place injection happens, reached from
`Application::render()` and from `DebugBarMiddleware`. So:

- an application needs **no middleware pipeline** to get a toolbar — every MVC
  application ends its request with `echo $app->render()`;
- it cannot get **two**, because injection is idempotent per request;
- and there is no framework-owned output buffer for anyone to destroy. The failure
  mode is gone rather than guarded.

This is what laravel-debugbar and Symfony's WebProfiler do: inject through the
response object, install no global buffer.

## What it costs, stated plainly

A response the framework never sees gets no toolbar — a raw `echo`, or a kernel that
ends an unmatched request by `require`-ing a page file. The page is delivered exactly
as written, which is the point. The
[Upgrade Guide](../../Pramnos_Upgrade_Guide.md) has the three-line change that gives
such a response a toolbar again, and the property it restores: **your** buffer is one
you opened and can safely clear.

While migrating, a page with no toolbar and a complete body is the expected
intermediate state.

## Removed with it

The two guards the buffer needed — the try/catch around the buffer callback, and the
shutdown re-send of a discarded page — are gone, along with the buffer. What stayed
is the rule they were protecting: anything thrown while injecting returns the body
untouched, and a result shorter than what arrived is discarded.

The provider is now what a provider should be: it registers collectors, names the
request, and captures PHP diagnostics. It installs nothing that outlives it.

## Documentation

- [Upgrade Guide](../../Pramnos_Upgrade_Guide.md) — "The debug toolbar no longer uses
  an output buffer", with the migration.
- [Debugging Guide](../../Pramnos_Debugging_Guide.md) — "How the toolbar reaches the
  page".
