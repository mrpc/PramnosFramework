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
        <bootstrap class="Pramnos\Framework\Testing\ProcessStateIsolation"/>
        <bootstrap class="Pramnos\Framework\Testing\RequestIdentityIsolation"/>
        <bootstrap class="Pramnos\Framework\Testing\DocumentIsolation"/>
        <bootstrap class="Pramnos\Framework\Testing\GateIsolation"/>
        <bootstrap class="Pramnos\Framework\Testing\ServerGlobalIsolation"/>
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

One line **after** each field. The field itself does not change:

```php
<label for="password">Password</label>
<input type="password" name="password" id="password" autocomplete="current-password" required>
<?php echo \Pramnos\Html\PasswordToggle::render('password'); ?>
```

Four things to know:

- **The field needs an `id`.** The control addresses it by id, and so does `<label for>`. A field
  without one has no programmatic label either, so add the id and wire the label at the same time —
  in the framework's own scaffolds, eight fields across three themes turned out to be missing both.
- **It goes after the input, not in the label row above it.** With no `tabindex` anywhere on the form,
  tab order *is* document order: a control placed above the field means tabbing off the username lands
  on the toggle instead of on the password box. The control renders as an eye and its own script moves
  it inside the field's right-hand edge, so it *looks* like it is in the field while staying after it
  in the document. `tabindex="-1"` is not the fix — it makes the control mouse-only.
- **Pass no class.** The button styles itself for position; a theme's `btn` brings padding, a border
  and a background that fight it. The `$class` argument is there for a caller that wants to take over,
  and the scaffolded views pass nothing.
- **The labels default to translated «Show password» / «Hide password»**, and they become the
  accessible name (`aria-label`/`title`) rather than visible text. Pass your own if the screen is in a
  language your catalogue does not cover — the second and third arguments to `render()`.

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
            . ' aria-label="' . $e($showLabel) . '" title="' . $e($showLabel) . '"'
            . ' data-show-label="' . $e($showLabel) . '" data-hide-label="' . $e($hideLabel) . '">'
            . self::EYE . '</button>'
            . '<script>(function(){'
            . 'if(window.__passwordToggleBound){return;}window.__passwordToggleBound=true;'
            . "var S='[data-password-toggle]';"
            . 'function place(b,f){var w=f.parentNode;'
            . "if(!w||w.getAttribute('data-password-wrap')===null){"
            . "w=document.createElement('span');w.setAttribute('data-password-wrap','');"
            . "w.style.position='relative';w.style.display='block';"
            . 'f.parentNode.insertBefore(w,f);w.appendChild(f);}'
            . 'w.appendChild(b);'
            . "b.style.cssText='position:absolute;top:50%;transform:translateY(-50%);right:.55em;"
            . "display:inline-flex;align-items:center;padding:.2em;margin:0;border:0;"
            . "background:transparent;color:inherit;opacity:.6;cursor:pointer;line-height:1';"
            . "f.style.paddingRight='calc(1.15em + 1.1em)';}"
            . 'function reveal(){document.querySelectorAll(S).forEach(function(b){'
            . "var f=document.getElementById(b.getAttribute('aria-controls'));"
            . 'if(f){place(b,f);}b.hidden=false;});}'
            . "document.addEventListener('click',function(ev){"
            . 'var b=ev.target.closest?ev.target.closest(S):null;if(!b){return;}'
            . "var f=document.getElementById(b.getAttribute('aria-controls'));if(!f){return;}"
            . 'var s=f.selectionStart,e2=f.selectionEnd,show=f.type==="password";'
            . 'f.type=show?"text":"password";'
            . "b.setAttribute('aria-pressed',show?'true':'false');"
            . "var l=show?b.getAttribute('data-hide-label'):b.getAttribute('data-show-label');"
            . "b.setAttribute('aria-label',l);b.setAttribute('title',l);"
            . 'f.focus();if(s!==null&&f.setSelectionRange){try{f.setSelectionRange(s,e2);}catch(x){}}'
            . '});'
            . "if(document.readyState==='loading'){"
            . "document.addEventListener('DOMContentLoaded',reveal);}else{reveal();}"
            . '})();</script>';
    }

    private const EYE = '<svg aria-hidden="true" focusable="false" width="1.15em" height="1.15em"'
        . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
        . ' stroke-linecap="round" stroke-linejoin="round">'
        . '<path d="M1.8 12S5.4 5.4 12 5.4 22.2 12 22.2 12 18.6 18.6 12 18.6 1.8 12 1.8 12Z"/>'
        . '<circle cx="12" cy="12" r="3.1"/></svg>';
}
```

This shortened copy keeps one eye for both states, which is the corner worth knowing you cut: the
framework's own swaps to a crossed-out eye so the icon and the accessible name agree.

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

### Three more attributes, on the same screens

Invisible on a desktop, which is why they go missing. All three are one attribute each and none of
them changes what the form submits:

```php
<!-- The username: iOS capitalises the first letter and autocorrects the word. A username that
     changed silently is, to the person typing it, a wrong password. -->
<input type="text" name="username" id="username" autocomplete="username"
       autocapitalize="none" autocorrect="off" spellcheck="false" required>

<!-- A six-digit code: `pattern` validates the value and does nothing to the keyboard. -->
<input type="text" name="code" id="code" autocomplete="one-time-code"
       inputmode="numeric" enterkeyhint="go" pattern="[0-9]{6}" maxlength="6" required>

<!-- The last field a person types into, so the keyboard's action key submits. -->
<input type="password" name="password" id="password" autocomplete="current-password"
       enterkeyhint="go" required>
```

`spellcheck="false"` earns its place separately from the other two: the red underline invites a person
to «fix» a username that was right.

!!! warning "Do not script this with a tag-matching regex"
    `<input\b[^>]*?>` is wrong for a PHP template, and the mistake is expensive: an attribute value
    here routinely contains `<?php echo … ?>`, whose `>` ends the match early — so the «tag» is a
    fragment, and an attribute appended to it lands in the middle of PHP code. Doing exactly that
    broke about a hundred of the framework's own scaffold views in one pass.

    Insert **straight after an attribute you know is in the tag**, `autocomplete="…"` being the
    obvious one. No brackets to find, nothing to parse, and it cannot corrupt a file:

    ```python
    src.replace('autocomplete="username"',
                'autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"')
    ```

### Two things the form should say: that it failed, and that it is working

Neither is visible on the machine of the person who builds the screen, and one of them is the only
accessibility item of the eight.

**Announce the failure.** An `alert alert-error` box is a red rectangle to anybody who can see it and
nothing at all to anybody who cannot. Two changes, no JavaScript:

```php
$errorFieldAttributes = $errorText !== ''
    ? ' aria-invalid="true" aria-describedby="form-error"'
    : '';
```

```html
<div role="alert" id="form-error" class="alert alert-error">Wrong username or password.</div>
…
<input type="text" name="username" id="username" autocomplete="username"
       <?php echo $errorFieldAttributes; ?> autofocus>
```

Do not stop at `role="alert"` and count it done. A live region is announced when it **changes**, and a
server-rendered error has been in the document since before the page existed — it never changed, so
most screen readers say nothing. The description is the part that works: the message is read out as
part of the field the moment focus lands on it, and focus lands there on load because the first field
carries `autofocus`.

Mark the **first** field only. These errors are form-level — «wrong username or password» is about the
pair — and marking four fields invalid to report one failure tells a screen reader four things that are
not true.

While you are in there, the rest of the boxes: `alert-error` and `alert-danger` want `role="alert"`,
which interrupts. `alert-info`, `alert-success` and `alert-warning` want `role="status"`, which waits
for the next pause. A sweep that puts `role="alert"` on everything makes the page worse, not better —
and watch for a `role` that was already on the tag *after* `class`, which is how you end up with two
of them on one element and no error from anything.

**Acknowledge the submit.** With the current framework, mark the form:

```html
<form method="POST" action="…/login" data-pf-progress>
```

and make sure the page loads `assets/js/pf-auth.js` — two of the framework's own auth views had every
other attribute right and no script tag at all, which looks correct in a diff and does nothing in a
browser.

Without upgrading, this is the whole behaviour:

```js
document.querySelectorAll('form[data-progress]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        // A tick later, so every other submit listener on this form has already run.
        setTimeout(function () {
            if (event.defaultPrevented) { return; }
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"], input[type="submit"]')
                .forEach(function (b) {
                    b.textContent = (b.textContent || '').trim() + '\u2026';
                    b.disabled = true;
                });
        }, 0);
    });
});
```

The deferral and the `defaultPrevented` check are the whole design, and skipping them is how this
becomes a bug rather than a fix. A submit listener that prevented the default did one of two opposite
things — **refused** the submit, in which case disabling the button hands somebody a form that can
never be submitted; or **held** it while something finishes, in which case that hold is exactly when
the second press happens. Nothing in the event tells them apart, so skip both, and have whatever is
holding mark the form itself.

Do not reach for a spinner class from your CSS framework unless every page that loads this script has
that framework. An indicator only one theme styles is an invisible indicator; `disabled` plus an
ellipsis works everywhere and needs no stylesheet.

### Verify it, once, rather than screen by screen

The check that keeps this from decaying is a test that reads your views and fails when a password
field has no toggle beside it. The framework ships exactly that for its scaffolds
(`tests/Unit/Html/ScaffoldPasswordFieldsTest.php`) — copy it and point it at `src/Views`. It asserts
four things:

- there are password fields to find at all, so a wrong path cannot read as «everything complies»;
- every one has an `id`;
- every one has a toggle addressed to **its** id;
- every toggle points at a field that exists — the mistake a rename makes, where the field becomes
  `new_password`, the toggle still says `password`, and the control renders and addresses nothing;
- and every toggle comes **after** its field, because with no `tabindex` on the form tab order is
  document order.

Two more in the same directory are worth copying with it: `ScaffoldSignInPracticesTest.php` for the
mobile-keyboard attributes and the 16px floor, and `ScaffoldFormFeedbackTest.php` for the roles and the
error wiring above. All three read your view sources and name the file and the field; none of them
needs a browser or a database.

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

## Since v1.2 — unreleased

**Read this section if you track `dev-main`.** Most applications on this framework do:
`"mrpc/pramnosframework": "dev-main"` in `composer.json`, and the framework's own
instructions are to `composer update`. So an upgrade crosses whatever landed since the tag,
and until this section existed the only record of a behaviour change was a dated changelog
post among dozens.

That gap is not theoretical. A mobile application failed the day before a municipal
presentation on a payload it had been sending unchanged for months, and the change behind it
had shipped a month after the v1.2 tag, filed under `Fixed:` in a daily post. **"Breaking"
and "fix" are orthogonal labels**, and filing under one had been excluding an item from the
other by construction.

This table is appended to when a change lands, and becomes the `v1.2 → v1.3` section when
the tag is cut — assembled as it happens rather than reconstructed from a month of posts.

| Area | Change | Action required |
|---|---|---|
| **JSON request bodies** | `Request::decodeBody()` decodes associatively, arrays all the way down. It was `(array) json_decode($raw)`, which cast only the top level, so every element of a nested list arrived as an `stdClass`. | Any handler that reads an element of a JSON body with `->` — a nested list especially — now receives an array. Convert the access, or normalise at the boundary. |
| **`QueryException` messages** | `getMessage()` is a short sentence that names nothing; the driver's text and the SQL moved to `getDriverMessage()`, `getQuery()` and `getDetail()`. `Model::_save()` raises one instead of a plain `\Exception`. | If you log `$e->getMessage()`, log `$e->getDetail()`. If you *echo* it to a client, you were echoing your schema — that is the point of the change. |
| **Controller resolution** | `Application::getController()` throws `ControllerNotFoundException` and no longer puts the request URI or the signed-in username in the message. | An `Api::exec()` override that matched `'Cannot find controller:'` on the message should catch the type. Read the context from `getContext()`. |
| **OAuth2 `redirect_uri`** | `/oauth/authorize` refuses a `redirect_uri` that disagrees with the client's registered `applications.callback`, matched exactly. A client with *nothing* registered is unaffected. | Register the callback each client actually sends, character for character. Several are allowed, separated by commas, spaces or newlines. |
| **Migrations** | `migrate` refuses to run ten or more migrations when the ledger is empty and the database already has tables; `Application::upgrade()` does the same rather than auto-running a history in a web request. | Nothing, on an installation with a recorded history. On one whose history moved, `migrate:adopt-legacy --dry-run` first — `migrate` now adopts a version-keyed ledger on its own. `migrate --adopt-baseline` overrides. |
| **`queue:health` output** | `--json` gained a `truncated` key, and the prose output a caveat line when the window reaches past the oldest surviving row. `schedule:list` gained trailing lines about whether anything executes the schedule. | Nothing for a person. A monitor that matches the whole of either command's output rather than the fields it needs should read `truncated` / the `overall` object instead. |
| **`Application::startMaintenance()`** | Gained a second parameter, `$origin`, so `maintenance:on` can distinguish an operator's decision from an automatic one. **A signature change, not a behaviour one** — PHP refuses to load a subclass whose declaration no longer matches. | Fatal at class load, before a route is matched, for any application whose own `Application` overrides `startMaintenance()`: grep for `function startMaintenance` and either delete the override or add `$origin = self::MAINTENANCE_AUTOMATIC` to it. |
| **Two-factor administration** | `TwoFactorAuthService::disable()` and `regenerateBackupCodes()` take a **required** second argument, `string $password`. | Every existing call breaks with `ArgumentCountError` — it is a caller break, not only a subclass one. Pass the password the user just re-entered; there is no way to skip the step-up, which is the point of the change. |
| **Flash message markup** | `Base::_printErrors()` / `_printMessages()` default to CSS class `error` / `message` instead of `pramnosError` / `pramnosMessage`. | Any theme styling `.pramnosError` or `.pramnosMessage` stops applying. Either restyle to the new class or pass the old one explicitly: `_printErrors('pramnosError')`. |
| **Methods that gained a parameter** | `CommandBase::heartbeat()`, `QueryBuilder::count()`, `Database::getColumns()`, `Database::logCacheHit()`, `Route::matches()`, `ScheduledTask::withoutOverlapping()`, `SchemaGrammar::compileCreateIndex()` (and its interface), `User::cleanupAllAuthTokens()`, `Validator::validateMax()` / `validateMin()` / `validateSize()`, `LogViewer::renderViewer()`, `LogViewerView::render()` / `getBody()`. All the new arguments are optional. | Nothing for a caller. **Fatal at class load for a subclass that overrides one** — PHP compares declarations, and optional is still a declaration. Grep your application for `function heartbeat`, `function matches`, `function count`, `function validateMax` and the rest of the list, then either delete the override or copy the parent's new parameter list verbatim. A custom `SchemaGrammar` must take `?string $where = null`. |
| **Methods whose return type changed** | `ScheduledTask::run()` and `CommandBase::heartbeat()` return `bool` instead of `void`; `Blueprint::index()` / `unique()` return `IndexDefinition`; `TokenActionsController::stats()` returns `mixed`; `UsersController::tokens()` returns `void`. | Same class of break as above, and it cannot be fixed by adding an argument: an override declaring `: void` where the parent now says `: bool` will not load. Return the value the parent's docblock describes — for `run()` and `heartbeat()` that is whether the work succeeded. |
| **Controller actions accept `mixed`** | `ServicesController::start()` / `stop()` / `restart()` / `logs()` and `LogController::clearFile()` take `mixed $name = null` where they took `string $name = ''`. | Nothing for a caller — the type widened. A subclass that still declares `string` is **narrowing** the parameter and will not load; widen it to `mixed` to match. |
| **Where a scaffolded project's tests run** | `TestEnvironment::setup()` now loads `app/config/testsettings.php` into `Settings` and brings that database's schema up to date. Until now it built `<db>_test` and told nothing to use it, so `BaseTestCase::setUp()`'s `init()` fell back to `app/config/settings.php` and **the suite ran against the development database**. | Your tests now hit `<db>_test`, which starts empty and is migrated on first run. Anything that depended on development data being there will fail — which is the point: the Testing Guide's own advice (factories, seeders, `truncate()` in `tearDown()`) was deleting it. Seed what the tests need. |
| **PostgreSQL session variables** | `app.userid` is now set to `guest` for an anonymous request. It was never set at all: the statement the framework emitted for a null userid — `SET app.userid = NULL` — is not PostgreSQL grammar, so it was refused on every unauthenticated request. | A query that filters `current_setting('app.userid', true) IS NULL` to mean "anonymous" now matches nothing — compare with `'guest'` instead. If you log or alert on the syntax error in the PostgreSQL log, it stops. |
| **`project:switch-ui` and the palette** | It no longer overwrites `app/themes/theme.css`. It used to write the scaffold's template over it and then generate `theme-tokens.css` from the template, so switching UI framework replaced the project's colours with the framework's default blue and renamed both of its themes. | Nothing to do. If you ever ran `project:switch-ui` and your colours reverted, this is why — restore the palette from version control and run `pramnos theme:build`. |
| **Theme colours on `bootstrap` and `plain-css`** | `theme:build` now writes the palette a second time, aliased onto `--bs-*` and onto the plain-CSS theme's own names, so both of those themes follow `app/themes/theme.css` instead of their framework's stock colours. A `prefersdark` theme reaches them too, through the file's existing `prefers-color-scheme` block. | Run `pramnos theme:build`, then look at the site. **The colours will change** if your palette is not Bootstrap blue, and a project on either theme will follow the visitor's OS into dark mode. To keep it light, remove `prefersdark: true` from the palette or set `data-theme` on `<html>`. The `tailwind` theme is unaffected. |
| **`theme:build` output** | `ThemeTokens::parse()` strips CSS comments before reading `app/themes/theme.css`, so a `/* … */` inside a `@plugin` block no longer becomes a key in `www/assets/theme-tokens.json` (and no longer swallows the declaration after it). | Nothing at runtime — the keys that disappear were never valid tokens. **Run `pramnos theme:build` once after upgrading**: the regenerated outputs differ — from this and from the alias block in the row above — so `theme:build --check` fails in CI until they are committed. |
| **Developer tools** | `/adminer` and `/debugbar` now honour `usertypes` / `userids` lists, each falling back to `devpanel`'s. | Nothing — an absent list widens nothing and every floor default is unchanged. Worth reading if you had raised a floor out of reach to close a tool: naming people is now narrower than that. |
| **API signing key, outside an API request** | `Api::deriveAuthenticationKey()` derives from `Api::baseUrl()` — the API's own base — instead of whatever `sURL` the running front controller has. Inside an API request the key is **byte-for-byte unchanged**; outside one (`SessionExchange`, `mcp:token`) it is now the key the API verifies with rather than one derived from the site root. | Nothing for existing API tokens — they still verify. Tokens minted by `SessionExchange` or `mcp:token` before this were refused with `403 InvalidAccessToken`; re-mint them and drop any local workaround that set `$app->authenticationKey` by hand. A project serving its API from somewhere other than `www/api/` sets `'api' => ['directory' => '…']` in `app.php`. |
| **`robots.txt` and `llms.txt`** | Scaffolded for **every** project, not only an authserver, and the wrapper controller the rewrites point at is now generated — both addresses answered 404 before. The `Sitemap:` line is emitted only when a file is actually served at `/sitemap.xml` (or `sitemap_url` is set); the docs and MCP addresses in `llms.txt` are derived instead of hard-coded. | Nothing for an existing project — `init` writes these, and it does not run again. To gain them, add `src/Controllers/MachineReadable.php` extending `Pramnos\Auth\Controllers\MachineReadable` and the two `RewriteRule` lines to `www/.htaccess`. If you relied on the old `Sitemap:` line, set `sitemap_url` or serve a sitemap. |
| **A failed scheduled command's message** | `ScheduledTask` runs the command through a pipe instead of `passthru()`, so the last 2 KB of its output — stdout and stderr merged — is appended to the `RuntimeException` as ` Output: …` and reaches `schedule.log`. | Nothing for a person; this is the reason a failure was missing. A monitor that matches the *whole* of a `failed:` line should match the command name and status instead. An interactive `schedule:run` now sees the command's stderr on stdout. An application that overrides the `protected runShellCommand()` keeps the old message unchanged and does not capture anything — delete the override to gain this. |
| **The scaffolded `dockertest`** | `init` writes `phpunit_status=$?` after each PHPUnit branch and `exit $phpunit_status` at the end. The script used to exit with the coverage-report block's status, which is 0 when its condition is false — so a red suite reported success. | Nothing for a new project. **An existing project owns its own copy and `composer update` will not touch it**: check the last lines of your `dockertest` for `exit $phpunit_status` and add the two pieces if they are missing. Anything that ran the suite without reading the summary — a `&&` chain, a git hook, a CI step — was being told a failing suite was green. |
| **Deploy webhook responses** | `WebhookHandler::handle()` sends `202 Accepted` **before** running the commands, instead of `200`/`500` after them. GitHub allows a webhook ten seconds in total and does not retry, and a normal deploy takes four to six — two consecutive pushes were lost to a slow `git fetch`. | Nothing to configure; the deploy that was silently dropped now completes. **The delivery list is green whether the deploy succeeded or failed** — if you monitor deploys by watching for a red delivery, watch `webhook.log` instead, which records every command and its exit code on both paths. To keep the old contract: `$handler->respondFirst(false);`. |
| **The SMTP password on `/admin/Settings`** | The field no longer renders the stored password into `value=""` — it is encrypted at rest and was appearing in clear in an admin page's HTML. It submits empty to mean **keep what is stored**, and the placeholder says whether there is one. | Nothing for a person using the screen. **A script or test that posted the whole Email tab back and relied on `smtp_pass` round-tripping now leaves the stored value alone** — which is the intent, but check anything that cleared the password by submitting the form empty: clear it with `Settings::setSetting('smtp_pass', '')` instead. |
| **`/health/check` and a fourth status** | `HealthStatus` gains `notice` — "correct here, and would not be everywhere" — and it answers **200**, not 503. `session_storage` on local files was `degraded`, so a correct single-server installation answered 503 to its uptime monitor from the moment that check shipped. `health:check` exits 0 on a notice. | Nothing to configure; a monitor that was alerting on a working installation stops. **Anything parsing the report must tolerate a fourth value**: a `match` on `$check['status']` without a `default` now throws, and `status === 'ok'` as a health test should become `HealthStatus::from($s)->isHealthy()`. A custom check that wants the old behaviour keeps returning `degraded`. |
| **`session_storage` on local files** | Reports `ok` on a single server instead of `degraded`. It becomes `degraded` only when the application declares `'servers' => 2` or more in `app/config/app.php`; one is assumed. A handler the check does not recognise is `notice`. | **Declare `'servers'` if you run more than one node**, or this check will not tell you that local session files have stopped being correct. Nothing to do on one server — a working installation stops being reported as a fault. |
| **`ClientResponse` header names** | The constructor lowercases them. `header()` always looked them up lowercased and the live path happened to build them that way, so the only responses affected were hand-built ones — every fake in every test. `ClientResponse::make('', 302, ['Location' => …])` stored `Location` and answered `header('Location')` with `''`. | Nothing for a caller of `header()`, which is case-insensitive and now actually works on fakes. **Code iterating `headers()` and matching a key by its original case** — `$h['Content-Type']` — must lowercase the key. |
| **`api:docs` with nothing to describe** | Exits non-zero and **writes nothing** when the scan finds no operations, instead of overwriting the document with an empty one and exiting 0. | A CI step or deploy script that ran `api:docs` over an application whose routes are registered on the router now fails instead of silently destroying the document. Pass `--routes=src/Api/routes.php` so the routes are read, or `--allow-empty` if the API really has none yet. |
| **`session_exchange` in the activity log** | Written once per session instead of once per exchange. A SPA asks for one on every cold load, so the log filled with entries nobody can act on and the ones they can drowned in them. | Nothing to do. A report that counted `session_exchange` rows as a measure of traffic now counts sessions instead — which is almost certainly what it meant. |
| **Second factors with no mail** | While `smtp_host` is empty (`Email::isConfigured()` is false), nothing asks for a mailed code or a mailed link. `EmailSecondFactor::isAvailable()` is false, so the email factor is not offered and an account that enabled it is not asked for it. `require_second_factor_from_usertype` demands nothing of an account with no factor. `auth_newsignin_action` `authlink` resolves as `require_2fa`, and `require_2fa` falls back to the passkey, or to nothing. These all used to demand a code that could not be sent — a lockout with no way out. | Nothing on an installation with SMTP configured. **Without it, an administrator with no factor now signs in with a password**: set `require_factor_enrolment_from_usertype` to the same number as the floor and register `RequireFactorEnrolmentMiddleware`, so the account is held at the setup screen until it enrols an authenticator or a passkey. |
| **Cookie consent switched off** | With `cookie_consent_enabled = 0` or `'cookie_consent' => false`, `CookieConsent::tag()` emits the script configured `{"enabled":false}` instead of `''`, and `pf-consent.js` on that configuration grants every optional category — `has()` is `true`, `type="text/plain"` scripts are released, `onGrant()` runs, Consent Mode gets an all-granted `update`. It used to release nothing, so a gated script never ran. | **Scripts gated with `data-consent` now run on a site with the banner off.** If you relied on switching the banner off to stop them, remove the tags instead. A test asserting `tag()` returns `''` when off should assert `{&quot;enabled&quot;:false}` in it. |
| **SQL result cache keys** | A cached query's prefix is the `cache` installation prefix, the table prefix and the connection's database and schema, instead of the table prefix alone — two databases behind one Redis shared an entry per statement, rows included. | Nothing through `Database::cacheflush()`. Cached entries miss once after upgrading. **Code that matches or deletes `<category>_<md5>.sql` keys directly**, such as a test bootstrap clearing them, no longer finds them — call `cacheflush($category)` instead. |
| **Mass messages filtered by organization** | The `organizations` criterion reads `userid` from the membership table instead of `user_id`, which the table does not have — so it had matched nobody on every installation. | A saved or scheduled message with an organization filter **now reaches that organization's members**. Review any that were created while it matched nobody before they send; the count on the compose screen now shows who they will reach. |
| **`TestClient` PUT, PATCH and DELETE** | The data is only in the request body, as in a real request. It was also copied into `$_POST` and `$_REQUEST`, which PHP never does for those methods. | A test of yours that now fails on a PUT/PATCH/DELETE was passing against a handler that reads `$_POST` — and that handler receives nothing in production. Read the body instead (`$request->body()`, or `Request::rawBody()`). |
| **The mail outbox is sent** | `mail:flush` is a framework schedule, every minute. Nothing ran it before, so every message written by `Email::queue()` or a queueable notification — the security alerts included — stayed `queued` and was never sent. | Nothing to configure. On the first run, outbox rows younger than `mail.outbox.deadline` (24 hours by default) **are sent**, and older ones are marked failed with the reason rather than delivered late. If another process already sends the outbox, `FrameworkSchedule::disable('mail:flush')`. |
| **Every issued token carries a `jti`** | The claims were `iss`, `aud`, `iat`, `nbf`, `exp` — none of which names the user — so two tokens minted in the same second were the same string and the second insert failed on `usertokens.token_lookup`. Both minting sites now add 16 random bytes as `jti`. | Nothing: existing tokens keep verifying and nothing reads the claim. Tokens are ~45 characters longer, so a column or a client buffer sized exactly to the old length needs checking. If you have been seeing sign-ins that silently did nothing, or a SPA bouncing to the login page on a second tab, this was it. |
| **The tailwind theme's global border rule** | Wrapped in `@layer base`. Unlayered, it beat every daisyUI component regardless of specificity, so components that set no border still drew one. | Look at a server-rendered page on the `tailwind` theme: outlines disappear from `btn-ghost`, inputs and cards. If your own `style.css` has blanket selectors (`html *`, `*`), layer them too. A project that had compensated with `!important` somewhere can drop it. |
| **Generated SPA form markup** | `Field.svelte`, `ConfirmDialog.svelte` and the Svelte sign-in screen use daisyUI 5's `fieldset` shape instead of v4's `form-control` / `label-text`, which style nothing in the pinned version. | New scaffolds get it. An existing project's copies are its own files: replace the `<div class="form-control">` wrapper with `<fieldset class="fieldset w-full">`, drop the `label-text` spans, and add `w-full` to inputs. A component test that queried `.form-control` needs the new selector; one using `getByLabelText` does not. |
| **`timescale:ensure` and `HypertableRegistry::apply()`** | Every step is read back from the catalogue and **raises** when the database does not show it. It used to print a tick regardless, so three failed calls produced three ticks and exit code 0. | **Run `timescale:ensure` once after upgrading.** If it now reports errors, those tables have never been hypertables — the ticks were wrong, not the errors. A migration that calls `apply()` will now fail instead of silently doing nothing, which is the point. An integer time column must declare its intervals as numbers (`604800`, not `'7 days'`); the refusal names the column. |
| **Continuous aggregates on an older TimescaleDB** | `createContinuousAggregate()` falls back to a plain materialised view with the same columns when TimescaleDB refuses the aggregate (`percentile_cont`, `COUNT(DISTINCT)` — refused at 2.26.4, accepted at 2.30.0), and the refresh policy follows what the view is rather than what the server has. | Nothing to do; three framework migrations that failed permanently now succeed. On such a host those views refresh through the PolicyEngine daemon, so make sure it is running — `schedule:list` says whether anything executes the schedule. |
| **`migrate` and the TimescaleDB extension** | An application with `'timescale' => true` in its database settings now has `CREATE EXTENSION IF NOT EXISTS timescaledb` attempted before the first migration — and **the run stops** with instructions when it cannot be created. | If your role is superuser (Docker, most development), nothing changes except that the extension appears. On managed hosting where it cannot, `migrate` now refuses instead of recording guarded migrations as applied: set `shared_preload_libraries = 'timescaledb'`, restart, `CREATE EXTENSION timescaledb;` as a superuser, and run again. **An application that is not meant to use TimescaleDB must remove the flag** — it also selects the SQL grammar, so it was never a no-op. |
| **`has(TIMESCALEDB)` asks the database** | The `'timescale' => true` setting no longer short-circuits the capability probe; `pg_extension` decides. | Nothing where the extension is installed. Where it is not, code guarded on the capability now correctly takes the other branch instead of running and failing three layers down — which is the point, and may surface migrations that were silently doing nothing. |
| **Migrations that no-op for a missing capability** | An `ifCapable(…)` with no fallback that finds the capability absent now makes the migration **decline** instead of recording it as applied, so it is retried on the next run. | **Run `migrate` once after upgrading.** On MySQL and plain PostgreSQL the TimescaleDB-only migrations will report `declined` rather than `ran` — that is the new, honest answer and costs a cheap retry per run. On a host that gained TimescaleDB after its first migrate, those conversions will now actually run; check `migrate:status` before and after. |
| **A new default health check** | `hypertables` is registered by default: it reports a declared hypertable that is an ordinary table, and a continuous aggregate that fell back to a plain materialised view, as **degraded**. | Nothing to do — but a monitor that alerts on any non-`ok` check may go yellow on a database that was already in this state and nobody knew. That is the point; the check did not invent the state. `down` is never returned for either. |
| **`primaryKeyColumns()` on PostgreSQL** | Returns the columns in **key order**. It joined `pg_attribute` on `attnum = ANY(i.indkey)` with no `ORDER BY` — a membership test, which says nothing about order — so a composite key came back in whatever order the plan produced. Right by accident on PostgreSQL 14, reversed on 17. | Nothing to do, and worth knowing if you built anything on the old order: a `WHERE` clause or a chunk boundary assembled from a composite key was wrong on PostgreSQL 15+. Single-column keys are unaffected. |
| **The scaffolded `docker-compose.yml`** | TimescaleDB is pinned to `2.26.4-pg17` instead of `latest-pg17`, so development is not ahead of the oldest host the framework supports. | Only new scaffolds. An existing project that wants the same guarantee edits its own compose file; one that knows its hosts are newer can pin higher instead. |
| **The scaffolded `.gitignore`** | `!/app/keys/public.key` and `!/app/keys/vapid_public.key` are gone: half of a pair is environment-specific, public or not. | Only affects new scaffolds. **If your repository has `app/keys/public.key` in it**, `git rm --cached` it, delete the stale copy on every environment that did not generate its own, and let each generate a pair — a lone public key is why `health:check` says `signing_keys: DOWN` while every page works. |
| **The scaffolded `CLAUDE.md` controller example** | Registers actions with `addaction()` in the constructor instead of `public array $actions = [...]`, which is a fatal at autoload: `Controller::$actions` is untyped and PHP forbids a subclass adding a type. | Nothing regenerates. **`grep -rn 'array $actions' src/`** in your project: any controller that has it dies with `Type of …::$actions must be omitted` the moment something calls `class_exists()` on it. Drop the type, or move the list into `addaction()`. |
| **`create:command` output** | Writes into the directory `init` scaffolded (`src/ConsoleCommands/`, discovered), names the command `noun:verb` instead of `app:<concatenation>`, appends the registration to `src/Console.php`, and generates a test that asserts something. | Nothing for commands you already have — the generator discovers an existing `src/Console/Commands/` and keeps using it. New ones land beside `Daemons` and appear in `<cli> list` without a manual edit. `--command-name` overrides the derived name. |
| **`create:crud` output** | The model extends `OrmModel` (a subclass of the old base, so nothing it could do stops working); the tenant column is set from `ApiCrudController::currentOrganizationId()` rather than from the request; `$_SESSION['user']` is gone; nullable columns keep their null instead of passing it into `strip_tags()`. | Nothing regenerates on its own. **Re-read any `create:crud` controller you already have**: if it assigns a tenant column from `Request::staticGet()`, that is a cross-tenant write and this is the fix. Override `currentOrganizationId()` when a user can belong to more than one organisation — the default returns null rather than guessing. |
| **`create:crud` search registration** | `display` is now the table's own text columns (it was always `['name']`, from a destructuring bug), and a `filter` scoping to the caller's organisation is emitted for a table with a tenant column. | Check `app/search.php` for a source registered against `name` on a table without that column: it has been finding nothing. Add the `filter` to sources registered before this, or the admin search box spans tenants. |
| **The tier `user:create --admin` grants** | `UserCreate::ADMIN_USERTYPE` is **99 (Root)** instead of 90, and the administrator `init` creates during scaffolding follows it. Every administrative screen the framework ships is reachable at either, so this is about which tier a fresh installation's owner is *given*: 99 is granted `*` rather than a list that has to be revisited when a capability is added above 90. | Nothing for existing accounts — no account's `usertype` is changed, and `--usertype=90` still produces exactly what `--admin` used to. Anything that branched on `=== UserCreate::ADMIN_USERTYPE` rather than `>=` now matches 99; the framework's own guards are all comparisons. |
| **A failed session-tracking write** | Logged and nothing else. Both dialect branches of `SessionTrackingMiddleware` — and `Addon\System\Session` — caught the exception and called `$session->reset()` and `$auth->logout()`, so any database error writing a tracking row signed the visitor out. | Nothing to do, and worth knowing if you have seen unexplained sign-outs: this was one cause of them. A revoked session still takes effect through `sessions.logout`, on the next request that reaches the database. |
| **`sessions.url` and `sessions.agent`** | `text` instead of `varchar(255)`, in the create-table and in a new migration for databases that already have them narrow. An OAuth callback carrying three scopes is over 400 characters, and PostgreSQL refused the whole insert. | **Run `migrate` once after upgrading.** Measured on 200,000 rows: **1.4 ms** on PostgreSQL (a catalogue change, no rewrite) and **711 ms** on MySQL (`ALGORITHM=COPY` is the only option, so the table is rebuilt and writes block). The table holds five minutes of visitors, so it is far smaller than that in practice. Nullability is preserved either way. **If an application has a view or materialised view selecting `sessions.url` or `sessions.agent`, PostgreSQL refuses the ALTER** — the migration declines and names the view rather than stopping the run; drop it, run `migrate`, recreate it. A report that assumed `url` fits in 255 characters now sees longer values: they were being lost before, not stored short. |
| **A bearer token on a `public_api_paths` route** | `ApiAuthMiddleware` still asks no key there, but now validates an `Authorization: Bearer` / `accessToken` if one is sent: a token that verifies makes its user the caller, one that does not seals the request anonymous. Without a token nothing changes. | Grep `public_api_paths`. An open endpoint that reads `User::getCurrentUser()` and is called with a token now sees the token's user — or nobody, where it used to see the website session. An application that claims `Authorization` for a scheme of its own in `configureApiPipeline()` should unset the header once it has read it. |
| **`/<version>/mcp` needs no API key** | Added to the public paths by `Api` itself, so a remote MCP connector reaches the controller's `401` instead of `403 APIKeyMissing`. | Nothing for the scaffolded MCP endpoint — it refuses an unauthenticated call on its own. An application with an unrelated route at `/mcp` in its API routes has just opened it; rename the route. |
| **OAuth discovery and the MCP `401`** | `registration_endpoint` pointed at `/register`, the sign-up page for people; it is now `/oauth/register`, and **absent** unless `'oauth_dynamic_registration' => true`. `/.well-known/oauth-authorization-server` gains `code_challenge_methods_supported` and `none`. The MCP endpoint's `WWW-Authenticate` names `/.well-known/oauth-protected-resource/<its path>` at the site's origin, not under the API's `sURL`. | To let Claude.ai or ChatGPT connect: set the flag in `app/app.php` and add `RewriteRule ^\.well-known/oauth-protected-resource/(.+)$ index.php?r=Discovery/oauthProtectedResource&resource_path=$1 [L]` beside the other well-known rules in `www/.htaccess` — without the rule the new `WWW-Authenticate` address is a 404. A client that followed the old `registration_endpoint` was receiving HTML. |
| **Codes from `/oauth/authorize`** | Issued by League's `AuthCodeGrant` — an encrypted payload, its row written through `token_lookup` — instead of a 64-character hex string the controller wrote itself. `/oauth/token` has always handed codes to League, which could not decrypt the hex, so **every code the authorization endpoint issued was refused** with `Cannot decrypt the authorization code`. | Nothing to change; the authorization-code flow now completes. A client that validated the code's length or alphabet must accept a long opaque string. Code that read `usertokens.code_challenge` for `auth_code` rows reads nothing now: the challenge travels inside the code. |
| **`resource` on the token request (RFC 8707)** | `/oauth/token` reads it: an absolute URI on this site's origin is added to the access token's `aud`; anything else is `400 invalid_target`. The MCP endpoint refuses a token whose `aud` names only other URLs. | A client that sent a `resource` pointing elsewhere was ignored and is now refused — drop it or point it here. Tokens without `resource`, and every token issued before, have no URL in `aud` and are accepted everywhere as before. |
| **A client that cannot keep a secret needs a redirect URI** | `/oauth/authorize` refuses a client with **no registered `callback`** when it is public (`is_confidential = 0`) or has no secret stored (`apisecret` empty). It used to accept any `redirect_uri` for it — and for such a client the registration is the only thing that stops a code leaving for somebody else's address. The application's page and edit form now warn about such a client. A confidential client with a secret is unaffected. | Open **Applications** in the admin area and look for the warning, or: `SELECT apikey, name FROM applications WHERE (is_confidential = 0 OR apisecret IS NULL OR apisecret = '') AND (callback IS NULL OR callback = '')`. For each, enter the exact callback URI(s) it uses; tick **Client Type** instead only if its secret really stays on a server. Until then its users see an authorization error. |
| **A token from `/oauth/token` authenticates API calls** | `ApiAuthMiddleware` and `UnifiedAuthMiddleware` look a verified bearer up by its `jti` when its full text matches no `usertokens` row, and `/oauth/userinfo` does the same after League's resource server has verified the token. `AccessTokenRepository` stores `/oauth/token` tokens by their `jti`, so every such token was verified and then left the call anonymous (`403 InvalidAccessToken` on a keyed route, `401` behind `UnifiedAuthMiddleware` or at the MCP endpoint). | Nothing to change. A client that fell back to a password-grant or session because code-flow tokens were refused can use them now. `User::loadByToken()` itself still matches only the full text — call it with the `jti` yourself only after verifying the signature. |
| **Erasing an account and `authserver.invitations`** | `Account::eraseUserData()` now calls `Invitations::forgetUser()`: the unaccepted invitations the account sent are deleted, accepted ones keep their row with `invited_by` cleared, and the invitation the account came from is deleted. | An application that erased invitation rows itself in an `account.data_erase` listener can drop that part; one that kept them on purpose (an audit of who invited whom) must copy what it needs before the erase. |
| **A continuous aggregate's first materialisation** | `createContinuousAggregate()` creates it `WITH NO DATA` and refreshes up to `now()`, so the open bucket is not materialised. It used to be (`WITH DATA`), which froze the open bucket of a real-time aggregate until it closed. Aggregates that already exist are not changed. | Nothing for a real-time aggregate — it now sees the open bucket, which is the fix. One with `materialized_only = true` no longer shows the open bucket's snapshot until its policy runs. To repair an aggregate created before this, drop and recreate it, or wait for the open bucket to close. |
| **`emailoptouts` and `mailing_list_subscribers` on the denial list** | `PersonalDataRegistry` withholds both from MCP `db-inspect`; `emailoptouts` holds addresses and was missing. The data export gains a **Mailing lists** section. | An MCP query that read `pramnos.emailoptouts` now gets it withheld — read opt-outs through `Unsubscribe::isOptedOut()` instead. A consumer that parses the export by position should read it by key. |
| **`massmessagerecipients.email` and `.language`** | Two nullable columns, added by `add_address_to_massmessagerecipients`, so a mass message can go to a mailing list's subscribers who have no account (`userid` 0). The dispatcher sends a row that carries an address through a new `deliverToAddress()`; rows without one go through `deliver()` exactly as before. | **Run `migrate`.** A report that counts recipients per account should expect `userid` 0 for address-only rows. An application that overrides `deliver()` is not called for list recipients — override `deliverToAddress()` too if it must see them. |
| **A deactivated role grants nothing** | `PermissionResolver` drops roles with `authserver.roles.is_active = 0`. It read only `user_roles.is_active`, so a role turned off on the Roles screen went on granting everything it held. Covers `Permissions::isAllowed()` and permission-granted admin screens. | Look for roles you deactivated and still rely on: `SELECT roleid, role_name FROM authserver.roles WHERE is_active = false`. Their members lose those permissions now — which the Roles screen always said would happen. Reactivate a role that should still grant. |
| **Saving an organisation** | `OrganizationsController::save()` changes `is_active` and `org_type` only when the form sends them; the bundled forms now do. Before, every edit deactivated the organisation and cleared its type. | Check for organisations deactivated by an edit rather than on purpose — `SELECT organization_id, name FROM organizations WHERE is_active = 0` — and reactivate them. An application's own copy of the edit view keeps working and stops changing either field; add the two fields to it to edit them. |
| **Admin actions that change state require a `POST` with the token** | Controllers declare them with `addWriteAction()`, and `exec()` refuses anything else before the action runs (a message and a redirect back, or `403` JSON). The bundled views post with `Icon::postButton()` / `postControl()`. | **An application with its own copy of an admin view** whose links (`<a href=…/delete/…>`) or forms lack the token will see "That request could not be verified" — make the link `Icon::postButton(...)` and give the form `Session::getInstance()->getTokenField()`. A script or test that called these actions with `GET` must `POST` with the token. |
| **A deny wins a priority tie, on every database** | `PermissionResolver` and `authserver.effective_permissions` compare with `>=`: the higher priority decides and a tie goes to the deny. PostgreSQL's trigger that added 1000 to a deny's priority on every insert and update is dropped, and migration `unify_authserver_deny_rule` restores the inflated rows with `priority % 1000`. On MySQL a deny and an allow at the same priority decided as an **allow**; on PostgreSQL an allow at 500 lost to a deny at 100. | **Run `migrate`.** Then look for the pairs whose decision changes. MySQL — ties that are now denies: `SELECT d.* FROM authserver_permissions d JOIN authserver_permissions a ON a.subject_type=d.subject_type AND a.subject_id=d.subject_id AND a.object_type=d.object_type AND a.action=d.action AND a.grant_type='allow' AND a.priority=d.priority WHERE d.grant_type='deny'`. PostgreSQL — allows that now override a deny: the same join with `a.priority > d.priority`. Raise or lower a priority where the new decision is not the one you want. A deny deliberately stored at 1000 or more on PostgreSQL comes back as its remainder. |
| **`permissions_changed` is sent for every change, and a role's event names no user** | Queued by every writer — role assignments, role edits and deactivation, organisation membership, `Permissions::allow()`/`deny()`, the Administration screens panel, the user page's grants — through `WebhookService::permissionsChanged()`. It came only from the Permissions screen, and there a role's event was queued for user `0`, which the events table's foreign key refuses. | A subscriber sees more of these events: handle `subject_type` `role` by dropping every holder's cache (or all of it). Code that reads the event row's `user_id` gets `NULL` for a role. An application writing grants itself should call `WebhookService::permissionsChanged()` after it. |
| **The Permissions screen** | The form has Priority, Application ID, Expires and Conditions, and `save()` writes them. It no longer offers "Group"; `save()` refuses any subject but `user` and `role`, conditions that are not a JSON object, and an expiry that is not a date. `Permissions/assign` is a write action (`POST` with the token). | An application's own copy of `permissions/edit` keeps working and leaves the three new columns as they are — `save()` writes each only when the form sends it. A copy that offers "Group" gets a refusal for it: grant to a role instead. To edit the new fields, add them to your copy or republish the view. |
| **Every framework admin screen opens at usertype 98** | `AdminAccess::defaultUsertype()` — the `admin_default_usertype` setting, 98 unless set — is the floor of every framework screen and its menu item; the controllers' `$requiredUserType` literals (80 or 90) and the menu's are replaced by it at construction, unless the application redeclared or set the property. Health and Logs, which had no floor of their own, are at it too. `init` writes `min_usertype => 98` for the area. Under `admin_access = mixed`/`permissions`, somebody below the area's floor whom a grant opens a screen to is let into the area. | **An administrator below 98 is refused every framework screen after the update.** Either set `admin_default_usertype` to `80` (every screen at 80 — Applications, Tokens, Permissions, Roles and Mass messages included, which were at 90) or `90`; or set `admin_access` to `mixed` and give those accounts a role that allows the screens they use (`AdminAccess::setDecisions('role', …)`, or the role's page). Find them: `SELECT userid, username, usertype FROM users WHERE usertype BETWEEN 80 AND 97`. A test signing in an administrator at 80 or 90 needs 98, or the setting. |
| **`UserTypes` capabilities are read from the screens** | `DEFAULT_CAPABILITIES` holds `99 => ['*']`, `1` and `0` only; every `admin.*` capability is added by `capabilityMap()` at the floor its screen applies, `admin.area` at the area's floor, `devpanel` at the panel's. `usertype_capabilities` still replaces the declared part; the screens are added to it. | Code calling `UserTypes::can($type, 'admin.…')` now gets what the screen does — `false` at 90 for every framework screen by default. Code reading `DEFAULT_CAPABILITIES[90]` or `[98]` gets nothing: call `UserTypes::capabilities()` instead. |
| **`Email::$moduleinfo`** | A new public property, written to `mails.moduleinfo` (it was always `''`). The mass-message dispatcher sets it to the message's id. | **Grep your application for `$moduleinfo` in classes extending `Pramnos\Email\Email`.** Redeclaring it untyped is fine; declaring it with a type (`public string $moduleinfo`) is a fatal at class load — drop the type. |
| **The message to one account is HTML** | `UsersController::sendnotification()` keeps the body's markup and `composeMessage()` cleans it with `SafeHtml` (formatting, links, `https` images); it was `strip_tags()`ed and escaped. The bundled `users/notify` views write it in `pf-editor.js`. A body with no markup is escaped with its line breaks, as before. | A project's own copy of `users/notify` keeps working. Text typed with `<`-markup in it — `<b>` meant literally — is now formatting. Run `project:resync --js` for `pf-editor.js`, or the form falls back to its plain textarea. |
| **JWT-assertion `client_credentials` needs the signing key** | Without `app/keys/private.key` the grant answers `500 server_error`. It signed the token HS256 with the client id — a public value — so anybody could mint one that verified. | Nothing, if the server has its key (every other grant already needs it). If tokens were being issued this way without one, generate the key pair; tokens already issued HS256 stay in `usertokens` until they expire — revoke them: `UPDATE usertokens SET status = 0 WHERE deviceinfo = 'jwt_bearer'`. |
| **Allowed Scopes are enforced** | `applications.scope` is checked at `/oauth/authorize` and for every grant at `/oauth/token`, refresh included: a scope outside it is `invalid_scope`. Empty means no restriction. The JWT-assertion grant also checks the server's scope registry now; it copied any `scope` it was sent. | Find clients whose list is narrower than what they ask for: every one with a non-empty `scope` column — `SELECT appid, name, scope FROM applications WHERE scope IS NOT NULL AND scope <> ''` — and compare with the scopes their tokens carry (`usertokens.scope`). Widen the list, or empty it, before upgrading; a client refused a refresh has to sign its user in again. |
| **The token response carries an `id_token` for `openid`** | `OAuth2ServerFactory` builds the server with `IdTokenResponse` (`makeResponseType()`); `/oauth/authorize` reads `nonce`, stored with the code in `usertokens.oidc_context` (migration `add_oidc_context_to_usertokens`). The discovery document lists only `code` / `query` and no implicit grant or back-channel logout, and the ID token's claims. | **Run `migrate`.** An OpenID Connect client library that ignored the missing ID token will now validate it: its configured issuer must be the discovery `issuer` exactly (`sURL`, with its trailing slash), and a client that sends a `nonce` gets it back. A subclass of `OAuth2ServerFactory` that declares its own `makeResponseType()`, or of `IdTokenResponse`'s parent with a different response type, keeps its own. |
| **Token lifetimes are configurable** | `OAuth2ServerFactory` reads `oauth.access_token_ttl` / `refresh_token_ttl` / `auth_code_ttl` from `app.php` (defaults unchanged: 1 h, 30 days, 10 min), and migration `add_token_lifetimes_to_applications` adds per-client `access_token_ttl` / `refresh_token_ttl` (seconds, NULL = default). The JWT-assertion grant's fixed 3600 follows the same rule. | **Run `migrate`.** Nothing changes until something is set. A subclass of `Pramnos\Auth\Application` declaring `$access_token_ttl` or `$refresh_token_ttl` with a type is a fatal at class load — drop the type. |
| **`permissions_changed` reaches only the applications it concerns** | `WebhookService::queuePermissionsChanged()` sends to the endpoints of the permission's `app_id`, or of the applications the affected users hold an unexpired token for or consented to; it went to every subscriber. `PermissionsController` passes a permission's `app_id`. | An application that relied on hearing about every change — to keep a cache of users who never signed in to it — no longer does; fetch on demand instead. A subclass of `WebhookService` overriding `queueEvent()` is still called, once per endpoint. |
| **One column-cache key per table** | `Model` caches column lists under `Database::columnCacheKey($table)` — the key `getColumns()` and the migration flush use — instead of `schema_columns_<qualified name>`; `forgetColumns()` also clears `Model::$columnCache`; `cache:clear` also clears the query cache. | Nothing to run: the old entries become unreachable and expire. Code of your own that builds `schema_columns_…` keys, or seeds `Model::$columnCache` with them in tests, should call `columnCacheKey()` instead. |
| **`Controller::terminate()`** | Exists on the base controller and throws `ApplicationClosedException` under any test runner (`PRAMNOS_TESTING` or either PHPUnit constant) instead of `exit`. The eight framework controllers that each carried their own copy — in four different behaviours — no longer do. | **Grep your application for `function terminate`.** An override declaring anything other than `protected function terminate(): void` is a fatal at class load; one declaring exactly that keeps working and keeps its own behaviour. In tests, a controller action that ends the request now throws instead of exiting or returning: expect `ApplicationClosedException`. `Oauth` no longer throws a bare `\Exception` with the message `OAuth controller terminated` — match the type. |
| **`Pramnos\User\User` is notifiable** | It implements `NotifiableInterface` and uses `NotifiableTrait`, so `$user->notify($notification)` works — which the Notifications Guide has always said and which was not true. The default routing is unchanged: `mail` reads `$this->email`, `database` reads `$this->userid`. | **Grep your application for `function notify` and `function routeNotificationFor`.** A user class extending `Pramnos\User\User` that declares either with a different signature is a fatal at class load; matching the trait's (`public function notify(NotificationInterface $notification): void`, `public function routeNotificationFor(string $channel): mixed`) keeps your behaviour. If you worked around the missing method with `(new Notifier())->sendNow($user, $n)`, that still works and can go. |
| **New methods on classes you may subclass** | `Model::forget()`, `SchemaBuilder::columnDetails()` / `tableNames()`, `Database::forgetAllColumns()`, `Session::tokenParameters()`, `ResizeTools::blend()` / `silhouette()`, `User::notify()` / `routeNotificationFor()`. All additive — no existing signature changed. | **Grep your application for `function forget`, and for the others if you subclass those classes.** A subclass that already declares a method of the same name with a different signature is a **fatal at class load**, not a runtime error: `Declaration of App\Models\Thing::forget() must be compatible with Pramnos\Application\Model::forget(): static`. `forget()` is the one to check — every model extends `Model` and it is an ordinary word for a helper. Fix by matching the parent's signature (`protected function forget(): static`, widening to `public` is allowed) or renaming yours. `MediaObject::__construct()` is exempt: PHP does not compare constructor signatures. |
| **`create:crud` output — `load()`** | Generated controllers take the value `load()` returns (`$model = (new Thing($this))->load($id);`) instead of calling it as a statement. `Model::forget()` is new, so a tenant-scoped `load()` can empty the model as well as refuse the row. | **Re-read every `create:crud` controller you already have.** Nothing regenerates. If your model scopes by tenant and the controller writes `$model->load($id);` on its own line, that controller serves the row the model refused — it is a cross-tenant read. Take the return value, and call `$this->forget()` on the refusal path in the model. |
| **`create:crud` output — the list endpoint** | `getApiList(…)` is generated with `useGetData: true`, matching the read of a single row. It was `false`, which returned raw database rows. | Nothing regenerates. An existing controller's list returns raw rows: any column the model transforms has a different shape there than on the read of the same row. Change the argument, and check whatever consumed the list — a front end formatting a raw timestamp draws 1 January 1970. |
| **GDPR erasure** | `Account::eraseUserData()` fires `account.data_erase` with the user id before its own deletes, and **skips a table this installation does not have** instead of raising. | If you override `eraseUserData()` to delete your own rows, you can drop the override and listen for the event instead — it runs at the right moment by construction. **And if account deletion has been failing for you**, this is likely why: five of the six tables are `authserver.*`, so without that feature every deletion answered "An error occurred". |
| **The scaffolded SPA API client** | A `FormData` body is passed through unencoded and carries no `Content-Type`. It was `JSON.stringify`d, which turns any `FormData` into `{}`. | Only new scaffolds. To gain it in an existing project, add the `isFormData` branch to `frontend/lib/api.js` — or `project:resync` it. If you wrote a bare `fetch` beside the client to upload a file, it can go back through the client, which is what carries the apiKey and the access token. |
| **A new default health check** | `site_url` is registered by default. It reports **degraded** when the site root is only inferred from the request rather than configured. | A monitor alerting on any non-`ok` check will go yellow until `APP_URL` is set in `.env` (or `'site_url'` in `app/config/app.php`). That is the point: a web request infers a root, cron cannot, so a scheduled task building a public URL has been producing `http:///…`. |
| **`migrate` clears the column cache** | A batch that ran anything calls `Database::forgetAllColumns()`. `getColumns()` caches a table's schema for an hour, and a migration writing raw DDL flushed nothing — so a newly added column was invisible to every list built through `getApiList()` until the hour was up. | Nothing to do, and worth knowing if you have seen a screen say it could not load anything after a migration: a payload indexing a missing key by name emits a warning ahead of the body, which makes the JSON unparseable. A schema changed by hand outside `migrate` still needs `cache:clear`. |
| **`TestClient::submitForm()`** | Implemented. It used to throw `submitForm is not yet fully implemented`. `Session::tokenParameters()` is new beside it, returning the CSRF field as `[name => value]`. | Nothing breaks — the method only ever threw. A test carrying a regular expression over `getTokenField()` to extract the CSRF token can delete it. `submitForm()` needs `symfony/dom-crawler` and `symfony/css-selector`, the pair the selector assertions already use. |
| **`OutboundUrl::fetch()`** | Two optional parameters, `allowTruncated` and `truncated`. The default is unchanged: a response past the cap is still refused. | Nothing. Turn `allowTruncated` on where the answer is at the top of the file — a `<head>`, a feed header, a manifest — and a page larger than the cap becomes readable instead of an error. |
| **`MediaObject`** | Has a constructor: `new MediaObject($id)` loads that row. It used to load nothing, because the class inherited `Base::__construct()`, which takes no parameters and which PHP does not complain about being handed one. | Nothing breaks — `new MediaObject()` is unchanged. **If media reads have been answering 404 for pictures that exist**, this is why: check for `new MediaObject($id)` followed by an `empty($media->mediaid)` that was always true. |
| **The scaffolded SPA stylesheet** | `@import "tailwindcss" source(none);`, so the `@source` line is the whole source list. In Tailwind v4 `@source` adds to automatic detection rather than replacing it, and detection starts at the git root. | Only new scaffolds. In an existing project, add `source(none)` to the import in `frontend/app.css`: the bundle drops the rules for classes that appear only in server-rendered views — about 30% in one project — and the content hash stops changing between builds of identical sources. |
| **Two new default health checks** | `session_storage` reports `degraded` when PHP keeps sessions in local files. (`site_url` was the other, added earlier.) | A monitor alerting on any non-`ok` check goes yellow on a single-server installation too, because the check cannot see sticky sessions at a load balancer. That is deliberate — it is reporting a topology risk, not a fault. Set `APP_SESSION_HANDLER=redis` to turn it green, or accept the yellow if the balancer is doing it. |
| **A new framework migration** | `2026_09_23_000001_create_locks_table` creates `pramnos.locks`, behind `Pramnos\Database\SharedLock`. | **Run `migrate` once after upgrading.** One small table; nothing writes to it unless a scheduled task asks for `onOneServer()`. |
| **Media on object storage** | `MediaObject` publishes to a storage disk **named `media`**, and `publicUrl()` answers that disk's address. `Storage::getManager()` no longer requires `Storage::init()`. | Nothing changes without a disk called `media` — no publish, no copy, and `publicUrl()` is the site's own address, which is what every installation has today. To adopt it: add the disk, `composer require aws/aws-sdk-php` for the `s3` driver, and call `republishToStorage()` over the existing library. In views, `$media->publicUrl('thumb')` instead of concatenating `sURL` with `$media->url` — the concatenation keeps working and keeps pointing at the local copy. |
| **The SPA debug module** | `DebugBarAsset::spaModule()` wraps the toolbar in a `typeof window`/`typeof document` guard, so `lib/debug.js` — and therefore `lib/api.js`, which imports it — can be imported outside a browser. It booted itself on import, so every test a scaffolded project ships for its API client died at `ReferenceError: window is not defined`. | Nothing in a browser. Run `project:resync --debug-panel --all` to pick up the guard, after which the project's own `node --test` suite for `lib/api.js` runs. |
| **Webhook endpoints on internal addresses** | A client-registered endpoint is checked at `/Webhook/register` and at every delivery (`Http\Client::forUserSuppliedUrl()`, pinned, no redirects followed). Public addresses and — by default — the private network (RFC 1918, carrier-grade NAT, IPv6 ULA) are allowed; **loopback and link-local are refused**, including `169.254.169.254`. An endpoint an administrator enters on the application's page is delivered to as written. `lastError` for a transport failure reads `Delivery refused or failed: …` instead of `cURL error: …`. | **A relying party receiving webhooks on `localhost` or `127.x` stops receiving** — the events are recorded as `failed`, not lost silently. Either list its range in `'authserver' => ['webhooks' => ['allow_private_ranges' => ['127.0.0.1/32']]]` in `app/app.php`, or re-enter the endpoint on `/admin/Applications/view/{appid}`. To refuse the private network too, set `'allow_private' => false`; to accept `http://` endpoints, `'require_https' => false`. A monitor that matched `cURL error:` in `last_error` should match `Delivery refused or failed:`. |
| **A new framework migration** | `2026_09_24_000001_add_registered_by_to_oauth2_webhook_endpoints` adds `registered_by` (`client` or `admin`, default `client`) to `applications.oauth2_webhook_endpoints`. `WebhookService` writes it on every registration. | **Run `migrate` once after upgrading.** Until it runs, registering an endpoint fails on the unknown column; delivery keeps working and treats every row as `client`. An application sharing this database that writes endpoint rows with its own code needs no change: the default is the stricter answer. |
| **New methods on webhook and applications classes** | `ApplicationsController::webhook()` / `webhookrotate()` / `webhookdelete()` / `webhookService()` / `applicationExists()`; `WebhookService::saveEndpoint()` / `endpointsFor()` / `deleteEndpoint()` / `rotateEndpointSecret()` / `allowedPrivateRanges()` / `addressRefusal()`; `Client::allowAddresses()`; `OutboundUrl::inRanges()`. All additive. | **Grep a subclass of `ApplicationsController` for `function webhook`** — the likeliest collision, since an application may have added its own. A declaration with a different signature is a fatal at class load; rename yours or match the parent's. `Webhook::storeEndpoint()` keeps its signature and now delegates to `WebhookService::saveEndpoint()`. |
| **`users` column defaults** | The users migration gives `usertype`, `sex`, `birthdate` and `modified` a default of 0. It used to declare them `NOT NULL` with no default, as the reference production schema does, so an insert that omitted one was refused. Only a **new** installation gets the defaults from the migration: `CREATE TABLE` does not run on a table that already exists. | Nothing is required, and no existing insert changes behaviour. To accept inserts that omit these columns on an existing installation, run the statements in [`users` columns without a default](#users-columns-without-a-default) below. |
| **Permission checks about a group** | On the `authserver.permissions` store, `Permissions::isAllowed($id, …, 'group')` answers with the permissions of the roles group `$id` holds. It used to resolve `$id` as a **user** id, so a group check returned the grants of whichever user had that id. | Grep for `'group'` as the sixth argument of `isAllowed()`. A check that happened to pass because a user shared the group's id now fails, as it should. Give the group the role it needs, on its page under *Roles*. |
| **Deleting a role tells the applications its holders use** | `Role::delete()` sends `permissions_changed` before it removes the assignments. It used to send it after, when no holder was left to find, so a deleted role reached no relying party. Holders through a group are included. | Nothing, unless a relying party treats an unexpected `permissions_changed` as an error. It should refetch the user's permissions, which is what the event is for. |
| **`userlog` is deprecated** | No framework migration creates `userlog` any more. An installation that already has it keeps it, and the framework never drops it; the DevPanel's user log reads it where it exists and says so where it does not. User events belong in `pramnos.changelog_events` (the `changelog` feature), which also records who acted. | Nothing on an existing installation. A **new** installation of an application that writes to `userlog` gets no table: grep for `userlog`, and either move those writes to `Model::logEvent()` or create the table in an application migration. |
| **A cloned `Database`** | `close()` on a clone, and its destructor, no longer close the link it shares with the original; `close()` returns `false`. Destroying a clone used to disconnect the original, and the second close raised *mysqli object is already closed*. | Nothing, unless code cloned a `Database` and closed the clone to end the original's connection. Close the original instead. |
| **`ConnectionStore::refresh()`** | Takes a `SharedLock` per connection and reads the row again under it. It can wait up to 30 seconds for another process's refresh, returns that process's tokens instead of refreshing again, and throws a non-terminal `OAuthClientException` (`temporarily_unavailable`) if the wait runs out. | Nothing for most callers. A caller that treats every `OAuthClientException` from `refresh()` as fatal should check `isTerminal()` first. A lock an application held around `refresh()` itself can go. Needs `pramnos.locks`, created by the `core` migrations. |
| **Queue retries** | A task that fails with attempts left is claimable again only after `retryDelaySeconds()`: 30 seconds per attempt, at most 600. It used to be claimable at once. | Nothing for most queues. A handler that relied on an immediate retry, or a test that claims a task straight after failing it, overrides `QueueManager::retryDelaySeconds()` to return `0`. Run `migrate` for the `availableat` column; until then retries stay immediate. |
| **`/admin/Services` Stop and Restart** | **Stop** keeps a service stopped (`pramnos.stopped_services`) until **Start**. It used to make the worker exit, and the supervisor started it again a cycle later. **Restart** now writes the stop file of a running service. It used to remove it, which did nothing to a running worker. `GET /admin/Services/status` gained `pools`, `batches` and `stopped_by_operator`. | Run `migrate` for the new tables; until then Stop behaves as before and says so. A runbook that used Stop as a restart should use Restart. A project with a published services view runs `project:publish-views --group=services --force` for the new screen. |
| **Application registry: the API is `api`** | An `Api` built with no name registers as `api`, not `default`, so it no longer replaces the site in a process that holds both. `BaseTestCase::setUp()` makes the site the current application before each test. | Nothing for `getInstance()` with no argument: inside an API request it is still the API. Code that asks for `Application::getInstance('default')` **from inside the API** now gets the site; ask with no argument or for `'api'`. A suite whose site tests relied on a bare `TestClient` after an API test was already order-dependent; it now gets the site. |
| **`Permissions::allow()` / `deny()` with a list of privileges** | The list form passes every argument through. It used to drop `$resourceElement`, so the resource type was stored as the element, the subject type as the resource type, and the subject type fell back to `user`. A grant to group `7` became a grant to **user** 7, and a plain `allow($uid, 'posts', ['read','update'])` was stored on element `module` of resource type `user`, so `isAllowed()` never found it. | Grants written this way are wrong in your database. Find them with `SELECT * FROM <permissions table> WHERE resourceelement IN ('module', 'menu') AND resourcetype IN ('user', 'group')`, plus any value your code passes as a resource type. Delete them and run the code that wrote them again. Code that granted one privilege at a time to work around this can go back to the list form. |
| **`Application::runPendingMigrations()` on a failure** | It throws a `RuntimeException` naming the failed migrations and records no fingerprint. It used to record the fingerprint anyway and return, so a failure was invisible and every later call reported the schema as up to date. The automatic per-request path now logs the failure instead of passing over it in silence. | A deploy script or bootstrap that calls `runPendingMigrations()` now stops on a failed migration. Catch the exception if the script should continue, and read its message. If a test database was built from nothing and is missing tables, run the suite again and read STDERR: it now names the migration that stopped the build. |
| **`Event::listen()` with a listener it already has** | A second registration of the same listener, for the same event and priority, is ignored. The same listener means the identical callable, a closure from the same place in the code with the same captured values, or an equal `ListenerInterface` instance. Before, each call appended another copy. | A listener registered in `init()` used to run once for every start of the application in a process, and now runs once. Code that registered one listener twice **on purpose**, to run it twice, must register two different listeners. |
| **Account erasure** | `Account::eraseUserData()` now also deletes the account's passkeys, trusted devices, role assignments, organisation memberships, push subscriptions, push log, push tests and the notifications addressed to it. None of these has a cascading foreign key, and all of them survived an Article 17 erasure. The erase itself moved to `Pramnos\Auth\AccountErasure`. | Nothing is required. An `account.data_erase` listener that deleted any of these itself can stop doing so. Accounts erased before the upgrade still have these rows: find them by joining each table's `userid` against `users`, and delete the orphans. |
| **`BaseTestCase` removes what a test class leaves behind** | When a class ends, every `users` row (and any table added to `$watchedTables`) above the highest key at the class's start is removed: users through `AccountErasure`, so the application's `account.data_erase` listeners run as well. The suite prints which classes left rows. `BaseTestCase` also gained `tearDown()` and `tearDownAfterClass()`. | A test class that creates users in one class for another class to use will find them gone, so create them where they are used. Overrides of `tearDown()` and `tearDownAfterClass()` should call the parent's. An `account.data_erase` listener now also runs in tests and must work against the test database. |
| **symfony/console 6.4** | The framework accepts 5.4 or 6.4 and installs 6.4 on `composer update`. It used to be pinned to `5.4.*`. Under 6.x, `Command::execute()` must return an `int`. | A command whose `execute()` reaches a bare `return;` or returns nothing now fails with `TypeError: …execute(): Return value must be of type int, null returned`. Grep your commands for `return;` inside `execute()` and return `Command::SUCCESS` or `Command::FAILURE`. While adding those returns, declare `execute(...): int` and `configure(): void`. |
| **The administration API answers to administrators** | `ApiAdmin`'s `users`, `logs` and `summary` (`/admin/users`, `/admin/logs`, `/admin/summary` in a scaffolded API) require `AdminAccess` for `admin.users`, `admin.logs` and `admin.dashboard`, or a permission-store grant for the action. They used to let through any signed-in account when no rule existed, and refused everybody when an organisation was in scope. | An account below the administration floor that used these endpoints gets 403. Grant it the `admin.*` ability, or the store grant `admin` / `<action>`. |
| **`/admin/search` and search sources without a `permission`** | The endpoint needs only a signed-in user. A source registered without `permission` shows only to whoever may open the administration area. It used to show to everybody who reached the endpoint. | A source in `app/search.php` meant for members needs `'permission' => fn ($user) => true`, or an ability. |
| **SMTP errors name the server** | When the transport fails, `Email::getLastError()` reads `SMTP <host>:<port>: <the transport's message>`. It used to be the transport's message alone. | Code that matches on the start of `getLastError()` for an SMTP failure: match on `str_contains()` instead. |
| **OIDC `phone_number` claim** | For a user with no mobile, the userinfo claim is now the `phone` column, or `null` when that is empty too. It used to be `''`, because `mobile` defaults to `''` and the fallback only looked for `null`. | Any client that reads `phone_number` and treats `''` as "no number" must also accept `null`. |
| **Permissions screen: an application's vocabulary** | `PermissionsController::save()` refuses a grant whose *Application ID* names an application that declared capabilities, when its object type is not one of that application's active resources or its action not one of the resource's active scopes (or `*`). A grant with no application, or for one that declared nothing, is unchanged. `display()` and `RolesController::view()` give the view a `problems` map; `edit()` a `vocabulary`. | A grant that used to save with a misspelt resource or action against such an application is refused with the reason — correct the name. To get the lists in the form and the marks on stale grants, republish the views: `php bin/pramnos project:publish-views --group=permissions,roles --force`. |
| **Client assertions (`private_key_jwt`)** | `/oauth/token` requires `iss` = client id, `aud` = the token endpoint or the issuer, `iat` and `exp` no more than 300 s apart, and a `jti` it has not seen from that client; ES256/384/512 and a `jwks_uri` are accepted beside RS* and `public_key`. It checked the signature, `sub` and `exp` only. The issued token is now stored with its lookup, so `/oauth/introspect` and `/oauth/revoke` find it. | A client whose assertion lacks `jti`, `iat` or `iss`, names another `aud`, or lives longer than five minutes gets `401 invalid_client`, the reason in `error_description`. Build each assertion fresh, with a random `jti`, per request. Needs the `authserver.jwt_replay_prevention` table (an authserver migration). |
| **`/oauth/revoke` authenticates the client** | Revocation needs client authentication — a secret (Basic or body), a client assertion, or a public client's bare `client_id` — and revokes only tokens issued to that client. Refresh tokens are now found (they were never matched, and the answer was `success`), and revoking one revokes the access token of its grant. `/oauth/introspect` reports refresh tokens `active`. | A caller that posted only `token` gets `401 invalid_client`: send the client's credentials with it. A client revoking another client's token gets `400 unauthorized_client`. |
| **End-session endpoint** | The discovery document's `end_session_endpoint` is `<site>/login/logout` (it named `/logout`, which answered 404). `Account::logout()` honours `id_token_hint`, `client_id`, `post_logout_redirect_uri` (registered redirect URIs and the client's `url`, matched exactly), `state` and `local=1`; with a valid hint it revokes the user's tokens for that client. A site-relative `redirect_uri` becomes the next sign-in's `return`. | A client that hard-coded `/logout` should read `end_session_endpoint`. To return the browser after sign-out, register the address as a redirect URI or as the application's URL. An application overriding `Account::logout()` keeps its own behaviour; the decision is `Pramnos\Auth\OAuth2\EndSession`. |
| **Device, jwt-bearer and token-exchange grants** | `/oauth/token` answers `urn:ietf:params:oauth:grant-type:device_code` (and `device_code`), `…:jwt-bearer` and `…:token-exchange` (and `exchange_token`); discovery lists exactly the grants the token endpoint enables (`OAuth2ServerFactory::GRANT_TYPES`). These check `applications.oauth2_application_grants`, and `jwt_bearer` is never a default. Two migrations: `last_polled_at` on `authserver.oauth2_device_codes`, and `jwt_bearer` in the policy table's CHECK. | Run `migrate`, and `composer dump-autoload` if your autoloader is not regenerated by `composer update`. A device client that polled and always got `unsupported_grant_type` now completes. To let an application use the jwt-bearer grant, `GrantPolicy::enable($appId, 'jwt_bearer')`. |
| **Per-application access and limits are enforced** | Every application with an API key is held to its `applications.application_settings`, and one without a row to the defaults: **1000 requests per hour, bursts of 100** (a token bucket; `429` with `Retry-After`), **HTTPS required** (loopback exempt; `403 HTTPSRequired`), the IP lock, and browser origins when restricted. List endpoints on `ApiListQuery` cap the page at the application's maximum (100 by default). `/oauth/token`, `/oauth/revoke` and `/oauth/introspect` check every grant against `oauth2_application_grants` and the client's authentication method against `oauth2_client_auth_methods` (a grant row decides that grant and the rest follow the defaults — `password` included, `jwt_bearer` not; no method rows: the defaults, `none` only for a client without a secret). | **Look at your applications before upgrading.** An integration that calls the API over plain HTTP from another machine gets `403 HTTPSRequired`: serve HTTPS, have the proxy send `X-Forwarded-Proto: https`, or untick *Require HTTPS* on its Access & limits tab. One making more than about a thousand requests an hour, or a hundred at once, gets `429`: raise its limit there. A list caller that asked for more than 100 rows a page now gets 100 — page through, or raise the maximum. An application with method rows uses only the methods they enable; a grant row changes only its own grant. |
| **The password grant honours the login lockout** | `grant_type=password` failures count toward `Loginlockout` like the login form's, and a locked account gets `400 invalid_grant` with `Retry-After` even with the right password. | A client that retries the password grant in a loop — a service account with a stale password, say — now locks the account for the form as well. Honour `Retry-After`; `pramnos auth:unlock` lifts a lockout. |
| **`?return=` must stay on this site** | `Account::sanitizeReturnUrl()` drops `/\host`, `javascript:`/`data:` and any scheme, and a host that only begins with `sURL`'s. A relative path (`account/security`) is made absolute under `sURL`. A dropped return signs in to the dashboard. | Nothing, unless a link of yours sends `?return=` to another host: that now lands on the dashboard. |
| **Device webhook events: own application, no `device_code`** | `device_authorized` and `device_deauthorized` from `/device` go only to the endpoints of the application that started the flow, and their payload no longer has `device_code`. | A receiver that read `device_code` from the payload: match on `user_code` and `client_id` instead. |
| **Public clients: no secret needed, PKCE required** | A client with `is_confidential = 0` authenticates at `/oauth/token`, `/oauth/revoke` and `/oauth/introspect` with its `client_id` alone (a secret it sends is still checked), and `/oauth/authorize` refuses its request without `code_challenge`. `/oauth/introspect` takes the same client authentication as revoke. The internal endpoints refuse an empty secret. | Grep your applications for `is_confidential = 0`: each must send `code_challenge` (PKCE) when it signs a user in. A client that called an internal endpoint with no secret must send its secret. |

### `users` columns without a default

An installation whose `users` table was created before the defaults existed keeps
`usertype`, `sex`, `birthdate` and `modified` as `NOT NULL` with no default. Any insert that
leaves one of them out is then refused: `Field 'usertype' doesn't have a default value` on
MySQL in strict mode, `null value in column "usertype" … violates not-null constraint` on
PostgreSQL. The framework's own code always writes all four, so this matters for seeders,
imports, and an application's own inserts.

To check whether an installation needs it:

```sql
-- MySQL: a NULL in COLUMN_DEFAULT means no default
SELECT COLUMN_NAME, COLUMN_DEFAULT FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
   AND COLUMN_NAME IN ('usertype', 'sex', 'birthdate', 'modified');

-- PostgreSQL
SELECT column_name, column_default FROM information_schema.columns
 WHERE table_schema = 'public' AND table_name = 'users'
   AND column_name IN ('usertype', 'sex', 'birthdate', 'modified');
```

To add the defaults. The statement is the same on both drivers:

```sql
ALTER TABLE users
    ALTER COLUMN usertype  SET DEFAULT 0,
    ALTER COLUMN sex       SET DEFAULT 0,
    ALTER COLUMN birthdate SET DEFAULT 0,
    ALTER COLUMN modified  SET DEFAULT 0;
```

- **Table prefix:** on a MySQL installation with a prefix, the table is `<prefix>users`, for
  example `pf_users`.
- **Existing rows are untouched.** A default applies only to rows inserted from then on, and
  none of the four columns can hold a NULL today.
- **Safe to run twice**, and safe on a table that already has the defaults. It changes only
  column metadata, so on MySQL 8 and PostgreSQL it neither rewrites the table nor locks it for
  longer than an instant.

### How this list was produced, and how to reproduce it

Nothing fails when a signature moves. The application still compiles, the tests still pass,
and the fatal arrives only on an installation that happens to override the method — which is
why a whole tag's worth of these accumulated without anybody noticing.

The check is mechanical: extract every `public`/`protected function` declaration from `src/`
at the tag and at `main`, and diff the two lists. It takes seconds and it is the only thing
that finds this class of break. Constructors are exempt — PHP does not compare those — and a
changed default value is not a signature change either.


### JSON request bodies decode associatively

The one most likely to be silent, and it was:

```php
- $postArray = (array) json_decode($rawInput);      // top level only
- $_POST = array_merge($postArray, $_POST);
+ $_POST = array_merge($this->decodeBody(), $_POST);   // json_decode($raw, true)
```

A handler reading `$element->deviceid` over a nested list now sees an array, `isset()` on a
property of an array is false, and a required-field check fails on **element one and returns
before anything is stored** — the whole batch refused, not the bad element. No exception, no
log line, and no failing test, because a unit test that hands the controller a hand-built
array of `stdClass` still passes: what changed is the decode, not the handler.

**The change itself is right** and nobody is asking for the old behaviour back. Associative
all the way down is the defensible contract, and `(array) json_decode()` producing object
elements inside an array was a half-cast structure. What was missing was this row.

Two ways to fix a handler, and the second is smaller under time pressure:

```php
// convert the access
$deviceId = $element['deviceid'] ?? null;

// or normalise once at the boundary, which also keeps working if a caller sends objects
$items = array_map(static fn($item) => (object) $item, $items);
```

An http-level test on the endpoint would have gone red at the upgrade instead of at a
customer. A controller-level test passes while production fails, because the decode is
upstream of the controller.

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
| **Carrier-grade NAT is not a public address** | `OutboundUrl::isPublicAddress()` also refuses `100.64.0.0/10`, `192.0.0.0/24`, `198.18.0.0/15` and `224.0.0.0/4` — the ranges PHP's `NO_PRIV_RANGE|NO_RES_RANGE` flags call public. `Http\Client` had its own stricter copy and now shares the one. | Nothing on the default `allow_private => true`, where CGNAT is allowed as private either way. **With `allow_private => false`, a webhook endpoint on `100.64/10` — the Tailscale block — is now refused, and was accepted before**: it was checked against an allow-list it was not on and then admitted as public anyway. Name the range in `allow_private_ranges` if you meant to allow it. |
| **The API document and its viewer** | `init` no longer adds `www/api/openapi*.json` and `www/api/docs/` to a new project's `.gitignore`. They are artefacts a deploy cannot regenerate — the scaffolded deploy is `git fetch`, `git reset --hard`, `composer install`, `migrate` — so ignoring them meant both addresses answered 403 in production. | Nothing for a new project. **An existing one still has the two lines and version control will not remove them**: delete them from `.gitignore`, then `git add -f www/api/openapi.json www/api/docs/`. If `/api/docs/` has been 403 on your live site, this is why. |
| **New protected methods on `Api`** | `publicApiPaths()` and `configureApiPipeline()`, so an application can declare a route open and add its own middleware in front of authentication. `ApiAuthMiddleware::__construct()` gained a fourth argument, `publicPaths`. | Nothing for a caller, and nothing changes without an override or a `public_api_paths` key. **Fatal at class load for a subclass that already declares either name** — grep your `Api` subclass for `function publicApiPaths` and `function configureApiPipeline`, and rename yours if it is there. The middleware's constructor is exempt from that check; a subclass calling `parent::__construct()` positionally is unaffected. |
| **The SPA build output** | `init` no longer ignores `www/assets/spa/`; it ignores `www/assets/spa/.vite/hot` instead. Nothing in the scaffolded deploy builds the bundle — it is `git fetch`, `git reset --hard`, `composer install`, `migrate` — so an ignored bundle is a production site with no front end. | Nothing for a new project. **An existing one still ignores the directory**: remove that line from `.gitignore`, add `www/assets/spa/.vite/hot` in its place, run the build and commit the output. If your deploy already runs `npm run build`, keep what you have — put the directory back in your own `.gitignore` and nothing changes. |
| **`Request::create()` in tests** | It now writes `$_SERVER['REQUEST_URI']` and `$_SERVER['REQUEST_METHOD']` as well as the statics, so a request created for a test survives the framework constructing one of its own. Nothing in `src/` calls it — it is test-facing — so no application's runtime behaviour changes. | Nothing, unless one of **your tests** asserts on `$_SERVER` after calling `create()`, or built a request for one URL and relied on a later `new Request()` still seeing the previous one. Restore `$_SERVER` in `tearDown()` if your suite shares it between tests. |
| **`Factory::getRequest()`** | `Request::resetInstance()` now clears the factory's cached request as well, through the new `Factory::resetRequest()`. Within a request it is the same singleton it always was, and it still returns by reference, so mock substitution is unaffected. | Nothing for an application serving requests. **A test that called `Request::resetInstance()` and then expected `Factory::getRequest()` to keep the previous object** now gets the current one — which is the point: an endpoint declared in `public_api_paths` answered 403 in tests and correctly in production, because the pipeline was handed the stale request. |
| **Migrations that use `addQuery()`** | The runner calls the new `Migration::runQueuedQueries()` after `up()` and `down()`, so queued statements actually execute. `executeQueries()` was `protected` and nothing called it, so such a migration was recorded as Ran and changed nothing. | **Check the schema, not the ledger**, for any migration of yours that queued statements and never called `executeQueries()`: it is recorded as applied and did not run, and a recorded no-op cannot be re-run — the repair is a second migration. Migrations that call `executeQueries()` themselves are unaffected; the queue is cleared each time, so nothing runs twice. |
| **System email text** | A notification may declare `storedMailTemplate()`, and `MailChannel` then lets a row in `mailtemplates` replace its subject, body and HTML wrapper. The four framework notifications (`auth.twofactor_code`, `auth.new_device_link`, `auth.new_signin`, `auth.security_change`) declare theirs. | Nothing unless somebody has written a template for one of those categories — **and if they have, it has been sitting unused and will now start being sent.** Check Administration → Mail templates for rows in those four categories before upgrading. A blank field keeps the built-in text, so a half-filled row is safe. |
| **`MailTemplatesController::placeholders()`** | Called as `static::` rather than `self::`, so a subclass's override is reached from `view()`, `edit()` and `test()`. `SystemMailTemplates::register()` is the new way for an application to describe its own categories, and `builtIn()` returns only the framework's. | Nothing, unless your generated `src/Admin/Controllers/MailTemplates.php` **overrides `placeholders()`** — that override was never reached before and now is. Check what it returns. Prefer `SystemMailTemplates::register()` from a service provider: it needs no subclass at all. |
| **`getData()` on an ORM model** | Stops publishing `OrmModel`'s own properties — `fillable`, `guarded`, `casts`, `timestamps`, `createdAtColumn`, `updatedAtColumn`, `softDelete`, `deletedAtColumn`, `withTrashedFlag`, `onlyTrashedFlag`, `withoutScopes`, `loadedRelations`, `eagerLoad`, `_pendingScopes`. They were being returned as though they were columns by every endpoint answering an ORM model. | **Any consumer reading one of those keys from an API response stops finding it** — a SPA, an MCP client, a mobile app. None of them is a column and `fillable`/`guarded` are the write allowlist, so this is a disclosure being closed rather than a field being removed. A model with its own switch excludes it by extending `internalProperties()`. |
| **The column cache key** | `Database::columnCacheCategory()` takes a third optional argument, the connection, and the key is now `schema_columns_<database>_<schema>_<table>`. Two databases behind one cache used to share a column list per table name. | Nothing to run: entries under the old key become unreachable rather than wrong and expire on their own, so the change is a one-time invalidation. **Fatal at class load for a subclass of `Database` that overrides `columnCacheCategory()`** — PHP compares declarations, and optional is still a declaration; copy the parent's new parameter list verbatim or delete the override. |
| **`Application::databaseVersion()`** | New public method, and a `version` column on the `schemaversion` ledger that `ensureHistoryTable()` adds on the next run. It reports the highest version among the successfully applied migrations of a scope. Rows recorded before the column existed are filled in by `adoptLegacyVersions()`, which `migrate` calls before deciding anything is pending. | Nothing for a caller, and the column is added automatically. **Fatal at class load for an application whose own `Application` already declares `databaseVersion()`** with a different signature — grep for `function databaseVersion` and either delete the override or match `databaseVersion(string $scope = 'app'): ?string`. **Run `php pramnos migrate` once after upgrading** — with nothing pending it still fills in the version of your existing history rows. Until it does, the ledger's legacy version-keyed rows are still read, so an installation that never left the legacy path reports its version without running anything. |
| **The auto-run fingerprint** | Covers every `*.php` file in the scanned migration directories, not only `YYYY_MM_DD_HHmmss_*.php` ones, and carries a digest of the whole set. An untimestamped migration (`Migration0151.php`) was invisible to it: adding one changed neither the count nor the latest timestamp, so every request took the fast path and it stayed pending. A directory of only untimestamped migrations produced an empty map and the check returned before it started. | **The first request after upgrading does one real migration check** rather than matching the recorded key — the fingerprint format changed, which is the correct response to it. If you have untimestamped migrations that were silently pending, they run then: look at what is pending first with `php pramnos migrate:status` if that matters on your installation. A `.php` file in a migrations directory that is not a migration now contributes to the fingerprint and reads as permanently pending, costing one full load per deploy that touches it — move it out of the directory. |
| **Redirects from `Oauth`, `Emailclick` and `FormRequest::failWith()`** | Go through `Application::redirect()` instead of a bare `header('Location: …')`. The destination is recorded (`getRedirect()`), the body carries the usual `<script>` fallback, and under a test runner the request ends with `ApplicationClosedException` carrying 302. In a browser nothing changes. `failWith()` no longer `exit`s the PHPUnit process. | **Tests only.** A test calling `authorize()` or `Emailclick::sendTo()` that expected `…Oauth::terminate() called`, or a return with no exception, must catch `ApplicationClosedException` and can now assert `$app->getRedirect()`. An application that overrides `Application::redirect()` now sees these redirects too. |
| **`LoginFlowResult::EMAIL_UNVERIFIED`** | A new result from `LoginFlow::attempt()`: the password was right, but the account registered under `auth_registration_verify_email` (or a domain list) and has not opened its confirmation link. No session is established. `Account` renders `email_unverified`; `ApiAccount` answers `403 email_unverified`. Only accounts held back by the new confirmation get it. | **Grep for `presentResult` and for code that switches on `LoginFlowResult::status`.** An override that knows only the old four treats this as a failure — safe, but the form says "invalid credentials". Map `isEmailUnverified()` to its own message. |

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
