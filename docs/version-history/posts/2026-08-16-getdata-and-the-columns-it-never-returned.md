---
date: 2026-08-16
categories:
  - Changelog
  - Fixed
  - Added
tags:
  - model
  - api
---

# getData() and the columns it never returned

It kept only values that were `is_numeric()` or `is_string()`. So `NULL` columns were
absent rather than null, booleans vanished, decoded JSON columns vanished, and a model
that declared no public properties returned an empty array.

<!-- more -->

## One cause, four symptoms

```php
foreach (get_object_vars($this) as $key => $value) {
    if ($key == '_primaryKey' || $key == '_dbtable' || ...) { continue; }
    if (is_numeric($value) || is_string($value)) { $data[$key] = $value; }
}
```

The type filter exists **because** the loop scans the whole object — it is what stops
`_initialData`, the controller and the message buffers from ending up in a payload. It
does that by dropping every array, object and boolean, and the columns are collateral.

Four consequences:

- **`NULL` columns are absent**, not `null`. `array_key_exists()` says *"this record has
  no such field"* about a field the record has.
- **Booleans and decoded JSON columns vanish.**
- **A model declaring no public properties returns `[]`.** Columns assigned to an
  undeclared property go through `Base::__set` into `_data`, which the loop sees as one
  array and drops whole, columns inside it and all.
- **`sqlError` was not on the exclusion list.** It is a string once a query has failed,
  so a failed read put its SQL error message into whatever was being serialised.

The generator already knew. `make:crud` emits per-column casts that put booleans back
after calling `parent::getData()` — patching the base's type filter one type at a time,
and stopping one short of JSON. That case exists now too.

## Why the fix is opt-in, measured rather than assumed

The obvious change is to name every internal and drop the type filter. It was written,
and then the blast radius was measured before shipping it.

In one existing application: **54 models, 45 override `getData()`, and 42 of those call
`parent::getData()`.** A change here reaches almost every endpoint that application has,
adding keys to every payload. All of the new keys are more correct. Every one is a
difference a consumer can notice, and the requirement this work was held to was *no
differences at all*.

So it splits:

**Unconditional** — the exclusion list is now a named array rather than eight chained
string comparisons, and it includes the internals that were previously excluded only by
luck of their type: `_initialData`, `controller`, `_isnew`, `_data`, the message
buffers, and `sqlError`. Output is byte-identical, with the single deliberate exception
of `sqlError` no longer leaking.

**Opt-in** — `protected $getDataFullFidelity = true` in **one** base model class returns
every column, and merges the `_data` bag so a model that declares nothing works too.

Adding a parameter was not available: `getData()` is overridden in dozens of places with
no arguments, and PHP treats a child with fewer parameters than its parent as a fatal
declaration error. Checked before designing around it.

## The golden master, and the flaw in the first one

The claim *"the default is byte-identical"* is checkable by machine, so it is checked:
the old algorithm is transcribed into the test model and compared with `serialize()` —
key order included, since a payload with the same keys in a different order is a
different JSON document.

The first version put that transcription on the **test class**, and it was wrong in a
way that made it agree.

`get_object_vars()` returns what the *calling scope* can see. From inside the model,
protected properties are included; from the test class, only public ones. So the
transcription never saw `sqlError` — the property the whole exercise had identified as
leaking — and the comparison passed by coincidence of the data rather than by running
equivalent code.

**A golden master that cannot see what the original saw is not one.** It is a method on
the model now, and the test that exposed it is the one asserting that `sqlError` is the
*only* difference.

Six of the fourteen tests fail if the default is flipped to full fidelity.

## Fixed

- `getData()` no longer returns `sqlError`, and no longer relies on a value's type to
  keep the model's internals out of a payload.
- `make:crud` generates a case for JSON columns, which had none.

## Added

- `Model::$getDataFullFidelity` — opt in to `NULL`, boolean and array columns, and to
  reading the `_data` bag for models that declare no public properties.

## Documentation

- [API guide](../../Pramnos_API_Guide.md) — which columns reach a payload and which do
  not, how to opt in, and what to check afterwards.
