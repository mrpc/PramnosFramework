---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
  - Documentation
tags:
  - document
  - migration
---

# "Minor variable name changes"

That is the commit message. March 2020, **14 insertions and 90 deletions**, and among the
deletions were eleven script and style registrations that templates enqueue by handle. An
unregistered handle throws, so a port that had been blocked for a day turned out to be blocked
by a diff from six years ago.

<!-- more -->

## What was reported

A consumer migrating from the legacy framework found that `Document::__construct()` no longer
registers `slimbox2`, `thickbox`, `spectrum` or the `Spry*` family. Their evidence was specific
rather than general, which is why it was actionable:

- `app/themes/admin/default/theme.html.php:15-16` calls `enqueueScript('slimbox2')` and
  `enqueueStyle('slimbox2')` **with no `src`**, and that file is the `index` template of the
  admin theme — so it loads on **every page of the panel**;
- `src/nannuka/media.php:571-572` does the same with `thickbox`;
- `_enqueueScript()` throws `Cannot find script: <handle>` when a handle is neither
  pre-registered nor given a source.

They re-checked across 36 commits before filing again, and noted that one commit in that range
had touched exactly this part of the constructor and made the gap *slightly larger*.

## Three findings, and they have three different answers

**The eleven registrations were an accident.** `git log -S` puts them in `4cbec2ec`, titled
*"Minor variable name changes"*. There was no decision to drop deprecated libraries; they were
collateral in a rename. **Restored verbatim**, at the URLs the legacy framework serves — plus
`mediamanager`, a twelfth the report had not spotted.

They are restored for compatibility and not on merit, and the code says so. Adobe Spry has been
unmaintained since 2012. But a template written when it was current still enqueues it by handle,
and a fatal in an admin panel is a worse answer than an old library.

**The inputmask handles were deliberate and still wrong.** `01da5954` replaced inputmask 4.0.9
with the 3.3.4 **bundle** and removed `jquery-inputmask-extensions` and `-date` because the
bundle contains them. Correct about the files, wrong about the contract: a template names the
*handle*. Both handles are registered again, resolving to the bundle.

**`jquery-inputmask-jui` never existed.** It is on their checklist among five that were real. Not
in this framework, not in the legacy one — checked in both. There is now a test asserting its
absence, so the next reader does not go looking.

## The CDN question, which was the more serious half

`jquery`, `bootstrap-datepicker` and `jquery-inputmask` are registered against
`cdnjs.cloudflare.com`. Everything else in that constructor is local. The consumer asked whether
that was intentional and said that if so it needs documenting as breaking.

It was intentional — `0541b22f`, *"load scripts from cdn"*, April 2020 — and **it was never
documented at all**. So an application that upgraded across it silently began loading three
third-party scripts from a third-party host. Their framing is the right one and worth repeating:

- **GDPR**, for a site with EU visitors: an IP address reaches Cloudflare before any consent is
  collected;
- **CSP**: a policy written for a self-hosted application does not list that origin, so the
  scripts are *blocked*, not merely remote.

**The default stays the CDN.** Flipping it would break every application that stopped vendoring
those files on the strength of that commit — the same mistake in the other direction. What was
missing was the choice and the sentence, not a different default:

```php
'documentAssetSource' => 'local',
```

serves them from `sURL` at the paths the legacy framework used. Documented in the
[Document guide](../../Pramnos_Document_Output_Guide.md), including the table of paths.

## Why no test caught this for six years

Nothing in this repository enqueues `slimbox2`. The registrations were a promise to *other
people's* templates, so deleting them broke nothing here and could not have.

There is a test now, and it is shaped around that: one case per handle the constructor promises,
generated from a list, asserting each is registered — plus one that enqueues a restored handle
with no source the way the consumer's theme does. It also pins the two things that made this
expensive:

- the throw happens when the **queue is processed**, not when `enqueueScript()` is called, which
  is why a missing registration arrives as a broken page rather than a broken template;
- the CDN defaults are still the CDN, so changing them is a deliberate act rather than a drift.

A promise made to code you cannot see needs a test you can.

## Fixed

- `slimbox2`, `thickbox`, `spectrum` (script and style), `mediamanager`, and the `Spry*` family
  are registered again.
- `jquery-inputmask-extensions` and `jquery-inputmask-date` resolve to the bundle that absorbed
  them.
- `documentAssetSource` chooses CDN or local for the three CDN-hosted defaults; the CDN move is
  documented as the breaking change it was.
