---
date: 2026-08-27
categories: [Changelog]
---

# The form field now uses the controls

`Html\Input` and `Html\Select` were added for controls with no form around them. The
field *inside* a form went on building its own tags — and the two copies had already
drifted apart in a way that only a validator would have told you about.

<!-- more -->

## Fixed

### `Form\Field` emitted `min`, `max` and `step` on every type

```html
<!-- before -->
<input type="text" id="nickname" name="nickname" value="" min="1" max="10" />
```

`min` on a text field is invalid markup. Every browser accepts it and ignores it, so
nothing looked wrong — which is why it survived. `Html\Input` has never done this: it
restricts the numeric attributes to the types that can use them, and that rule simply did
not exist in `Field`'s own copy of the loop.

## Changed

### `Form\Field` builds its control with `Html\Input` and `Html\Select`

One rule about which attributes a type may carry, instead of two that agree until they
do not.

`Field` keeps everything that is about being **in a form**: the label, the description,
the required marker, the style preset, `effectiveValue()`, `readSubmitted()`, an `id`
defaulted to the name — `Input` refuses to invent one, correctly, but a form field is the
case where `<label for>` requires it — and the checkbox's hidden companion, without which
an unchecked box submits nothing and a setting can be switched on but never off.

Two things had to be kept deliberately on this side of the boundary, and both have a test
saying so:

- **`Field`'s own type vocabulary.** `datetime` → `datetime-local`, `image` → `text`,
  `textfield` → `text`. `Input` has never heard of `datetime`, and its documented answer
  for an unrecognised type is `text` — so handing the raw type over would have turned a
  date-time picker into a text box and an upgrade into a bug report about a disappearing
  calendar. The type is resolved before it is passed on.
- **The checkbox's broader idea of "on".** Anything that is not `'0'` or `''`, because a
  setting stored as `1`, `yes` or `true` all mean the same thing here. `Input` tests
  equality against its `checkedValue`, which is right for a filter and wrong for this.

**The attribute order in the rendered markup has changed** — `name` now precedes `id`, and
the preset's classes come last. No signature changed, and the only consumer in the
framework is `SettingsForm` (via `Theme::addSetting()`), whose tests pass unchanged. Worth
stating for anything asserting on exact output.

## Added

### `Form\Field` gains the native-validation attributes

`pattern`, `title`, `minlength`, `maxlength`, `placeholder`, `autocomplete`, `inputmode` —
the properties `Input` already had and this class could not express at all.

## Changed — a rename, and one silent break

**`Field::$title` is now the HTML `title` attribute. The label is `$label`.**

`$title` meant *label* here, inherited from the legacy form class. Rather than work around
that — an intermediate commit today offered the attribute as `$tooltip`, which preserved
the collision instead of resolving it — the label was renamed and `title` now means what
it means in `Input`, `Select` and HTML itself.

What this costs, stated precisely:

- **Positional construction is unaffected.** `new Field('email', 'Email')` and every
  `addField('email', 'Email', …)` keep working — which is every caller in the framework
  and every documented example.
- **`$field->title = 'Email';` breaks silently.** It now sets a tooltip and leaves the
  label auto-generated from the field name. Nothing errors. Change it to `$field->label`.
  This is the one case worth grepping for.
- **`addField(name: 'x', title: 'Y')` breaks loudly** — PHP refuses an unknown named
  parameter. That is the intended outcome and it has a test: a stack trace naming the
  property beats a page rendering the wrong label.

`Html\Select` gained `$title` in the same change, so a control in a form explains itself
the same way whichever kind it is.

### `FieldTest`

`Field` had no direct tests — it was covered only through `SettingsForm`. 25 now, over the
delegation boundary: what must survive it, what must not come back, and that the rename
above is refused rather than misread.
