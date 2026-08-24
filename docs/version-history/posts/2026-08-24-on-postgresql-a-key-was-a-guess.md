---
date: 2026-08-24
categories: [Changelog]
---

# On PostgreSQL a key was a guess, and a response could not say what it cost

Two filings from the same consuming application, both raised by using what
shipped earlier today. On PostgreSQL the generators could not find a primary key
or a foreign key — faults older than the generator work that surfaced them, and
affecting the MVC generator equally. And a `ClientResponse` carried no transfer
statistics, so a pooled request could not say what it cost.

<!-- more -->

## Fixed — on PostgreSQL a foreign key was never a foreign key

`Database::getColumns()` computed its `ForeignKey` flag from
`information_schema.constraint_column_usage`. For a FOREIGN KEY constraint that
view lists the column of the **referenced** table, not the referencing one. So on
`streams(station_id) → stations(id)`:

| column | `PrimaryKey` | `ForeignKey` | `ForeignTable` |
|---|---|---|---|
| `id` | true | **true** | `''` |
| `station_id` | false | **false** | `stations` |

Measured, not reasoned: the flag was true on the primary key and false on the
actual foreign key, while `ForeignTable` and `ForeignColumn` — from
`key_column_usage`, in the same row — were correct. The right data was already
there under the right name; only the flag disagreed with it.

**It was never true for a foreign key on any table.** Everything gated on it
therefore saw none:

- the generated Svelte form rendered a **number input** where the searchable
  picker belongs — the headline of yesterday's generator work, unreachable on
  PostgreSQL;
- the generated MVC form rendered a bare `<input>` instead of the
  select2-over-`fkOptions()` it has had for much longer;
- `unsigned` is decided from the same flag, so generated migrations differed too.

`PrimaryKey` used the same view and was *accidentally* right, because for a
PRIMARY KEY constraint `constraint_column_usage` does list the table's own
columns. The two looked symmetric while one of them was a coincidence, so both
now read `key_column_usage` — measured identical on single and composite keys,
and right for the same reason as its neighbour rather than by luck.

## Fixed — and a primary key was a guess

```php
if (($result->fields['Key'] ?? '') === 'PRI'
    || ($result->fields['Column_key'] ?? '') === 'PRI') {
```

`Key` and `Column_key` are the MySQL projection's names. PostgreSQL answers
**`PrimaryKey`** as a boolean, which this never read — so the loop could never
match, and the `<singular>id` convention was the answer for every PostgreSQL
table.

Measured against one application's schema: of **88** single-column primary keys
the convention got **3** right.

```
albums            real: id      guessed: albumid
applications      real: appid   guessed: applicationid
station_streams   real: id      guessed: station_streamid
```

**Why this is worse than a wrong default usually is:** the read path never
touches the key. The generated screen puts that name in its `KEY` constant, and
every `PUT {resource}/{id}`, every `DELETE` and the table's `rowKey` are built
from a column that does not exist. So a generated CRUD **lists perfectly and
fails on the first save or delete** — after somebody has started trusting it.
That is worse than failing at generation time.

`primaryKeyFor()` now reads `PrimaryKey` too, through a small `isTruthyFlag()`
helper because PostgreSQL's booleans arrive as `true`, `'t'` or `'1'` depending
on how the row was cast. The convention stays as the last resort it was meant to
be — the schema-first workflow needs it for a table that does not exist yet.

`create:view --full` on an existing table also asked
`getSingularPrimaryKey()`, which is pure convention and never touches the
database. That call site now asks the table. The migration-wizard call sites
still use the convention, and should: there the table does not exist and the
migration about to be written is what will name its key.

## Added — a response says what it cost

```php
$response->transferredBytes();   // bytes over the wire, headers included
$response->elapsedMs();          // how long the request took
```

libcurl measured both already and the client discarded them.

**In a pool, each entry reports its own figures.** That is the point. Without
them a caller keeping an outbound-bandwidth ledger had only the clock around the
whole batch, and dividing it across the requests silently changes the column's
meaning from *"how slow was that server"* to *"what share of our elapsed time did
this cost"*. Both are legitimate numbers; having to pick one because the response
would not say is not.

Three decisions in it, each from how the ledger is actually read:

- **The headers count.** `CURLINFO_SIZE_DOWNLOAD` is the body alone, so a
  `headersOnly()` probe would have reported **0 bytes** for a request that really
  moved bytes — measured at 161 bytes of headers against 0 of body on a local
  endpoint. Zeroing the byte column of a screen an operator reads is the failure
  this exists to avoid, and it would have hit precisely the caller that probes
  rather than downloads.
- **A failure still reports.** A 404 with a page of HTML behind it is bandwidth
  that was paid for, and a 500 with a stack trace is bandwidth *and* a wrong
  address. A statistic only populated on success would miss exactly the requests
  worth finding.
- **`null` means nobody measured, not zero.** A faked response has no transfer to
  report, and `0` would quietly deflate any total it was added to.

`transferredBytes()` is deliberately not `strlen(body())`: they differ under a
`maxResponseBytes()` ceiling, under compression, and by the headers.

Additive, so BC holds, and the pool needed no new API — each entry already
returns its own `ClientResponse`.

## What made these findable

Both filings say the same thing about how they were found, and it is worth
repeating: **a fixture would not have caught either PostgreSQL fault.** A
hand-built fixture names its columns after the convention, so the key fault is
invisible; and the foreign-key fault needs two real tables with a real constraint
between them. They appeared the first time the introspection ran against a live
schema.

The new tests are integration tests against the Docker PostgreSQL for that
reason, and their fixture is deliberately built with keys the convention gets
**wrong** — a table keyed on `id` rather than `<singular>id`. One of them asserts
that the convention and the real answer differ, so the day they coincide the test
says so by failing rather than by quietly proving nothing.

Reverting each half reddens its own tests and not the other's: the foreign-key
view reddens four, the primary-key read reddens three.

## Documentation

- **[Database API Guide](../../Pramnos_Database_API_Guide.md#reading-a-tables-schema-getcolumns)**
  — what the flags look like on each driver, and that a reader should accept
  either spelling of a boolean.
- **[HTTP Client Guide](../../Pramnos_Http_Client_Guide.md#what-a-request-cost)**
  — the two accessors, why the headers count, and the pooled-ledger example.
