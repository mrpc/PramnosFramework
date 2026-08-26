---
date: 2026-08-27
categories: [Changelog]
---

# A select and a pager that need no form around them

Two structural UI pieces the framework nearly had. Requested with counts — 37 files and 8
files in a consuming application — which is what made them framework work rather than
application work.

<!-- more -->

## Added

### `Html\Select` — a `<select>` outside any form

```php
$status = new \Pramnos\Html\Select('statusSelect', $current);
$status->addOption('All', '');
$status->addOption('Active', 1);
$table->addColumn('Status', true, false, true, '', $status->render(), …);
```

`Html\Form\Field` renders a `<select>` too and is the right thing for a field **in a
form** — it carries a label and its `render()` requires the form's style preset. The case
this fills has no form: a footer filter in a `Datatable`, whose rendered string is passed
to `addColumn()` as markup. 37 files do that, 35 of them through `addOption()`. Asking each
to construct a style preset in order to render one dropdown is the coupling that gets a
class copied instead of used.

**The label is the first argument** — `addOption($label, $value)`. That is the legacy order
and it is kept deliberately: reversing it to the more obvious `(value, label)` would
compile, run, and render 35 files' dropdowns with labels and values swapped, with no error
anywhere.

Two things the implementation it replaces did not do:

- **It escapes.** Labels and values were concatenated into markup untouched, and the
  documented use is a filter populated from a database column.
- **It compares as strings.** The old `==` answered `0 == ''` differently on PHP 7 and
  PHP 8, so a select with an "All" option of `''` and a current value of `0` selected a
  different option depending on the interpreter — precisely the kind of thing a version
  migration finds the hard way.

### `Html\Pagination` — the presentation half of paging

```php
$pagination = new \Pramnos\Html\Pagination($totalPages, $page, '/genres/p/:page');
$pagination->containerElementClass = 'results-pages';
$pagination->previousButtonText    = '<img src="/img/prev.svg" alt="Previous" />';
$pagination->displayFirstLast      = false;
```

`QueryBuilder` already covered the query side — `forPage()`, `limit()`, `offset()`. Nothing
turned "page 3 of 17" into links, so every application wrote that loop again.

The URL is a **pattern** with `:page`, not an appended query string, because paginated
listings are usually indexable pages: a crawler treats `/genres/p/4` as a page while it may
treat `?page=4` as a variant of `/genres`. `firstPageUrl` gives page 1 its own address, for
the same reason.

Four defects in the 367-line implementation this replaces, all of them in its **output**,
which is why they survived:

- the opening container tag was built as `'<' . $element . $class . '">'`, so with no class
  set it emitted `<span">`;
- the per-item container was *opened* at both ends — `'<li>'` where `'</li>'` was meant —
  so a list nested instead of listing;
- every link carried `alt=""`, which is not valid on `<a>`, leaving image and ellipsis
  links with no accessible name at all;
- `render()` appended `/:page` to its own property, so a template with a pager above and
  below a list produced `/items/:page/:page` on the second call.

It also declines to render anything for a single page, keeps both ends reachable in a long
list, shows the elision dots only where something is actually hidden, drops
previous/next at the ends, clamps an out-of-range page from a URL, and labels each link
with `aria-label`/`aria-current`.

The button-text properties are **not escaped**, deliberately — they exist to hold an
`<img>`. That is the one place a caller's string reaches the page unfiltered, and it has a
test of its own so nobody "fixes" it later and breaks every arrow icon.

## Not added, and why it is worth saying

The request named what it did **not** want, and that is the more useful half: chart
rendering, country and currency data, input/checkbox/colorpicker/time widgets, a form
builder, YouTube embedding, a payment-addon base class and a site-search registry. All of
them are application logic wearing a framework name. Within `Html\`, every remaining legacy
class is now either present or explicitly declined — the inventory gap is closed, not
merely reduced.

## Documentation

- New [HTML Components Guide](../../Pramnos_Html_Components_Guide.md): both classes, a table
  of every component `Pramnos\Html\*` ships, and — the question the request itself raised —
  when to reach for `Html\Select` rather than `Form\Field`.
