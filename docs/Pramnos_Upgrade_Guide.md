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

The framework has state that is per-request in production and **process-wide in a test
run** — five pieces of it, one extension each. Without a reset between tests, state one test establishes answers for every
test after it. In the framework's own suite that cost 135 failures once and three on
another occasion — and in both cases the failures appeared in tests that had nothing to do
with the state involved, so they looked like bugs in the tests that failed.

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

All five ship in `src/` (`Pramnos\Framework\Testing`), so they exist in `vendor/` and need
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
five things:

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
shipped to inject. Add it with `enqueueStyle()` or `addCss()` if you want it.

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
| **Auth screens: brand colour** | With `auth_brand_primary_color` unset, the framework's auth views no longer force `#2563eb`: the theme's own primary applies, light or dark. `Brand::current()` gains `primary_color_set`. | Want the blue back: set `auth_brand_primary_color` to `#2563eb`. A brand array of your own without `primary_color_set` keeps the old behaviour. |
| **Auth screens: links and wording** | The sso and OAuth2 fallback screens link to the account (`routeBase`), not `adminUrl('Dashboard')`; the sso register button shows only while registration is open. In Greek, `Allow`, `Scopes:` and the account's `Dashboard` read differently (the account nav uses a new `Overview` key). | Nothing, unless a test matches the old link or wording. To keep a wording, put the key in your own catalogue. |
| **Page cache and the visitor's id** | `PageCache::store()` refuses a body containing the session's `visitorid` or the value of a tracking cookie the same response sets (a value of digits only, such as `lastseen`'s `time()`, is not looked for). | A page that prints the visitor's id is no longer cached (`X-Pramnos-Cache: MISS` every time). Have its script read the `visitorid` cookie, or send a beacon, instead of printing it — see the Page Cache Guide. |
| **Daemons restart on a migration** | `DaemonOrchestrator` restarts its daemons when the schema generation moves, as on a new commit: any batch that applies or rolls back a migration, in any process. | Nothing. A daemon that cannot afford a restart mid-day should not have migrations run under it without a deploy window, which was true before for commits. |
| **Qualified mixed-case columns on PostgreSQL** | The query builder quotes the column of `table.column` when it has an upper-case letter (`u.registrationStatus` → `u."registrationStatus"`), as it already did a bare name. | Code that names a column created **unquoted** (stored lower case) with capitals, qualified — `u.userId` for `userid` — now asks for a column that is not there. Write the name as it is stored. The bare form had the same rule already. |
| **A bool bound on PostgreSQL** | A PHP `bool` is bound as `'1'`/`'0'`, not `'true'`/`'false'`, so a `smallint` flag takes it. | Nothing for a column: a `boolean` reads both. A bool selected straight back (`SELECT ? AS flag`) comes back as `1`/`0`. |
| **`User::save()` writes `active` on PostgreSQL** | It was left out of every save there, so the column's default (new users) or the old value (existing ones) won. It is written as the object holds it, as on MySQL. | Code that saved a `User` it had loaded earlier, while something else changed `active` in between, now writes its own copy back — reload before saving, as on MySQL. |
| **`Model::_load()` on PostgreSQL** | A load whose query fails (a table that is not there, a column that is not) throws a `QueryException`. It read as an empty model, behind a warning about `numRows` on `false`. | A model loaded from a table that may not exist yet: catch `QueryException`, or check with `SchemaBuilder::hasTable()` first — as on MySQL, where it always threw. |
| **`CONCAT()` through the MySQL translation** | On PostgreSQL with `translateMysql`, `CONCAT(a, NULL)` is `NULL`, as on MySQL. It went through as PostgreSQL's `concat()`, which skips a NULL. | A query that relied on the PostgreSQL answer — a concatenation that came back filled where a part was missing — now gets `NULL`. Wrap the part in `IFNULL(x, '')` where an empty part is meant. |
| **Datatable search on PostgreSQL** | `Datasource`'s search box and column filters match text ignoring case and accents, through the new `QueryBuilder::whereLike()`, as MySQL's `_ci` collations do. They were case- and accent-sensitive on PostgreSQL. | Nothing, usually: a search finds what a person typed. A list that relied on a case-sensitive search on PostgreSQL now finds more rows. |
| **`mail:flush` and future-dated rows** | A pending row whose `date` is in the future is sent when it is due, not at the next run. | Nothing, unless an application wrote a future `date` meaning "send now": write `time()` there. |
| **The daemon orchestrator on SIGTERM** | It asks every daemon to stop after its current task and waits up to `STOP_GRACE_SECONDS` (30) before it exits, sending SIGTERM to any still running. It used to exit at once. | Keep `TimeoutStopSec` above 30 s in its systemd unit. A stop of the service takes as long as the longest task in hand. |
| **Queue worker without `--type`** | Claims only the task types it has a handler for (registered, `queue.handlers`, the framework's own). It claimed every type and failed the unhandled ones. | A worker that finds handlers dynamically by overriding `getTaskHandler()` overrides `handledTaskTypes()` too — or returns `null` from it to claim every type. |
| **`addTask(unique: true)`** | The duplicate check reads `getQueueTableName()` through the query builder, not the model's `getList()`. | A `QueueManager` subclass that moved the queue table only through `createQueueItemModel()` overrides `getQueueTableName()` as well; `addMany()` already required it. |
| **`queue:cleanup` without `--hours`** | Reads `queue.retention_hours` from `app.php` (24 when absent). | Nothing, unless `app.php` already has a `queue.retention_hours` meaning something else. An application that disabled the framework's task to run `--hours=720` can enable it again and set the value. |
| **The Queue screen** | A DataTable fed by `Queue/data`. `?status=`, `?type=` and `?page=` in its address no longer filter; Clear takes its status from a select. | A link or bookmark to `queue?status=failed` opens the whole list — choose the status in the table. An application overriding `queue.html.php` rewrites it around `$this->datatable`. |
| **Queue status `stopped`** | A new task status, set by Stop; `queue:cleanup` removes it with the completed ones, and `addTask(unique)` does not count it as live. | Run `migrate` on PostgreSQL, or its status constraint refuses a stop. Code that lists every status adds `stopped`. |
| **The Queue screen's columns and labels** | `Queue/data` returns more columns — Priority third, the application's own from the eleventh — and every label goes through `t()`. `QueueController` gains protected `show()`, `history()` and helpers such as `statusLabels()`, `statusCounts()`, `ratesByType()`. | An override that reads `$row[n]` from `data()` reads the new positions. One that compares a badge or message with its English text compares with the translation. A subclass with a method of one of those names renames it. |
| **Email history** | Status 0 reads Failed (it read Pending). `emails/resend/{id}` takes a POST with the token, and an unsent message's resend dates it now. `data()` puts `extraColumns()` cells after Status, before Opens and Actions. Labels go through `t()`. | A link or GET to `emails/resend/` becomes a form with `getTokenField()`. A check for "Pending" in `emails/data` finds "Failed". Code reading `emails/data` by position after the sixth cell counts the extra columns. |
| **`Http\Client::json()`** | Throws `InvalidArgumentException` for data that cannot be encoded as JSON — invalid UTF-8, most often. It sent an empty body. | A call that sent such data and ignored the remote error now stops at `json()`: fix the data (a `preg_*` without `/u` on UTF-8 text is the usual cause), or catch the exception. |
| **`broadcast:serve` address** | Binds `127.0.0.1` by default; it bound `0.0.0.0`. | A server reached directly, not through a reverse proxy on the same host: set `broadcasting.websocket.listen_host` to `'0.0.0.0'` in `app.php`, or pass `--host=0.0.0.0`. |
| **`broadcast:serve` Redis ingest** | The Redis key prefix is taken off before delivery, so a client subscribed to `chat.updates` receives what was published on `app:chat.updates`. With the `redis` backplane it also `PSUBSCRIBE`s `<prefix>private-users.*`, and Redis must be reachable at start. | A client that subscribed to the prefixed name (`app:chat.updates`) subscribes to the bare one. |
| **`broadcast:serve` `redis-stream` ingest** | Reads `<prefix>stream:<channel>`, the key the `redis-stream` driver writes; it read `<prefix><channel>` and saw nothing. | Nothing, unless a producer of its own wrote the unprefixed key: write `<prefix>stream:<channel>`. |
| **`broadcast:serve` options** | `--host`, `--port`, `--app-key` and `--channels` fall back to `broadcasting.websocket.*` and `broadcasting.ingest` in `app.php` when not given. | A supervisor that relied on the built-in defaults while `app.php` says otherwise now gets `app.php`'s values: pass the option to keep the old ones. |
| **Email history (`EmailsController::data()`)** | Once `add_userid_to_mails` has run, each row has a **User** cell after the recipient, so the cells after it move one place. | Code that reads the list's cells by position — a test, a client-side customisation — counts the new column, or checks `MailLog::recordsUser()`. |
| **"Message one user" and mass messages** | `{firstname}`, `{lastname}`, `{full_name}`, `{username}` and `{email}` in the subject or body are filled in for each recipient when the message is sent. | A message that meant those words literally escapes them, or uses other braces; an application that filled them itself before queueing has nothing left to fill and needs no change. |
| **`work` after a restart** | A new `work` process runs the tasks due in the minutes since the previous process's last pass (at most 15), read from `var/pramnos-work.lock.minute`, instead of the current minute only. | A task that must not run late after a deploy checks the time itself; nothing to do otherwise. Delete the file to start a worker with no look-back. |
| **`mail:flush` retries** | A message refused for now waits a minute, then two, four, up to an hour before the next attempt; it was tried on every run. | Nothing, usually. `'mail' => ['outbox' => ['backoff' => false]]` restores a retry on every run. |
| **Realtime notifications** | With `broadcasting.transport` `websocket`/`pusher`: `DatabaseChannel` broadcasts `notification.created` on `private-users.{id}`; signed-in pages carry `<meta name="pf-realtime">` and `pf-utils.js` opens a socket from it; `NotificationBell` emits `data-channel`; the default channel registry admits a user to their own `private-users.{id}`. | Nothing on `sse` or with no broadcasting. A page with a client of its own defines `window.pfRealtime` before `pf-utils.js`. Anything listening on `private-users.*` also receives `notification.created` — move it with `broadcasting.notifications_channel`. An own rule for `users.{id}` is matched after the built-in one. `'channel' => ''` keeps a bell off the socket. |
| **OAuth2 `redirect_uri`** | `/oauth/authorize` refuses a `redirect_uri` that disagrees with the client's registered `applications.callback`, matched exactly. A client with *nothing* registered is unaffected. | Register the callback each client actually sends, character for character. Several are allowed, separated by commas, spaces or newlines. |
| **Migrations** | `migrate` refuses to run ten or more migrations when the ledger is empty and the database already has tables; `Application::upgrade()` does the same rather than auto-running a history in a web request. | Nothing, on an installation with a recorded history. On one whose history moved, `migrate:adopt-legacy --dry-run` first — `migrate` now adopts a version-keyed ledger on its own. `migrate --adopt-baseline` overrides. |
| **`queue:health` output** | `--json` gained a `truncated` key, and the prose output a caveat line when the window reaches past the oldest surviving row. `schedule:list` gained trailing lines about whether anything executes the schedule. | Nothing for a person. A monitor that matches the whole of either command's output rather than the fields it needs should read `truncated` / the `overall` object instead. |
| **`Application::startMaintenance()`** | Gained a second parameter, `$origin`, so `maintenance:on` can distinguish an operator's decision from an automatic one. **A signature change, not a behaviour one** — PHP refuses to load a subclass whose declaration no longer matches. | Fatal at class load, before a route is matched, for any application whose own `Application` overrides `startMaintenance()`: grep for `function startMaintenance` and either delete the override or add `$origin = self::MAINTENANCE_AUTOMATIC` to it. |
| **Two-factor administration** | `TwoFactorAuthService::disable()` and `regenerateBackupCodes()` take a **required** second argument, `string $password`. | Every existing call breaks with `ArgumentCountError` — it is a caller break, not only a subclass one. Pass the password the user just re-entered; there is no way to skip the step-up, which is the point of the change. |
| **Flash message markup** | `Base::_printErrors()` / `_printMessages()` default to CSS class `error` / `message` instead of `pramnosError` / `pramnosMessage`. | Any theme styling `.pramnosError` or `.pramnosMessage` stops applying. Either restyle to the new class or pass the old one explicitly: `_printErrors('pramnosError')`. |
| **Framework auth screens and their language** | The framework's own views (sign-in, second factor, consent, device, account, status) and the `Account` / `TwoFactorAuth` flash messages go through `t()`, and a Greek application gets the framework's Greek for them (`resources/language/el.php`, merged under its own catalogue). The card screens draw a brand block (`partials/auth_brand`: logo or name, from `auth_brand_*` / `sitename`) and the public ones a home link. | Nothing, if you want them. To keep a wording, put the English key in your own catalogue — yours wins. A test or check that matches the English text of a flash message on a Greek installation now sees Greek. An application that already prints its own logo above the framework's views gets two: set `auth_brand_logo`, or override `partials/auth_brand`. |
| **`BounceMailbox::unseen($limit)`** | With a limit it returns the **newest** `$limit` unseen messages (still oldest first), not the oldest. `mail:bounces --imap --limit` therefore reads the most recent mail. | Nothing for `mail:bounces`. Code of your own that calls `unseen($limit)` and relied on the oldest messages: call `unseen()` and slice it yourself. |
| **`SchemaBuilder::hasTable()` / `hasColumn()`** | A table or column **found** is remembered for the life of the process, per connection. A missing one is asked every time. DDL through the same connection (the builder's or raw `DROP`/`ALTER`/`RENAME`) and the migration runner forget the answers. | Nothing, mostly: a query that fails on a missing table makes the connection forget, a migration restarts the orchestrator's daemons (the schema generation, `var/schema-generation`) and makes any `CommandBase` worker forget between tasks. After DDL through another connection in the same process, call `SchemaBuilder::forgetWhatExists()`. |
| **Controller resolution** | A controller segment reaches its class in any case (`/twofactorauth` → `TwoFactorAuth`), whether or not the class is loaded yet. The exact name is still tried first everywhere. | An application that relied on a wrong-case URL answering 404 now gets the controller. |
| **Methods that gained a parameter** | `CommandBase::heartbeat()`, `QueryBuilder::count()`, `Database::getColumns()`, `Database::logCacheHit()`, `Route::matches()`, `ScheduledTask::withoutOverlapping()`, `SchemaGrammar::compileCreateIndex()` (and its interface), `User::cleanupAllAuthTokens()`, `Validator::validateMax()` / `validateMin()` / `validateSize()`, `LogViewer::renderViewer()`, `LogViewerView::render()` / `getBody()`. All the new arguments are optional. | Nothing for a caller. **Fatal at class load for a subclass that overrides one** — PHP compares declarations, and optional is still a declaration. Grep your application for `function heartbeat`, `function matches`, `function count`, `function validateMax` and the rest of the list, then either delete the override or copy the parent's new parameter list verbatim. A custom `SchemaGrammar` must take `?string $where = null`. |
| **Methods whose return type changed** | `ScheduledTask::run()` and `CommandBase::heartbeat()` return `bool` instead of `void`; `Blueprint::index()` / `unique()` return `IndexDefinition`; `TokenActionsController::stats()` returns `mixed`; `UsersController::tokens()` returns `void`. | Same class of break as above, and it cannot be fixed by adding an argument: an override declaring `: void` where the parent now says `: bool` will not load. Return the value the parent's docblock describes — for `run()` and `heartbeat()` that is whether the work succeeded. |
| **Controller actions accept `mixed`** | `ServicesController::start()` / `stop()` / `restart()` / `logs()` and `LogController::clearFile()` take `mixed $name = null` where they took `string $name = ''`. | Nothing for a caller — the type widened. A subclass that still declares `string` is **narrowing** the parameter and will not load; widen it to `mixed` to match. |
| **Where a scaffolded project's tests run** | `TestEnvironment::setup()` now loads `app/config/testsettings.php` into `Settings` and brings that database's schema up to date. Until now it built `<db>_test` and told nothing to use it, so `BaseTestCase::setUp()`'s `init()` fell back to `app/config/settings.php` and **the suite ran against the development database**. | Your tests now hit `<db>_test`, which starts empty and is migrated on first run. Anything that depended on development data being there will fail — which is the point: the Testing Guide's own advice (factories, seeders, `truncate()` in `tearDown()`) was deleting it. Seed what the tests need. |
| **PostgreSQL session variables** | `app.userid` is now set to `guest` for an anonymous request. It was never set at all: the statement the framework emitted for a null userid — `SET app.userid = NULL` — is not PostgreSQL grammar, so it was refused on every unauthenticated request. | A query that filters `current_setting('app.userid', true) IS NULL` to mean "anonymous" now matches nothing — compare with `'guest'` instead. If you log or alert on the syntax error in the PostgreSQL log, it stops. |
| **`project:switch-ui` and the palette** | It no longer overwrites `app/themes/theme.css`. It used to write the scaffold's template over it and then generate `theme-tokens.css` from the template, so switching UI framework replaced the project's colours with the framework's default blue and renamed both of its themes. | Nothing to do. If you ever ran `project:switch-ui` and your colours reverted, this is why — restore the palette from version control and run `pramnos theme:build`. |
| **Theme colours on `bootstrap` and `plain-css`** | `theme:build` now writes the palette a second time, aliased onto `--bs-*` and onto the plain-CSS theme's own names, so both of those themes follow `app/themes/theme.css` instead of their framework's stock colours. A `prefersdark` theme reaches them too, through the file's existing `prefers-color-scheme` block. | Run `pramnos theme:build`, then look at the site. **The colours will change** if your palette is not Bootstrap blue, and a project on either theme will follow the visitor's OS into dark mode. To keep it light, remove `prefersdark: true` from the palette or set `data-theme` on `<html>`. The `tailwind` theme is unaffected. |
| **`theme:build` output** | `ThemeTokens::parse()` strips CSS comments before reading `app/themes/theme.css`, so a `/* … */` inside a `@plugin` block no longer becomes a key in `www/assets/theme-tokens.json` (and no longer swallows the declaration after it). | Nothing at runtime — the keys that disappear were never valid tokens. **Run `pramnos theme:build` once after upgrading**: the regenerated outputs differ — from this and from the alias block in the row above — so `theme:build --check` fails in CI until they are committed. |
| **CORS in the `api` block** | `cors_origins` and `cors_from_db` inside `'api' => [...]` — where `init` writes them — are read; they were ignored, and the API answered every origin (`*`). | An API whose browser clients come from an origin not in that list is now refused by the browser. Add the origin, or set `['*']` deliberately. |
| **Generated models** | `create:crud` / `create:model` write `save($autoGetValues = false, $debug = false): static`. Without the return type a generated model did not load: `OrmModel::save()` declares `: static`. | A model generated earlier: add `: static` to its `save()` and end it with `return $this;`. |
| **`health:check`** | Runs the framework's whole list of checks, as `/health` does — `cache`, `site_url`, `session_storage`, `framework_scripts`, `llm_tables`, `hypertables` — not only database, disk and memory. | A script that reads its exit code may now see `degraded` where it saw `ok`; read the `checks` in `--json` to see which. |
| **AI without a key** | Until a provider's key is set, *Ask AI* is left out of the user menu, the chat widget draws nothing, the admin header has no Ask and the admin menu has no *AI* and `/admin/Ai` opens the settings, which Settings links to. `NavItem` gained `when:`. | Nothing, once a key is set. A test that expects these on an installation with no key sets one first. |
| **Help pages are a feature** | The panel's Help tab, `/admin/Help`, its menu item and the analyst's `help` tool need `'help'` in `features`. | An installation already using help pages adds `'help'` to `features` in `app/app.php`; without it the panel shows Ask alone and `/admin/Help` is not found. |
| **Analyst tools on MCP** | An `llm.admin_tools` entry that implements `ScopedMcpTool` is served by `mcp:serve` and the MCP endpoint as well, to tokens holding its scope; `mcp.tools` in `app/app.php` adds tools for MCP alone. | Check the scopes on your analyst tools: any token granted one now sees that tool. An analyst tool without a scope is not served. |
| **The admin header's Ask** | `Analyst::headerButton()` draws the side panel as well: the link, a `<template>` and `<script src=".../pf-assist.js" defer>`. Without the analyst it is *Help* where the page has help pages, where it used to be nothing. `Controller` gained `protected string $assistSubject` and records each request's first controller in `Assist\Page`. | Run `project:resync --js --all` for `pf-assist.js`. A test that compares the header's HTML exactly must expect the panel. A controller of your own that declares `$assistSubject` with another type will not load: rename it. Add `src/Admin/Controllers/Help.php` for the Help screen; under `admin_access = permissions` grant `admin.help`. |
| **`SiteUrl` in the console** | `SiteUrl::get()` answers `''` in a console command when nothing is configured, instead of an address built on the console's stand-in request (`http://localhost<script dir>/`). | Nothing, if `APP_URL` or `site_url` is set. A command or queue job that builds links without it now gets relative paths. Set `APP_URL`, which the `site_url` health check already asks for. |
| **The analyst's days** | The analyst's tools count days, and its prompt states dates, in the application's default zone (or `'llm' => ['analyst' => ['timezone' => …]]`) instead of UTC. A tool's window carries a `timezone` key. | Nothing on a UTC installation. Elsewhere a per-day figure moves to the local day; an `admin_tools` tool that builds its own `Window` gets the same zone. |
| **Sign-in from the administration area** | A guest refused inside the area is sent to `login?return=/admin/Page` — the whole path — where it was `return=Page`. A controller's `addAuthAction()` refusal inside the area does the same. | Nothing, if your sign-in redirects to `return` as given. A login handler that prefixes `return` with `sURL` itself, or accepts only a bare page name, must take a path starting with `/`. |
| **Developer tools** | `/adminer` and `/debugbar` now honour `usertypes` / `userids` lists, each falling back to `devpanel`'s. | Nothing — an absent list widens nothing and every floor default is unchanged. Worth reading if you had raised a floor out of reach to close a tool: naming people is now narrower than that. |
| **API signing key, outside an API request** | `Api::deriveAuthenticationKey()` derives from `Api::baseUrl()` — the API's own base — instead of whatever `sURL` the running front controller has. Inside an API request the key is **byte-for-byte unchanged**; outside one (`SessionExchange`, `mcp:token`) it is now the key the API verifies with rather than one derived from the site root. | Nothing for existing API tokens — they still verify. Tokens minted by `SessionExchange` or `mcp:token` before this were refused with `403 InvalidAccessToken`; re-mint them and drop any local workaround that set `$app->authenticationKey` by hand. A project serving its API from somewhere other than `www/api/` sets `'api' => ['directory' => '…']` in `app.php`. |
| **`robots.txt` and `llms.txt`** | Scaffolded for **every** project, not only an authserver, and the wrapper controller the rewrites point at is now generated — both addresses answered 404 before. The `Sitemap:` line is emitted only when a file is actually served at `/sitemap.xml` (or `sitemap_url` is set); the docs and MCP addresses in `llms.txt` are derived instead of hard-coded. | Nothing for an existing project — `init` writes these, and it does not run again. To gain them, add `src/Controllers/MachineReadable.php` extending `Pramnos\Auth\Controllers\MachineReadable` and the two `RewriteRule` lines to `www/.htaccess`. If you relied on the old `Sitemap:` line, set `sitemap_url` or serve a sitemap. |
| **A failed scheduled command's message** | `ScheduledTask` runs the command through a pipe instead of `passthru()`, so the last 2 KB of its output — stdout and stderr merged — is appended to the `RuntimeException` as ` Output: …` and reaches `schedule.log`. | Nothing for a person; this is the reason a failure was missing. A monitor that matches the *whole* of a `failed:` line should match the command name and status instead. An interactive `schedule:run` now sees the command's stderr on stdout. An application that overrides the `protected runShellCommand()` keeps the old message unchanged and does not capture anything — delete the override to gain this. |
| **The scaffolded `dockertest`** | `init` writes `phpunit_status=$?` after each PHPUnit branch and `exit $phpunit_status` at the end. The script used to exit with the coverage-report block's status, which is 0 when its condition is false — so a red suite reported success. | Nothing for a new project. **An existing project owns its own copy and `composer update` will not touch it**: check the last lines of your `dockertest` for `exit $phpunit_status` and add the two pieces if they are missing. Anything that ran the suite without reading the summary — a `&&` chain, a git hook, a CI step — was being told a failing suite was green. |
| **Deploy webhook responses** | `WebhookHandler::handle()` sends `202 Accepted` **before** running the commands, instead of `200`/`500` after them. GitHub allows a webhook ten seconds in total and does not retry, and a normal deploy takes four to six — two consecutive pushes were lost to a slow `git fetch`. | Nothing to configure; the deploy that was silently dropped now completes. **The delivery list is green whether the deploy succeeded or failed** — if you monitor deploys by watching for a red delivery, watch `webhook.log` instead, which records every command and its exit code on both paths. To keep the old contract: `$handler->respondFirst(false);`. |
| **The SMTP password on `/admin/Settings`** | The field no longer renders the stored password into `value=""` — it is encrypted at rest and was appearing in clear in an admin page's HTML. It submits empty to mean **keep what is stored**, and the placeholder says whether there is one. | Nothing for a person using the screen. **A script or test that posted the whole Email tab back and relied on `smtp_pass` round-tripping now leaves the stored value alone** — which is the intent, but check anything that cleared the password by submitting the form empty: clear it with `Settings::setSetting('smtp_pass', '')` instead. |
| **`/health/check` and a fourth status** | `HealthStatus` gains `notice` — "correct here, and would not be everywhere" — and it answers **200**, not 503. `session_storage` on local files was `degraded`, so a correct single-server installation answered 503 to its uptime monitor from the moment that check shipped. `health:check` exits 0 on a notice. | Nothing to configure; a monitor that was alerting on a working installation stops. **Anything parsing the report must tolerate a fourth value**: a `match` on `$check['status']` without a `default` now throws, and `status === 'ok'` as a health test should become `HealthStatus::from($s)->isHealthy()`. A custom check that wants the old behaviour keeps returning `degraded`. |
| **`session_storage` on local files** | Reports `ok` on a single server instead of `degraded`. It becomes `degraded` only when the application declares `'servers' => 2` or more in `app/app.php`; one is assumed. A handler the check does not recognise is `notice`. | **Declare `'servers'` if you run more than one node**, or this check will not tell you that local session files have stopped being correct. Nothing to do on one server — a working installation stops being reported as a fault. |
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
| **The Permissions screen** | The form has Priority, Application ID, Expires and Conditions, and `save()` writes them. It no longer offers "Group"; `save()` refuses any subject but `user` and `role`, conditions that are not a JSON object, and an expiry that is not a date. `Permissions/assign` is a write action (`POST` with the token). | An application's own copy of `permissions/edit` keeps working and leaves Application ID, Expires and Conditions as they are — `save()` writes each only when the form sends it. **Priority is always written**, as `100` when the form has no `priority` field, so a copy without it resets every stored priority on save: add the field. A copy that offers "Group" gets a refusal for it: grant to a role instead. To edit the new fields, add them to your copy or republish the view. |
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
| **Two new default health checks** | `session_storage` reports on where PHP keeps sessions: `ok` for local files on a single server, `degraded` for local files when the application declares `'servers' => 2` or more, `notice` for a handler it does not recognise. (`site_url` was the other, added earlier.) | Nothing on one server. With more than one node, declare `'servers'` in `app/app.php` so the check can see the risk, then set `APP_SESSION_HANDLER=redis` to turn it green — or accept the yellow if a balancer keeps sessions sticky. See also the "`session_storage` on local files" row. |
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
| **`Html\Date` built with `null`** | `new Date($name, null)` is the same as `new Date($name, 0)`: no date. `date()` reads null as now on PHP 8, so with nothing in the request `getDate()` answered today's midnight instead of `2`. | **A list that passes a never-set property (`$this->datefrom`) and treats `getDate() < 10000` as "no filter" stops filtering itself to today.** Nothing to change; a workaround that initialised the property to `0` can stay or go. |
| **`Datasource::getList($…, false)` under DataTables 1.10** | A request carrying `draw` with `$encode = false` returns the 1.9 shape — `sEcho` (the draw counter), `iTotalRecords`, `iTotalDisplayRecords`, `aaData` — as it did before the 1.10 translation existed. It returned `draw`/`recordsTotal`/`recordsFiltered`/`data`, and code written for 1.9 that looks for `aaData` skipped its post-processing and sent the raw rows. `$encode = true` still answers in the 1.10 shape. | **A caller that reads `$result['data']` (or `recordsTotal`/`recordsFiltered`/`draw`) from the array stops finding it** — read `aaData` and the `i…` names, or keep the `isset($result['data']) ? 'data' : 'aaData'` fallback, which works with both. The client needs nothing: DataTables 1.10 reads the 1.9 names. |
| **Every `Email::send()` writes a `mails` row** | `send()` records each message, sent or failed, in `mails` (`recordToMails`, on by default), and so does the static `Email::sendMail()`. `'mail' => ['record' => false]` in `app/app.php` turns it off for every send. | **An application that writes its own `mails` row for each message gets two per message.** Add `'mail' => ['record' => false]` to `app/app.php`, or drop its own write and let the framework's row stand. |
| **A send with no `smtp_host`** | Fails with `getLastError()` = `SMTP not configured: smtp_host is empty.` instead of Symfony's `The mailer DSN is invalid.` | Only code matching the old message text — grep for `mailer DSN is invalid`. |
| **`json_encode()` of a `Model`** | A `Model` encodes as `getData()` (it implements `JsonSerializable`). It encoded its public properties, so one with a controller failed with *Recursion detected* — a 500 from any response with a model nested in it — and one without carried `prefix`, `controller` and the other internals. | **A client that read a non-column property of an encoded model** stops finding it — grep for `json_encode` over arrays holding models. A subclass that declares its own `jsonSerialize()` keeps it. |
| **`new User($id)` that finds no row** | The user is new: `save()` inserts a row with its own id. It kept the default `userid` of 1 and saved as `UPDATE users … WHERE userid = 1` — the Guest row, or nothing — then wrote its details against that id. A save whose UPDATE finds no row now writes nothing and records `There is no user N to save: it does not exist.` | Code that builds a user from an id that may not exist and saves it now creates an account — which is what the code reads as doing. **Grep for `new User($_SESSION['uid'])` and `new User(` followed by `save()`**, and check `userid > 1` after constructing where the id must exist. |
| **`Model::_getList()` with an empty order** | `''` adds no `ORDER BY`; only `null` (the default) orders by primary key descending. `''` was read as `null` too, so wrappers declared `getList($filter = '', $order = '')` returned every list newest-first. | **A list that passes `''` and wants newest first** now gets the database's own order: pass `null`, or the order itself. Grep for `_getList(` and `getList(` called with `''` as the order. |
| **Public clients: no secret needed, PKCE required** | A client with `is_confidential = 0` authenticates at `/oauth/token`, `/oauth/revoke` and `/oauth/introspect` with its `client_id` alone (a secret it sends is still checked), and `/oauth/authorize` refuses its request without `code_challenge`. `/oauth/introspect` takes the same client authentication as revoke. The internal endpoints refuse an empty secret. | Grep your applications for `is_confidential = 0`: each must send `code_challenge` (PKCE) when it signs a user in. A client that called an internal endpoint with no secret must send its secret. |
| **The framework's queries over `organizations`, `applications`, `queueitems`, `notifications`, `queuestats`** | Named with `#PREFIX#`, as the framework's models and migrations already name them. On an installation with a table prefix the queue screen, the organisation names and lists, a user's tokens and the OAuth joins read the prefixed tables the models write to. | Nothing on an installation without a prefix. With one: an application that created one of these tables **itself, unprefixed**, beside the framework's — rename it to the prefixed name, which is the one the framework's models were already using. |
| **CORS origins on PostgreSQL** | `CorsMiddleware::fromApplicationSettings()` reads an application's `cors_origins` (`TEXT[]`) and `cors_enabled` on PostgreSQL. It could read neither: the origins never parsed, so every application allowed `*`; and `'f'` read as enabled. | An application with CORS settings on PostgreSQL now **enforces its listed origins** instead of `*`. A browser client calling from an origin not in `cors_origins` is refused — add it there, or set `cors_enabled` false for the wildcard. |
| **Framework migrations on an installation with a prefix** | Every framework migration names its tables through the schema builder, including the table a foreign key references; a fresh installation with a table prefix migrates on MySQL and PostgreSQL. New MySQL installations get `usertokens.parentToken`/`applicationid` and `tokenactions.tokenid`/`urlid` as `INT UNSIGNED`, so their foreign keys are created. | Nothing on an existing installation. A foreign key `add_missing_foreign_keys_to_existing_tables` cannot add because the two columns' types differ is now skipped with the reason in the `migrations` log, instead of failing the batch. |
| **`organization_id` nullable on an application's own chat tables** | Where an application created `chat_conversations`, `chat_messages`, `chat_memory`, `chat_asks`, `ai_consents` or `ai_routines` before the framework, a migration makes their `organization_id` accept null; its type is kept. | **Run `php bin/pramnos migrate`.** Until then the analyst's first question — and any conversation outside an organisation — fails to insert (`/admin/Ai` answers 500), and the `llm_tables` health check reports which column. |
| **`/chat` and `/admin/Ai` load `assets/js/pf-chat.js`** | The conversation pages ask in place — files, a thinking line, the answer without a reload — through a framework script, which `composer update` does not publish. | **Run `php bin/pramnos project:resync --js --all`** after updating. Without it the pages work as plain forms with a 404 in the console; the `framework_scripts` health check reports it `degraded` and lists the files. |
| **`/admin/Ai` is Ask; the settings moved** | `/admin/Ai` opens the administrators' analyst. The language-model settings are `/admin/Ai/settings`; `/admin/Ai?show=problems` and `/admin/Ai/analyst` redirect. The analyst's actions are `ask`, `asked`, `delete`, `forget`, `consent`, `api` and `export`. | A link or bookmark to the settings: `/admin/Ai/settings`. An application's `Admin\Controllers\Ai` that overrides `display()` meant the settings: rename it `settings()`. |
| **`pramnos.pushlog.sent` is UTC** | A push is recorded in UTC with its offset. It was PHP's local time without one, which `timestamptz` read in the database's zone. | Rows written before the upgrade are off by the difference between PHP's zone and the database's — on a PHP in Europe/Athens and a database on UTC, two or three hours in the future. Nothing to do unless something reads old `sent` values exactly; they age out in 90 days. |
| **`project:resync --spa-components` keeps changed components** | A shared component (DataTable, Field, …, and their tests) is replaced only while it is still what the framework wrote, recorded in `app/shipped-files.json`. A changed file, or one with no record, is reported `kept` and left alone. | A project from before the record has no record: files identical to the framework's are recorded on the first run, the rest are kept. To take the framework's copy of a file you did change, `--overwrite`. Commit `app/shipped-files.json`. |
| **An *Ask AI* item in the user menu** | With the `llm` feature enabled, the user menu has `user.chat`, *Ask AI*, linking to `/chat`. | An application with `llm` and no `/chat` page — one whose `init` predates it, or a SPA — gets a link to a 404. Write `src/Controllers/Chat.php` extending `Pramnos\Llm\Chat\Web\ChatPageController`, or `NavRegistry::remove('user.chat')` after the application's navigation is registered. |
| **Person events are kept in the change log** | Each occurrence of a person event is an application event of `pramnos.changelog_events` (entity `user`), not a row of `person_event_log`, which is no longer created; `audience.event_log_days` is gone — the change log's retention applies. Without the `changelog` feature only the summary is kept and a "N times in the last M days" condition is refused. | Enable the `changelog` feature for windowed conditions and A/B goals; an installation that already made `person_event_log` can drop it; remove `event_log_days` from `'audience'` in app.php. |
| **The audience screens' markup** | The segments, consent lists, rules and A/B test views, the builder (`pf-audience.js`) and the rule editor (`pf-rules.js`) draw with the screens' own `pf-*` classes in every theme; `AudienceBuilder::classes()` returns `pf-btn` / `pf-input` / `pf-select` instead of the theme's. | An application that styled `.pf-audience-*` or `.pf-rule-*` itself, or reads `AudienceBuilder::classes()` for its own controls, checks them against the new markup; a layout that prints the page title adds `pf-titled` to the body classes. |
| **Carrier-grade NAT is not a public address** | `OutboundUrl::isPublicAddress()` also refuses `100.64.0.0/10`, `192.0.0.0/24`, `198.18.0.0/15` and `224.0.0.0/4` — the ranges PHP's `NO_PRIV_RANGE` and `NO_RES_RANGE` flags call public. `Http\Client` had its own stricter copy and now shares the one. | Nothing on the default `allow_private => true`, where CGNAT is allowed as private either way. **With `allow_private => false`, a webhook endpoint on `100.64/10` — the Tailscale block — is now refused, and was accepted before**: it was checked against an allow-list it was not on and then admitted as public anyway. Name the range in `allow_private_ranges` if you meant to allow it. |
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
| **`Pagination` number row** | Near either end the window borrows from the other side, so page 1 shows `1 2 3 4 5` rather than `1 2 3` with the default `adjacents = 2`. | Nothing, unless a test or a layout counts the numbered links on the first or last pages. `$pagination->steadyWindow = false` restores the cut window. |
| **`Html\Date` stylesheet** | A calendar `Date` widget enqueues the `bootstrap-datepicker` style beside the script. Under `documentAssetSource` naming `bootstrap-datepicker` (or `'local'`) it is `plugins/datepicker/bootstrap-datepicker3.standalone.min.css`. | On an installation serving the datepicker locally, put that file next to the script, or the page logs a 404. A theme that styled the bare popup itself may want to drop its rules. |
| **`Llm\Client::askWithTools()` reply** | The reply is the text of every round, in order, a blank line apart — including rounds that also called a tool. It was the last round's text alone. | Nothing, unless code splits or matches the reply expecting one round's text, or a verifier assumed it saw only the last round. |
| **Help on site pages** | In an application with an administration area, a page outside it shows the site's help (`app/help-site/`), not the administration's — to an administrator as to a visitor. | An administrator who relied on an `app/help/` page showing on a site page: copy it to `app/help-site/` under the same name, if visitors may read it. |
| **Help search order** | `search()` ignores common English and Greek words, weighs each word by how rare it is across the pages, matches a four-letter word to longer ones it begins, and puts a page whose `questions:` lists the question first. `score` is a float. | Nothing, unless a test pins the order or the scores of `Help::search()`; list the questions readers ask under `questions:`. |
| **Flash messages in the layout** | The scaffolded layouts show the flash above every screen with `Html\Flash::render()`, which takes it; the bundled admin screens' "Saved." was written and never shown. `ApplicationsController` now takes the flash it shows. | In a project scaffolded before, add `<?php echo \Pramnos\Html\Flash::render(); ?>` above `[MODULE]` in each theme's `theme.html.php`, and the `.pf-flash` rules from the scaffolded `style.css`. A template of your own that prints `$this->messages` also prints it: take it with `_printMessages()` or `Request::takeMessages()` instead. |
| **`Html\Datatable` markup** | `$bootstrap` follows `scaffold_theme`: off under tailwind and plain-css, on under bootstrap or none. The Bootstrap column picker carries `btn-secondary btn-sm`, `data-bs-toggle` and `dropdown-item` beside Bootstrap 3's names. | A tailwind or plain-css project whose tables relied on Bootstrap's table classes: set `$dt->bootstrap = true`. A stylesheet that targets the picker's exact class list should allow the added names. |
| **`AdminArea::serve()`** | Called after the navigation was built, it builds the framework's navigation again on the area's URL. It puts `src/Admin/` in scope and holds for every request the process serves; with `sURL` already ending in the prefix, the area's URL is `sURL`. | An application that replaced or removed a framework nav item *before* calling `serve()` moves that after the call, or declares `'admin' => ['serve' => true, …]` in its config and drops the call. An application with classes under `<namespace>\Admin\Controllers` that it did not mean as the area's: they are now found first. |
| **`LogController` subclasses** | A subclass builds its links, redirects, breadcrumbs and the viewer's address from its own class name (`logsRoute()`), and under any name but `Logs` it renders the bundled `logs` views before the application's. A subclass named `Logs` is unchanged. | A subclass with another name that is still served at `/Logs`: add `protected ?string $logsRoute = 'Logs';`. |
| **`framework_scripts` health check** | Also `degraded` when a published `pf-*.js` differs from the framework's copy, listed under `outdated`. | A project whose web root kept older scripts after `composer update` now sees `degraded` where it saw `ok`: run `php bin/pramnos project:resync --js --all`. |
| **`SettingsForm::getData()`** | Throws `Pramnos\Html\Form\TokenRejected` when the CSRF token does not check out; it returned `[]`. It also accepts the synchronizer token (`_csrf_token` or `X-CSRF-Token`). An unchecked checkbox reads `'0'`; it read `'1'`. | Code that tested `getData() === []` for a rejected submit: catch `TokenRejected`, or check `tokenFailed()` first. Re-save checkbox settings saved since the hidden `0` was added: they were stored as on. |
| **Query builder table names** | On an installation with a table prefix, a bare name in `table()` / `from()` / a join (`table('things')`) takes the prefix, as the schema builder's `createTable('things')` does: `pfx_things`. In a SELECT the prefixed table keeps the bare name as its alias, so `things.id` still works. It was left as written, so it read a table no migration had made. `#PREFIX#`, an already-prefixed name, `schema.table`, `pg_*` and a CTE are unchanged. With no prefix, nothing changes. | A prefixed installation that queries tables it created itself **without** the prefix: set `'database' => ['prefix_bare_names' => false]`, or rename those tables. Grep for `->table('` and `->from('` naming tables that are not `{prefix}…` in the database. |
| **`/health/check` to an unsigned caller** | The body keeps the verdict and each check by name, as `{"status": "ok"}` or `{"status": "error"}` (`notice` counts as `ok`). `message` and `details` are left out unless a signed-in administrator asks, or `app.php` sets `'health' => ['public_details' => true]`. The HTTP code is unchanged. | A monitor that reads `checks.*.message` / `details`, or a per-check `degraded`/`down`, without signing in: set `public_details`, or read `/health` signed in. One that reads `status` and the HTTP code needs nothing. |
| **`client_credentials` token `iss`** | The access token from `/oauth/token` (client assertion) carries `iss` = `Discovery::issuer()`, which is `sURL` with its trailing slash. It was `sURL` without it, so it disagreed with the discovery document's `issuer`. Tokens already issued are still accepted. | A client that compared `iss` with the slash-less URL: compare with the discovery document's `issuer`, as RFC 8414 has it do. |
| **Protected-resource metadata of the MCP endpoint** | `/.well-known/oauth-protected-resource/<path>/mcp` lists in `scopes_supported` only `mcp`, the scopes the offered tools require and `offline_access`, and the endpoint's `401` names them in `scope="…"`. It listed every scope the server has. | A client that connected through the MCP endpoint and relied on also getting other scopes — `openid`, `profile`, `system:*` — asks for them explicitly, or the path is given its list in `'oauth' => ['resource_scopes' => [...]]`. |
| **MCP `tools/call` without the scope** | A call to a public tool the user may reach but whose scope the token lacks answers `403` with `WWW-Authenticate: Bearer error="insufficient_scope", scope="…"` and a JSON-RPC error whose `data` names the scope. It answered `200` with "Unknown tool". A tool the user may not reach still answers "Unknown tool". | A client or test that read "Unknown tool" for a missing scope reads `error.data.scope` instead. |
| **OAuth consent page** | The bundled `OAuth2/authorize` views have a ticked box per requested scope; a person who clears one grants the rest, and the code, token and stored consent carry only those. `openid` and `mcp` (or `oauth.consent_required`) have no box. A published copy of the view without the boxes grants everything, as before. | A client that assumed it received every scope it asked for reads the token's scopes. To give an application's own consent view the choice, copy the checkboxes and the hidden `scope_choice` field from the bundled view. |
| **Revoked OAuth tokens and the authorized-applications list** | `EndSession::revoke()` sets a client's tokens to status **3** (revoked, with `removedate`), as the account screen does; it wrote 0. `Account::getAuthorizedApplications()` also lists an application holding only a consent (`token_count` 0) and a deactivated one (`active` false), with `consented_at` and `scope`. `revokeapplication` finds a deactivated application instead of answering "Application not found". | Code that looked for status 0 to find tokens a client's sign-out revoked looks for 3. A customised `authorized_applications` view that assumed every row has a live token handles `token_count` 0. |
| **`bytea` columns read on PostgreSQL** | A `bytea` value read through `query()`, `execute()` or the query builder is the bytes, as a MySQL `blob` is. It was PostgreSQL's hex text (`\x00ff…`). | Code that decoded the hex itself (`pg_unescape_bytea()`, `hex2bin(substr($v, 2))`) drops the decoding. Writing bytes takes `new \Pramnos\Database\Binary($bytes)`. |
| **The framework's dashboard cards** | `logs.errors`, `ai.spend`, `messages`, `users.active`, `database`, `queue` and `api.performance` are shared: their figures are kept in the cache's `dashboard` category for `data_ttl` (300 s by default) and handed to everybody who may see them, instead of being worked out on every dashboard. They are up to that old. Each is `data()` and `present()` now, and `render()` is `present(data())`. | Nothing to do. For fresher figures, lower `'dashboard' => ['data_ttl' => …]`; to have nobody wait for them, run `dashboard:precompute` from cron. A card of an application's own that overrides `render()` is unaffected. |
| **A sent message's body in `mails`** | A message sent or failed from now on has an empty `content`. Its body is in the body store: `mail_bodies` on TimescaleDB (`path` = `db:`), elsewhere a file under `var/emails` (`path` = the file). It is kept before its tracking and with its unsubscribe link as a placeholder, and `hash` is its md5 rather than an md5 of the address, subject and date. Queued messages keep their body in `content` until sent. | Code that reads `mails.content` reads `\Pramnos\Email\Body::of($row)` instead, which gives the body as it was sent. The web server must be able to write `var/emails`, or set `'email' => ['bodies' => ['path' => …]]`. To keep bodies in the row as before: `'email' => ['bodies' => ['store' => 'content']]`. |
| **Spooled rows dated before a compression cutoff** | For a table declared with `deferred_writes` (the framework's `pramnos.changelog` and `changelog_events`), a spooled row dated before the table's compression cutoff goes to the deferred-write queue and reaches the table at the next `timescale:drain` (hourly), instead of being inserted into the compressed chunk at once. Recent rows are written as before. Only on TimescaleDB with a compression policy. | Code that writes an old-dated event and reads it back at once: drain first (`php pramnos timescale:drain`), or write inside `withCompressionPaused()`. |
| **`Helpers::objectDiff()` and null properties** | A property that is present but null is no longer reported as `removed` (null after) or `added` (null before). A value that became null is now under `changed`, with its original. Null on both sides is no change. | Code that logged `$diff['removed']` on every save stops writing entries for untouched null fields; one that read a cleared field from `removed` reads it from `changed`. |
| **`'retention' => null` in an app.php `hypertables` override** | `timescale:ensure` now removes the retention policy an earlier run installed on that table: the table is kept for ever, as the override says. It used to add nothing and leave the installed policy in place. A null that is only the framework's default still leaves a policy alone. | Remove a `'retention' => null` override that was written as a no-op, or the table's existing policy goes on the next `timescale:ensure`. |
| **`Database::getInsertId()` on PostgreSQL** | The id comes from the sequence of the table the last INSERT wrote (its serial or identity column), not from `LASTVAL()`. The two differ only where something else took a sequence value during the insert: a trigger, or TimescaleDB opening a new chunk, where `LASTVAL()` answered the wrong id. An INSERT that names the id column itself, or a statement that is not an INSERT, still reads `LASTVAL()`. | Code that relied on `getInsertId()` returning a value from a trigger's sequence must read that sequence itself. |
| **`mail:prune` and account erasure with the body store** | `Retention::strip()` (`mail:prune`'s strip) empties `path` and `bodyvars` as well as `content`, and removes the kept body: the `mail_bodies` row, the file, the file on a disk. `Retention::prune()` removes the bodies before the rows. `AccountErasure::erase()` now pseudonymises the account's rows in `mails` (`tomail`/`toname` empty, `subject` `[erased]`, no body or `extrainfo`) and empties the address on its tracking rows. `Retention::batches()` is gone, because every database sweeps in batches by id. | Code that looked up an erased person's mail by address finds none. An application whose own `account.data_erase` listener deleted or rewrote `mails` rows can stop. A subclass overriding `batches()` can drop the override. |
| **Mail to bounced or blocked addresses** | Once the messaging migrations have run, `send()`, `queue()` and `mail:flush` do not send to an address in `bounces` that hard-bounced, soft-bounced 15 times or within the last day, or was blocked: `send()` returns false and the `mails` row says why. An application's existing `bounces` table is kept and gains `blocked` and `reason`, so its recorded bounces start refusing mail sent through `\Pramnos\Email\Email`. A `5.1.x` refusal at send time is recorded as a hard bounce. | Code that checked bounces itself before `send()` can stop. To keep sending regardless: `'email' => ['bounces' => ['hard' => 0, 'soft' => 0, 'softWithin' => 0]]`. Set the thresholds to the ones the application used (for example `'soft' => 30`). |
| **Page cache with `store => file` or `redis`** | The store is built with the page cache's `prefix`. A file store keeps its pages in a subfolder named after it, so pages stored before are not read again (a miss, then stored anew) and not removed by a purge. `flush()` and `pagecache:purge --all` remove only the pages: on Redis it was `FLUSHDB`, and on files the whole cache folder. Without a `redis` key or a `cache` hostname, Redis is the shared connection (`REDIS_HOST`), not localhost. | A file store: delete the old page files from `CACHE_PATH` once (`pagecache:*`). Code that relied on `pagecache:purge --all` emptying the whole Redis database or cache folder clears those itself. |
| **`db:migrate-from-mysql` and `bigint unsigned`** | A table the migration makes gets `bigint` for a MySQL `bigint unsigned`; it got `numeric(20,0)`, which can be neither an identity column nor a foreign key's target. The dry run counts the values above `9223372036854775807`. | A column that holds such values: make it `numeric(20,0)` in a migration before moving. Code that read the column as a string (`numeric`) now gets an integer. |
| **`QueuedListener` and `ListenerInterface`** | `Pramnos\Event\QueuedListener` no longer implements `ListenerInterface`, so that a subclass can declare `handle(TheEvent $event)`. It is callable instead: `Event::listen()` still takes an instance, and `fire()` still queues it. | Any `instanceof ListenerInterface` (or `is_a(…, ListenerInterface::class)`) that was meant to match a queued listener: test `instanceof QueuedListener` as well. |

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
| **Scheduled AI tasks (`ai_routines`)** | Three failed runs in a row now pause a routine and tell its owner; it ran and failed every day before. New tables `ai_task_runs` and `ai_task_subscriptions` and new `ai_routines` columns; the `llm_tables` health check is degraded until they exist. | Run `php bin/pramnos migrate`. A routine paused this way resumes with `setActive(…, true)`, or from the scheduled-tasks screen. |
| **New public methods on `Chat\Api\ChatController` and `Chat\Web\ChatPageController`** | `routineRuns()`, `readRun()`, `continueRun()`, `reports()`, `subscribe()` and `unsubscribe()` were added to the API controller and `report()` to the page controller, and `Routes::register()` mounts `/chat/runs` and `/chat/reports` ahead of `/chat/{conversation_id}`. | A subclass of either controller (the application's `Chat` controller, say) with a method of one of these names and another signature fails to load: rename yours. An application route of its own at `/chat/runs…` or `/chat/reports` is now answered by the framework's; pass `paths: ['runs' => …, 'reports' => …]` to move them. |
| **The scaffolded service worker skips `admin/` and `api/`** | `scaffolding/templates/service-worker.js.stub` returns before handling any request under `admin/` or `api/` (relative to its scope): those assets are no longer cached, and no offline page is shown there. Only a project that copies the stub again gets this; an existing `sw.js` is unchanged. | To keep caching there, register with `sw.js?skip=` (nothing); for an administration area under another prefix, `sw.js?skip=<prefix>/,api/`. A test that compares a project's `sw.js` to the stub needs the new block. |
| **Roles (`Pramnos\User\Roles`)** | Bundled screens and tools ask a role (`roles` in `app.php`) instead of a number of their own. **The developer tools follow `root`: the DevPanel in development and the debug bar's grants now open at 99, not 90** (`DebugGrantController::DEFAULT_MIN_USERTYPE` is 99). The rest of the defaults reproduce what was in force. The bundled controllers' `$requiredUserType` defaults are now `0`, meaning the administration floor; `DevPanelController::$minUserType` defaults to `0`, meaning `developerFloor()`; `TokensController::$perUserUserType` defaults to `0`, meaning `manager`. | An administrator below 99 who used the DevPanel or issued themselves a toolbar grant locally needs `'devpanel' => ['min_usertype' => 90]` / `'debug' => ['grant_min_usertype' => 90]` back, or root. Otherwise nothing, unless a subclass reads one of these properties before its constructor ran, or redeclared `$requiredUserType = 0` expecting a screen open to every account: `0` is now the administration floor. To move every line at once, set `'roles' => ['admin' => …, 'super' => …, 'root' => …]`. |
| **`schedule_task` requires `kind`** | The chat tool's schema lists `kind` as required, and a call without it answers an error instead of making an `ask` task. | Nothing for a model, which calls again with the kind. Code that runs the tool's `run` closure itself passes `kind` (`ask` for the older default). `Routines::create()` is unchanged and still defaults to `ask`. |
| **CSRF tokens refresh themselves** | Every page's head has `<meta name="csrf-refresh">`, and `pf-utils.js` holds a form whose token is ten minutes old until it has asked `GET /Csrf` for the current one; the framework serves `/Csrf`. | Nothing, unless the application has its own controller named `Csrf` (it takes the address), or a test compares a whole `<head>` (it has one more tag). A page that submits a form by script and must not be delayed calls `window.pfRefreshCsrf()` itself. |
| **Dashboard widths and settings** | A widget's default `size()` is `m` (was `half`); layouts are `{id, size, settings}`, and a saved `half` reads as `m`. `Widget` gained `options()`, `configure()`, `setting()`, `description()`, `cleanSettings()`, `normalSize()` and the property `$dashboardSettings`; `DashboardController` gained `card()` and `cardFor()`. | Code comparing a size with `Widget::HALF` compares with `Widget::MEDIUM`. A widget subclass with a method of one of these names and another signature fails to load: rename yours. A published `admin_dashboard` view keeps working, and reloads when a card is added, but its cards cannot be dragged: dragging is by the grip `data-pf-widget-grip` and the corner `data-pf-widget-size-handle` now, which only the new `card.html.php` draws; take `--group=dashboard --force` for the four widths, the settings form and drawing without a reload, and add `.pf-dashboard` grid rules from the theme's `components.css` if your theme has its own. |
| **`ChatPageController` gains the scheduled page** | New public actions `scheduled`, `schedule`, `scheduledupdate`, `scheduleddelete`, `scheduledrun`, `scheduledsubscribe` (from the trait `Chat\Web\SchedulesTasks`, with protected `taskService()` and its hooks), also on `AiController` with `report()`; the conversations page links to `chat/scheduled` unless it was given its own `urls`. | An application's `Chat` controller with a method of one of these names and another signature fails to load: rename yours. A published `chat/chat.html.php` has no link until it is taken again; the page works without it. |
| **Erasing an account erases the assistant's data** | `AccountErasure::erase()` also deletes the account's chat conversations and messages, memory, queued questions, agreements, dashboard, scheduled tasks with their runs and subscriptions, and clears `userid`, prompt, messages and reply in its `llm_calls` rows. The delete-account page asks who takes over the account's scheduled tasks. | An application that erased these rows itself in an `account.data_erase` listener can drop that, or keep it; deleting nothing twice is harmless. An application that relied on `llm_calls.userid` surviving an erasure for its own accounts reads the cost without it. A published `delete_account` view has no hand-over choice until it is taken again: tasks are then deleted with the account. |
| **Dashboard figures are read lazily; the catalogue lists every card** | `DashboardController::display()` hands `$this->activeUsers`, `dbStats`, `apiStats` and `healthResults` as `LazyFigures` objects, not arrays; `$this->catalogue` lists every card with `placed`. | A published view that indexes, loops over or counts the figures works unchanged. One that tests them with `is_array()` reads `->toArray()`. A view that took `$this->catalogue` as the cards to add filters `!$one['placed']`. |
| **A partial falls back to the bundled scaffold** | `View::insert()` looks last in `scaffolding/themes/<scaffold_theme>/views/<ViewName>/`, as a whole template already did. | A view that inserted a partial it does not have, and drew nothing, now draws the framework's. Copy the partial into the application, empty, to keep drawing nothing. |
| **Flash messages are translated** | `Base::addMessage()` and `addError()` keep `t($words)` for a string: the language's translation when it has one, the words otherwise. | An application that translated before flashing loses nothing (a translation of a translation is itself). One whose `addMessage()` override translates can drop it. A test asserting a framework message in English with another language loaded reads the translation. |
| **Scheduled tasks: space, time and links** | `llm:routines` runs a task of another space with that space's assistant; times read back are UTC (`next_run_local` on MySQL changes by PHP's zone); a notification link with no scheme or leading slash gets a `/`; deleting a task deletes its subscriptions and runs. | An application that ran analyst tasks with its own runner can drop it. A screen that worked around the shifted `next_run_local` drops the correction. Set `APP_URL` for an application in a folder, or links from cron point at the site root. |
| **Scheduled tasks: format, gate, link** | `next_run_local` is `d/m/Y H:i` (`19/10/2026 09:00`), not `D j M Y, H:i`; the flash after scheduling is `t('Scheduled. Next: %s.', …)`; the scheduled page's actions in `AiController` sit behind the admin floor (`taskDenied()`); an analyst report's link from a process with no admin area is `admin/Ai/report/N` under the site address, as its conversations' already were. | Code that parsed or matched `next_run_local` reads the new format. A translation keyed on the whole `Scheduled. Next: …` string uses `Scheduled. Next: %s.`. An `Ai` subclass that re-declared a usertype check on the six scheduled actions, or built `TaskPolicy::link()` from `sURL`, can drop it. |
| **Dashboard: four more cards** | The framework's widgets add `queue`, `email` (from the `mails` send history) and `ai.spend` (on a new dashboard) and `logs.errors` (in the catalogue), each offered only to whoever may open the screen it summarises; `health` now comes after them. | Somebody with no saved layout sees the new cards. A layout already saved keeps what it has, and the new cards are in its catalogue. An application with a card of its own under one of those ids keeps its own. To leave them out, register a widget under the id that draws `''`, or set `'dashboard' => ['framework' => false]`. |
| **`push:setup`** | Two more steps, Icon and Routing; `--apply` stops when `push/key` does not answer as the push controller does. A `www/assets/js/push.js` without `data-push-base` and `pf:notification-click` is reported out of date and rewritten on `--apply`. With `push.icon`/`push.badge` set, every notification carries them unless it gives its own. | A deploy script running `push:setup --apply` can now exit non-zero on routing: route `push` to the framework's controller. Run `push:setup --apply` once so the browser script follows notification clicks and works in a folder; a page whose SPA routes itself cancels `pf:notification-click`. |
| **Enqueued assets printed once** | With a theme that calls `renderCss()`/`renderJs()`, each stylesheet and script is on the page once instead of twice; `renderJs()` inside `render()` no longer prints the head scripts, which stay in `<head>`. | Nothing, unless a page relied on a library initialising twice. Code that worked around the doubles (a guard against a second `$.fn.dataTable` init, a second-load check) can be removed. |
| **Shared report readers** | `ai_routines.audience` (migration `2026_10_16_000016`); `TaskPolicy::canSee()` reads it when a report's maker chose readers. Reports without it behave as before. | Run `migrate`. A `canSee()` override that re-implemented an audience can read `$task['audience']` (`min_usertype`, `users`) instead of a setting of its own. |
| **`push/subscribe`, `push/unsubscribe`** | Refused with 403 unless the request carries the session's token (`X-CSRF-Token`) or `Sec-Fetch-Site: same-origin`. The scaffolded worker's assets cache is deleted when the worker activates, and assets loaded by admin/API pages are no longer cached. | A page of your own that subscribes with `fetch()` sends `X-CSRF-Token` from `<meta name="csrf">`. Run `push:setup --apply` so `push.js` does. A copied worker: take the new fetch handler from the stub. |
| **Worker and page cache headers** | The scaffolded worker enables navigation preload; `init` registers it as `ServiceWorker::url('sw.js')` (`?v=<mtime>`); with `cacheControl` configured, the miss that was just stored carries it too. | A copied worker: take the activate and navigate handlers from the stub. Serve the worker with `Cache-Control: no-cache`. A `cacheControl` of `public, …` now also reaches the first visitor: make sure the pages really are public. |
| **`auth` is a token, not the password hash** | `$_SESSION['auth']`, the `auth` cookie and a login response's `auth` are a per-sign-in `RememberTokens` token (`selector.validator`); the `remember_tokens` table holds them (migration `2026_10_16_000001`). A remembered sign-in replaces its token; signing out ends it; a password change ends all of them. Without an auth addon, `authCheck()` now signs in from the cookies. | Run `migrate` — until then sign-ins keep the old credential. `auth($username, $auth, true, true)` — an addon's own remember sign-in, a client re-authenticating — accepts the token. Code that compared `$_SESSION['auth']`, the `auth` cookie or a `userauth` header with `users.password` — an API's own check, a page printing it as `accessToken` — now gets a token: compare with `$_SESSION['auth']`, or use the session plus `X-CSRF-Token`, or an access token. Never print it into a page. After the old cookies have turned over, set `'auth' => ['legacy_remember' => false]`. |
| **`pf-vitals.js` and `pf-calendar.js` are framework scripts** | New `assets/js/pf-vitals.js` and `pf-calendar.js` ship; the `framework_scripts` health check lists them as missing until they are published. Nothing measures until `vitals.sample` is set. | Run `project:resync --js --all`. To measure: `migrate`, `project:install web-vitals`, set `vitals.sample`, and print `ClientVitals::tag()` in the theme's footer. |
| **The worker's error reports** | The scaffolded worker reports each error once, at most five a minute, with `credentials: 'omit'`, and becomes network-only after a failed `caches.open()`; `ClientErrors` closes the session first and rate-limits through the application's cache when there is no APCu. | A copied worker: take `assetCache()`, `store()`, the fetch handler and `reportWorkerError()` from the stub. A report no longer carries the session, so a worker's error has no user id. |
| **A sign-in token is spent where the hash was expected** | `auth($username, $token, true, true)` — an addon's own remember sign-in, a client re-authenticating — signs in and then ends the token it was given; the new one is in `$_SESSION['auth']` and the response. A sign-in in a session that had one ("log in as") ends the token it replaces. | A client that re-authenticates with a stored `auth` must keep the new one it is answered with. An impersonation flow that returned to the administrator by putting the old token back signs in again instead. |
| **`llm_calls.routine_id` is required** | A scheduled run's model calls write `routine_id` (migration `2026_10_16_000017`). | Run `migrate` before scheduled tasks run on the new code: without the column, those calls are not logged. |
| **Page cache and cookies** | A page that queued a cookie with `setcookie()` — other than `harmlessCookies` (`lastseen`, `visitorid`) — is no longer stored; a visitor carrying the framework's remember-me `logged` cookie now bypasses the cache. | A page that sets a cookie of its own and should still be cached: add the cookie's name to `harmlessCookies`, or set it from JavaScript. Expect fewer hits from remembered visitors: they were served the anonymous page before. |
| **"Log in as"** | A plain `loginById()` for another account ends the administrator's token, so coming back needs a sign-in. `Auth::impersonate()` keeps a way back. | Replace a "log in as" built on `loginById()` with `Auth::impersonate($id)` (or POST `Users/loginas/{id}`), print `Impersonation::banner(sURL . 'Impersonation')` in the theme, and run `migrate` (`impersonations`). |
| **`pf-image.js` is a framework script; the profile picture is cropped first** | New `assets/js/pf-image.js` ships, and the `framework_scripts` health check lists it as missing until it is published. The scaffolded profile pages load it; with Cropper.js installed, choosing a picture opens a crop dialog and sends the cropped square at once, without **Upload**. | Run `project:resync --js --all`, and `project:install cropperjs` for the dialog. A profile view of your own adds `ProfilePhoto::uploadAttributes()` to the picture input, an `<img id="profile-photo-preview">` and `ImageUpload::assets()`. Set `profile_photo_upload_size` for a size other than 512. |
| **`_` properties of a user are not saved** | `User::save()` never writes a property whose name begins with `_` to `userdetails`; one that had not been loaded was written on every save. | Nothing to change. Rows written before stay: `DELETE FROM userdetails WHERE fieldname LIKE '\_%'` removes them, after checking that nothing of yours reads one back. |
| **Indexes a fresh installation lacked** | The next `migrate` runs `add_missing_indexes_after_their_tables`, which adds the `tokenactions`/`usertokens` indexes `add_missing_indexes_to_existing_tables` skipped on installations made from scratch — only where no index with those columns exists, under any name. `cascade_usertokens_application` no longer touches a key that is missing or already cascades. | Expect index builds on `tokenactions` on the next `migrate` of an installation made fresh; on a large table, run it outside peak hours. |
| **`encrypt_tokens`** | Token values are encrypted where there is an `APP_KEY` unless `'auth' => ['encrypt_tokens' => false]`; the lookup index of `add_token_lookup_to_usertokens` is plain where the same token is stored twice. | Nothing by default. An application whose own code still reads `usertokens.token` sets `encrypt_tokens` to `false` until it reads `token_lookup` instead. |
| **OAuth signing keys are never made by a request** | `Oauth` no longer generates `app/keys/private.key`/`public.key` (or `encryption.key`) when they are missing; the token endpoint refuses with "run `php bin/pramnos oauth:keys`" and the `signing_keys` check is down. | Run `php bin/pramnos oauth:keys` once (as the web server's user, or as root with `--owner=www-data`) and back `app/keys/` up. An installation that relied on the first request making them, a container per deploy included, must make them at deploy time — and keep them, or every deploy signs everybody out. |
| **MCP takes only access tokens** | A bearer on `/<version>/mcp` must be an access token (`tokentype` `access_token`); the API's sign-in JWT is answered `401`. `mcp:token` signs with the API's key and prints `<api>/<version>/mcp`; `--url` is the API's base. | A client that called MCP with a sign-in token gets one through the OAuth flow or `mcp:token`. Re-mint tokens made with `mcp:token` before this, if they answered `401`. |
| **`/.well-known/health` and `authorization_servers`** | The health answer is `status` and `timestamp`, `components` only with `'health' => ['public_details' => true]`. `authorization_servers` is the issuer with its trailing slash (`https://host/`). | A status page that read `components` sets `public_details`. A client that matched `authorization_servers` without the slash compares with the issuer instead. |
| **Moments without an offset on MySQL and MariaDB** | The language-model tables and the push log write the bare UTC wall clock on MySQL/MariaDB (`Instant::utc()`), the activity log PHP's local time (`Instant::local()`). PostgreSQL keeps the offset. | Nothing on MariaDB or on a UTC session: what is stored is what was stored. On MySQL 8 with a session in another zone, rows written before keep their stored moment and read back shifted by the offset, as they did before; new rows read back right. A routine scheduled before the update can fire once early or late by that offset, and then follows the new rows. |
| **`img-src` allows `blob:`; `project:resync` syncs `components.css`; activity details are redacted** | The framework's CSP allows `blob:` images. A plain `project:resync` also refreshes `www/assets/css/components.css` from the theme `scaffold_theme` names, keeping a copy the project changed. A secret-named key in `ActivityLog::record()` details is stored as `[redacted]`. | Nothing for most. A project whose `components.css` predates the record `init` keeps is reported `kept`: compare it, then `project:resync --css --overwrite`. A screen that showed a token or password from activity details shows `[redacted]`, which is the point. |
| **The activity log with the `auth` feature off** | `ActivityLog::record()` writes whenever `authserver.user_activity_log` exists; it skipped every event while the `auth` feature was off. | Nothing to change. An application with the feature off but the table in place now gets its sign-in, impersonation and data events recorded; expect the table to grow. |
| **`Users/endsession` takes `sid`; the page cache keys from the arriving query** | `Users/endsession` ends the session whose `sid` is POSTed; a `visitorid` field is ignored. A page whose controller rewrites `$_GET` is now served from the cache, where before it was stored and never hit. | A form of your own that POSTs `visitorid` to `Users/endsession` sends `sid` instead. Check that a page that rewrites `$_GET` is really public, since it now gets cache hits. |
| **`applications.oauth2_active_tokens` on PostgreSQL** | It is one row per live token again (`tokenid, userid, tokentype, token, created, expires, lastused, scope, ipaddress, client_name, client_id, username, email`). The per-application count that held the name (`appid, token_count, expired_count, revoked_count, avg_expiry_days`) is now `applications.oauth2_active_tokens_summary`, as it always was on MySQL. Migration `rename_oauth2_active_tokens_summary` moves it on an existing installation. | **Any SQL, report or dashboard that reads `token_count` (or the other count columns) from `applications.oauth2_active_tokens`** must read `applications.oauth2_active_tokens_summary` instead. If you built a view on the summary, the migration fails on the `DROP VIEW` and names it: drop or repoint your view, then migrate again. |
| **`User::setPassword()` dates a change; `/me` carries `password_changed_at`** | Giving an account that already has a password a *different* one now sets the `passwordchangedate` userdetails field, which `save()` writes. That covers the change and reset pages, the admin user form and `pramnos user:password`. A first password, or the same one again, sets nothing. `GET /me` has a new key, `password_changed_at` (ISO 8601 or `null`). `User` gains `getPasswordChangeDate(): ?int`. | **A `User` subclass that defines `getPasswordChangeDate()`** must declare it `public function getPasswordChangeDate(): ?int`, or it will not load. If yours did what the base class now does, delete it, and delete a `setPassword()` override that only dated the change. **A client that validates `/me` against a closed schema** must allow the new key. |
| **Webhook signing secrets can be shown; an empty one is generated** | `ApplicationsController::webhooksecret()` (POST, administrators) shows one endpoint's stored secret, decrypted, and logs `webhook_secret_revealed`. `WebhookService::endpointSecret(int $appId, int $webhookId): ?string` reads it. `WebhookService::saveEndpoint()` given an empty secret now stores a generated one instead of `''`. Every generated secret (admin screen, regenerate, `/Webhook/register`) is now `whsec_` + 64 hex characters (`WebhookService::generateSecret()`); it was 64 hex characters. | **A client of `/Webhook/register` that validates the returned `secret` as 64 hex characters** must accept the `whsec_` prefix; use the whole string as the HMAC key. **A caller of `saveEndpoint()` that passes `''`** and then signs or verifies with `''` must read the stored secret with `endpointSecret()`. **A subclass of `WebhookService` or `ApplicationsController`** that defines `endpointSecret()` or `webhooksecret()` must match these signatures. **An application's own copy of `applications/view.html.php`** gains no button: add a POST form to `applications/webhooksecret/{webhook_id}` with the CSRF field and `appid`, as the scaffolded view does. |
| **Every built-in webhook type now fires, to the user's own applications** | `user_deauthorized`, `account_deleted` and `scope_changed` are queued for the first time; `token_revoked` also on `User::deleteToken()`; `gdpr_request` also on a data export, `user_profile_changed` also on a profile save. `Gdpr::request()` queued `gdpr_request_created` and `Gdpr::notifyChange()` `profile_changed` — names no endpoint can subscribe to — and now queue `gdpr_request` and `user_profile_changed`. `Gdpr::deauthorizeAll()`'s `token_revoked` went to every subscriber of the type and now goes to the user's applications only. The new events' bodies start with `event_type` and `user_id`. `device_deauthorized` is queued with no user instead of `0`, which the foreign key refused. | **An endpoint subscribed to one of these types starts receiving events** — make sure its handler exists and verifies the signature. **A receiver that relied on `token_revoked` from `deauthorizeAll()` for users who never used it** no longer gets those. Bodies are listed in the AuthServer Integration Guide's webhook table. |
| **The conversations' sidebar reads `conversation_members.starred`** | A migration adds the column; `Conversations::forPerson()` selects it and each conversation carries `starred`. The sidebar is in sections (starred, channels, direct, system) and the dock no longer opens a small window for a new message while the conversations are open in their own window. | **Run `php bin/pramnos migrate`.** Until then `/admin/Conversations/conversations` fails and the page and the dock show nothing. An application that rebuilds `components.css` into a stylesheet of its own rebuilds it for the new `pf-conv-side-*` and `pf-conv-dock-face*` rules. |
| **`AiController` gains `all()`, `readsAll()` and `reader()`** | A public action `all` (`/admin/Ai/all`, every administrator's Ask conversations read only, for `admin.ai.all`), protected `readsAll(?object $user): bool` and `reader(): ?object`; the Ask page's `urls` gain `all`, and `chat/chat.html.php` links to it when set. | An application's `Ai` controller with a method of one of these names and another signature fails to load: rename yours. A published `chat/chat.html.php` shows no link until it is taken again; the page works at its address without it. |
| **The conversations' views need `conversation_saved`** | A migration creates `conversation_saved`; `messages/{id}` answers with `saved` beside `pinned`, and the sidebar's *Saved for later*, *Threads*, *Activity*, *Drafts and sent* and *All unread* read new endpoints. | **Run `php bin/pramnos migrate`.** Until then opening a conversation fails (`messages/{id}` reads the table). An application that rebuilds `components.css` rebuilds it for the `pf-conv-inbox-*` rules. |
| **`@Ask` answers with the least privileged reader's rights** | `@Ask` in a conversation others read is answered with the rights of its least privileged reader — `Turn::rightsOf()`, set through `extra['rights_of']` — without the asker's memory or `export_csv`; `AnalystAssistant::tools()` builds the framework's tools for `rightsOf()`. A conversation with Ask (`bot`, named "Ask") is new, with a `withask` action on `Controllers\Conversations`; `Ask::queueWith()`'s callable gets a fourth argument, the analyst conversation it goes on. | An `AnalystAssistant::tools()` override that cuts tools by `$turn->scope->userId` keeps the asker's rights in channels: decide by `$turn->rightsOf()`. A `Conversations` controller subclass with a `withask` method of another signature: rename it. |
| **Help pages' headings are the levels they write** | `Help::html()` (the help screen, its panel, the help API) draws `#` as `<h1>`, `##` as `<h2>` and so on; it drew each two levels lower, as a chat reply's are. `Chat\Markdown::toHtml()` takes `'headingShift'`, 2 by default. | CSS that styled a help page's headings as `h3`–`h6` — written for the shifted levels — now targets `h1`–`h4`. A help page that relied on the shift to keep its `#` small: write `##`. |
| **The conversations import nothing** | The `Conversations\Import` namespace, the `conversations_import` queue task, `/admin/ConversationImports` (`admin.chat.import`, `Access::IMPORT`, `Access::imports()`), the actions and page of a person's connection to another chat, the client's prop for it, and the migration `create_conversation_imports_table` are removed. `SystemPost::post()` reads its text as Markdown, as every message; its converter from another chat's marks is removed. | Code that used `Conversations\Import` writes through `Messages::find()`, `Messages::post()` (`source`, `externalid`, `at`, `editedAt`, `authorName`, `quiet`), `Messages::react()` and `Files::store()`, and keeps its tables with migrations of its own; an installation that has `conversation_imports` and `conversation_import_tokens` and no use for them drops them. A `SystemPost::post()` text written in another chat's marks (`*bold*`, `<url\|label>`) is written in Markdown. |
| **A column's `collation()` and `charset()` are written** | On MySQL and MariaDB, `ColumnDefinition::collation()` and `charset()` now emit `CHARACTER SET … COLLATE …` after the column's type; they were stored and ignored. PostgreSQL still ignores them. `conversation_reactions.emoji` becomes `utf8mb4_bin` (migration `make_conversation_reactions_emoji_binary`). | A migration that calls `->collation(` or `->charset(` now gets that collation on MySQL: check the name is one the server has, or remove the call where the table's default was meant. Code comparing reactions' emoji in SQL compares them exactly. |
| **The conversations' full page is the application's** | `/admin/Conversations` is a basic page (`pf-conversations-basic.js`, `Client::basic()`); the sidebar, the views above the conversations, the quick switcher, the conversations' own window and install, and the beacon's browser client are gone from `pf-conversations.js`, which draws the dock alone (its list is its own). The actions `manifest` and `worker`, `resources/js/conversations/sw.js`, and `Client::props()`'s `app` are removed; `Client::app()` stays. The `pf-conv-*` rules nothing draws any more are removed from the themes' `components.css`. | An application that wants the full chat draws its own page on the JSON API, with its own controller named `Conversations` and the class `pf-conv-page` on its body; one that served the manifest or worker from the framework serves its own; a stylesheet compiled from `components.css` is rebuilt. Code that called `PfConversations.start()` calls `PfConversationsBasic.start()` or its own client. |
| **The segments and groups lists are datatables** | `Segments::display()` gives its view `datatable` (server-side, rows from `Segments/data`) and `counts`, no longer `rows`; `Groups::display()` gives `datatable` (rows from `Groups/data`), no longer `groups`. | A published copy of `views/segments/segments.html.php` or `views/groups/groups.html.php` that reads `$this->rows` or `$this->groups` renders `$this->datatable->render()` instead, or is deleted to use the framework's. An application `Segments` or `Groups` controller that overrides `display()` without the parent keeps its own list. |
| **Groups and segments: a tab each, groups first; a group's members paged** | `/admin/Segments` has the tabs groups, segments and mine, groups shown first; `?kind=all` and the *kind* column are gone; each tab has its own datatable (`pf-groups-table`, `pf-segments-table`, `pf-mine-table`), whose `Segments/data` request carries `tab`, not `kind`/`mine`; its view gets `tab`, `counts` (groups, segments, mine) and `datatable`, no longer `kind` or `mine`. The menu item reads *Groups & segments*. `Groups::view()` gives `members` as a datatable (rows from `Groups/members/{id}`) and `count`, `uses`, `membersUrl`, no longer the members' rows. `Groups::delete()` refuses a group in use. | A published `views/segments/segments.html.php` or `views/groups/view.html.php` is copied again or deleted; one reading `$this->members` as rows renders `$this->members->render()`. Links to `?kind=all` land on the default tab; `'audience' => ['default_tab' => 'segments']` keeps segments first. A delete that must go through while something uses the group removes that use first. |
| **The Groups screen opens with the tables alone; a group's page redrawn** | `Controllers\Groups` refuses only where the `usergroups` feature is off **and** its tables are missing (`groupsHere()`), where it refused whenever the feature was off. Its page `views/groups/view.html.php` is `pf-*` markup with every word through `t()`; the document title is the group's name, not `Group: …`; the breadcrumb's first crumb is the library's groups tab. | An application that kept the screen shut by leaving the feature off while it has the tables shuts it its own way (a subclass's `refused()`, or the `admin.groups` grant). A published copy of the view is copied again or deleted. |

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
