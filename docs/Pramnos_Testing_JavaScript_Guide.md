---
use_cases:
  - Testing an application's JavaScript
  - Testing the JavaScript the framework ships
  - Covering code the framework writes, not only code it runs
  - Testing a page script that uses jQuery, fetch or the framework's pf-*.js
  - Running the linter or the JavaScript tests
---

# Testing JavaScript

Part of the [testing guide](Pramnos_Testing_Guide.md).

## Testing the JavaScript of an application

An application tests the scripts it writes for its pages with the framework's runner and helpers,
not with copies of them:

```bash
php bin/<app> test:js                    # node --test over every *.test.js in tests/js
php bin/<app> test:js tests/js/notes.test.js
php bin/<app> lint:js                    # ESLint, the framework's rules, over assets/js and tests/js
php bin/<app> lint:js --fix
```

`test:js` reads its directory from `js_tests_dir` in `app/app.php` (default `tests/js`, or
`--dir`) and finds `*.test.js` in it and below. Both commands run Node **where the console runs**.
In a project in Docker that is the container, as `./dockerbash` or the generated CLI wrapper
gives it, so the tests run against the container's Node, as PHPUnit runs against its PHP. Where
there is no Node, they say so.

Nothing to install. `node --test` and the helpers use only Node. `lint:js` uses the project's
`node_modules/.bin/eslint` when there is one, and otherwise `npx` fetches ESLint 9.

### The helpers: `require('pramnos-testing')`

`test:js` puts the framework's helpers on `NODE_PATH`, so every test has them by name:

| Helper | What it gives a test |
|---|---|
| `createDom({ url, meta, readyState })` | `{ window, document }`: elements with attributes, classes, `dataset`, children, siblings and listeners. Events bubble to the document and the window. There are `localStorage`/`sessionStorage`, and a `location` whose assignments land in `window.navigatedTo`. `meta: { csrf: 'x' }` puts `<meta name="csrf">` in the head. `document.createElementNS()` makes an SVG element, keeping its name's case and its `namespaceURI`. |
| `loadScript(file, window)` | Runs a classic browser script, a `<script src>`, with the window as its globals. Returns the context: top-level `var`s and what the script put on `window`. `{ source: true }` takes the code itself. |
| `stubFetch(routes)` | A `fetch` that answers from a table: `'GET /api/notes'` or `'/api/notes'` maps to a body, to `{ status, body }`, or to `(request) => …`. Anything else answers 404. `fetch.calls` records every request. |
| `installApiHeaders(window)` | `window.pfApiHeaders`, as `pf-utils.js` defines it: the CSRF token from the page's `<meta name="csrf">`. |
| `installJquery(window)` | A jQuery-shaped `$` for legacy code. It covers `on`/`off`/`trigger` with delegation, `val`, `text`, `html`, `attr`, `data`, the class methods, `find`, `closest`, `each`, and `$.ajax`/`$.get`/`$.post` through the stubbed `fetch`. |

Selectors: `tag`, `#id`, `.class`, `[attr]`, `[attr="v"]` (and `^=` `$=` `*=`), compounds,
descendants, `>` and comma lists. A selector outside that throws rather than matching nothing.
Assigned `innerHTML` is kept as a string, not parsed: build the elements a test needs with
`createElement`.

```js
// tests/js/notes.test.js
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createDom, loadScript, stubFetch, installApiHeaders } = require('pramnos-testing');

test('saving a note posts it with the page token', async () => {
    // Arrange — the page the script expects
    const { window, document } = createDom({ meta: { csrf: 't0ken' } });
    window.fetch = stubFetch({ 'POST /api/notes': { status: 201, body: { id: 7 } } });
    installApiHeaders(window);
    const button = document.body.appendChild(document.createElement('button'));
    button.setAttribute('data-save-note', '');

    // Act
    loadScript('www/assets/js/notes.js', window);
    button.click();
    await new Promise((done) => setImmediate(done));

    // Assert
    assert.equal(window.fetch.calls[0].method, 'POST');
    assert.equal(window.fetch.calls[0].headers['X-CSRF-Token'], 't0ken');
});
```

### A script that extends the framework's `pf-*.js`

Load the framework's script first, from where the page loads it, then the application's. They
share the window as they share a page:

```js
loadScript('www/assets/js/pf-utils.js', window);        // as published by project:resync --js
loadScript('www/assets/js/admin-extras.js', window);    // the application's, which uses window.pfApiHeaders
```

Test the published copy, not the one under `vendor/`. The page runs the published copy, so a stale
one is the bug the test should see.

### Linting with the framework's rules

The rules are defects only, never a style: a redeclared variable, an undefined name, unreachable
code. Without an `eslint.config.mjs`, `lint:js` applies the framework's whole configuration.
An application that needs its own globals, a legacy jQuery page for instance, writes one that
extends it:

```js
// eslint.config.mjs
import { browser, tests } from './vendor/mrpc/pramnosframework/resources/js/pramnos-testing/eslint.base.mjs';

export default [
    { ignores: ['www/assets/vendor/**'] },
    browser(['www/assets/js/**/*.js'], { jQuery: 'readonly', $: 'readonly' }),
    tests(['tests/js/**/*.js']),
];
```

## The JavaScript the framework ships

One browser asset — `src/Pramnos/Debug/assets/debugbar.js`, a few thousand lines, served on every
page of every project with the debug toolbar enabled — plus the tests that cover it under
`tests/js/`.

```bash
./testjs              # node --test over tests/js/**/*.test.js
./lintjs              # ESLint over src and tests/js
./lintjs --fix        # fix what can be fixed automatically
./lintjs src          # a subtree
```

Both run **inside the container**, like `./dockertest`, and for the same reason: the container
is the environment. A linter that reports differently depending on whose Node ran it is worse
than no linter. `npm` is in the `Dockerfile`; `./lintjs` installs it into a stale image rather
than failing on a detail nobody wants to think about while linting.

### What the linter is for, and what it is not

Every rule enabled describes a **defect**. There are no style rules — no quote policy, no
semicolons, no indentation — because `debugbar.js` was written long before the config: reformatting it
would bury the next real change in noise, and a `--fix` sweep across the whole file is precisely the
diff nobody can review.

The rule the config exists for is `no-redeclare`. A `var` declared twice in that asset is
legal JavaScript and passes every test here, yet a consuming project's stricter tooling can
refuse the file — and with it every panel test that loads it.

!!! warning "Do not write a unit test for this class of mistake"
    A test that scans for duplicate `var` declarations flags `var rows` in six unrelated
    functions, because it matches an **identifier** rather than a redeclaration.
    `no-redeclare` understands scope; a grep never will.

    The same reasoning applies to `no-undef`, `no-dupe-keys` and the rest: they are answers a
    parser can give and a test cannot.

### `CLIENT_TABS` is deliberately absent

The three tabs a `CLIENT_TABS` lookup table would name *are* special-cased in `debugbar.js`, by
explicit `tab.key === …` checks in three places. Such a constant would duplicate that knowledge
rather than replace it, and wiring it up would be a refactor of behavioural code, so the note is
kept in the file instead.

CI runs both, on Node 20 — the version the container ships, so a failure there reproduces with
`./lintjs` rather than being a CI-only surprise.

## Code the framework *writes* is not covered by the guards over code it *runs*

`MissingClassReferenceTest` resolves every `\Pramnos\…` name in `src/`, `LegacyClassReferenceTest`
catches the CMS-era shapes, and between them a class name that does not exist cannot reach a
release — in the framework's own code. Neither sees a single line of what `create:*` and `init`
emit, and that is the larger surface: **the framework ships far more code than it runs.**

The two sweeps that cover it are `EveryStubIsSyntacticallyValidTest` (does it parse) and
`GeneratedCodeNamesRealClassesTest` (do the names resolve). A stub cannot be tokenised the way a
source file can, because it is a template rather than loadable PHP, so the second one works on
text and has to earn its precision:

- **Strip comments first.** A comment naming a class is prose. One explaining *what a line
  replaced* names, by definition, a class that no longer exists.
- **Skip `namespace` and `use` statements.** `namespace App\Controllers;` is not a class, and a
  guard that reports it reports it for ever.
- **Only sweep roots the framework actually depends on.** `App\…` in a stub is the generated
  application's own namespace and cannot resolve here; sweeping it would flag every template.

Both points are the same point: a guard that reports things nobody can fix is a guard somebody
deletes, and the value of a mechanical sweep is entirely in being believed.

### Writing one of these

Verify it bites before you trust it. Break a stub on purpose, watch the test fail with a message
that names the file and the offending name, then restore. A sweep is the one kind of test where
passing on a clean tree and matching nothing at all look identical.
