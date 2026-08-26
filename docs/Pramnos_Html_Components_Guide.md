---
use_cases:
  - Rendering a dropdown filter outside any form
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
| [`Pagination`](#pagination) | page links from a count, a current page and a URL pattern |
| `Datatable` | a DataTables-backed table, with server-side paging and filters |
| `Breadcrumb` | a breadcrumb trail, plus its `BreadcrumbList` structured data |
| `Date` | date and time formatting helpers |
| `Seo` | canonical URLs, meta tags and structured data |
| `Form\Field` | one field **inside a form**, with a label and a style preset |

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
| `displayNextPrevious` | `true` | |
| `displayFirstLast` | `true` | |
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
  last page is the point of a numbered pager over previous/next alone.
- **Dots only where something is hidden.** `1 … 2` would be a lie about a page that is
  right there.
- **Previous and next disappear at the ends.** A "previous" on page 1 either links to page
  1 or to page 0, and both are worse than absent.
- **An out-of-range current page is clamped.** Page numbers arrive from a URL, so they
  arrive wrong; page 0 and page 99 of 5 both produce a usable pager.
- **Links are labelled for a screen reader** with `aria-label` and `aria-current`. A link
  whose content is an image or an ellipsis has no accessible name otherwise — a screen
  reader reads the URL.

---

## See also

- [Query Builder](Pramnos_QueryBuilder_Guide.md) — `forPage()`, and the query half of paging
- [Document Output](Pramnos_Document_Output_Guide.md) — where rendered markup goes
- [Theming](Pramnos_Theme_Guide.md) — views, layouts and the theme these render inside
