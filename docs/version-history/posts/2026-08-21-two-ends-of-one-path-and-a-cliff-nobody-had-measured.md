---
date: 2026-08-21
categories: [Changelog]
---

# Two ends of one path, and a cliff nobody had measured

Three fixes from one round of consumer feedback, and the most useful of them is an answer to a
question rather than a bug report.

<!-- more -->

## Fixed — the stop path could disagree with itself, undetectably

`DaemonOrchestrator` writes the `.stop` sentinel beside the `lockFile` declared in a worker's
desired-process entry. The worker found its own path through `CommandBase::getJobLockFilePath()`,
which has its own default (`ROOT/var/<job>`) and is overridable. Two independent computations of
one path — and **nothing could notice when they diverged.** A sentinel read where nothing writes is
indistinguishable from no sentinel: no error, no log, a worker reporting itself healthy while
ignoring every stop request.

The orchestrator now exports the resolved path as `PRAMNOS_JOB_LOCK_FILE` when it spawns a worker,
and `CommandBase` reads it in preference to anything the command computes. **An override that
disagrees is impossible rather than imperceptible.** `getJobLockFilePath()` stays overridable — it
is a legitimate application default and still applies when a command runs by hand — but the part
that prefers the supervisor's answer is `final`.

Reported by a project whose loop workers overrode the method to match their supervisor and whose
realtime worker did not, so **adopting the WebSocket stop seam landed as a no-op**: the seam was
wired, the callable was right, and it read `var/realtime.stop` while the orchestrator wrote
`logs/realtime-<id>.lock.stop`. The fix for a silent failure reproduced it, in the project that had
just filed it, with the whole thing fresh in mind. That is the argument for making the divergence
impossible rather than detectable — they proposed both and were right that the first is the shape
worth having.

## Fixed — publishing to an encrypted channel with no key sent it in the clear

Asked as a question rather than filed as a finding, and the answer is that it was the wrong
decision.

Authorizing a `private-encrypted-` channel without a key already threw, and a wrong-length key was
refused at construction — both on the reasoning that a realtime feature failing on its first real
event fails in front of users. **Publishing** to one, with no key, sent the payload in the clear
under a channel name that promises otherwise. It was documented and pinned by a test, so it was a
decision; it was not a consistent one.

Publishing now throws. A visible exception costs a request; silent plaintext on a channel whose
whole purpose is that the relay cannot read it costs the thing the feature exists to protect. There
is no legitimate case for the old behaviour either — `pusher-js` decrypts these channels natively,
so a plaintext payload on one does not merely leak, it also does not arrive.

**This is a behaviour change for anyone currently doing it, and they are currently leaking.**
Breaking loudly is the point.

## Fixed — the per-node client ceiling was undocumented

`LocalBroadcastServer` described itself as a single-threaded `stream_select()` loop and said
nothing about `FD_SETSIZE`. Its limitations list said *"up to ~100 concurrent connections without
tuning"*, which is vague in the dangerous direction: it reads as a soft limit an operator can push
past.

`stream_select()` is `select(2)`, whose descriptor sets are fixed-size bitmaps bounded by
`FD_SETSIZE` — 1024 in a typical build. Past it the call returns **false**, so the loop stops
serving every connected client at once. A cliff, not a slope.

Measured and reported by a deployment that went looking for the number rather than waiting for it,
including why it stays invisible: `ulimit -n` on that host is 1,048,576, so nothing in the
environment suggests a bound near a thousand — and it is the wrong number anyway, since it bounds
how many descriptors a process may *hold* rather than how many `select(2)` can *watch*.

The class docblock now names it, `descriptorCeiling()` exposes it, and a warning is logged once at
90% (`CLIENT_WARN_RATIO`) counting the listening socket and any ingest stream, because they sit in
the same set. The realtime guide states it under *Running more than one daemon*, since it is the
main reason to reach for clustering at all: past that ceiling more nodes is the answer, and a bigger
one does not exist.

Two off-by-ones were caught writing the test for it, one in each direction. The warning was
computed before the accepted client was registered, so it counted one short — the wrong direction
for a cliff warning. And the test seeded client ids from 1 without advancing `nextSocketId`, so
`acceptClient()` overwrote client 1 instead of adding one, and the assertion was measuring nothing.

## Not a bug, and worth recording

A consuming application reported that the unregistered-`redis-stream` fault never reached it,
having checked against Redis rather than reasoning from the config: its own `Application` constructs
the drivers itself and registers a `redis-dual`, so it never asks the registry for a name — 1,004
entries in the stream key, newest carrying a live payload. So what an earlier note called "not a
divergence" was in fact bypassing the registry, which is exactly why the bug was invisible from
there. **An application can be immune to a class of fault by accident**, and that is worth knowing
when a consumer reports that something did not affect them.
