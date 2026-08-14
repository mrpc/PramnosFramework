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

## What both have in common

Neither was a bug in behaviour anybody could see. One was a path that is correct in the project
that wrote it, the other an option whose safe use depended on files the framework cannot know
about. Both were reported by somebody who checked their own filesystem before acting on advice —
`find` in one case, reading the generated config in the other — and in both cases the check is what
made the report useful rather than a question.

## Added

- `pramnos init --web-root=<dir>`, applied to everything the scaffold writes under the document
  root, prose included.
- `documentAssetSource` accepts a list of handles as well as `'local'`.

## Fixed

- The generated `vite.config.js`, `dockernpm`, `doc.sh`, `CLAUDE.md` and Svelte entry stub no
  longer name `www/` in prose when the document root is elsewhere.
