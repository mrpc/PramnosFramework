---
use_cases:
  - Rendering a dropdown filter outside any form
  - Rendering a search box, checkbox or textarea with no form around it
  - Placing the cross-entity search box somewhere other than the header
  - Building pagination links for a listing page
  - Choosing between Html\Select and a form field
  - Finding out which reusable HTML components the framework already ships
  - Changing how a component looks in a theme, or adding a theme
  - Attaching a validation message to the field it belongs to
---

# HTML Components Guide

`Pramnos\Html\*` holds the reusable building blocks that render markup on their own —
each one constructed, configured with public properties, and turned into a string by
`render()`.

| Class | What it renders |
|---|---|
| [`Select`](#select) | a `<select>`, with no form around it |
| [`Input`](#input) | one input, textarea, checkbox or radio, with no form around it |
| [`SearchBox`](#searchbox) | the cross-entity search box in an admin header |
| [`Pagination`](#pagination) | page links from a count, a current page and a URL pattern |
| [`Datatable`](#datatable-and-its-datasource) | a DataTables-backed table, with server-side paging and filters |
| [`Breadcrumb`](#breadcrumb) | a breadcrumb trail, plus its `BreadcrumbList` structured data |
| [`Date`](#date-and-time) | a date field — datepicker or three dropdowns — with an optional time |
| [`Time`](#date-and-time) | a native time field, and what `Date::$time` renders |
| [`Icon`](#row-actions-as-icons) | one inline SVG glyph, or an icon wrapped in a link |
| [`Seo`](#seo) | a canonical link and a `ld+json` structured-data block |
| [`Form\Field`](#formfield) | one field **inside a form**, with a label and a style preset |

Every one of them also renders when cast to a string, so `echo $component` works.

---

## Overriding a component's class names

Five routes, in the order you should reach for them.

### 1. Write CSS — for any `pf-*` hook

The commonest case, and it needs no PHP at all. A component emits a neutral hook; your stylesheet
decides what it means:

```css
.pf-pagination { gap: 1rem; }
.pf-pagination .current { background: var(--brand); }
```

Twenty-three hooks work this way, and all three scaffolded themes already carry rules for every one
of them — so you are overriding a rule, not inventing one:

```
pf-action · pf-action-danger · pf-breadcrumb · pf-footsearch · pf-home · pf-icon · pf-muted
pf-omnibox (+ 9 pf-omnibox-* children) · pf-pagination · pf-skip-link · pf-state (+ -on/-off)
pf-visually-hidden
```

### 2. Declare the names once — `component_classes`

For the case a stylesheet cannot answer: markup that must carry a **specific** class because
something other than CSS is looking for it. A jQuery plugin doing `$('.breadcrumb')` reads the
name, not the rule.

```php
// app/app.php
'component_classes' => [
    'breadcrumb'         => 'breadcrumb',
    'pagination'         => 'pagination',
    'pagination.current' => 'active',
    'icon'               => 'icon',
    'action'             => 'btn',
    'omnibox'            => 'search-wrapper',
],
```

Every key, with the hook it replaces, is in `Html\ComponentClasses::KEYS`. An unlisted key is
reported by `ComponentClasses::unknownKeys()` rather than ignored — a misspelling is otherwise
silent, and silence is indistinguishable from a feature that does not work.

An empty string means **no class**, and is honoured: there the caller did speak.

**Why configuration and not the property below, when the property already existed.** Because the
objects are not all yours. A `Breadcrumb` is constructed in eight places in a scaffolded project —
`Document`, `Application`, and an `admin_breadcrumb` and `account_breadcrumb` partial in each of
three themes — and **two of those are inside this framework**, on the path that renders every page.
A per-object property covers six of the eight. Read at construction, one declaration covers all of
them.

### 3. Replace the class on one object — where a property exists

For one object rather than the project — a single breadcrumb that has to differ from the rest.
Configuration decides the default; this decides the outcome, and wins.

| Component | Property | Default | Controls |
|---|---|---|---|
| `Pagination` | `$containerElementClass` | `pf-pagination` | the container |
| | `$currentPageClass` | `current` | the current page's link |
| | `$containerElement` | `div` | the element itself, not just its class |
| `Breadcrumb` | `$listClass` | `pf-breadcrumb` | the `<ol>` |
| | `$extraStyle` | `''` | inline style on the container |
| `Datatable` | `$tableClass` | `display` | the `<table>` |

```php
$breadcrumb->listClass = 'breadcrumb';        // Bootstrap's own name
$pagination->containerElementClass = '';      // no class at all
$pagination->containerElement = 'ul';         // and a different element
```

### 4. Add alongside — `$extraAttributes`

`SearchBox`, `Select` and `Input` take a raw attribute string, so you can add classes without
replacing what is there:

```php
$select->extraAttributes = 'class="my-select" data-role="filter"';
```

It is **not escaped** — it exists to carry markup, so never build it from user input.

### 5. Form fields — `FieldStyles`, keyed by what the application declared

`Field` takes no class properties. Its classes come from a per-theme preset:

```php
echo $field->render(FieldStyles::for('tailwind'));
```

You rarely need to name the theme. `app/app.php` already carries `scaffold_theme` — the UI
framework the project was generated against, in this same vocabulary — and it is the default:

```php
new SettingsForm('settings');                 // uses scaffold_theme
new SettingsForm('settings', 'bootstrap');    // unless you say otherwise
FieldStyles::configured();                    // what that resolves to
```

Add a theme by adding an entry to the preset array; `group`, `label`, `input`, `select`, `area`,
`check` and `help` are the keys. Keep it in step with the scaffolding directories — a project on a
framework with no preset silently renders as `plain`, and a test asserts the two lists match.

### And colours come from one file, not from any of this

None of the five routes is where a palette lives. `app/themes/theme.css` holds every colour and
radius for the project, in daisyUI's theme-generator format, and `pramnos theme:build` propagates
it to `www/assets/css/theme-tokens.css`, `www/assets/theme-tokens.json` and
`ThemeTokens::token()` for PHP. See the [Theme guide](Pramnos_Theme_Guide.md#one-palette-every-ui-system).

So a `pf-*` rule should be written in those tokens rather than in literals:

```css
.pf-pagination .current {
    background: var(--primary-color, #2563eb);
    color: var(--white, #fff);
}
```

A rule with a hardcoded colour is a rule the palette cannot reach, and the failure shows up as one
component that did not change when everything else did.

### What you cannot replace, and why it does not matter

`Icon` emits `pf-icon` and `SearchBox` emits four `pf-omnibox-*` names, with no property for any of
them. **Restyle them with CSS (route 1)** — they are neutral hooks, so a rule is the whole answer
and a property would only be a second way to do the same thing.

The real exception is `Datatable`. Nine of its classes are a CSS framework's own —
`btn btn-default dropdown-toggle`, `form-control`, `ui-buttonset` — chosen by `$jui`:

```php
$datatable->jui = true;    // jQuery UI markup instead of Bootstrap 3
```

Two looks, and no route to a third. It is the one component you cannot bring onto a different CSS
framework without editing the class, and the only one where route 1 does not save you.

## Why there are two mechanisms

Routes 1 and 4 above look like opposites — one hides the theme's classes behind a neutral name, the
other puts them in the markup. Both are right, for different kinds of class.

Tailwind's entire model is utilities in the markup. Hiding ten of them behind a `pf-input` and
re-applying them with `@apply` fights the framework it is meant to serve, and leaves a stylesheet
nobody on a Tailwind project expects to have to read. That is why `Field` uses a preset.

A breadcrumb's `<ol>`, on the other hand, is one element with one meaning. It wants one hook.

> A **structural** class — one per element, standing for *what this is* — is `pf-*`, and the theme
> dresses it.
>
> A **utility-dense** set the theme wants visible in the markup is a keyed preset.

Getting this backwards is how `Breadcrumb` came to emit `class="breadcrumb"` — Bootstrap's own name,
as a literal, in framework markup. It reads as neutral and is not: a Tailwind project got an element
carrying a name nothing in its stylesheet defines.

**Every hook has a rule in every scaffolded theme, and a test asserts it.** A hook with no rule
anywhere is the failure this convention exists to prevent, and it is invisible — the markup is
correct and the page merely looks wrong, in a way people blame on their own stylesheet.

## Select

```php
$status = new \Pramnos\Html\Select('statusSelect', $current);
$status->addOption('All', '');
$status->addOption('Active', 1);
$status->addOption('Inactive', 0);

echo $status->render();
```

**The label is the first argument.** `addOption($label, $value)`, not the other way round.

### When to use this rather than `Form\Field`

`Form\Field` renders a `<select>` too, and it is the right thing for a field in a form: it
carries a label, a title, validation state, and its `render()` takes the form's style
preset.

`Html\Select` is for a `<select>` with no form around it. The commonest case is a footer
filter in a `Datatable`, where the rendered string is handed to `addColumn()` as markup:

```php
$table->addColumn('Status', true, false, true, '', $status->render(), …);
```

| | `Html\Select` | `Form\Field` |
|---|---|---|
| needs a form | no | yes |
| `render()` arguments | none | a `$styles` preset |
| carries a label | no | yes |

### Options

```php
$select->addOption('Greece');                    // label is also the value
$select->addOption('Pinned', 'pinned', true);    // selected regardless of $current
$select->addOptions([1 => 'one', 2 => 'two']);   // value => label, in bulk
$select->hasOptions();                           // for a query that returned nothing
```

### Multiple

```php
$tags = new \Pramnos\Html\Select('tags', [2, 3]);
$tags->multiple = true;
```

The name becomes `tags[]`, which is not cosmetic: without the brackets PHP keeps only the
last submitted value and a multiple select silently behaves like a single one. An array
current value selects every option it holds.

### Two things it does that its predecessor did not

**It escapes.** Labels and values go through `htmlspecialchars()`. The class this replaces
concatenated them into markup untouched, and its documented use is a filter populated from
a database column.

**It compares as strings.** The current value is matched with `(string) $a === (string)
$b`. The old `==` gave a different answer for `0 == ''` on PHP 7 than on PHP 8, so a select
with an "All" option of `''` and a current value of `0` selected a different option
depending on the interpreter.

### Other attributes

```php
$select->id       = 'status-filter';   // no id is emitted unless you set one
$select->required = true;
$select->extraAttributes = 'data-role="filter"';
```

No `id` is invented from the name, deliberately: two selects for one field on a page is
ordinary — a filter above a table and another below it — and duplicate ids are invalid
HTML that breaks `<label for>` and `getElementById` with no visible error.

---

## Input

```php
$search = new \Pramnos\Html\Input('q', $current);
$search->placeholder = 'Search…';
echo $search->render();
```

The companion to [`Select`](#select), and there for the same reason: `Form\Field` is for a
control **in a form** and its `render()` needs the form's style preset; a filter above a
table has no form.

### One class, not five

It replaces four legacy classes — `input`, `checkbox`, `colorpicker` and a `time` widget —
and covers more than they did. The old `input` was a *dispatcher*: `type` decided, and for
`date`, `time`, `checkbox` and `color` it constructed a different class and forwarded a
dozen properties. Most of that existed because those input types did not work in browsers
when it was written. `<input type="color">` and `type="time"` have been native for years,
and what is left once the widgets are gone is a tag with attributes.

### Types

```php
$input->type = 'number';   // text hidden password email url tel search number range
                           // date time datetime-local month week color
                           // checkbox radio file
                           // textarea
```

`textfield` is accepted as a spelling of `text` and `colorpicker` as one of `color`,
because the classes this replaces used them. An unrecognised type becomes `text` rather
than being emitted — a browser treats an unknown type as text anyway, so passing a typo
through only adds an attribute a validator will reject.

`textarea` is in the list even though it is not an `<input>`: it is the same job from a
caller's point of view, and `Form\Field` already treats it as a type. The value goes
between the tags.

### Checkbox and radio

```php
$active = new \Pramnos\Html\Input('active', $row['active']);
$active->type = 'checkbox';           // checked when $row['active'] === '1'
$active->checkedValue = 'yes';        // for a column that stores something else
```

`value` is the **current state**; `checkedValue` is what gets **submitted**. Swapping them
renders a checkbox that submits whatever the row already held — which looks correct until
somebody unticks it and the old value is saved back.

### Attributes

| Property | Applies to |
|---|---|
| `label` | all — rendered before the control, escaped, no trailing colon |
| `id` | all — none is emitted unless you set one |
| `required` / `readonly` / `disabled` | all |
| `placeholder` | everything except checkbox, radio, file, color |
| `min` / `max` / `step` | `number`, `range` and the date/time types |
| `maxlength` / `minlength` / `size` / `pattern` | the textual types |
| `title` | all — the tooltip, and the message a failed `pattern` shows |
| `inputmode` | all — the on-screen keyboard (`numeric`, `tel`, `decimal`, …) |
| `rows` | `textarea` (and `size` becomes `cols`) |
| `autocomplete` | all |
| `multiple` | `file` — appends `[]` to the name |
| `extraAttributes` | all — rendered verbatim, **not escaped** |

Each is emitted only where it applies, so a text input does not arrive carrying `min=""`.
`min` on a text field is invalid markup and a validator is the only thing that will ever
tell you.

Two more things it does without being asked: **a file input carries no value** — every
browser ignores it, validators reject it, and it is the one type whose value could only
have come from the server; and **no `id` is invented from the name**, for the same reason
as `Select` — two controls for one field on a page is ordinary, and duplicate ids break
`<label for>` and `getElementById` with no visible error.

### Native validation, without a JavaScript library

```php
$code = new \Pramnos\Html\Input('code');
$code->pattern   = '[0-9]{4}';
$code->title     = 'Four digits, e.g. 1234';   // what the browser says when it fails
$code->minlength = 4;
$code->maxlength = 4;
$code->inputmode = 'numeric';                  // the right keyboard on a phone
$code->required  = true;
```

**Always set `title` alongside `pattern`.** Without it the browser reports "Please match
the requested format", which tells the user they are wrong and not what right looks like.
It is text written for a person, so it is escaped — which is why it is a property rather
than something to pass through `extraAttributes`.

Error messages themselves are the browser's, in the browser's language. That is more
correct than a framework's own translated strings and needs nothing here.

### What it does not do

No `validate` / `addcss` / `addjs`. The legacy properties of those names made an element
push CSS and JavaScript into the document while rendering itself, so echoing a search box
changed the page's asset list. `Document::addScript()` and `addStyle()` are how a page
declares what it needs.

---

## PasswordToggle

The «show password» control that sits beside a password field. First recommendation of
[web.dev's sign-in form guidance](https://web.dev/articles/sign-in-form-best-practices), and the
reason is mobile: on a phone the commonest cause of a failed sign-in is a typo in a field nobody can
read. Someone who cannot see what they typed retries the same wrong thing, then resets a password
they never forgot.

```php
<label for="password">Password</label>
<input type="password" name="password" id="password" autocomplete="current-password" required>
<?php echo \Pramnos\Html\PasswordToggle::render('password', 'Show', 'Hide'); ?>
```

`render(string $inputId, string $showLabel = '', string $hideLabel = '', string $class = '')`. The id
is the `id` of the input it controls; the labels default to translated «Show password» / «Hide
password». The class is optional and normally left off — see below.

### It goes *after* the input, and it is an eye

Both of those are the same fix, arrived at twice.

The control renders as an eye icon, and its own script moves it inside the field's right-hand edge at
runtime — wrapping the input in a `position: relative` span, positioning the button absolutely and
adding right padding so a long password does not run underneath it. Nothing in a view or a theme has
to change for that, and there is no stylesheet to ship.

It is done in JavaScript rather than in the markup because the two requirements pull in opposite
directions. The button has to come **after** the input in the document: with no `tabindex` anywhere,
tab order *is* document order, so a control written into the label row above the field means tabbing
off the username lands on «Show» instead of on the password box — reported as «the tabindex on the
login form is wrong», and it was, by being absent. Absolute positioning moves the button visually
without moving it in the document, so the tab order stays right and the icon still sits in the field.

`tabindex="-1"` on the button would have fixed the tab order too, and is the wrong answer: it makes
the control mouse-only, which removes it for the visitor most likely to need it.

The words are the **accessible name**, not the visible content: `aria-label` and `title` carry them,
and both are swapped along with `aria-pressed` so a screen reader hears the new state. A bold «Show
password» printed under the box is louder than the field it belongs to, for something most people
never press.

The icon is an inline SVG sized in `em` and stroked in `currentColor`, so it fits a theme nobody told
it about, and it works in a project with no icon set, no build step and no network. The `$class`
argument still exists for a caller that wants its own button styling, but a theme's `btn` brings
padding, a border and a background that fight the positioning — the scaffolded views pass nothing.

### The button ships `hidden`

It is rendered with the `hidden` attribute and unhidden by its own script. A control that cannot do
anything is worse than no control: without JavaScript a visible «show» button is something a person
presses twice and then distrusts the rest of the form. A no-JS visitor sees exactly the form they saw
before, and the field never depended on the script.

### The script repeats, on purpose

Every button carries the script, and the script guards itself in the browser — the first copy binds a
delegated listener on `document`, the rest return immediately.

The first version kept a static «already emitted» flag so it went out once per page. That flag is
**process** state, not request state: a process that renders more than one response — an in-process
test client, a long-running worker, anything producing two pages — gave the second page a button with
no listener behind it. A few hundred repeated bytes is the better trade against a bug that only
appears outside a plain request, which is exactly where nobody looks for it.

### What it does not touch

Only `type` changes. `name`, `id` and `autocomplete` stay as they are, because those three are what a
password manager matches on — a toggle that renamed the field would stop it offering the saved
password, which costs more than an unreadable field does. Focus and the caret position are preserved
too: toggling mid-word and losing your place is the same frustration in a different shape.

### The id is checked, not escaped

It reaches an HTML attribute *and* a `getElementById` call. An id is written by a developer as a
constant, so anything outside `^[A-Za-z][A-Za-z0-9_:.-]*$` raises `InvalidArgumentException` rather
than being quietly encoded into a control that addresses nothing.

## SearchBox

```php
echo (new \Pramnos\Html\SearchBox())->render();
```

The markup for the cross-entity search box. The results come from
[`Search\Registry`](Pramnos_Search_Guide.md), the behaviour from `data-pf-omnibox` in
`assets/js/pf-utils.js`, and the styles from `assets/css/style.css` — all three already
on a scaffolded page, so this contributes **no script and no stylesheet** of its own.

A scaffolded theme renders it in the header already. Reach for this class only to put a
second one somewhere else.

```php
$box = new \Pramnos\Html\SearchBox('/api/1.0/admin/search');
$box->placeholder       = 'Find anything…';
$box->id                = 'sidebar-search';   // renames the input, label and panel together
$box->minimumCharacters = 3;
$box->debounce          = 400;
$box->label             = 'Αναζήτηση';
```

The endpoint defaults to `<api_prefix>/admin/search`, read from `app/app.php` rather than
assumed — a project served under `/v1` would otherwise get a box pointing at a 404, and a
404 on a search box reads as "search is broken".

### The one component that does default an `id`

`Input` and `Select` deliberately invent none. This does, because `aria-controls` and
`aria-activedescendant` are associations *by id*, and without them a screen reader has a
text field and an unrelated list rather than a combobox. One box per page is also the
premise, so the collision risk that made the others refuse does not apply — set `id` if
you need two.

The accessible name is a real visually-hidden `<label>`, not an `aria-label`: an
`aria-label` is invisible to a translation tool that only reads element text, and the
result is a translated interface whose search box announces itself in English.

---

## Breadcrumb

```php
$bc = new \Pramnos\Html\Breadcrumb();
$bc->addItem('Home', sURL);
$bc->addItem('Users', sURL . 'users');
$bc->addItem('Alice');          // no url — the current page
echo $bc->render();
```

It emits the trail twice: as an `<ol class="breadcrumb">` for the reader, and as
`BreadcrumbList` JSON-LD for a search engine, from the same items.

**A label is rendered as HTML; a title and a URL are escaped.** Callers pass markup in a label
on purpose — an icon, an emphasised word — which is why the structured-data name is
`strip_tags()`d rather than escaped. So a label built from user input has to be escaped by
whoever builds it. The `title` and `href` attributes have no such contract and are escaped
here: a title defaults to the label, and one double quote in a name would otherwise end the
attribute and make everything after it markup the visitor chose.

### Every label is wrapped in a heading, and your CSS has to know

```html
<li class="breadcrumb-item"><h5><a href="…"><span>Users</span></a></h5></li>
<li class="breadcrumb-item active" aria-current="page"><h4><span>Alice</span></h4></li>
```

The descending heading levels are how the trail expresses depth to a crawler. A heading is
a **block element**, and Tailwind's Preflight strips its size but not its `display` — so a
stylesheet that styles `.breadcrumb` and forgets the headings gets a trail where each label
fills its own line and the separators drop underneath:

```
Home Dashboard
 /        /      Tokens
```

Which is what it looks like in a browser, and nothing in any log. Reset them:

```css
.breadcrumb-item h1, .breadcrumb-item h2, .breadcrumb-item h3,
.breadcrumb-item h4, .breadcrumb-item h5, .breadcrumb-item h6 {
    display: inline;
    margin: 0;
    padding: 0;
    font-size: inherit;
    font-weight: 400;
    line-height: inherit;
}
```

All three bundled themes carry that rule in their `style.css`. A hand-written theme, or an
application compiling its own Tailwind, needs it too.

**`render()` does not escape labels.** It is documented that way because a caller sometimes
wants markup in a crumb — so anything dynamic has to go through `htmlspecialchars()` before
`addItem()`.

---

## Date and time

```php
$when = new \Pramnos\Html\Date('startdate', $record->starts);
echo $when->render();
// …and on the way back in
$record->starts = $when->getDate('post');
```

One `dd/mm/yyyy` text field with a datepicker attached. `getDate()` reads it back from
`{name}_datepicker`, which is the name `render()` emitted — the round trip is the contract, and
the wire format is deliberately not `type="date"`, because a native date input posts
`YYYY-MM-DD` and every receiving end already parses the other one.

### A time beside the date

```php
$when->time = true;             // adds a Time widget, posting {name}_timepicker
$when->timeChangeLine = false;  // …on the same line
```

`getDate()` combines the two into one timestamp.

The time field itself is a native `<input type="time">` — `Pramnos\Html\Time`, usable on its
own — which submits `HH:MM` on every platform that matters. The browser draws the clock,
validates the value and localises how it is displayed.

### Three dropdowns instead of a datepicker

```php
$birth = new \Pramnos\Html\Date('birth', $user->born);
$birth->calendar = false;              // day / month / year boxes
$birth->dropdownYear = true;           // year as a <select> too
$birth->dropdownRequireSelect = true;  // start empty — "not answered" stays visible
$birth->dropdownLabels = false;        // drop the D: M: Y: labels
```

A birth date is the case this exists for: a datepicker asking somebody to page back forty
years is worse than picking a year from a list. Without `dropdownYear` the year is a
`type="number"` field bounded by `minyear`/`maxyear`, rather than a two-thousand-option list.

**`dropdownRequireSelect` is the one worth understanding.** Without it the boxes pre-select the
current value, and a field nobody filled in comes back as a real date the visitor never chose
— indistinguishable from one they did. With it, nothing is selected until somebody selects it,
and `getDate()` returns `0` for an unanswered field.

These boxes post as `{name}day`, `{name}month` and `{name}year`; `getDate()` reads whichever
set `$calendar` says to expect.

---

## Datatable, and its Datasource

`Datatable` renders the table and its JavaScript; `Datatable\Datasource` answers the AJAX
requests DataTables makes. This section covers what a caller configures, not a full tour.

### Waiting before searching

```php
$table = new \Pramnos\Html\Datatable('users', sURL . 'users/data');
$table->searchDelay     = 1200;   // ms; default 500
$table->minSearchLength = 3;      // characters before a column filter searches; 0 = no guard
```

The table debounces in two places — its footer filters' own `keyup` handler, and
DataTables' `searchDelay` option for the global box — and `searchDelay` feeds both. It is a
property because it is not one number for every table: a list with six `LEFT JOIN`s behind it
wants a second or more, against `600` on a light report. With a fixed value the query rate on
exactly the heaviest lists is the one nobody can lower.

`minSearchLength` guards the **per-column footer filters** (`footerTextSearch`). Without it
each keystroke is one AJAX request and one server-side query: typing `papadopoulos` into a
column filter sends twelve, and the first of them is `LIKE '%p%'` across the whole table.

An **empty** box is always let through, whatever the minimum — clearing a filter has to clear
the filter, or the column stays filtered on a term no longer on screen.

### Filtering one column

One box over every column answers *find this person*. It cannot answer *the administrators
registered this month*, which is most of what an operator asks a list — so a column can carry
its own filter:

```php
$table->footerTextSearch = true;          // a text box under every column that asks for one

//                 label   visible  sortable  searchable  type   footer  showHide  align   filter        value
$table->addColumn('Username', true,    true,     true,     '',     '',     true,   'left',  true);
$table->addColumn('Type',     true,    true,     true,    'num', $select->render(), true, 'left', 'dt-users-type', '90');
```

The **9th argument** is the filter:

| Value | What appears |
| --- | --- |
| `true` | a text box under the column, debounced and guarded by `minSearchLength` |
| an **id** string | the control you rendered into the footer (8th argument) — the table wires `change` and a debounced `keyup` on that id |
| `false` (default) | no filter for this column |

The **10th** is the filter's current value, applied on load. Without it a bookmarked or
returned-to filter shows its chosen value while the table shows every row, which reads as a
filter that does not work.

An id rather than `true` is how an enumerated column gets a dropdown: nobody guesses that
"administrator" is stored as `90`, and a numeric column is matched **equal** rather than with
`LIKE`, so a text box on it is a worse question as well as a harder one.

```php
$filter = new \Pramnos\Html\Select('usertype_filter');
$filter->id = 'dt-users-type';
$filter->addOption('Any type', '');
$filter->addOptions(\Pramnos\User\UserTypes::options());   // Admin (90), Manager (80), …
```

The bundled Users, Applications and Organizations lists ship with these on, and
`create:crud` generates them for every column of a new entity.

The generated box carries `class="pf-footsearch"` and the column's label as its
placeholder. **The class is not decoration**: a bare `<input>` under a modern CSS reset has
no border, no padding and no background, so the filter row was there and invisible — every
bundled theme styles it, and a theme of your own should too.

### Row actions, as icons

```php
use Pramnos\Html\Icon;

$row[] = Icon::link(adminUrl('users/view/') . $id, 'view', 'View this user')
       . Icon::link(adminUrl('users/edit/') . $id, 'edit', 'Edit this user')
       . Icon::link(adminUrl('users/delete/') . $id, 'deactivate', 'Deactivate', [
             'data-confirm' => 'Deactivate this user?',
             'class'        => 'pf-action-danger',
         ]);
```

`View Edit Deactivate` repeated on every row spends more width on words than on data, and
after the first row the words carry no information. `Icon::link()` is the same action in a
28-pixel cell — **labelled twice**, as `aria-label` and `title`, because an icon-only
control with neither is a control only its author can use.

Inline SVG rather than an icon font or a CSS class: these are rendered by a *controller*
into JSON a DataTable inserts, so the markup has to work in every theme. `currentColor` and
a `1em` box mean an icon inherits whatever the cell around it already is. `Icon::names()`
lists the set; an unknown name renders nothing rather than a broken glyph.

`Icon::svg('edit')` is the glyph on its own, for a button: the framework's own admin uses
it for the full-width actions beside a record, so "edit" looks the same in a table cell and
in a button.

**Make the first column a link too.** A row whose only way in is the last cell makes the
whole row a target people click with nothing happening. The bundled lists link the id and
the name; `create:crud` links the first visible column.

### Telling the Datasource what a column is

```php
$ds = new \Pramnos\Html\Datatable\Datasource();
//        name        format     details  start  end   ignoreOnOthertypes  min   max
$ds->addField('id',    'int',     '',      false, true, false,              1,    null);
$ds->addField('email', 'email');
$ds->addField('notes', 'text',    '',      true,  true, true,               3,    null);
```

**`$startWildcard` is `false` by default**, so a column search is `LIKE 'term%'`. That
default is the rule almost everywhere rather than a rarely-hit fallback: `render()` calls
`addField()` with a bare column name for every field declared as a plain string, which is how
most applications declare all of them. Pass `true` per column where matching mid-word is
worth losing the index for.

The global search sends **one** term, and each column decides whether it applies to it:

| `format` | Applies when | How |
| --- | --- | --- |
| `email` | the term is a valid address | `LIKE`, with the column's wildcards |
| `phone` | it looks like a phone number (10–14 chars of digits, spaces, dashes, `+`) | `LIKE` |
| `numeric` / `number` / `int` | it is numeric, and within `min`/`max` **by value** | `=` |
| `date` | — | not searched; `formatdetails` is the output format |
| anything else | unless `ignoreOnOthertypes` and the term is a number or an address; and within `min`/`max` **by length** | `LIKE`, with the column's wildcards |

A column that declines contributes no clause, and if every column declines, nothing is
filtered.

Each of those is a real cost avoided rather than a nicety:

- **`=` on a numeric column.** `LIKE '%5%'` on a number matches 5, 15, 50 and 1523 — a
  search for an id returning a page of unrelated rows, which is what "the search box does
  not work" looks like.
- **`ignoreOnOthertypes`.** On a wide list, searching every free-text column for `12345`
  costs a scan each and returns noise; the numeric columns answer that query.
- **`min` / `max`.** A one-character term against a large text column is a full scan, and a
  term outside a numeric column's range cannot match it at all.
- **The wildcards.** A leading `%` is what makes an index unusable, which is why it is off
  by default and asked for per column.

### Reading the query a filter produced

```php
$ds->render('orders', $fields);
echo \Pramnos\Html\Datatable\Datasource::$lastQuery;
```

The SQL of the last list query, across all instances — a debug and test hook, and the only
way to see what a filter actually produced. An admin screen can show the query behind a list;
a test can assert on an `ORDER BY` without building a dataset large enough to make the
ordering observable. It is set before the query runs, so a query that throws is still there
to read. Nothing reads it to decide anything.

### Grouping

```php
$ds->render(
    'orders', $fields, false, $where, $join, true, 5, 'datatables',
    false, null, '', 'where',
    'a.`customer_id`'        // ← GROUP BY, without the keyword
);
```

Its own parameter rather than something smuggled into the `where` string, which is fragile
enough that a consuming application maintained **two forks** of this class whose only
difference was this argument.

It applies to the counts as well as the rows, and that is the half worth stating:
`QueryBuilder::count()` preserves a `GROUP BY` (it documents that), so `COUNT(*)` on a
grouped query returns the size of the *first group*. A pager built on that promises pages
that are not there. The counts are therefore taken before the grouping is applied, as
`COUNT(DISTINCT <the grouped columns>)`.

`$groupBy`, `$join` and `$whereStatement` are interpolated: they are column lists and SQL
fragments from the calling code, never from a request.

---

## Pagination

```php
$pagination = new \Pramnos\Html\Pagination($totalPages, $page, '/genres/p/:page');
$pagination->containerElementClass = 'results-pages';
$pagination->previousButtonText    = '<img src="/img/prev.svg" alt="Previous" />';
$pagination->displayFirstLast      = false;

echo $pagination->render();
```

This is the presentation half of paging. [`QueryBuilder`](Pramnos_QueryBuilder_Guide.md)
covers the query half — `forPage()`, `limit()`, `offset()`.

### The URL is a pattern

`:page` is replaced with the page number:

```
'/genres/p/:page'         ->  /genres/p/4
'/search?q=x&page=:page'  ->  /search?q=x&page=4
```

A pattern with no `:page` gets `/:page` appended. **Rendering twice gives the same
result** — worth stating because the implementation this replaces appended to its own
property inside `render()`, so a template with a pager above and below a list produced
`/items/:page/:page` on the second one.

```php
$pagination->firstPageUrl = '/genres';   // page 1 has its own address
```

Two URLs for one page is duplicate content as far as a crawler is concerned, which is the
whole reason these are paths rather than a query parameter.

### Options

| Property | Default | |
|---|---|---|
| `containerElement` | `'div'` | element wrapping the whole thing |
| `containerElementClass` | `''` | class on it |
| `pageContainerElement` | `''` | element wrapping each link — `'li'` for a list |
| `currentPageClass` | `'current'` | class on the current page's link |
| `adjacents` | `2` | pages shown either side of the current one |
| `displayNextPrevious` | `true` | the ‹ › buttons |
| `displayFirstLast` | `true` | the « » buttons |
| `displayEdgePages` | `true` | the pinned `1` and last-page **numbers** beside the ellipses |
| `previousButtonText` / `nextButtonText` | `'&laquo;'` / `'&raquo;'` | **markup, not escaped** |
| `firstButtonText` / `lastButtonText` | `'&laquo;&laquo;'` / `'&raquo;&raquo;'` | **markup** |
| `dotsText` | `'&hellip;'` | shown where pages are elided. **markup** |

!!! warning "The button properties are not escaped"
    They exist to hold an `<img>`, which is what the request that added this class needed.
    That makes them the one place a caller's string reaches the page unfiltered, so they
    must never be built from user input. Page numbers, URLs and the container class *are*
    escaped.

### What it does without being asked

- **A single page renders nothing at all.** One page is not a paginated result, and an
  empty container is something a stylesheet still puts margins around.
- **Both ends are always reachable**, even in a long list — one click to page 1 or to the
  last page is the point of a numbered pager over previous/next alone. Set
  `displayEdgePages = false` for a window that is purely relative to the current page.
- **Dots only where something is hidden.** `1 … 2` would be a lie about a page that is
  right there.
- **Previous and next disappear at the ends.** A "previous" on page 1 either links to page
  1 or to page 0, and both are worse than absent.
- **An out-of-range current page is clamped.** Page numbers arrive from a URL, so they
  arrive wrong; page 0 and page 99 of 5 both produce a usable pager.
- **Links are labelled for a screen reader** with `aria-label` and `aria-current`. A link
  whose content is an image or an ellipsis has no accessible name otherwise — a screen
  reader reads the URL.

### A window with no pinned ends

```php
$pagination->displayEdgePages = false;   // … 8 9 [10] 11 12 …   — no 1, no 20
$pagination->displayFirstLast = true;    // « and » still reach both ends
```

The two are independent, and that combination is the common one: the ends stay one click
away through the buttons, while the number row keeps a constant width as the reader moves
through it.

The ellipses stay honest either way — they appear only where a page is actually hidden,
and the threshold moves with the setting. With `1` on screen a gap exists from page 3, so
`1 … 2` would be a lie; with `1` off screen a gap exists from page 2, and omitting the
dots there would hide the fact that page 1 exists at all.

---

### It is a navigation landmark

Every link already carried `aria-label="Page 7"` and the current one `aria-current="page"` — all of
which help once you are inside the pager. Getting inside was the part that did not work: with no
`<nav>` this was an anonymous run of anchors, and somebody moving through a page by its regions
passed straight over it.

```html
<nav aria-label="Pagination"><div class="pf-pagination"> … </div></nav>
```

`$navigationLabel` names the region — a page can hold more than one, and a reader listing the
regions hears the labels, not the markup. A caller who sets `containerElement` to `nav` themselves
gets the label on their own element rather than a second region wrapped around it; two nested
navigation landmarks announce the region twice and neither is the answer.

### Turning the numbers off

```php
$pagination->displayPageNumbers = false;   // leaves only « ‹ › »
```

Not a preference. In search results the page count is large and **moves with the filters**: a
reader on page 7 of 40 cannot say what page 7 is, and after narrowing the query it is a different
page 7. Two links are the entire meaningful interface there, and a row of twelve numbers is noise
that changes width as you read it.

It is distinct from `$displayEdgePages`, which only decides whether `1` and the last page are
pinned *inside* that row. This removes the row.

**With the numbers off and neither button pair enabled, `render()` returns an empty string.** Every
branch would be skipped and what was left was an empty `<nav>` — and an empty landmark is worse
than none: it appears in a reader's list of regions and leads nowhere. Same rule as a single page,
reached a different way.

## Form\Field

The one that belongs to a form. It is not an alternative to `Input` and `Select` — it is
**built on them**.

```php
$field = new \Pramnos\Html\Form\Field('email', 'Email', 'email', required: true);
$field->description = 'We never share it.';
$field->pattern     = '[^@]+@[^@]+';
$field->title       = 'name@example.com';
echo $field->render(\Pramnos\Html\Form\FieldStyles::for('bootstrap'));
```

`Field` keeps what is actually about being in a form — the label, the description, the
required marker, the style preset, `effectiveValue()`, `readSubmitted()`, and an `id`
defaulted to the name. The tag itself is built by `Input` or `Select`, so the rules about
which attributes a type may carry live in one place.

That mattered: while the two were separate, `Field` emitted `min`/`max`/`step` on **every**
type including `text` — invalid markup that browsers accept and validators reject.

### `$label` is the label, `$title` is the HTML attribute

```php
$field->label = 'Email address';      // what the reader sees
$field->title = 'name@example.com';   // the tooltip, and what a failed pattern says
```

The two are separate because the HTML attribute has a job of its own: `title` is the
tooltip, and it is what the browser shows when a `pattern` fails. A class that spent its
`title` on the label could not offer either.

### One more difference from `Input`: the checkbox companion

```html
<input type="hidden" name="active" value="0" />
<label for="active"><input type="checkbox" id="active" name="active" value="1" /> Active</label>
```

Without it an unchecked box submits nothing at all, which is indistinguishable from the
field not being on the form — so a setting could be switched on and never off. `Input`
does not do this, because a filter checkbox outside a form has no such problem.

`Field` also treats anything that is not `'0'` or `''` as checked, which is broader than
`Input`'s equality test: a setting stored as `1`, `yes` or `true` all mean the same thing
to a settings form.

### Its own type vocabulary

`textfield`, `datetime` and `image` are `Field`'s names, resolved before the control is
built: `datetime` → `datetime-local`, `image` → `text` (it always carried a path, and
nothing ever rendered a picker for it). Passing them to `Input` directly would get you a
plain text box.

---

### A validation message that belongs to its field

`aria-describedby` and `aria-invalid` did not exist anywhere in `Pramnos\Html` until now. A
`<small>` under an input is not attached to it — it is a sentence that happens to sit nearby, which
is a relationship only a sighted reader can see. Somebody using a screen reader heard the label and
the control and never the explanation, so the field that needed the most explanation gave none.

```php
$field        = new Field('email', 'email', 'Email', null, 'Your work address');
$field->error = 'That address is already registered.';

echo $field->render(FieldStyles::for('tailwind'));
```

```html
<input type="email" name="email" id="email"
       aria-describedby="email-error email-description" aria-invalid="true" …>
<small id="email-error" role="alert">That address is already registered.</small>
<small id="email-description">Your work address</small>
```

`$error` is the **rendering** end of validation, not a second validator: `Validation\FormRequest`
and `View::$errors` still own that. Set it and the field is marked invalid *and says why, to
everybody*.

Three details are deliberate:

- **The error id comes first.** `aria-describedby` is read in the order the ids are listed, and a
  field that is wrong should say so before it explains what it wanted. The other way round, the
  correction arrives after the instruction it corrects.
- **`role="alert"` is on the message, not the field.** A message that appears after a failed submit
  has to interrupt to be heard. On the field it would re-announce the whole field every time
  anything about it changed.
- **A field with neither text gets no attribute at all.** An `aria-describedby` pointing at an id
  that is not on the page is worse than its absence: the reader announces the field and then
  nothing, and the silence reads as a bug in the reader.

Selects render down a different path in that class and get the same treatment — which is exactly
how one of two paths otherwise ends up accessible and the other does not.

## Seo

Two strings for the `<head>`, and the only two pieces of markup there that a crawler reads
differently from a browser.

```php
use Pramnos\Html\Seo;

echo Seo::canonicalLink('https://example.com/stations/kosmos');
echo Seo::jsonLd([
    '@context' => 'https://schema.org',
    '@type'    => 'RadioStation',
    'name'     => $station['name'],
    'url'      => $url,
]);
```

Both are `static` and neither has state, which is why this one is not constructed like the
components above.

They live here rather than on `Document` because a page does not have to be built through a
`Document` to need them: an application rendering a layout template directly wants the same
string, produced the same way, and a second copy of the encoding rules below is how the two
drift apart. `Document::setCanonical()` and `Document::addStructuredData()` call these.

### `jsonLd()`

The encoding flags are not a preference. Each answers a specific way the block goes wrong:

| Flag | What it prevents |
|---|---|
| `JSON_HEX_TAG` | **the only injection this format has** — a `</script>` inside any value ends the block early and everything after it is parsed as markup. Structured data is assembled from record titles and descriptions, which is exactly where such a string arrives from. |
| `JSON_HEX_AMP` | the same reasoning one step out, for consumers that re-parse the block out of an HTML string. |
| `JSON_UNESCAPED_SLASHES` | every URL becoming `https:\/\/…` — valid JSON, and unreadable in view-source, which is the only place anybody ever checks it. |
| `JSON_UNESCAPED_UNICODE` | non-Latin text becoming `\uXXXX`, with the same cost and no benefit. |

**Absent is not empty.** Omit a key you have no value for rather than emitting `"genre": ""`.
An empty string is a claim that the field *is* blank, which is a different statement from not
making the claim, and consumers treat it as one. The method will not do this for you — it
cannot tell a deliberate empty string from a lookup that returned nothing, and guessing would
be worse than either.

An empty array returns an empty string, and so does data that cannot be encoded — a resource
handle, invalid UTF-8, recursion. A page with no structured data is a smaller problem than a
page with a broken script block in its head, and the caller gets to carry on rendering.

### `canonicalLink()`

```html
<link rel="canonical" href="https://example.com/stations/kosmos">
```

The URL is escaped with `ENT_QUOTES | ENT_SUBSTITUTE` and **not** double-encoded, so a URL
that already contains an entity survives. An empty or whitespace-only URL returns an empty
string rather than `href=""`, which resolves to the current page and tells a crawler the
opposite of nothing.

`Breadcrumb` renders its own `BreadcrumbList` block through the same encoder — see
[Breadcrumb](#breadcrumb).

---

### `SiteIdentity`

`Organization` and `WebSite`, on every page, from settings the installation already has.

Until now the only `@type` the framework emitted anywhere was `BreadcrumbList` — the shape of a
page's position in a hierarchy that was never named. `Document::addStructuredData()` existed the
whole time and the framework called it from nowhere.

```php
Html\SiteIdentity::jsonLd();    // called for you, from Document::seoHeadMarkup()
Html\SiteIdentity::graph();     // the same thing as data, to extend before rendering
```

It reads `sitename`, `sitelogo` and `social_profiles` from settings, and **asserts only what is
configured**, for the same reason the meta tags do:

- An unset logo is **absent**, not `"logo": ""`. An empty field is not a blank — it is a claim that
  the thing does not exist.
- A malformed entry in `sameAs` is dropped rather than published. It does not fail loudly; it
  quietly stops the organisation matching the entity it names.
- A `SearchAction` appears only where `Search\Registry` has sources. One that leads to a page
  returning nothing is offered to a reader, tried once, and teaches them the site is broken.

Empty when the site has no configured name, because a name is the one field both objects need to be
worth asserting.

### Absent is not empty

The rule `jsonLd()` has documented from the start, now enforced by the head renderer too.

`<meta name="description" content="">` is **not** the absence of a description. It is a claim that
this page has none — and a crawler records the claim rather than falling back to the page text. An
application that never set the property said nothing of the sort.

So `description`, `og:title`, `og:url`, `og:image` and `og:description` are emitted only when they
have a value. `og:title` and `og:site_name` still fall back to the page title and the `sitename`
setting, because those are values the framework *has*, not claims it invents.

Two more in the same pass. **`viewport` moved onto the document** — it lived in the scaffolded
themes and nowhere else, so a theme that omitted it produced a page Google labels not
mobile-friendly with no signal that anything was missing. And **`twitter:card` is emitted only when
there is an image for it**: X reads the OpenGraph tags for everything else, and a
`summary_large_image` card promising an image the page does not have renders worse than no card.

The `xmlns:og` and `xmlns:fb` attributes are gone — RDFa declarations from 2010, parsed by nothing
since Facebook moved to `<meta property>`, occupying the first hundred bytes of every page the
framework rendered.

## See also

- [Query Builder](Pramnos_QueryBuilder_Guide.md) — `forPage()`, and the query half of paging
- [Document Output](Pramnos_Document_Output_Guide.md) — where rendered markup goes
- [Cross-Entity Search](Pramnos_Search_Guide.md) — the registry and endpoint behind `SearchBox`
- [Theming](Pramnos_Theme_Guide.md) — views, layouts and the theme these render inside
