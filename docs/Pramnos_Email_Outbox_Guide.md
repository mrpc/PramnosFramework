---
use_cases:
  - Queueing mail through the outbox and retrying failures
  - Deciding what of a sent message is kept, and for how long
  - Moving the mail log to a hypertable
  - Stopping the mail log growing without limit
---

# The outbox, and what is kept of a sent message

Part of the [email guide](Pramnos_Email_Guide.md).

## The outbox

`send()` opens an SMTP connection and waits for it. That is right when somebody is watching the
screen for what the message contains — a second-factor code, a sign-in link — and wrong for
everything else, where a visitor is paying 200–800ms for a message they are not waiting on.

```php
$email->queue();     // composed now, delivered by mail:flush
```

Two things happen. The message is **composed in this request** — the mail type applied, the
address checked against the unsubscribe records, the wrapper rendered — and then written to
`mails` at status `2` instead of being dialled out.

Composed now rather than by the worker, and that is the point rather than an implementation
detail: composition reads the request's language, its settings, its signed-in user and its
unsubscribe token, and a worker running an hour later has none of them. A spool that stored the
inputs and rendered later would send a different message from the one you composed —
occasionally, and unreproducibly. What the row holds is the final string.

`queue()` returns whether the message was **accepted for delivery**, which is a weaker claim
than `send()`'s "delivered". That is why it is a separate method and not a flag: no existing
caller's understanding of its own return value changes.

An address that has opted out is not queued at all — it is recorded as refused, exactly as
`send()` would.

### Delivering it

```bash
./yourapp mail:flush                # send what is pending
./yourapp mail:flush --limit=50
./yourapp mail:flush --dry-run      # list it, send nothing
```

The framework schedules it every minute — a `work` process or a `schedule:run` crontab line is
all it needs. Disable it with `FrameworkSchedule::disable('mail:flush')` only if something else
sends the outbox; otherwise every queued message, the security alerts included, stays `queued`
and nothing says it did not go. It moves each row to sent or failed **in place**, so a message
has one row for its whole life and the history screens need no union.

It sends what is **due**: a row whose `date` is still in the future is mail queued for later, and
stays pending until its time. So an application that queues a reminder for tomorrow writes the
row with tomorrow's `date`, and `mail:flush` sends it then.

It is a worker, so unlike `mail:prune` it does its job by default — a dry run is an option, not
the default.

```php
// app.php
'mail' => ['outbox' => [
    'enabled'  => true,        // false: nothing sent, nothing given up on — the outbox waits
    'deadline' => 86400,       // seconds a message stays worth retrying, or 'never'
    'from'     => ['address' => 'team@example.com', 'name' => 'The Team'],  // a row with no sender
    'backoff'  => ['base' => 60, 'max' => 3600],  // seconds before a retry; false: every run
]],
```

`deadline` `never` (or `--deadline=never`) keeps every message until it goes or the server
refuses it for good, through a backlog or an outage of any length.

A message the server refuses **for now** is not tried on every run: after its n-th such refusal
it waits `base · 2^(n−1)` seconds, at most `max` — a minute, two, four, up to an hour. The count
and the time are kept in the row's `extrainfo`, beside what it was queued with.

A message queued with names — `setTo(['person@example.com' => 'A Person'])` — keeps them in
`toname` / `fromname`, and goes out as `"A Person" <person@example.com>`. The names of several
recipients in one message are not kept: the row joins their addresses with commas, which a name
may hold too. Without `from`, a message with
no sender of its own is from `admin_mail`, with the site's name.

**What an application does around each send** goes in a subclass, registered under the same
name in its console application, which replaces the framework's:

```php
final class MailFlush extends \Pramnos\Console\Commands\MailFlush
{
    /** A reason not to send — the row is failed with it — or null. */
    protected function beforeSend(array $row, \Pramnos\Email\Email $email): ?string
    {
        return Blocklist::has($row['tomail']) ? 'On the blocklist' : null;
    }

    /** One message went. */
    protected function afterSent(array $row, \Pramnos\Email\Email $email): void
    {
        DailyStats::count('emails');
    }

    /** One failed for good: refused here, bounced, or refused by the server — not a retry. */
    protected function afterFailed(array $row, string $error): void
    {
    }

    /** A switch of the application's own, instead of mail.outbox.enabled. */
    protected function sendingEnabled(): bool
    {
        return Settings::getSetting('sendemails') !== 'no';
    }
}
```

How long sent mail is kept is `mail:prune`'s, from `mail.retention` — see below.

### The two answers that must not be confused

| The server said | Meaning | What happens |
|---|---|---|
| `5xx` | never — no such mailbox, rejected for policy | failed at once, reason on the row |
| `4xx` | not now | left pending, retried next run |
| nothing recognisable — DNS, timeout, refused connection | probably not now | left pending |

Treating a permanent refusal as retryable spends a full SMTP connection every run on an address
that will not accept the message at any point. Treating a temporary one as fatal throws away a
message because a mail server had a bad minute — and that failure is the invisible kind, because
a row marked failed looks exactly like one that was genuinely undeliverable.

### Bounded by time, not by attempts

There is no attempt counter. A real MTA retries for days and then bounces, because the useful
question is whether something has been undeliverable *long enough to give up on* — not how many
times it was tried. A row younger than the deadline is retried; past it, it fails with the
reason.

```php
'mail' => [
    'outbox' => ['deadline' => 86400],   // seconds; 24 hours by default
],
```

The sweep that fails the overdue rows runs on every `mail:flush`, as
`UPDATE mails … WHERE status = 2 AND date < ?`. It needs an index that leads with `status`: the
queued rows are a handful and the table is the whole history, so without one it reads every row,
every minute. The framework's `mails` has `idx_mails_status`. For a `mails` that predates the
framework's migrations, `add_status_date_index_to_mails` adds `(status, date)`. It does nothing
where any index already leads with `status`, whatever its name. On a log of tens of millions of
rows, building the index is a maintenance window. Build it yourself at a quiet hour, or exclude the
migration until then.

### One at a time

The command holds a worker lock, because two overlapping runs would both read the same pending
rows and both send them — and a duplicate security alert is a support ticket. Rows are marked
**after** the send rather than before, so a crash mid-run resends at worst one message rather
than losing it. For a notification that says somebody signed in to your account, that is the
right way round.

### From a notification

A notification declares `queueable(): bool` and the mail channel does the rest. See the
[Notifications guide](Pramnos_Notifications_Guide.md#getting-the-mail-off-the-request).


### What a queued message keeps

The `mails` row holds the address, the subject and the composed body. Everything else the send
needs travels in the row's `extrainfo` column, as JSON, while the message waits:

- copies (`cc`, `bcc`), `replyto` and the return path;
- the attachment, the priority and the read receipt;
- `addHeader()` headers, including a thread's `In-Reply-To` and `References`;
- the unsubscribe link, mailto and list, and the one-click flag, from which `List-Unsubscribe`,
  `List-Unsubscribe-Post` and `List-ID` are built;
- tracking, and structured data for Gmail.

`mail:flush` reads it back before sending, so a queued message leaves as it would have left at
once. When the row reaches its final state, the column holds `''` once the message is sent, or
the transport's error if it failed. A queued row is never shown as having an error.

An attachment is kept as its path, so the file has to be there when the worker runs: queue
from a file that stays, not a temporary upload. A message signed with `signWith()`, or sent
through `via()` or `withTransport()`, is sent at once and never queued, because the row would
have to hold the key or the password.

## What to keep of a sent message, and for how long

`mails` is the table that grows without limit in every installation of this framework, and it
grows for a reason that is easy to miss: it stores the **rendered body**. A password-reset mail
is maybe two hundred bytes of facts — when, to whom, which module, did it send — wrapped around
forty kilobytes of HTML. At a thousand messages a day that is fifteen gigabytes a year of markup
nobody will read, and about eighty megabytes of the answers people actually ask.

So the policy has **two stages**:

```php
// app/app.php
'mail' => ['retention' => [
    'strip_after'      => '90d',   // empty the body, keep the row
    'delete_after'     => '2y',    // remove the row
    'recipients_after' => '6m',    // recipient rows of finished campaigns
]],
```

1. **Strip the body.** The row stays, and with it every question an operator asks months later —
   "did they ever get the code", "when did we last write to this address", "how many that week".
   What is lost is [the message report](Pramnos_Email_Writing_Guide.md#a-message-to-one-account): nothing can be read back out
   of a message whose body is gone.
2. **Delete the row.** Eventually, because an audit log with no horizon is not a policy.

Deleting alone is the version people write, and it is the wrong shape: it throws away the cheap
thing to save the expensive one. Stripping alone is also wrong — it leaves a table growing at
eighty megabytes a year for ever.

```
./yourapp mail:prune                   # what the policy would do, and nothing else
./yourapp mail:prune --apply
./yourapp mail:prune --strip-after=90d --delete-after=2y --apply
```

**A dry run by default**, which is the opposite of most commands here and deliberate: this one
deletes, the amount depends on two numbers somebody just typed, and the difference between `90d`
and `90` is three months of an audit trail. A duration that does not parse is **no policy**
rather than a small number — a typo that meant "everything" would delete a mail log on a
scheduled run and nothing would ever say why.

With nothing configured the command reports the table's size and explains the two stages, and
**assumes no policy**. `mail:prune --apply` is scheduled daily at 04:10 and does nothing until
somebody has decided; a default here would apply a guess to an audit trail.

**The body goes from wherever it is kept**: the row's `content`, its `pramnos.mail_bodies` row, its file
under `var/emails`, or its file on a storage disk. A file that another message still names is left
until that message is stripped too, because identical bodies of the same hour are one file. A
stripped row has an empty `path`, and reads as a message with no body.

Two smaller notes. Deleting runs **before** stripping — the other order strips a body and then
deletes the row it belonged to, having written every one of those rows twice. And the sweep
works in batches by id on every database, because a neglected table is millions of rows and one
statement over all of them holds a lock long enough to make the maintenance the outage. A write
the database refuses ends the run with what it did, logged.

`recipients_after` covers `massmessagerecipients`, the other table that grows without limit: one
row per recipient per campaign, whose only remaining purpose once the campaign is finished is
the count on its page — and the count is on the campaign row.

### The mail log as a TimescaleDB hypertable

```bash
php bin/pramnos mail:hypertable --dry-run     # where the table is, and how much there is to move
php bin/pramnos mail:hypertable               # convert, then move the rows, newest first
php bin/pramnos mail:hypertable --no-drain    # convert only; move the rows in a later run
```

On TimescaleDB, `mails` can become a hypertable: monthly chunks, compressed once they are ninety
days old, so statistics over a period read only that period. It is a console command rather than a
migration because it is not cheaply undone, and when to run it is the installation's decision.
Plain PostgreSQL and MySQL keep their table, and there the body store is what keeps it small.

Run it as the application, with its own database role, which owns `mails`. The new table belongs
to whoever creates it: made by a superuser, it is a table the application's role cannot read or
write.

It runs with the site up. The **conversion** takes milliseconds and touches only the catalogue:
- `mails` is renamed `mails_legacy`;
- a new `mails` is made like it, with the same columns, defaults and indexes. A serial `id`
  keeps its sequence. An IDENTITY `id`, which is what `db:migrate-from-mysql` makes, gets an
  identity of its own, numbered on from the old table's highest id and given the old sequence's
  name, so a process already running (a mail worker) goes on reading the right insert ids;
- `date` is widened to `bigint`, so it outlives 2038;
- the primary key becomes `(id, date)`, as a hypertable needs;
- the status index becomes `(status, date)`, which is what the outbox reads.

New mail goes to the new table at once. The old rows then **move newest first**, in batches of ten
thousand. Each batch is deleted from the old table and inserted into the new one in one statement,
so a row is never in both. Sixty thousand rows a second were measured, and a run stopped part way
is finished by the next. When nothing is left, `mails_legacy` is dropped.

**Move the bodies first.** Run `mail:bodies-to-database` before `mail:hypertable`. Moving a
body also updates its `mails` row, and once `mails` is a hypertable an old row sits in a
compressed chunk, where every update costs many times more. Both commands hold back the
compression policy of the table they fill while they run, and compress once at the end.

`date` stays a Unix timestamp, so nothing that reads or writes it changes. While the rows move,
history not yet moved is not in `mails`, and the screens fill from today backwards. Erasure,
`mail:prune` and a person's copy of their mail read both tables, so none of them misses a
message.

### A person in the mail log: their copy, and their erasure

```php
use Pramnos\Email\MailPersonalData;

MailPersonalData::export('someone@example.com');  // every message to them, with its body
MailPersonalData::erase('someone@example.com');   // how many messages were erased
```

An account's data export carries the first as its `mail` section, labelled *Mail sent to you*.
Each message comes with its date, status, sender, subject and module, and with its body as it
was sent, read from wherever it is kept.

An account erasure (`Pramnos\Auth\AccountErasure`) calls this with the account's address. Each message
to the address **stays, pseudonymised**:

- no address and no name;
- the subject `[erased]`;
- no body, from wherever it was kept;
- no outbox state or error that names the person.

What survives is that a message of this module went out then, with this outcome: the audit
log's question, without the person. A newsletter body that other readers share stays, for them.
The tracking row keeps its counts, and loses the address and the subject.

The address matches however it was stored: `Someone@Example.com` is the same person.

Two records are left on purpose:
- **`bounces`** holds only a hash, which tells nothing to somebody without the address.
- **`emailoptouts`** is the record that this address asked not to be mailed. Erasing it would
  undo the request.
