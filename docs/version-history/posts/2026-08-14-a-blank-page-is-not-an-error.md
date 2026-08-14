---
date: 2026-08-14
categories:
  - Changelog
  - Added
  - Fixed
tags:
  - console
  - document
  - scaffolding
---

# A blank page is not an error

Two findings from two different consumers, both about a path that was right for one project and
silently wrong for another. `www` was hardcoded in 38 places, and `documentAssetSource => 'local'`
— which shipped this morning — would have produced two 404s in the first application that tried
it.

<!-- more -->

## `--web-root`

The scaffold writes its document root as `www/` by convention. A consumer reported it through the
place it hurt: `outDir` in the generated `vite.config.js` was `'www/' . SPA_BUILD_DIR`, so a
project served from anywhere else built its front end into a directory nothing serves.

**The symptom is a blank page, not an error.** The build succeeds, the files are written, and the
shell looks for a manifest that is not there. Nothing in any log.

```bash
php bin/pramnos init --web-root=public
```

Everything under the document root follows it now — the directory, the front controller,
`.htaccess`, assets, favicons, the API entry point, the SPA shell and build output, the
`.gitignore` lines, the Docker `DocumentRoot`, and **the prose in generated files that names the
path**. That last one is not decoration: the generated `vite.config.js` explained itself with
"writes into `www/assets/spa/`", which would have been a comment naming the wrong directory in
exactly the file somebody reads when the page is blank.

A half-applied option is worse than no option: a project that looks configured, broken in a way
the configuration appears to explain. So the test scaffolds with a non-default root and asserts on
the **tree** rather than on the flag, including that nothing was left behind in `www/`. Finding the
last two took a second pass — four literals were written `'/www/...'` rather than `'www/...'`, and
a substring search for one spelling does not find the other.

## And the option from this morning was the wrong shape

`documentAssetSource => 'local'` shipped a few hours earlier, to answer a
[GDPR and CSP concern](2026-08-14-minor-variable-name-changes.md) about three defaults pointing at
`cdnjs.cloudflare.com`. The consumer who asked for it went to enable it and **did not**, for a
reason worth more than the feature:

> confirmed with `find` that only `media/js/jquery/jquery.min.js` exists locally — there is **no
> `plugins/` directory**, so `bootstrap-datepicker.js` and `jquery.inputmask.js` would 404 if it
> were switched on today.

All-or-nothing left them choosing between a GDPR problem they wanted to fix and two broken
scripts, when what they needed was to fix the one they could. It takes a list now:

```php
'documentAssetSource' => ['jquery'],   // jquery local; the other two stay on the CDN
```

`'local'` still means all three. A comma-separated string and a JSON array are accepted too,
because settings round-trip a list as an array, an `stdClass` or a string depending on how it was
stored, and three of four spellings producing silence would be worse than not taking a list.

The guide now also says to check the files exist first, with the `ls` to run. **The option changes
a URL and verifies nothing** — that is the honest description, and it is the sentence that was
missing this morning.

## The third finding was fixed and said so nowhere useful

The same filing listed `Api::_attachDebugPayload()` and `Api::_sendServerTiming()` as
`protected`, so a non-`Api` application cannot feed the SPA debug bar without reimplementing them.

**Literally true, and it stopped being the obstacle in `3dacef1a`.** Both methods are now thin
delegations to public statics, and `ApiDebugMiddleware` calls the same two:

| What `Api` does privately | The public seam |
| --- | --- |
| `_attachDebugPayload($body)` | `ApiDebugPayload::attachTo(string $body): string` |
| `_sendServerTiming()` | `ApiDebugPayload::sendHeaders(): void` |

One line in the pipeline covers every routing style. So no code changed — and that makes this the
more interesting of the three, because **the consumer looked in exactly the right place and the
right place did not tell them.** Reading `Api` leads to "protected, cannot use" and stops there.

Two fixes, neither of them behaviour:

- the middleware is now documented in the **Application Styles guide**, on the page a Services +
  API + SPA project actually reads. It was in the Debugging and Upgrade guides, which is the same
  mistake as `throwOnError` being thoroughly documented in a section nobody is standing in;
- both `Api` methods carry a comment naming the public seam, because somebody reading the
  protected method is the person who needs it.

Their own ledger had just described this failure mode from the other side — a *fix* documented
weaker than it is, rotting in the direction nobody watches, because a tripwire checked a **name**
(is this method still protected) rather than a **construction** (is the capability reachable). It
is the same shape as the `conname` test that asserted the right table and never the column.

## What all three have in common

None was a bug in behaviour anybody could see. One was a path correct in the project that wrote
it; one an option whose safe use depended on files the framework cannot know about; one a
capability that existed and could not be found from where somebody was reading.

Two were reported by consumers who checked their own filesystem before acting on advice — `find`
in one case, reading the generated config in the other — and that check is what made them reports
rather than questions. The third is a reminder that **"still not fixed" and "still not findable"
produce the same filing**, and only one of them is answered by writing code.

## Added

- `pramnos init --web-root=<dir>`, applied to everything the scaffold writes under the document
  root, prose included.
- `documentAssetSource` accepts a list of handles as well as `'local'`.

## Fixed

- The generated `vite.config.js`, `dockernpm`, `doc.sh`, `CLAUDE.md` and Svelte entry stub no
  longer name `www/` in prose when the document root is elsewhere.

## Documentation

- [Application Styles guide](../../Pramnos_Application_Styles_Guide.md) — feeding the debug
  toolbar from a non-`Api` application, on the page such a project reads.
- [Console guide](../../Pramnos_Console_Guide.md) — `--web-root`.
- [Document guide](../../Pramnos_Document_Output_Guide.md) — the per-handle asset source, and the
  check to run before switching.
