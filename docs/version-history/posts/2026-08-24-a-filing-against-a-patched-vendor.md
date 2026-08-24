---
date: 2026-08-24
categories: [Changelog]
---

# A filing against a patched `vendor/`, and the three real bugs inside it

Three findings arrived from a consuming application. Two of them described code
that has never existed in this repository — it had been patched into that
project's own `vendor/mrpc/pramnosframework/` and read back as though it were
upstream. That patch also contained three things that *are* real, and that nobody
had filed.

<!-- more -->

## The two that were not framework bugs

Both quoted `Pramnos\Application\Controller::getModel()`:

- a leftover `fwrite(STDERR, "CANDIDATE: …")` firing five times per model, per
  request, behind no flag;
- a candidate list containing `\App\Models\`, `\Admin\Models\` and
  `\Edgeapi\Models\` — one application's namespaces hard-coded into a general
  framework.

The second reads as a serious design complaint, and it would be. Measured against
the repository, at the exact reference `composer.lock` pins:

```
framework at 9a16ff26  →  no fwrite, no application namespaces
vendored copy          →  both present
```

`getModel()` here derives the class from `applicationInfo['namespace']` and has
done throughout its history. The debug line and the namespace list appear in no
commit. They were added to the vendored directory directly — most likely while
debugging a migration — and a later reading of that file mistook it for
upstream.

Worth saying plainly because it is a trap anyone can fall into, and the filing's
own rules already guard against it: *"run `composer update` and confirm the
finding still exists in the latest dev version."* A `vendor/` that has been
edited answers that check with the edit.

**Three files** in that vendored copy differ from the framework:
`Application/Controller.php`, `Http/Request.php` and `Routing/Route.php`. All
three are one `composer update` away from disappearing, which is the more urgent
half of this: if anything in that project now depends on the patched
`getModel()`, it breaks silently on the next update.

## The three real bugs the patch was hiding

The `Request.php` half of that patch was not application-specific at all. It was
fixing framework bugs — quietly, in a directory that gets overwritten.

### The subdirectory strip assumed `PHP_SELF` is a web path

`Request::__construct()` cut `strlen(dirname($_SERVER['PHP_SELF']))` characters
off the front of the URI, unconditionally, to support an application served from
a subdirectory.

`PHP_SELF` is not always a web path. Under the CLI — a console command, a daemon,
a test runner — it is the script's filesystem path. Under PHPUnit that is
`…/vendor/bin/phpunit`, whose dirname is 23 characters, so **every URI lost its
first 23**. A relative `PHP_SELF` gives a dirname of `.` and eats one character.

**This repository's own routing tests worked around it.** They pin `PHP_SELF` to
`/index.php` before constructing a Request, with a comment explaining that the
constructor would otherwise truncate every URI in the file. So the same
workaround had been written twice, in two repositories, by two people, and
neither called it a bug. Those comments now describe a web request rather than a
workaround.

The rule is now the one the intent implies: strip the directory **only when the
request actually starts with it**. That also stops `/myapplication/stations`
being mistaken for a `/myapp` subdirectory — a `strlen()`-based strip cuts on a
partial name match.

### `calcParams()` emptied `$_GET` and rebuilt it

Right for the keys it produces, wrong for every other one. Anything a front
controller, a middleware, a rewrite rule or a test had put in `$_GET` was
discarded, silently, with no way for the caller to know.

It keeps what was there now, and the query string still wins on a key it defines
— which is what the original assignment did for every key it produced. `r` is
still dropped, because that is the front controller's own routing parameter and
never belonged to the application.

### `getInstance()` could not be reset

The shared Request lived in a function-static, which nothing outside the method
can reach. A process could therefore only ever have one request — so a suite that
exercises routing, controllers or input could not start a second, and every
caller going through `getInstance()` kept whichever one was built first.

`Request::resetInstance()` clears the instance **and the derived statics**:
leaving `$requestUri` behind would hand the next request the previous one's
address, which is the failure the reset exists to prevent, arriving one step
later.

## The third finding: modernizr, and why it is not coming back

The legacy `pramnos_document_html` carried `public $modernizr = true;` and
injected `<script src="…media/js/modernizr.min.js">` into every page. The modern
`Html` document does not. The filing asked for either the feature back or a
written statement that the removal was deliberate.

**It was deliberate**, for two reasons now written into the
[Document Output Guide](../../Pramnos_Document_Output_Guide.md#what-the-framework-does-not-inject-and-why):

- the framework does not ship that file, so an unconditional injection would be
  a 404 on every page of every scaffolded project;
- a page's assets are the application's decision, which is what the asset
  registry is for. A default that cannot be seen in the calling code is a default
  nobody knows to turn off.

`Html::render()` does inject one thing — the two-line inline script that replaces
`class="no-js"` with `js`, inline because a round trip to decide whether
JavaScript exists would arrive after the page had been painted. The guide now
says so, and gives the `addHeadContent()` snippet for a theme that needs the rest
of modernizr's feature classes. That snippet is the filing project's own
workaround: it works identically on the legacy document and the modern one, so it
is safe to add before migrating, and it needs no framework change.
