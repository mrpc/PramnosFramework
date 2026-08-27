---
use_cases:
  - Rendering a dropdown filter outside any form
  - Rendering a search box, checkbox or textarea with no form around it
  - Placing the cross-entity search box somewhere other than the header
  - Building pagination links for a listing page
  - Choosing between Html\Select and a form field
  - Finding out which reusable HTML components the framework already ships
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
| `Date` | date and time formatting helpers |
| `Seo` | canonical URLs, meta tags and structured data |
| [`Form\Field`](#formfield) | one field **inside a form**, with a label and a style preset |

Every one of them also renders when cast to a string, so `echo $component` works.

---

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

## See also

- [Query Builder](Pramnos_QueryBuilder_Guide.md) — `forPage()`, and the query half of paging
- [Document Output](Pramnos_Document_Output_Guide.md) — where rendered markup goes
- [Cross-Entity Search](Pramnos_Search_Guide.md) — the registry and endpoint behind `SearchBox`
- [Theming](Pramnos_Theme_Guide.md) — views, layouts and the theme these render inside
