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
                'Give up on a message older than this, in seconds. '
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
            $expired = $dryRun ? 0 : $this->expire($deadline);

            if ($expired > 0) {
                $output->writeln(
                    $expired . ' message(s) gave up after ' . $deadline . 's and were marked failed.'
                );
            }

            $pending = $this->pending($limit, $deadline);

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
        $email = $this->mailer();
        $email->setTo((string) $row['tomail']);
        $email->setSubject((string) $row['subject']);

        if (trim((string) $row['frommail']) !== '') {
            $email->setFrom((string) $row['frommail']);
        }

        /*
         * Assigned rather than composed.
         *
         * `body` is what the transport falls back to when nothing has been wrapped, and this
         * string was already wrapped when it was queued. Going through `send()` would wrap it a
         * second time — a whole HTML document inside another one — and would write a second
         * audit row for a message that already has one.
         */
        $email->body = (string) $row['content'];

        if ($email->sendRendered()) {
            $this->mark((int) $row['id'], Mail::STATUS_SENT, '');

            return 'sent';
        }

        $error = $email->getLastError();

        if ($this->isPermanent($error)) {
            $this->mark((int) $row['id'], Mail::STATUS_FAILED, $error);

            return 'failed';
        }

        return 'retry';
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
     * The pending rows worth attempting, oldest first.
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
        $db = Factory::getDatabase();

        // The count comes off the `Result`: `Database` has no `getAffectedRows()`, and asking
        // it for one returns nothing — which reads as "no rows expired" on a run that expired
        // thousands.
        $result = $db->query(
            'UPDATE ' . $db->prefix . 'mails SET status = ' . Mail::STATUS_FAILED
            . ", extrainfo = 'Undeliverable within the outbox deadline'"
            . ' WHERE status = ' . Mail::STATUS_QUEUED
            . ' AND date < ' . (time() - $deadline)
        );

        return $result ? (int) $result->getAffectedRows() : 0;
    }

    /**
     * How long a message stays worth retrying.
     */
    protected function deadline(mixed $option): int
    {
        $value = (int) ($option ?? 0);

        if ($value < 1) {
            $value = (int) (\Pramnos\Application\Application::currentInstance()
                ?->applicationInfo['mail']['outbox']['deadline'] ?? 0);
        }

        return $value > 0 ? $value : self::DEADLINE;
    }

    /**
     * A seam, so a test can send nothing.
     */
    protected function mailer(): Email
    {
        return new Email();
    }
}
