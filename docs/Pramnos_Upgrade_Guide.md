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
| **`session_exchange` in the activity log** | Written once per session instead of once per exchange. A SPA asks for one on every cold load, so the log filled with entries nobody can act on and the ones they can drowned in them. | Nothing to do. A report that counted `session_exchange` rows as a measure of traffic now counts sessions instead — which is almost certainly what it meant. |
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
| **`Controller::terminate()`** | Exists on the base controller and throws `ApplicationClosedException` under any test runner (`PRAMNOS_TESTING` or either PHPUnit constant) instead of `exit`. The eight framework controllers that each carried their own copy — in four different behaviours — no longer do. | **Grep your application for `function terminate`.** An override declaring anything other than `protected function terminate(): void` is a fatal at class load; one declaring exactly that keeps working and keeps its own behaviour. In tests, a controller action that ends the request now throws instead of exiting or returning: expect `ApplicationClosedException`. `Oauth` no longer throws a bare `\Exception` with the message `OAuth controller terminated` — match the type. |
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
| **The SPA debug module** | `DebugBarAsset::spaModule()` wraps the toolbar in a `typeof window`/`typeof document` guard, so `lib/debug.js` — and therefore `lib/api.js`, which imports it — can be imported outside a browser. It booted itself on import, so every test a scaffolded project ships for its API client died at `ReferenceError: window is not defined`. | Nothing in a browser. Run `project:resync --debug-panel --all` to pick up the guard, after which the project's own `node --test` suite for `lib/api.js` runs. |

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
