<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Console\WorkerLock;
use Pramnos\Email\Email;
use Pramnos\Framework\Factory;
use Pramnos\Messaging\Mail;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deliver the mail sitting in the outbox.
 *
 * ```
 * ./yourapp mail:flush                    # send what is pending
 * ./yourapp mail:flush --limit=50
 * ./yourapp mail:flush --dry-run          # list it, send nothing
 * ```
 *
 * `Email::queue()` composes a message and writes it to `mails` at
 * {@see Mail::STATUS_QUEUED}. This is the other half: it takes those rows, sends them, and
 * moves each one to sent or failed **in place**, so a message has exactly one row for its
 * whole life and the history screens need no union.
 *
 * Run it from the scheduler, every few minutes. It is a worker, so unlike `mail:prune` it
 * does its job by default rather than reporting — a dry run is an option, not the default.
 *
 * ## Bounded by time, not by attempts
 *
 * There is no attempt counter, and that is a decision rather than an omission: a real MTA
 * retries for days and then bounces, because the useful question is «has this been undeliverable
 * long enough to give up on» and not «how many times have we tried». A row younger than the
 * deadline is retried; past it, it fails with the reason. `mail.outbox.deadline` in `app.php`,
 * 24 hours by default.
 *
 * The same discrimination the push channel makes, for the same reason: a **permanent** refusal
 * (an SMTP 5xx — no such mailbox, rejected for policy) fails the row immediately, and a
 * **transient** one (4xx, a refused connection, a timeout) leaves it pending. Treating the
 * first as retryable spends a full SMTP connection per run on an address that will never
 * accept; treating the second as fatal throws away a message because a mail server had a bad
 * minute.
 *
 * ## One at a time
 *
 * The command holds a {@see WorkerLock}. Two overlapping runs would both read the same pending
 * rows and both send them, and a duplicate security alert is a support ticket. Rows are marked
 * **after** the send rather than before, so a crash mid-run resends at worst one message rather
 * than losing it — for a security notification that is the right way round.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MailFlush extends Command
{
    /** How many messages one run sends unless told otherwise. */
    public const BATCH = 100;

    /** How long a message stays worth retrying, in seconds. */
    public const DEADLINE = 86400;

    protected function configure(): void
    {
        $this->setName('mail:flush')
            ->setDescription('Send the mail queued in the outbox (mails.status = 2)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED,
                'How many to send this run. Defaults to ' . self::BATCH . '.')
            ->addOption('deadline', null, InputOption::VALUE_REQUIRED,
                'Give up on a message older than this, in seconds, or `never`. '
                . 'Defaults to `mail.outbox.deadline` or ' . self::DEADLINE . '.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'List what would be sent, and send nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit    = max(1, (int) ($input->getOption('limit') ?: self::BATCH));
        $deadline = $this->deadline($input->getOption('deadline'));
        $dryRun   = (bool) $input->getOption('dry-run');

        $lock = new WorkerLock('mail-flush');

        if (!$dryRun && !$lock->acquire()) {
            // Not a failure: the scheduler fires this on a timer and an overlap is ordinary.
            $output->writeln('<comment>Another mail:flush holds the lock; nothing to do.</comment>');

            return Command::SUCCESS;
        }

        try {
            // Switched off — a development copy, an emergency: nothing is sent and nothing given
            // up on, so every message is still there, queued, when it is switched back on.
            if (!$this->sendingEnabled()) {
                $output->writeln('<comment>Sending is switched off (mail.outbox.enabled); the outbox stays as it is.</comment>');

                return Command::SUCCESS;
            }

            $expired = $dryRun || $deadline === null ? 0 : $this->expire($deadline);

            if ($expired > 0) {
                $output->writeln(
                    $expired . ' message(s) gave up after ' . $deadline . 's and were marked failed.'
                );
            }

            // With no deadline every queued row is due, however old: a window back to the epoch.
            $pending = $this->pending($limit, $deadline ?? time());

            if ($pending === []) {
                $output->writeln('The outbox is empty.');

                return Command::SUCCESS;
            }

            if ($dryRun) {
                foreach ($pending as $row) {
                    $output->writeln(sprintf(
                        '  #%d  %s  %s',
                        (int) $row['id'],
                        str_pad(substr((string) $row['tomail'], 0, 34), 34),
                        substr((string) $row['subject'], 0, 60)
                    ));
                }
                $output->writeln('');
                $output->writeln(count($pending) . ' message(s) would be sent.');

                return Command::SUCCESS;
            }

            $sent = $failed = $retry = 0;

            foreach ($pending as $row) {
                switch ($this->deliver($row)) {
                    case 'sent':
                        $sent++;
                        break;
                    case 'failed':
                        $failed++;
                        break;
                    default:
                        $retry++;
                }

                $lock->heartbeat(['sent' => $sent, 'failed' => $failed]);
            }

            $output->writeln(sprintf(
                '%d sent, %d permanently failed, %d left pending for the next run.',
                $sent,
                $failed,
                $retry
            ));

            return Command::SUCCESS;
        } finally {
            if (!$dryRun) {
                $lock->release();
            }
        }
    }

    /**
     * Send one stored message, and record which of the three things happened.
     *
     * @param  array<string, mixed> $row
     * @return string 'sent' | 'failed' | 'retry'
     */
    protected function deliver(array $row): string
    {
        // Bounced or blocked since it was queued: it is not sent, and the row says why.
        $refused = $this->bounced((string) $row['tomail']);
        if ($refused !== null) {
            return $this->refuse($row, 'Not sent: ' . $row['tomail'] . ' ' . $refused . '.');
        }

        $email = $this->mailer();
        $email->setTo((string) $row['tomail']);
        $email->setSubject((string) $row['subject']);

        if (trim((string) $row['frommail']) !== '') {
            $email->setFrom((string) $row['frommail']);
        } elseif (($sender = $this->defaultSender()) !== null) {
            $email->setFrom($sender);
        }

        /*
         * Assigned rather than composed.
         *
         * `body` is what the transport falls back to when nothing has been wrapped, and this
         * string was already wrapped when it was queued. Going through `send()` would wrap it a
         * second time — a whole HTML document inside another one — and would write a second
         * audit row for a message that already has one.
         */
        $email->body = \Pramnos\Email\Body::of($row);

        // And the rest of what it was queued with: copies, reply-to, the attachment, the
        // unsubscribe headers, the thread, tracking. The id is this row's, for tracking to
        // point at. A row queued before the outbox kept this has none, and sends as it did.
        $kept = json_decode((string) ($row['extrainfo'] ?? ''), true);
        if (is_array($kept) && is_array($kept['outbox'] ?? null)) {
            // Sent as of the row's date, which is what its links are issued from — and what a
            // stored body is given them again from.
            $email->restoreOutboxState(array_merge($kept['outbox'], ['mailId' => (int) $row['id'], 'sentStamp' => (int) $row['date']]));
        }

        // The application's own reasons not to send — a list of its own, a check of its own.
        $refused = $this->beforeSend($row, $email);
        if ($refused !== null) {
            return $this->refuse($row, $refused);
        }

        if ($email->sendRendered()) {
            $this->mark((int) $row['id'], Mail::STATUS_SENT, '');
            $this->keepBody($row, $email);
            $this->afterSent($row, $email);

            return 'sent';
        }

        $error = $email->getLastError();

        if ($this->isPermanent($error)) {
            return $this->refuse($row, $error);
        }

        return 'retry';
    }

    /** A row failed for good, with its reason, and the application told. */
    private function refuse(array $row, string $reason): string
    {
        $this->mark((int) $row['id'], Mail::STATUS_FAILED, $reason);
        $this->afterFailed($row, $reason);

        return 'failed';
    }

    /**
     * A reason this message must not go, or null to send it — an application's own list or
     * check, beside the bounces the framework already keeps. The row is failed with the reason.
     *
     * @param array<string, mixed> $row
     */
    protected function beforeSend(array $row, Email $email): ?string
    {
        return null;
    }

    /**
     * A message went: an application's own count or log of it.
     *
     * @param array<string, mixed> $row
     */
    protected function afterSent(array $row, Email $email): void
    {
    }

    /**
     * A message failed for good — refused before sending, bounced, or refused by the server.
     * Not called for a message left pending to try again.
     *
     * @param array<string, mixed> $row
     */
    protected function afterFailed(array $row, string $error): void
    {
    }

    /**
     * Whether this run sends at all: `mail.outbox.enabled` in `app.php`, true unless it says
     * false. An application with a setting of its own for it overrides this.
     */
    protected function sendingEnabled(): bool
    {
        $enabled = \Pramnos\Application\Application::currentInstance()?->applicationInfo['mail']['outbox']['enabled'] ?? true;

        return $enabled !== false && $enabled !== 0 && $enabled !== '0';
    }

    /**
     * Who a message with no sender of its own is from: `mail.outbox.from` in `app.php`, as
     * `['address' => …, 'name' => …]`, or null for the mailer's own default — `admin_mail`, with
     * the site's name.
     *
     * @return array<string, string>|null `[address => name]`, as Email::setFrom() takes it
     */
    protected function defaultSender(): ?array
    {
        $from    = (array) (\Pramnos\Application\Application::currentInstance()?->applicationInfo['mail']['outbox']['from'] ?? []);
        $address = trim((string) ($from['address'] ?? ''));

        return $address === '' ? null : [$address => (string) ($from['name'] ?? '')];
    }

    /**
     * Move a sent message's body out of the outbox row, to where the body store keeps bodies. A
     * failure leaves it in the row — sent, with its body where the outbox put it.
     *
     * @param array<string, mixed> $row
     */
    protected function keepBody(array $row, Email $email): void
    {
        try {
            $columns = $email->keepSentBody((int) $row['id'], (int) $row['date'], (string) $row['tomail']);
            if ($columns['path'] !== '') {
                Factory::getDatabase()->queryBuilder()->table('#PREFIX#mails')->where('id', (int) $row['id'])->update($columns);
            }
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Sent mail ' . (int) $row['id'] . ' kept its body in the row: ' . $e->getMessage(), 'mail');
        }
    }

    /** Why mail must not go to this address, or null — and null when the store cannot say. */
    protected function bounced(string $address): ?string
    {
        try {
            return \Pramnos\Email\Bounces::refusal($address);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether a refusal will still be a refusal tomorrow.
     *
     * Symfony's transport exceptions do not type this, so the SMTP reply code in the message is
     * what there is: `5xx` is the server saying «never», `4xx` is «not now». Anything with no
     * recognisable code — a DNS failure, a timeout, a refused connection — is treated as
     * transient, which is the safe direction: the cost of being wrong is one more attempt,
     * against silently discarding a message that would have gone.
     */
    protected function isPermanent(string $error): bool
    {
        return preg_match('/\b5\d\d\b/', $error) === 1;
    }

    /**
     * Move a row out of the outbox, in place.
     */
    protected function mark(int $id, int $status, string $error): void
    {
        Factory::getDatabase()->queryBuilder()
            ->table('#PREFIX#mails')
            ->where('id', $id)
            ->update(['status' => $status, 'extrainfo' => $error]);
    }

    /**
     * The pending rows worth attempting, oldest first: those due — a row dated in the future is
     * mail an application queued for later, and stays until its time — and not past the deadline.
     *
     * Oldest first because a queue that serves the newest first can starve one message for
     * ever, and the one that has been waiting longest is the one closest to its deadline.
     *
     * @return list<array<string, mixed>>
     */
    protected function pending(int $limit, int $deadline): array
    {
        $result = Factory::getDatabase()->queryBuilder()
            ->table('#PREFIX#mails')
            ->where('status', Mail::STATUS_QUEUED)
            ->where('date', '>=', time() - $deadline)
            ->where('date', '<=', time())
            ->orderBy('date', 'asc')
            ->limit($limit)
            ->get();

        $rows = [];

        while ($result && ($row = $result->fetch()) !== null) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Fail everything that has been pending past the deadline.
     *
     * One statement rather than a row at a time: these are not being sent, only given up on,
     * and an installation whose mail server was down for a day has a great many of them.
     */
    protected function expire(int $deadline): int
    {
        // The count comes off the `Result`: `Database` has no `getAffectedRows()`, and asking
        // it for one returns nothing — which reads as "no rows expired" on a run that expired
        // thousands.
        $result = Factory::getDatabase()->queryBuilder()
            ->table('#PREFIX#mails')
            ->where('status', Mail::STATUS_QUEUED)
            ->where('date', '<', time() - $deadline)
            ->update(['status' => Mail::STATUS_FAILED, 'extrainfo' => 'Undeliverable within the outbox deadline']);

        return is_object($result) ? (int) $result->getAffectedRows() : 0;
    }

    /**
     * How long a message stays worth retrying.
     */
    protected function deadline(mixed $option): ?int
    {
        $configured = \Pramnos\Application\Application::currentInstance()?->applicationInfo['mail']['outbox']['deadline'] ?? null;
        foreach ([$option, $configured] as $value) {
            // `never`: a message is not given up on for its age — it waits out a backlog or an
            // outage of any length, and only the server's refusal fails it.
            if (is_string($value) && strtolower(trim($value)) === 'never') {
                return null;
            }
            if ((int) $value > 0) {
                return (int) $value;
            }
        }

        return self::DEADLINE;
    }

    /**
     * A seam, so a test can send nothing.
     */
    protected function mailer(): Email
    {
        return new Email();
    }
}
