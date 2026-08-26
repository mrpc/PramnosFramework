---
date: 2026-08-27
categories: [Changelog]
---

# Four legacy input classes become one

`Html\Select` on its own was half a job: a standalone dropdown and no standalone text
field. This is the other half — and it is smaller than what it replaces.

<!-- more -->

## Added

### `Html\Input` — one control, no form around it

```php
$search = new \Pramnos\Html\Input('q', $current);
$search->placeholder = 'Search…';
echo $search->render();
```

Covers `text`, `hidden`, `password`, `email`, `url`, `tel`, `search`, `number`, `range`,
`date`, `time`, `datetime-local`, `month`, `week`, `color`, `checkbox`, `radio`, `file` and
`textarea`. Documented in the [HTML Components Guide](../../Pramnos_Html_Components_Guide.md#input).

The same reasoning as `Select`: `Form\Field` is right for a control **in a form** — it
carries a label, a title, validation state, and its `render()` requires the form's style
preset — and a filter above a table has no form to take a preset from.

### Why it is one class and the legacy was four

The old `input` was a **dispatcher**. `type` decided, and for `date`, `time`, `checkbox`
and `color` it constructed a different class — `checkbox`, `colorpicker`, a `time` widget —
and forwarded a dozen properties into it. Most of that existed because those input types
did not work in browsers when it was written, so each needed JavaScript to fake it.

`<input type="color">` and `type="time"` have been native for years. Once the widgets are
not needed, what is left is a tag with attributes, and a dispatcher with nothing to
dispatch to is just indirection.

### Attributes appear only where they apply

The tests that matter most here are about attributes *not* being emitted:

- no `min` / `max` / `step` on a text field — invalid markup that browsers accept and
  validators reject, which is the kind of wrong nobody notices
- no `value` on a file input — ignored by every browser, and the one type whose value could
  only have come from the server
- no `id` unless you set one — two controls for one field on a page is ordinary, and
  duplicate ids break `<label for>` and `getElementById` silently. The legacy generated one
  from the name plus a `uniqid()`, so it also changed between renders
- no `for` on a label without an id — a screen reader announces the association and then
  finds no control
- no trailing colon after a label. The legacy appended one unconditionally, which is a
  typographic decision that belongs to the page and could not be undone from outside

Values, labels and placeholders are escaped; the legacy escaped none of them, and a value
here comes from a request or a database by definition.

### What was deliberately left out

No `validate` / `addcss` / `addjs`. Those legacy properties made an element push CSS and
JavaScript into the document while rendering itself — so echoing a search box changed the
page's asset list. `Document::addScript()` and `addStyle()` are where a page declares what
it needs.
