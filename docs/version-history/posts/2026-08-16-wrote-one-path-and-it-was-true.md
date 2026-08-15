---
date: 2026-08-16
categories:
  - Changelog
  - Fixed
tags:
  - console
  - openapi
  - routing
---

# Wrote 1 path(s), and every word of it was true

`api:docs` scanned `src/Controllers` and wrote into `www/`. For an application that
keeps its API in `src/Api/Controllers` and is served from `public/`, both are wrong,
neither appeared in the output, and the command reported success.

<!-- more -->

## One endpoint of seventy-two

A consumer ran it against an application serving **72** endpoints:

```
Wrote 1 path(s), 1 operation(s) to /srv/app/www/api/openapi.json
```

Nothing there is false. It did write one path, it did write it to that file. What it
did not say is *where it looked* — `src/Controllers`, which in that application holds
the single MVC controller, added the week before beside 51 attribute-routed API
controllers in `src/Api/Controllers`.

**The scan is the dangerous half, and it is worth being precise about why.** A file
written to the wrong directory is noticed the first time somebody opens it: the path
is right there in the output, and the URL 404s. A document describing 1 endpoint of 72
is **published and believed** — it is indistinguishable from an application that
genuinely has one endpoint. There is nothing to notice.

## Four defaults, and a fifth thing nobody had asked about

The filing named four fixes. All four landed:

- **The success line names what was scanned**, not only where it wrote:

  ```
  Scanned src/Api/Controllers (namespace App\Api\Controllers)
  Wrote 72 path(s), 96 operation(s) to /srv/app/public/api/openapi.json
  ```

- **A thin result says so.** When a sibling directory holds more operations than the
  one scanned, the command names it and the counts. Nothing is switched under you —
  a directory swapped silently would be a worse surprise than a thin document — and
  the check is skipped entirely when `--controllers` was passed, because naming the
  directory is a decision, not a guess to correct.

- **The default looks for the API first**: `src/Api/Controllers`, then
  `src/Controllers`. An application with only the second is unaffected.

- **The output follows the document root** — whichever of `www`, `public`, `html`,
  `web` holds an `index.php`. Hardcoding `www/` stopped being defensible the moment
  [`pramnos init` grew `--web-root`](2026-08-14-a-blank-page-is-not-an-error.md): a
  project scaffolded with `--web-root=public` had this command create a `www/` beside
  it, served by nothing.

**The fifth was found while fixing the others, and it is why the documented escape
hatch did not work either.** `detectNamespace()` appended a fixed `\Controllers` to
the application namespace regardless of which directory it had been told to scan. So
the command's own usage block — `--controllers=src/Api/Controllers`, with no
`--namespace` — looked for `App\Controllers\*` inside `src/Api/Controllers`, found
nothing, and exited `0`. Somebody following the documented workaround would have gone
from a document with one endpoint to a document with none, and got the same
reassuring `Wrote` line either way.

The namespace follows the directory now: application namespace plus the path after
`src/`. `src/Controllers` still gives `App\Controllers`, so nothing that worked
changes.

## The shape

The command contained the evidence against itself the whole time. Its usage block
offered `--controllers=src/Api/Controllers` as the example, which is the layout the
default did not look in — and the same example was broken by the namespace bug, so
neither half could have been run recently by anybody.

That is the second time this week a defect has been sitting inside its own
documentation: the Document guide's SEO section
[named three methods that never existed](2026-08-15-one-quote-in-a-station-name.md),
and this usage block described a workaround that could not work. Prose beside code is
not checked by anything, and both were found by running it rather than reading it.

## Fixed

- `api:docs` prints the directory and namespace it scanned.
- It warns, naming counts and the alternative, when a sibling controllers directory
  holds more operations than the one it scanned.
- `--controllers` defaults to the first of `src/Api/Controllers`, `src/Controllers`
  that exists.
- `--output` defaults to `<document root>/api/openapi.json`, detected rather than
  assumed to be `www/`.
- `--namespace` is derived from the controllers directory instead of a fixed
  `\Controllers` suffix — the documented `--controllers=…` example produces a
  document now.

## Documentation

- [Routing guide](../../Pramnos_Routing_Guide.md) — what the command looks at, what it
  writes, and what it prints; the example no longer passes options it now works out.
- [Application Styles guide](../../Pramnos_Application_Styles_Guide.md) — check the
  `Scanned …` line against where your API lives before publishing the result.
