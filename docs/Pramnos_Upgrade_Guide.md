---
use_cases:
  - Upgrading an existing application to a newer framework version
  - Finding the breaking changes between two versions
  - Verifying an upgrade afterwards
---

# Upgrade Guide (Version-to-Version)

Step-by-step instructions for upgrading an existing application from one Pramnos
Framework release to the next. Each section is self-contained: to jump more than
one version, apply the sections in order (e.g. `v1.0 → v1.1`, then `v1.1 → v1.2`).

For the day-to-day stream of individual changes see the
[Changelog](version-history/index.md); for the frozen technical reference of a
release see its feature document (e.g. [New Features in v1.2](1.2-new-features.md)).

!!! tip "Read this before you bump the dependency"
    Most upgrade pain is behavioural, not signature-level: code still compiles and
    autoloads, but a response envelope, default, or generated asset changes shape.
    The per-version **Breaking changes** tables below list exactly those cases.

---

## General upgrade procedure

The same disciplined loop applies to every version bump:

### Preconditions

- A verified backup of the database and uploaded assets.
- A staging environment that mirrors production (same PHP version, same DB engine
  and version — MySQL / PostgreSQL / TimescaleDB).
- A documented rollback plan (previous release artifact + DB snapshot).
- A green test suite on the current version *before* you start.

### Steps

1. **Update the dependency** in `composer.json` and run `composer update mrpc/pramnosframework`.
2. **Run pending migrations** — framework and application:
   ```bash
   php vendor/bin/pramnos migrate
   ```
   Review pending migrations first with `php vendor/bin/pramnos migrate:status`.
3. **Apply the per-version changes** from the matching section below.
4. **Clear caches and rebuild generated assets** (compiled views, scaffolded
   controllers/views, CSP nonces).
5. **Run the full test suite** and the validation checklist for that version.

### Rollback

1. Restore the previous release artifact (`composer.lock` + vendor).
2. Revert configuration/settings toggles introduced by the upgrade.
3. Roll back migrations (`php vendor/bin/pramnos migrate:rollback`) or restore the
   pre-upgrade database snapshot if a migration is not reversible.

---

## Test isolation extensions, for existing projects

**Applies to:** every project with a test suite that was scaffolded before this change.
It is a two-line edit and there is no code to change.

The framework has three registries that are per-request in production and **process-wide in
a test run**. Without a reset between tests, state one test establishes answers for every
test after it. In the framework's own suite that cost 135 failures once and three on
another occasion — and in both cases the failures appeared in tests that had nothing to do
with the state involved, so they looked like bugs in the tests that failed. `GateIsolation`
is the third, and was written with the gate rather than after an incident.

`pramnos init` now writes the registration into `phpunit.xml`. Add it to yours:

```xml
<phpunit ...>
    <extensions>
        <bootstrap class="Pramnos\Framework\Testing\RequestIdentityIsolation"/>
        <bootstrap class="Pramnos\Framework\Testing\DocumentIsolation"/>
        <bootstrap class="Pramnos\Framework\Testing\GateIsolation"/>
    </extensions>

    <testsuites>
        ...
```

`<extensions>` goes before `<testsuites>`. Nothing else changes.

**What to expect after adding them.** Usually nothing — a green suite stays green and gets
slightly less mysterious. Two things can happen, and both are the point:

- **A test starts failing.** It was passing on state left behind by a test that ran before
  it. Give it what it needs in its own `setUp()`; it was never testing what it claimed to.
- **A test starts passing.** It was the victim.

If a test *depends* on ordering in a way you cannot remove immediately, establish the state
in that test's `setUp()` rather than removing the extension: the reset runs at
`PreparationStarted`, which is before `setUp()`, so anything the test sets for itself
survives.

All three ship in `src/` (`Pramnos\Framework\Testing`), so they exist in `vendor/` and need
no autoload configuration. See
[Isolating process-wide state](Pramnos_Testing_Guide.md#isolating-process-wide-state).

---

## Show password, for existing projects

A scaffolded project gets the «show password» control on every password field from the start. An
**existing** project does not — its views were copied before the control existed, so adding it is a
deliberate step. This section is that step, in both the case where you can take the new framework and
the case where you cannot.

Why bother: it is the first recommendation of
[web.dev's sign-in form guidance](https://web.dev/articles/sign-in-form-best-practices), and the
reason is mobile. The commonest cause of a failed sign-in on a phone is a typo in a field nobody can
read — the person retries the same wrong thing and then resets a password they never forgot. An
evaluation of one real installation found the control missing from **every** screen; that is the
normal state of a project that predates it.

### Find every field first

```bash
grep -rn 'type="password"' src/Views/ | sed 's/:.*//' | sort -u
```

Every hit is a field a person types into and cannot read. Include the ones that are not sign-in
screens — change-password, delete-account confirmation, an administrator setting somebody else's
password, an SMTP password on a settings page. They are all passwords typed by hand.

### With the current framework

One line beside each field. The field itself does not change:

```php
<div class="flex items-baseline justify-between mb-1">
    <label for="password">Password</label>
    <?php echo \Pramnos\Html\PasswordToggle::render('password', '', '', 'btn btn-ghost btn-xs'); ?>
</div>
<input type="password" name="password" id="password" autocomplete="current-password" required>
```

Three things to know:

- **The field needs an `id`.** The control addresses it by id, and so does `<label for>`. A field
  without one has no programmatic label either, so add the id and wire the label at the same time —
  in the framework's own scaffolds, eight fields across three themes turned out to be missing both.
- **The last argument is your theme's button class.** The helper has no opinion about how a button
  looks, so a hard-coded class would render a control matching nothing on your page.
- **The labels default to translated «Show password» / «Hide password».** Pass your own if the screen
  is in a language your catalogue does not cover — the fourth and fifth arguments to `render()`.

### Without upgrading the framework

If the dependency cannot move yet, the control is small enough to carry locally. Put this in your own
`src/` and call it exactly as above; when you do upgrade, delete it and change the class name back.

```php
<?php
namespace YourApp\Html;

class PasswordToggle
{
    public static function render(
        string $inputId,
        string $showLabel = 'Show password',
        string $hideLabel = 'Hide password',
        string $class = ''
    ): string {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_:.-]*$/', $inputId) !== 1) {
            throw new \InvalidArgumentException('Not a usable input id: ' . $inputId);
        }

        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES);

        return '<button type="button" hidden'
            . ($class !== '' ? ' class="' . $e($class) . '"' : '')
            . ' data-password-toggle aria-controls="' . $e($inputId) . '" aria-pressed="false"'
            . ' data-show-label="' . $e($showLabel) . '" data-hide-label="' . $e($hideLabel) . '">'
            . htmlspecialchars($showLabel) . '</button>'
            . '<script>(function(){'
            . 'if(window.__passwordToggleBound){return;}window.__passwordToggleBound=true;'
            . "var S='[data-password-toggle]';"
            . 'function reveal(){document.querySelectorAll(S).forEach(function(b){b.hidden=false;});}'
            . "document.addEventListener('click',function(ev){"
            . 'var b=ev.target.closest?ev.target.closest(S):null;if(!b){return;}'
            . "var f=document.getElementById(b.getAttribute('aria-controls'));if(!f){return;}"
            . 'var s=f.selectionStart,e2=f.selectionEnd,show=f.type==="password";'
            . 'f.type=show?"text":"password";'
            . "b.setAttribute('aria-pressed',show?'true':'false');"
            . "b.textContent=show?b.getAttribute('data-hide-label'):b.getAttribute('data-show-label');"
            . 'f.focus();if(s!==null&&f.setSelectionRange){try{f.setSelectionRange(s,e2);}catch(x){}}'
            . '});'
            . "if(document.readyState==='loading'){"
            . "document.addEventListener('DOMContentLoaded',reveal);}else{reveal();}"
            . '})();</script>';
    }
}
```

Four properties in there are the whole design, and a hand-rolled toggle usually misses at least two:

1. **The button ships `hidden`** and its own script unhides it. Without JavaScript a visible «show»
   button is something a person presses twice and then distrusts the rest of the form.
2. **Only `type` changes.** `name`, `id` and `autocomplete` are what a password manager matches on. A
   toggle that renamed the field would stop it offering the saved password — a worse outcome than an
   unreadable field.
3. **Focus and the caret survive.** Toggling mid-word and losing your place is the same frustration
   the control was added to remove.
4. **The script guards itself in the browser** rather than being emitted once from PHP. A static
   «already emitted» flag is *process* state, not request state: anything that renders two responses
   from one process — an in-process test client, a long-running worker — gives the second page a
   button with no listener behind it. That is the failure mode of the naive optimisation, and it is
   silent.

If you add a CSP with a nonce, put it on that `<script>` tag; the framework's own version reads
`Application::currentInstance()->cspNonce` and does it for you.

### Verify it, once, rather than screen by screen

The check that keeps this from decaying is a test that reads your views and fails when a password
field has no toggle beside it. The framework ships exactly that for its scaffolds
(`tests/Unit/Html/ScaffoldPasswordFieldsTest.php`) — copy it and point it at `src/Views`. It asserts
four things:

- there are password fields to find at all, so a wrong path cannot read as «everything complies»;
- every one has an `id`;
- every one has a toggle addressed to **its** id;
- every toggle points at a field that exists — the mistake a rename makes, where the field becomes
  `new_password`, the toggle still says `password`, and the control renders and addresses nothing.

## The debug toolbar no longer uses an output buffer

**Applies to:** any application that enables the debug toolbar and produces part of
its response with a raw `echo` rather than through the framework.

**What changed.** The toolbar used to be injected by a process-wide `ob_start()`
installed when the debug provider booted. It is now injected into the **response** —
`DebugBar::injectInto()`, reached from `Application::render()` and from
`DebugBarMiddleware`, which is what Laravel's debugbar and Symfony's profiler do.

**Why.** The buffer added an output-buffer level to every request. Code that cleared
"its" buffer — a bare `ob_get_clean()`, or the `while (ob_get_level())
{ ob_end_clean(); }` loop a kernel uses to drop stray output — cleared the
framework's, with the response inside it. The result was `200` with an **empty body**
while every header said the request had succeeded, nothing in any log, and the same
code working perfectly with the toolbar off. Two applications hit it; one lost a day
and switched `APP_DEBUG` off.

**What you get for free.** Every application that ends its request the scaffolded way
keeps its toolbar with no changes at all:

```php
echo $app->render();                    // covered
echo $pipeline->run($request, fn() => $app->render());   // covered
```

**What needs action.** A response the framework never sees no longer receives a
toolbar. The common shape is a kernel that ends an unmatched request by including a
page file:

```php
// Before — the toolbar arrived via the output buffer
require PUBLIC_PATH . '/spa.php';
```

Give the framework the response instead, and it is injected into:

```php
// After — the same page, through the response
ob_start();
require PUBLIC_PATH . '/spa.php';
echo \Pramnos\Debug\DebugBar::getInstance()->injectInto((string) ob_get_clean());
```

or, if the file can be made to return its markup rather than echo it, hand that
string over directly. Either way **your** buffer is one you opened and can safely
clear, which is the property the framework can no longer provide for you.

JSON responses are unaffected: `Application\Api` attaches `_debug` itself, and an
attribute-routed application adds `\Pramnos\Debug\ApiDebugMiddleware` to its
pipeline (see the [Debugging Guide](Pramnos_Debugging_Guide.md)).

**While migrating**, a page with no toolbar and a complete body is the expected
intermediate state. It is the right way round: a missing toolbar is a bug report, a
missing page is a phone call.

---

## Two features the legacy document had, and the modern one does not

Reported by an application migrating from the legacy `pramnos_document_html`, and worth
stating because neither was default-off — both were removed entirely.

### `$modernizr`

The legacy document type had `public $modernizr = true;` and injected

```html
<script async src="<?= sURL ?>media/js/modernizr.min.js"></script>
```

into the `<head>` of **every** page. `Pramnos\Document\DocumentTypes\Html` has no such
property and emits no such tag.

**This is not being restored**, and the reason is worth the paragraph: the framework
does not ship `media/js/modernizr.min.js`. Reinstating a default-on injection of a file
that may not exist would give every upgraded application a 404 in its `<head>`, to
replace a feature most of them were not using. If you rely on Modernizr's feature
classes, add it yourself:

```php
$doc->addHeadContent('<script async src="' . sURL . 'media/js/modernizr.min.js"></script>');
```

### `$reset` / `reset.css`

The same shape and the same answer: no property, no injection, and no stylesheet
shipped to inject. Add it with `addStylesheet()` if you want it.

### What *was* fixed: the `no-js` marker

The modern document kept emitting `class="no-js"` after the script that flips it was
removed — so every page declared `no-js` permanently, and any CSS written as
`.no-js .thing { display: none }` hid that thing forever in a browser with JavaScript
working.

It was also on the wrong element. `<head class="no-js">` cannot be matched by a
stylesheet at all: the head is not rendered, so `head.no-js` selects nothing. Modernizr
puts its classes on `<html>`, which is what makes the pattern work.

Since 2026-08-16 the class is on `<html>` and a two-line inline script turns it into
`js` — no external file, no dependency:

```html
<html class="no-js" lang="en">
<head>
<script>document.documentElement.className=document.documentElement.className.replace(/\bno-js\b/,'js');</script>
```

So `.no-js` and `.js` selectors behave the way every guide on progressive enhancement
says they should. If your stylesheets were written against the legacy behaviour, they
start working rather than stopping.

## v1.1 → v1.2

v1.2 is a large release (replicas, query/schema builders, migration system
overhaul, middleware pipeline, response object, security hardening). Most of it is
purely additive. The items below are the ones that require action in application
code or operations.

Full reference: [New Features in v1.2](1.2-new-features.md).

### Breaking changes

| Area | Change | Action required |
|---|---|---|
| **DataTables** | Server-side list views now use the DataTables 1.10+ protocol; `Datasource::getList()` returns rows under `data` instead of `aaData` when the request carries `draw`. | Update any custom list endpoint that post-processes rows — see below. |
| **`Factory` accessors** | Legacy static `Factory` accessors were removed. | Use the documented replacements (see §76 of the v1.2 reference). |
| **`_getJsonList()`** | Marked `@deprecated`; still returns the DT 1.9 `aaData`/`sEcho` envelope. | Migrate new code to `_getApiList($format = 'datatables')`. |

### DataTables server-side AJAX (`aaData` → `data`)

This is the change most likely to break an existing admin UI, and it fails loudly:

```
DataTables warning: table id=<id> - Requested unknown parameter '7' for row 0, column 7
```

**Why it happens.** In v1.2 two changes ship together:

1. `\Pramnos\Html\Datatable::renderJs()` now emits DataTables 1.10+ options
   (`serverSide: true` + a modern `ajax` block) instead of the DT 1.9
   `bServerSide`/`sAjaxSource`/`fnServerData`. The **client therefore always sends
   a `draw` parameter.**
2. `\Pramnos\Html\Datatable\Datasource::getList()` auto-detects `draw` and returns
   the modern envelope `{ draw, recordsTotal, recordsFiltered, data }` — rows live
   under **`data`**, no longer under `aaData`.

Any endpoint that fetches an *unencoded* result, decorates rows in PHP, and
re-encodes them — the standard hand-written `getJsonList()` / `data()` pattern —
reads and writes the `aaData` key. Under the modern format that key is absent, so
the decoration loop silently does nothing and rows go out with only the raw DB
fields. The grid then asks for a column index that no longer exists.

**Before (breaks in v1.2):**

```php
public function getJsonList()
{
    $result = \Pramnos\Html\Datatable\Datasource::getList($table, $fields, false);

    foreach ($result['aaData'] as $i => $row) {      // key no longer present
        $row[7] = '<span class="badge">…</span>';    // computed column
        $row[8] = '<a href="…/edit/' . $row[0] . '">Edit</a>';
        $result['aaData'][$i] = $row;
    }

    return json_encode($result);
}
```

**After (works under both formats):**

```php
public function getJsonList()
{
    $result  = \Pramnos\Html\Datatable\Datasource::getList($table, $fields, false);

    // v1.2 returns rows under 'data' when the request is DataTables 1.10+,
    // and under 'aaData' for legacy requests. Operate on whichever exists.
    $rowsKey = isset($result['data']) ? 'data' : 'aaData';

    foreach ($result[$rowsKey] as $i => $row) {
        $row[7] = '<span class="badge">…</span>';
        $row[8] = '<a href="…/edit/' . $row[0] . '">Edit</a>';
        $result[$rowsKey][$i] = $row;
    }

    return json_encode($result);
}
```

The one-line `$rowsKey` guard is the whole fix; it keeps the same code working for
both legacy and modern requests.

!!! note "Alternative: adopt the new API path"
    New code should prefer `_getApiList($format = 'datatables')`, which produces the
    modern envelope directly and unifies the paginated/non-paginated paths. The
    `$rowsKey` guard above is the minimal-diff fix for existing hand-written
    endpoints.

#### Regression test recipe

Existing tests typically exercise only the legacy path (they never send `draw`),
which is why this regression can ship unnoticed. Add a test that drives **both**
paths and asserts the modern row has the same column structure as the legacy row:

```php
public function testGetJsonListHandlesModernDatatableFormat(): void
{
    // Legacy request (DataTables 1.9 — no draw param).
    unset($_POST['draw'], $_REQUEST['draw']);
    $legacy = json_decode($model->getJsonList(), true);

    // Modern request (DataTables 1.10+ — carries draw).
    $_POST['draw'] = $_REQUEST['draw'] = 1;
    $modern = json_decode($model->getJsonList(), true);
    unset($_POST['draw'], $_REQUEST['draw']);

    $legacyRows = $legacy['data'] ?? $legacy['aaData'] ?? [];
    $modernRows = $modern['data'] ?? $modern['aaData'] ?? [];

    if (!empty($legacyRows) && !empty($modernRows)) {
        // Compare column structure only — stable regardless of ordering,
        // pagination, or which rows each independent query returns.
        $this->assertSame(
            array_keys($legacyRows[0]),
            array_keys($modernRows[0]),
            'Modern-format rows must expose the same columns as legacy rows'
        );
    }
}
```

Compare **column structure**, not row values or whole row sets: the two calls are
independent queries and may legitimately differ in sort order, page window, or row
count, which makes value/row-set comparisons flaky. Every row of a given
`getJsonList()` output is column-homogeneous, so the first row is representative.

### Migrations

v1.2 introduces framework-managed migrations under
`database/migrations/framework/`. Installations whose database predates the
migration system must set a cutoff so the baseline epoch is skipped:

```
migration_cutoff = 2020_01_02_000000   # skips all 2020_01_01_* baseline migrations
```

Run `php vendor/bin/pramnos migrate:status` before `migrate` to confirm the set of
pending migrations matches your expectations.

### Validation checklist

- Every admin list view renders without a `DataTables warning` in the console.
- Authentication / login flow works.
- API smoke tests pass.
- Migrations applied cleanly on staging against the production DB engine.
- Background jobs process successfully.

---

## v1.0 → v1.1

v1.1 centres on PostgreSQL compatibility, a pluggable cache layer, and security
hardening. It is largely additive; the actions below are the ones most upgrades
need.

Full reference: [v1.1 release notes](version-history/posts/2026-04-19-v1-1-release.md).

### Breaking changes / required actions

| Area | Change | Action required |
|---|---|---|
| **`app.php` config** | The application config gained `migration_cutoff` and an `features` array (plus `csp` and `auth` blocks). | Add the keys below to `app/app.php`. |
| **CSRF** | CSRF protection was rewritten with session fingerprinting. | Ensure forms/AJAX send the current token; clear sessions on deploy if tokens were cached. |
| **Content-Security-Policy** | CSP headers with nonce injection are emitted for inline scripts/styles. | Move inline `<script>`/`<style>` to nonce-aware output or enqueue them; declare allowed external hosts in `app.php` → `csp`. |
| **PostgreSQL** | Schema-qualified table names and corrected NULL comparisons. | If targeting PostgreSQL, review raw SQL for unqualified names and `= NULL` comparisons. |
| **Cache** | Cache layer became pluggable (File/Memcache/Memcached/Redis). | Select and configure a cache adapter explicitly. |

### `app.php` configuration

Upgrading applications must extend `app/app.php` (the array returned from that file)
with the keys the framework now reads during `Application::init()`.

#### `migration_cutoff` — skip legacy baseline migrations

Framework baseline migrations carry a deliberately old timestamp epoch
(`2020_01_01_*`). An **existing** installation already has those structures via its
own historical migrations, so it must tell the migration runner to skip everything
before a cutoff — otherwise the baseline migrations would try to re-create tables
that already exist:

```php
// app/app.php
/**
 * Migration cutoff date. Migrations before this date are ignored.
 * Used to skip legacy baseline migrations when upgrading an existing project.
 */
'migration_cutoff' => '2026-01-01 00:00:00',   // any datetime after the 2020_* epoch
```

- **Fresh install:** omit `migration_cutoff` — the baseline migrations run and build
  every table from scratch.
- **Existing install (the upgrade case):** set `migration_cutoff` to any datetime
  after the `2020_*` epoch. The runner silently skips all pre-cutoff framework
  migrations and touches only your application's own, already-applied migrations.

Verify the resulting plan before running anything:

```bash
php vendor/bin/pramnos migrate:status   # confirm baseline migrations show as skipped
php vendor/bin/pramnos migrate          # apply the rest
```

#### `features` — active features

The `features` array declares which framework features are active for this
application. `FeatureRegistry::loadFromConfig()` reads it during init; each enabled
feature contributes its service provider, its framework migration sub-directory
(`database/migrations/framework/<feature>/`), and its default nav items. `core` is
always enabled implicitly and never needs to be listed.

```php
// app/app.php
'features' => [
    'auth',
    'authserver',
    'messaging',
    'queue',
],
```

Available feature keys:

| Key | Enables |
|---|---|
| `core` | Core framework — always active (implicit) |
| `auth` | Users, sessions, 2FA, GDPR — **and the permission store** (roles, user_roles, permissions) |
| `authserver` | OAuth 2.0 authorization server; builds on the `auth` permission store |
| `messaging` | Messaging system (threads and recipients) |
| `queue` | Background job queue |
| `cache` | Cache system (PSR-16; array/file/redis/memcached adapters) |
| `mcp` | MCP server (AI-assistant integration via stdio) |
| `debug` | DebugBar — HTML toolbar injected when `APP_DEBUG=true` |
| `devpanel` | DevPanel — web-accessible developer/admin dashboard |
| `broadcasting` | Real-time event dispatch (null/log/pusher/reverb drivers) |
| `webhook` | HMAC-verified git webhook receiver |

!!! warning "Features gate framework migrations"
    A framework migration directory named after a feature runs **only** when that
    feature is listed. If you enable a feature after the initial upgrade, run
    `migrate` again so its migrations are applied. Directories that are not a known
    feature key always run regardless.

#### `csp` and `auth` blocks

- **`csp`** — declare the external hosts your pages legitimately load from, so the
  new Content-Security-Policy headers do not block them:

  ```php
  'csp' => [
      'script-src'  => ['https://cdn.jsdelivr.net'],
      'style-src'   => ['https://fonts.googleapis.com'],
      'font-src'    => ['https://fonts.gstatic.com', 'data:'],
      'img-src'     => ['https://*.tile.openstreetmap.org'],
      'connect-src' => ['https://maps.googleapis.com'],
  ],
  ```

- **`auth`** — legacy applications whose stored passwords are MD5 hashes must opt in
  explicitly; the framework transparently rehashes to bcrypt on the next successful
  login:

  ```php
  'auth' => [
      'legacy_md5'   => true,   // default false — enable only for legacy apps
      'auto_upgrade' => true,   // default true  — upgrade MD5 → bcrypt on login
  ],
  ```

### Validation checklist

- `migrate:status` shows the baseline migrations as skipped (existing installs).
- Every declared feature's provider and nav items load without error.
- Forms and AJAX POSTs succeed (CSRF token accepted).
- No CSP violations reported in the browser console for first-party assets.
- Legacy MD5 logins succeed and rehash to bcrypt.
- Database queries behave identically on your target engine.
- Cache reads/writes hit the configured backend.

---

## Post-upgrade observability

For 24 hours after any production upgrade, watch:

- Application error logs for new fatal/exception signatures.
- Browser consoles on admin list pages for `DataTables warning` messages.
- Slow-query and migration timing on the primary database.
- Background-job success/failure rates.

Keep the rollback plan ready until the above are clean.
