---
date: 2026-08-25
categories: [Changelog]
---

# A policy you could create but never change

`HypertableRegistry` has declared retention and compression intervals for seven
tables since it was written. Changing one of those numbers did nothing, and said
nothing.

<!-- more -->

## Fixed

- **`timescale:ensure` compares intervals, not just presence.** A declaration that
  no longer matches the database is reported as drift and repaired.
- **Every run no longer adds another policy on MySQL.** `hasPolicyJob()` answered a
  flat `false` without TimescaleDB, so the guard never fired and
  `addRetentionPolicy()` inserted beside the existing row each time — N identical
  policies then issued the same `DELETE` N times against the same table.

## Added

- `SchemaBuilder::policyInterval()`, `removeRetentionPolicy()`,
  `removeCompressionPolicy()`.
- Per-table overrides from application config.

```php
// app/app.php
'hypertables' => [
    'tokenactions'      => ['retention' => '10 years'],
    'pramnos.changelog' => ['compress_after' => '3 days'],
],
```

## Why the number in the code was never the number in the database

```php
if ($spec['retention'] !== null && !$schema->hasRetentionPolicy($table)) {
    $state['missing'][] = 'retention policy';
}
```

Presence only. Change `'2 years'` to `'90 days'`, run `timescale:ensure`, and it
reports nothing missing, changes nothing, and exits successfully. The two numbers
then disagree for ever — and the one in the code is the one people read.

Nothing could have fixed it either: `add_retention_policy()` raises on a duplicate,
which is why `hasRetentionPolicy()` existed in the first place, and there was no
remove. Without a remove there is no replace.

## The bias in the drift check

Comparing intervals means deciding whether `@ 90 days` and `90 days` are the same
thing. They are, and PostgreSQL will hand back either depending on where you read
it from.

So the check normalises, and **answers "no drift" whenever it cannot parse
something**. That asymmetry is the whole design:

- a false positive removes and re-adds a policy on *every run, for ever* — constant
  work against the scheduler over a formatting difference;
- a false negative costs one changed number not taking effect, which is exactly the
  situation this arrived to improve.

`1 year 6 mons 3 days` is therefore left alone rather than rewritten.

## The MySQL duplicate

```php
protected function hasPolicyJob(string $table, string $procName): bool
{
    if (!$this->capabilities->hasTimescaleDB()) {
        return false;
    }
```

A retention policy exists off TimescaleDB too — as a row in `framework_policies`,
executed by the `PolicyEngine` daemon. Answering `false` made
`HypertableRegistry::apply()` believe there had never been one, so every run
inserted another. It now reads the software policy store, and `addRetentionPolicy()`
updates an existing row rather than inserting beside it.

## Documentation

- [Hypertable Guide](../../Pramnos_Hypertable_Guide.md) — two new sections:
  retuning a declaration from `app.php`, and what drift repair does and
  deliberately does not do.
