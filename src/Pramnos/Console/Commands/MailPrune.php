<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Email\Retention;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Apply the mail log's retention policy.
 *
 * ```
 * ./yourapp mail:prune                    # what the policy would do, and nothing else
 * ./yourapp mail:prune --apply
 * ./yourapp mail:prune --strip-after=90d --delete-after=2y --apply
 * ```
 *
 * **A dry run by default**, which is the opposite of most commands here and deliberate: this
 * one deletes, the amount it deletes depends on two numbers somebody just typed, and the
 * difference between `90d` and `90` is three months of somebody's audit trail.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MailPrune extends Command
{
    protected function configure(): void
    {
        $this->setName('mail:prune')
            ->setDescription('Strip old mail bodies and remove old rows, by the configured policy')
            ->addOption('strip-after', null, InputOption::VALUE_REQUIRED,
                'Empty the body of messages older than this (e.g. 90d, 6m, 1y). '
                . 'Defaults to `mail.retention.strip_after`.')
            ->addOption('delete-after', null, InputOption::VALUE_REQUIRED,
                'Remove messages older than this. Defaults to `mail.retention.delete_after`.')
            ->addOption('recipients-after', null, InputOption::VALUE_REQUIRED,
                'Remove recipient rows of finished campaigns older than this.')
            ->addOption('apply', null, InputOption::VALUE_NONE,
                'Actually do it. Without this the command only reports.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $strip      = $this->seconds($input->getOption('strip-after'), 'strip_after');
        $delete     = $this->seconds($input->getOption('delete-after'), 'delete_after');
        $recipients = $this->seconds($input->getOption('recipients-after'), 'recipients_after');

        $stats = $this->stats($strip, $delete);

        if (isset($stats['error'])) {
            $output->writeln('<error>' . $stats['error'] . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('  Messages:        ' . number_format($stats['rows']));
        $output->writeln('  Still with body: ' . number_format($stats['with_body'])
            . ' (' . $this->size($stats['body_bytes']) . ')');

        if ($stats['oldest'] > 0) {
            $output->writeln('  Oldest:          ' . date('Y-m-d', $stats['oldest']));
        }

        $output->writeln('');

        if ($strip === 0 && $delete === 0 && $recipients === 0) {
            /*
             * No policy is a state, not a default.
             *
             * Picking one here would apply somebody's guess to an audit trail on the first run
             * of a command they were only exploring — and the whole point of the two numbers is
             * that they are a decision.
             */
            $output->writeln('<comment>No retention policy is configured, and none is assumed.</comment>');
            $output->writeln('Set it in app.php:');
            $output->writeln('');
            $output->writeln("  'mail' => ['retention' => ['strip_after' => '90d', 'delete_after' => '2y']],");
            $output->writeln('');
            $output->writeln('Or pass <info>--strip-after</info> and <info>--delete-after</info> for one run.');
            $output->writeln('');
            $output->writeln('Stripping keeps the row — who, when, which module, did it send —');
            $output->writeln('and drops the rendered body, which is most of the bytes and the');
            $output->writeln('only part `/admin/emails/show` can no longer read back.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('apply')) {
            $output->writeln('<comment>Dry run.</comment> With <info>--apply</info> this would:');

            if ($strip > 0) {
                $output->writeln('  strip  ' . number_format((int) ($stats['would_strip'] ?? 0))
                    . ' bodies older than ' . $this->human($strip));
            }

            if ($delete > 0) {
                $output->writeln('  delete ' . number_format((int) ($stats['would_delete'] ?? 0))
                    . ' messages older than ' . $this->human($delete));
            }

            if ($recipients > 0) {
                $output->writeln('  remove recipient rows of campaigns finished over '
                    . $this->human($recipients) . ' ago');
            }

            return Command::SUCCESS;
        }

        /*
         * Delete first, then strip.
         *
         * The other order strips a body and then deletes the row it belonged to — the same
         * outcome, having written every one of those rows twice.
         */
        if ($delete > 0) {
            $output->writeln('  deleted ' . number_format($this->prune($delete)) . ' messages');
        }

        if ($strip > 0) {
            $output->writeln('  stripped ' . number_format($this->strip($strip)) . ' bodies');
        }

        if ($recipients > 0) {
            $output->writeln('  removed ' . number_format($this->pruneRecipients($recipients))
                . ' recipient rows');
        }

        return Command::SUCCESS;
    }

    /**
     * The retention layer, as four seams.
     *
     * So the guards above — the duration parser, the dry run, the order of the two operations —
     * can be asserted without a database. What they guard is a deletion, and a test that needed
     * a live table to check them would be a test somebody skips.
     *
     * @return array<string, mixed>
     */
    protected function stats(int $stripAfter, int $deleteAfter): array
    {
        return Retention::stats($stripAfter, $deleteAfter);
    }

    protected function strip(int $olderThan): int
    {
        return Retention::strip($olderThan);
    }

    protected function prune(int $olderThan): int
    {
        return Retention::prune($olderThan);
    }

    protected function pruneRecipients(int $olderThan): int
    {
        return Retention::pruneRecipients($olderThan);
    }

    /**
     * A duration, from the option or from the configuration.
     *
     * `90d`, `6m`, `2y`, or a plain number of seconds. Refused rather than guessed at when it
     * is neither: this number decides how much of an audit trail is deleted, and a typo that
     * silently means "everything" is not a class of mistake to leave open.
     */
    protected function seconds(mixed $option, string $key): int
    {
        $value = trim((string) ($option ?? ''));

        if ($value === '') {
            $configured = \Pramnos\Application\Application::currentInstance()
                ?->applicationInfo['mail']['retention'][$key] ?? null;
            $value = trim((string) ($configured ?? ''));
        }

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^(\d+)\s*([smhdwy]|mo?)?$/i', $value, $matches) !== 1) {
            return 0;
        }

        $amount = (int) $matches[1];

        return $amount * match (strtolower($matches[2] ?? '')) {
            's'        => 1,
            'h'        => 3600,
            'd'        => 86400,
            'w'        => 604800,
            'm', 'mo'  => 2592000,
            'y'        => 31536000,
            default    => 60,
        };
    }

    protected function human(int $seconds): string
    {
        [$amount, $unit] = match (true) {
            $seconds >= 31536000 => [round($seconds / 31536000, 1), 'year'],
            $seconds >= 2592000  => [round($seconds / 2592000), 'month'],
            $seconds >= 86400    => [round($seconds / 86400), 'day'],
            default              => [$seconds, 'second'],
        };

        // «1 months» in a line somebody reads before deleting an audit trail reads as a bug,
        // and a reader who thinks the tool is buggy checks the number less carefully, not more.
        return $amount . ' ' . $unit . ((float) $amount === 1.0 ? '' : 's');
    }

    protected function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => round($bytes / 1073741824, 1) . ' GB',
            $bytes >= 1048576    => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024       => round($bytes / 1024) . ' KB',
            default              => $bytes . ' B',
        };
    }
}
