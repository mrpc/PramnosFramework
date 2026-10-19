<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Email\BounceMailbox;
use Pramnos\Email\Bounces;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mail:bounces` — record the delivery reports mail came back with, and keep the list by hand.
 *
 * ```bash
 * php bin/pramnos mail:bounces var/inbox                  # every .eml in the folder
 * php bin/pramnos mail:bounces var/inbox --delete         # … and remove the reports read
 * php bin/pramnos mail:bounces < report.eml               # one message on standard input
 * php bin/pramnos mail:bounces --imap                     # the bounce mailbox, over IMAP
 * php bin/pramnos mail:bounces --block=a@example.com --reason="asked by phone"
 * php bin/pramnos mail:bounces --clear=a@example.com
 * php bin/pramnos mail:bounces --status=a@example.com
 * ```
 *
 * Standard input makes it a mail server's delivery target: an alias such as
 * `bounces: "|php /srv/site/bin/pramnos mail:bounces"` records each report as it arrives. A
 * folder is for a mailbox fetched to disk. With `--delete`, a file that was a report is removed
 * once recorded; anything else is left for a person to read. With `--imap` the mailbox the
 * application names is read ({@see BounceMailbox}): its unseen messages, each report recorded and
 * marked seen, the rest left unread.
 */
class MailBounces extends Command
{
    protected function configure(): void
    {
        $this->setName('mail:bounces')
            ->setDescription('Record bounced mail from delivery reports, and block, clear or look up an address')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Report files, or folders of .eml files; standard input when none')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Remove each file that was a report, once recorded')
            ->addOption('block', null, InputOption::VALUE_REQUIRED, 'Stop all mail to this address')
            ->addOption('clear', null, InputOption::VALUE_REQUIRED, 'Forget this address\'s bounces and block')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Show what is recorded for this address')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why, for --block', '')
            ->addOption('imap', null, InputOption::VALUE_NONE, 'Read the bounce mailbox the application names, over IMAP')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'With --imap: at most this many messages (0: all)', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (['block', 'clear', 'status'] as $action) {
            $address = (string) $input->getOption($action);
            if ($address !== '') {
                return $this->byHand($action, $address, (string) $input->getOption('reason'), $output);
            }
        }

        if ($input->getOption('imap')) {
            return $this->imap(max(0, (int) $input->getOption('limit')), $output);
        }

        $paths = (array) $input->getArgument('paths');
        if ($paths === []) {
            $recorded = $this->report((string) stream_get_contents($this->stdin()), $output);

            return $recorded === null ? Command::FAILURE : Command::SUCCESS;
        }

        $files = [];
        foreach ($paths as $path) {
            $found = is_dir($path) ? (glob(rtrim($path, '/') . '/*.eml') ?: []) : [$path];
            array_push($files, ...$found);
        }
        $reports = 0;
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                $output->writeln('<error>Cannot read ' . $file . '</error>');
                continue;
            }
            if ($this->report($raw, $output) !== null) {
                $reports++;
                if ($input->getOption('delete')) {
                    @unlink($file);
                }
            }
        }
        $output->writeln(sprintf('<info>%d report(s)</info> in %d file(s).', $reports, count($files)));

        return Command::SUCCESS;
    }

    /** Read the bounce mailbox: each report recorded and marked seen, the rest left unread. */
    private function imap(int $limit, OutputInterface $output): int
    {
        $mailbox = $this->mailbox();
        if ($mailbox === null) {
            $output->writeln('<error>No bounce mailbox is configured.</error> Set email.bounces.mailbox in app.php, or the imap_host setting.');

            return Command::FAILURE;
        }
        try {
            $mailbox->open();
            $uids    = $mailbox->unseen($limit);
            $reports = 0;
            foreach ($uids as $uid) {
                if ($this->report($mailbox->fetch($uid), $output) !== null) {
                    $mailbox->markSeen($uid);
                    $reports++;
                }
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } finally {
            $mailbox->close();
        }
        $output->writeln(sprintf('<info>%d report(s)</info> in %d unseen message(s) of %s.', $reports, count($uids), $mailbox->describe()));

        return Command::SUCCESS;
    }

    /** The bounce mailbox — a seam, so a test can give one. */
    protected function mailbox(): ?BounceMailbox
    {
        return BounceMailbox::configured();
    }

    /** Record one message's failures; null when it was not a delivery report. */
    private function report(string $raw, OutputInterface $output): ?int
    {
        $failures = Bounces::parseReport($raw);
        if ($failures === []) {
            return null;
        }
        foreach ($failures as $failure) {
            if (Bounces::record($failure['email'], $failure['soft'], $failure['reason'])) {
                $output->writeln(sprintf('  %s  %s  %s', $failure['soft'] ? 'soft' : 'hard', $failure['email'], $failure['reason']));
            }
        }

        return count($failures);
    }

    private function byHand(string $action, string $address, string $reason, OutputInterface $output): int
    {
        if (Bounces::hash($address) === null) {
            $output->writeln('<error>' . $address . ' is not an email address.</error>');

            return Command::FAILURE;
        }
        if ($action === 'status') {
            $row     = Bounces::status($address);
            $refusal = Bounces::refusal($address);
            $output->writeln($row === null ? 'Nothing recorded for ' . $address . '.' : sprintf(
                '%s: %d hard, %d soft bounce(s)%s.',
                $address,
                (int) $row['hardbounces'],
                (int) $row['softbounces'],
                (int) ($row['blocked'] ?? 0) > 0 ? ', blocked' : ''
            ));
            $output->writeln($refusal === null ? 'Mail to it is sent.' : 'Mail to it is not sent: ' . $refusal . '.');

            return Command::SUCCESS;
        }
        $done = $action === 'block' ? Bounces::block($address, $reason) : Bounces::clear($address);
        if (!$done) {
            $output->writeln('<error>There is no bounces table.</error> It is made by the messaging migrations: run migrate.');

            return Command::FAILURE;
        }
        $output->writeln($action === 'block' ? 'No mail goes to ' . $address . ' now.' : $address . ' is cleared; mail to it is sent again.');

        return Command::SUCCESS;
    }

    /** Standard input — a seam, so a test can give one. @return resource */
    protected function stdin()
    {
        return STDIN;
    }
}
