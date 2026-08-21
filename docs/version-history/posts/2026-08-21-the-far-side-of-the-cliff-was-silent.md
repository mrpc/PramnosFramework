---
date: 2026-08-21
categories: [Changelog]
---

# The far side of the cliff was silent

The descriptor ceiling got a warning at 90%. What happened *past* it was handled as though nothing
had happened: `$changed === false` and `$changed === 0` shared one branch, so a node that could no
longer watch anybody looked exactly like an idle one.

<!-- more -->

## Fixed

`false` and `0` are now separate branches. On `false` a counter increments, the first failure is
logged immediately, and repeats are throttled to one line every 30 seconds carrying the count of
what the throttle swallowed. `select_failures` is in the metrics endpoint.

Three things conspired to make the far side invisible, and all three were ours:

- the shared branch,
- `@stream_select(...)`, which suppresses PHP's only boundary diagnostic,
- the 90% warning being logged **once per process** — so a node that crossed an hour ago had no
  live signal at all.

A loop turning over every 100 ms and serving nobody, indistinguishable from a quiet one.

Immediate-then-throttled rather than every time, which is a small deviation from the report: the
first line is what makes the crossing observable at the moment it happens, and ten lines a second
would bury the signal as effectively as silence. The count means nothing is lost.

**It does not retire the daemon**, deliberately. Retiring would help — a fresh process gets low
descriptor numbers again — but it drops every connection to do it, and whether that beats serving
nobody is a deployment's call. The counter is there so a health check can make it.

Also caught while writing the test: `stream_select()` does not only return `false`. Given a set PHP
considers unusable it throws `ValueError: No stream arrays were passed`, and `@` suppresses warnings
rather than exceptions — an unhandled fatal in the one loop the whole server is. Now treated as
what it is, a select that could not watch anybody.

## Corrected, for the second time, and now measured

This docblock has said three things about how `stream_select()` fails past `FD_SETSIZE`. It said
`false` first, was "corrected" to a per-descriptor skip on the strength of PHP's strings, and is now
back to `false` on the strength of a measurement. Both corrections came from the same project, which
ran the experiment it had offered:

> In a container with `nofile=4096` and 1120 descriptors open, a set of one low fd plus one high fd
> returns `false` with *"It is set to 1024, but you have descriptors numbered at least as high as
> 1118"*; the same set with only the low descriptor returns `1` and no warning.

**The control case is the informative half.** 1120 descriptors were open while the call answered
normally — so the trigger is the descriptor *numbers in the set*, not what the process holds.
Counting open descriptors remains the right proxy, but for a mechanism rather than the reason
originally given: a new socket takes the lowest free number, so every other open file pushes the
watched sockets' numbers up. Gaps in the table are exactly what keep it a proxy, which is why the
label was right and now has something behind it.

And on failure **PHP leaves the arrays untouched** — `false` came back with both streams still
listed. `LocalBroadcastServer` short-circuits, but any application borrowing
`isNearDescriptorCeiling()` for its own loop needs to know, so the guide says it where that audience
reads.

## Note on how this arrived

The strings were right and the guess was wrong, and the project that guessed wrong is the one that
proved it. Worth recording because the sequence — infer from strings, correct the inference, then
measure and correct the correction — is what the docblock now shows rather than hides.
