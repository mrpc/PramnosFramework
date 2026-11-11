<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Metrics\ReportBoard;
use Pramnos\Metrics\Reports;
use Pramnos\Metrics\Window;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `metrics:reports-send` — mail the saved reports that have a schedule, once for each period that
 * closed since they were last mailed: the report of that period, as its owner sees it, in the
 * mail's body, to each address it lists, through the outbox.
 *
 * The framework's schedule runs it every morning; a report nobody scheduled is never mailed.
 * Exits `1` when one could not be sent; it is tried again next time, the others are marked sent.
 */
class MetricsReportsSend extends Command
{
    protected function configure(): void
    {
        $this->setName('metrics:reports-send')
            ->setDescription('Mail the scheduled metric reports for the periods that closed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reports = new Reports();
        $failed  = 0;
        $sent    = 0;
        foreach ($reports->due() as $entry) {
            $row = $entry['row'];
            try {
                $owner = new \Pramnos\User\User((int) $row['ownerid']);
                // Not mailed where the application keeps the report from its owner now.
                if (Reports::access($owner, $entry) === false) {
                    continue;
                }
                $title = $entry['name'] . ' — ' . Window::of($entry['report']->definition['grain'], $entry['period'])->label();
                // Its cards drawn for a mail: charts as images carried inline, or their numbers.
                try {
                    $body   = '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>' . ReportBoard::html($entry, $entry['period'], $owner);
                    $images = ReportBoard::images();
                } finally {
                    ReportBoard::leave();
                }
                foreach (Reports::addresses((string) $row['recipients']) as $address) {
                    $mail = $this->mail();
                    $mail->setTo($address);
                    $mail->setSubject($title);
                    $mail->setBody($body);
                    foreach ($images as $name => $png) {
                        $mail->addInlineImageData($png, $name);
                    }
                    if (!$mail->queue()) {
                        throw new \RuntimeException('the outbox refused the mail to ' . $address);
                    }
                }
                $reports->markSent((int) $row['reportid'], $entry['period']);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln('<error>Report ' . $row['reportid'] . ': ' . $e->getMessage() . '</error>');
                \Pramnos\Logs\Logger::log('Report ' . $row['reportid'] . ' not mailed: ' . $e->getMessage(), 'metrics');
            }
        }
        $output->writeln(sprintf('Mailed %d reports.', $sent), $sent > 0 ? OutputInterface::VERBOSITY_NORMAL : OutputInterface::VERBOSITY_VERBOSE);

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** A mail to fill in: overridable, for a test or another transport. */
    protected function mail(): \Pramnos\Email\Email
    {
        return new \Pramnos\Email\Email();
    }
}
