---
date: 2026-08-17
categories: [Changelog]
---

# A form class that was never ported

`Theme::addSetting()` and `Addon::addSetting()` have declared the same API since the day those
files were transferred from the legacy framework. Both fatalled. The collaborator they called
into — `pramnos_html_form` — was never ported, and the line that built it arrived **already
commented out**:

```php
#$this->_form = new pramnos_html_form($this->name, false);
```

So `$_form` was null from the first commit, and ten public methods across two subsystems have
never worked. The practical consequence: **no addon could have settings at all.**

<!-- more -->

## What was built, and what deliberately was not

`Pramnos\Html\Form\SettingsForm`, with `Field` and a shared `FieldStyles` preset table. It is
**settings, not CRUD**, and the boundary is the design:

- **Laravel removed its form builder from core** — it survives as an unmaintained package —
  because markup in PHP objects fights every front-end toolchain. Symfony keeps a full Form
  component, and it is the single most common complaint about its learning curve.
- This framework **already generates CRUD forms**, from real column introspection, with foreign
  keys becoming a `<select>` (Select2 remote for large tables) and three theme presets. A
  runtime builder would be a *third* way to render a field, after that and an SPA's components.
- Validation, error surfacing and old input already belong to `FormRequest` and `View::$errors`.

What was genuinely missing is the shape Django's `Form` and WordPress's Settings API both
describe: the caller declares fields, the framework renders, reads and persists them. Two
subsystems had already declared exactly that.

The legacy class was not ported, and would have been the wrong thing to port. It needed **four**
classes, not one — `pramnos_html_form_field`, `pramnos_html_select` and `pramnos_html_input`
are all absent too. It carried a real XSS hole: `value="' . $this->value . '"`, unescaped, on a
page whose values are administrator-supplied and re-rendered after every save. It had a
`getInstance()` singleton on a *form*, and its CSRF token was the field's **name**, so nothing
else could verify it.

What was kept from it: the eight-argument signature, the three option shapes, multilanguage
fields — `Addon` uses them — and the method name `addField()`.

## Details that are behaviour, not decoration

- **Everything is escaped**: values, labels, descriptions, option labels and option values.
- **A checkbox can be turned off.** Browsers submit nothing for an unchecked box, so each one
  renders with a hidden `0` companion the checkbox overwrites with `1`. Without it a setting
  could be switched on and never off.
- **A rejected submit writes nothing.** `getData()` returns `[]` when the CSRF token does not
  check out, and `Theme::saveSettings()` refuses to store an empty result — so an expired
  session cannot blank every setting.
- **An empty string is a value.** Only `null` falls back to the default, so a setting
  deliberately cleared stays cleared instead of reverting on every render.
- **The CSRF field is the framework's own**, `Session::getTokenField()`, not a token of the
  form's invention.
- Style presets are shared with the scaffolder's generated forms, so a settings page and the
  CRUD form beside it agree. They cannot share a *renderer* — the scaffolder emits template
  source containing `<?php echo …`, this emits markup — but the class names are the part that
  drifts, and there is now one list.

One bug caught by its own test: `['1' => 'Enabled', '0' => 'Disabled']` — the obvious way to
declare a boolean setting — arrives with **integer** keys, because PHP coerces numeric string
keys. The first version read that as a flat list and rendered `value="Enabled"`.
`array_is_list()` decides it correctly. The residual ambiguity is documented rather than hidden:
`[0 => 'No', 1 => 'Yes']` is indistinguishable from `['No', 'Yes']`, so use the `[label, value]`
pair form when your keys are `0` and `1` in that order.

## Documentation

The Theme Guide's settings section documented `$this->addField(...)` **on a theme**.
`addField()` is real, but it belongs to the form — the chain was `Theme::addSetting()` →
`pramnos_html_form::addField()`, and the guide had written the inner call as if it were the
theme's own. It also showed a `'value,Label|value,Label'` option format the code never parsed.
Ten occurrences, in a section describing a feature that could not run.

The new class keeps the name `addField()` for exactly that reason: anyone carrying the legacy
knowledge now lands on the right object.

The section is rewritten to current state — the real signature, the option shapes with their one
undecidable case, escaping, the checkbox companion, where values are stored, and how addons
declare the same thing.

## Tests

33 for the form, at 96% line coverage; `Field` and `FieldStyles` at 100%. The escaping cases are
the ones that matter, and there is a test for the *converse* of every refusal — a valid CSRF
token being accepted — because otherwise a class that rejected everything would pass.

Two existing `ThemeTest` cases injected an anonymous stub implementing `addField()` and a public
`$_fields`, because the real collaborator did not exist. They now use the real form and need no
stub. That is worth naming as a signal: **a test that has to invent its subject's collaborator is
describing something that cannot run in production.**
