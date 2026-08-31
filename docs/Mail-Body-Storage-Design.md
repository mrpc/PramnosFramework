---
use_cases:
  - Planning where a sent message's body is stored and how it is deduplicated
  - Understanding why `mails.path`, `mails.hash` and `BodyStore` disagree with each other
  - Deciding the migration of a large `mails` table to a hypertable
---

# Mail body storage — design

**Status:** design. Nothing here is implemented.
**Last updated:** 31 August 2026

An earlier attempt at this was written while the design was still being discussed, carried
decisions that had not been agreed, and was reverted whole (`03e944cc`). This page keeps the
material so the next attempt starts from a plan rather than from assumptions.

See also [Roadmap & Open Items](Roadmap.md).

---

## 1. What already exists

Applications built on this framework have been storing mail bodies on disk for years, and at
least two of them do it with **the same class, copied**: identical `saveContent()`,
`loadContent()` and `parse()`, the same `path` / `hash` columns, the same `Y/m/d/h` directory
layout, the same md5 filename. One is a superset of the other — it added bounce handling and
IMAP on top — but the storage core is character-for-character the same.

They differ in exactly one thing, the root:

```
ROOT/_history/emails/Y/m/d/h/<md5>.html.gz
ROOT/var/emails/Y/m/d/h/<md5>.html.gz
```

Two consequences:

- **The core belongs in the framework.** It is not one application's convention; it is a pattern
  that was copied because the framework did not offer it.
- **The root has to be configurable.** Neither value is "the" right one.

### The survey is incomplete

Only the applications on one working machine were examined. **Before this design is settled,
find out whether other installations have their own store** — each one found adds a root and
possibly a variation in the layout.

---

## 2. The `mails` table

The schema an application has and the schema this framework ships are **already almost
identical**. There is no structural gap to bridge. There are two semantic ones:

| Column | Application | Framework | Note |
|---|---|---|---|
| `id` | `int` AI PK | same | |
| `status` | `tinyint` 0/1/2 | same — `Mail::STATUS_QUEUED = 2` exists | identical |
| `frommail` / `fromname` | `varchar(128)` / `varchar(255)` | same | |
| `tomail` / `toname` | `varchar(128)` indexed / `varchar(255)` | same | |
| `subject` | `varchar(255)` | same | |
| `content` | `text` | same | empty once the body is in a file |
| `date` | `int(11)` unix | same | **overflows 19 Jan 2038** (signed int32) |
| `module` / `moduleinfo` | `varchar(128)` / `varchar(255)` | same, `module` indexed | |
| `extrainfo` | `text` | same | |
| `path` | `varchar(255)` — **the body**, relative to `ROOT` | same column, **always written empty** | |
| `hash` | `char(32)` = **md5(content)** | `char(32)` indexed = **`md5(tomail\|subject\|date)`** | **the two disagree** |

1. **`path` is where the body is.** The schema comment calls it "Template path or file reference",
   which is stale — an application writes the `.html.gz` location there and reads the body back
   from it. The framework has never written to it.
2. **`hash` means two different things.** The schema documents "MD5 hash of the email content",
   which is what an application computes and compares against `md5($content)` to decide whether a
   body needs storing again. `Email::send()` writes an identity of the *message* instead.
   **No query anywhere filters on it** — verified across both codebases.

> Changing what `hash` means is a change to a column other code reads and acts on. It is not a
> framework-internal decision.

---

## 3. Indexes

Every query against the table, in the framework and in a large application using it:

| Query | Where | How often |
|---|---|---|
| `WHERE id = ?` | everywhere | constantly |
| `WHERE status=2 AND date<? ORDER BY date ASC LIMIT n` | the application's mail-sending cron | every minute |
| `WHERE LOWER(tomail) = LOWER(?)` | framework: listing, user card, `AuthCollector` | per page view |
| `WHERE tomail = ?` | the application's GDPR export | rarely |
| `DELETE WHERE date<? AND (status=1 OR status=0)` | the application's history cleanup | daily |
| `WHERE date>=? AND date<?` | the application's statistics | per page view |
| `WHERE content<>'' AND date>0 ORDER BY id LIMIT n` | `mail:archive` | manual |

**Proposal, not agreed:**

| Index | Today | Earns it? | Proposal |
|---|---|---|---|
| PK | `id` | — | `(id, date)` if this becomes a hypertable |
| `(status, date)` | `status` alone | the sending cron — the hottest query either side has | replaces the single-column one |
| `tomail` | yes | GDPR export | keep — but see below |
| `date` | yes | statistics, prune | redundant under a hypertable (chunk exclusion) |
| `module` | yes | **no query, anywhere** | drop |
| `hash` | yes | **no query, anywhere** | drop |

**A separate finding.** The framework writes `LOWER(tomail) = LOWER(?)` everywhere. A plain btree
**cannot be used** when the column is wrapped in a function, so `idx_mails_tomail` is paid for on
every insert and no framework query uses it. An application querying bare `tomail` on MySQL gets
away with it through a case-insensitive collation.

Either an expression index on `LOWER(tomail)` for PostgreSQL, or normalise the column to lower
case on write — the second removes `LOWER()` from every query and works on both drivers, at the
cost of rewriting existing data.

---

## 4. Retention and erasure policy — agreed

- **Mail is never deleted**, except on a GDPR account erasure.
- On erasure the row **stays, pseudonymised**: `tomail` / `toname` blank, `subject` `[erased]`,
  `content` blank, the stored file removed. What survives is the fact — a message of this kind
  went out, then, with this outcome — without the person.
- The body is stored on disk **by default**.
- The framework's layout **moves toward what applications already use**, without a BC break; the
  existing convention stays readable as a fallback.

**A premise that turned out to be wrong.** "It is enough to delete the body" does not hold.
`tomail` is present on every row and **is** the personal datum, not metadata about it. And the
content was in the database on most rows anyway, because the store was opt-in and had a
512-byte floor.

**Still open:** GDPR erasure does not touch `mails` at all today. `deleteuser()` removes only the
`users` row; the `Gdpr` controller touches `usertokens` and `gdpr_requests`.

---

## 5. Template plus variables

**The idea:** store the body **once** with its `{tags}` intact; store the resolved per-recipient
values as JSON on the row; recombine when rendering.

It works because **the placeholders already exist upstream**. An application writes `{firstname}`
and substitution happens at the last moment. Nothing has to be invented or parsed back out — what
is stored is simply the string as it was **before** substitution.

```
path      → the message with {tags} intact     → 100 recipients, one file
bodyvars  → {"firstname":"…", …}                → one small value per row
hash      → md5 of what was actually sent       → integrity
```

### What varies per recipient

| | Where it enters | In the JSON |
|---|---|---|
| unsubscribe URL | `EmailTheme::wrap()` | the list name only — the token derives from `tomail` |
| tracking pixel | `$body . Tracking::pixel($id)` | the `trackingId` |
| tracked links | `Tracking::wrapLinks()` | nothing — the **real** hrefs are stored |
| language | `Language::using($user->language)` | nothing — another language is another body, correctly |

**No HTML parsing is needed.** Everything above is injected by the framework, which knows each as
an exact string. The capture happens **before** `applyTracking()`.

### Two findings that force the design

1. **`MailAction::token()` contains `time() + $ttl`.** A click token can never be regenerated, so
   "re-run the transformation at render time" is not available. A placeholder is the only route.
2. **`Unsubscribe::token()` is deterministic** — `email|list`, signed, no timestamp. It derives
   from `tomail` and needs no storage.

### Where this must differ from what applications do today

`parse()` builds its tag map **from the live user object**. Storing only a user id and re-running
it shows *today's* name — an audit trail that changes retroactively when somebody corrects their
surname. **The JSON has to hold resolved values.**

### The cost

`Email::send()` currently guarantees, in as many words, that *the mailer sends this and the audit
log records it, so they have to be the same string*. Storing links without their tracking ends
that guarantee: the archived body is no longer byte-identical to what was sent.

Mitigating, but not cancelling: click tokens expire in 30 days, so the exact bytes are
short-lived anyway; and `hash` still identifies what was sent, so a copy can be checked even if it
cannot be reproduced.

**Not yet agreed.**

---

## 6. Deduplication by hard link

**Problem.** A dated partition scopes deduplication to its own directory. With templated bodies
that is expensive over time: a password-reset body is identical for ever, so an hourly partition
alone writes it 8,760 times a year.

**Mechanism** — not present in any application today:

```
.by-digest/3f/3f8a…c1.html.gz    ← the body, once
2026/08/01/09/3f8a…c1.html.gz    ← hard link
2026/08/15/12/3f8a…c1.html.gz    ← hard link
```

**Measured**, one template across 30 days × 24 hours:

```
written: 1  |  links: 719  |  names: 721  |  inodes: 1  |  on disk: 4 KB
```

Also verified: `rm -rf 2026/08/01` does **not** destroy a body another period still names.

**It also makes collecting orphans scale.** Instead of O(rows + files), it becomes "walk
`.by-digest`, unlink where `nlink == 1`" — one entry per *distinct body*. The kernel has been
counting references all along, so no separate count can drift out of step with the rows.

### Open questions

- **Where does `.by-digest` live?** Inside the store means every walker sees each body twice,
  which quietly breaks file counts and size reporting. Outside means two roots to configure and
  back up together. *Leaning outside*, because "remember to skip it" is the rule that gets
  forgotten.
- **A sweep by `nlink` must look at `.by-digest` and nothing else.** Bodies written before this
  mechanism are plain files with a link count of one and a row pointing straight at them. Walking
  the dated tree and trusting `nlink` deletes every one of them. That needs a structural
  guarantee, not a comment.
- **Does it coexist with `BodyStore::orphans()`?** They are two incompatible definitions of an
  orphan. `orphans()` may simply have to go: it solves a problem that "mail is never deleted"
  makes nearly non-existent, and it is the part that does not scale.
- **Backup.** `rsync` without `-H` expands hard links into copies. This has to be documented, or
  a backup is silently many times the size of the original. SMB and NFS do not always support
  links — fall back to a plain copy.

---

## 7. Hypertable migration

Measured on 2 million rows / 1 GB:

| | |
|---|---|
| `ALTER TABLE … SET SCHEMA` on 1 GB | **3 ms** — catalog only, size-independent |
| Moving 2 M rows in batches, site live | 74 s (~27,000 rows/s) |
| Cutover (view swap) | **21 ms** |
| Compression | 1085 MB → 22 MB |
| TimescaleDB 2.19.3: INSERT into a compressed chunk | works |

**Four steps:** create the new structure → rename → transparent view → an `add_job()` that drains
in the background and **deletes itself** when done. The migration itself finishes in milliseconds,
so it is safe inside the web request that triggers migrations.

Because history may be **invisible while the drain runs**, no UNION view, no `INSTEAD OF`
triggers and no cutover step are needed.

### Traps found by running it

1. `CREATE TABLE … LIKE … INCLUDING DEFAULTS` copies a default that references the **old** table's
   sequence. `DROP TABLE … CASCADE` would take the sequence with it and **every later INSERT would
   fail**. Needs `ALTER SEQUENCE … OWNED BY` first.
2. The mover must be `DELETE … RETURNING` feeding `INSERT` in **one** transaction. Copying instead
   of moving doubles every row.
3. Drain **newest first**. Oldest-first leaves people looking at an empty screen for hours that
   then fills in backwards.
4. UPDATE or DELETE by id against a row that has not moved yet affects **zero rows, silently**.
   So `mail:archive` runs **before** the migration, not during it.
5. `set_integer_now_func` requires the function's return type to match the partition column
   **exactly** (`integer`, not `bigint`). Moot if `date` becomes `timestamptz`.
6. **Peak disk.** `DELETE` does not return pages to the operating system, so peak usage is the old
   table at full size plus the new one. Countermeasures: compress chunks as they fill, and archive
   bodies out first — the second is the larger lever by far.

### Decisions

- `date` to `timestamptz`.
- **No compression on chunks that still receive updates.** An inbox table whose read state changes
  is the case that breaks; an append-only mail log is not.
- Retention by row *type* cannot be expressed by `drop_chunks`, which is time-only. It needs two
  hypertables.
- **Plain PostgreSQL and MySQL: nothing.** They keep today's table. There, the body store is the
  only answer to size — which is why it matters that the store is application-level and works
  everywhere.

### Open

Should this migration be **CLI-only**? Probably — it requires the archive to have run first and it
is not cheaply reversible. And **how does the `Mail` class know which shape is in effect**, when
`integer date` and `timestamptz date` will coexist across installations? A third route: have the
view expose `date` as an integer and let an `INSTEAD OF INSERT` cast on write, so no application
changes at all.

---

## 8. Real bugs, still open

Found while prototyping; none is fixed.

1. **TOCTOU in `BodyStore::orphans()`.** It reads the rows first and the directory second. A
   message sent in between writes a file that no row named at the time the rows were read, so it
   is reported as an orphan — and `--gc` deletes the body of something that was just delivered.
   On an installation that sends all day this window is not theoretical, and the damage is silent:
   the row keeps its path and the file behind it is gone.
2. **`BodyStore::bodyOf()` returns an empty string for a row whose body is in `path`.** It knows
   `content` and its own column and nothing else, so the screens go blank on exactly the
   installations with the most history to show.
3. **`orphans()` does not scale.** It reads every path on every row into memory and walks every
   file. At ten million messages that is a job that cannot be run.
4. **"A campaign is one file" is false for personalised mail.** The unsubscribe token and the
   tracking pixel are per recipient, so a mailed campaign is one file per recipient. Only bodies
   that are genuinely identical collapse.

---

## 9. Next steps

1. **Finish the survey** — other installations with their own store.
2. **Decide** the open questions in §5 (the audit-trail cost), §6 (where `.by-digest` lives, and
   the fate of `orphans()`), and §3 (indexes, `tomail` normalisation).
3. **Write an implementation plan** with an order and checkpoints — no code before that.
4. Separately and afterwards: the hypertable migration (§7) and GDPR erasure (§4).
