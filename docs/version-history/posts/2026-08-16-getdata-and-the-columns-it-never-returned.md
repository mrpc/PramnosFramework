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

## Why it changed, measured rather than assumed

The obvious fix is to name every internal and drop the type filter. The blast radius was
measured first, on an application with **54 models, 45 overriding `getData()`, and 42 of
those calling `parent::getData()`** — so a change here reaches almost every endpoint it
has.

Running the old and new implementations side by side against those real model classes:

| | |
| --- | --- |
| models that gain keys | **48 of 54** |
| keys added | **523** (avg 10.9 per model) |
| of which `NULL` | **411** |
| boolean | 53 |
| array | 55 |

And the measurement produced the argument **for** the change rather than against it.
Those same overrides do:

```php
$data = parent::getData();
$data['reportid'] = (int) $data['reportid'];
$data['date']     = (int) $data['date'];
```

Unguarded. So a record with `NULL` in one of those columns raised *Undefined array key*
in production, and `(int) null` put a **`0`** in the payload where the value was `NULL`.
The absent key was not a neutral quirk; it was producing warnings and wrong numbers in
the application that had lived with it longest.

So the default now returns everything, and the historical shape is available as an
opt-out for anybody who needs it back:

```php
protected $getDataFullFidelity = false;   // the pre-1.2 shape, byte for byte
```

Adding a parameter was never available: `getData()` is overridden in dozens of places
with no arguments, and PHP treats a child with fewer parameters than its parent as a
fatal declaration error. Checked before designing around it.

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

- `getData()` returns `NULL`, boolean and array columns instead of dropping them, and
  reads the `_data` bag for models that declare no public properties.
- It no longer returns `sqlError`, in either mode.
- `make:crud` generates a case for JSON columns, which had none.

## Added

- `Model::$getDataFullFidelity` — set it to `false` for the pre-1.2 shape, byte for
  byte.

## It also got 8.5× faster

Not the point of the change, but worth recording because the shape of the win was not
where it looked:

| | µs per call |
| --- | --- |
| the original | **12.143** |
| exclusion list as an `isset()` lookup instead of eight chained `==` | 5.340 |
| `array_diff_key()` instead of the loop | **1.422** |

`get_object_vars()` alone is 0.949 µs of that last figure, so what remains is 0.34 µs of
overhead and there is nothing further to win.

A page of 50 rows through `useGetData` went **0.797 ms → 0.271 ms**, while returning
more data than before. The opt-out improved too — 6.617 → 4.183 µs — because the
internals are removed before the type test runs, so the loop covers twelve columns
rather than thirty-one properties.

One optimisation was measured and **rejected**: skipping the `array_merge` when the
`_data` bag is empty saves nothing (1.299 µs against 1.287), because merging an empty
array is already cheap. It would have been a branch earning its keep in nobody's
benchmark.

## The merge order was wrong first

Where a column exists both as a declared property and in the `_data` bag, the **declared
property wins** — it is the live value; the bag is the fallback for columns nobody
declared.

The first implementation had `array_merge($source, $this->_data)`, which is the other
way round, so a stale bag entry shadowed the property. That presents as a field that
stops updating: no error, no warning, a value that is simply old. It was caught by the
one test written for precedence rather than for output, which existed only because the
merge looked too obvious to leave untested.

## Documentation

- [API guide](../../Pramnos_API_Guide.md) — which columns reach a payload and which do
  not, how to opt in, and what to check afterwards.
