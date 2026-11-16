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
 * `metrics:reports-send` — send the saved reports that have a schedule, once for each period that
 * closed since they were last sent: the report of that period, as its owner sees it, to each of
 * its destinations through the outbox, a webhook, a conversation channel, or the application's
 * own storage ({@see Reports::destinations()}) — one sender, four destinations.
 *
 * The framework's schedule runs it every morning; a report nobody scheduled is never sent.
 * Exits `1` when one destination could not be reached; it is tried again next time (the whole
 * report, every destination), the others are marked sent.
 */
class MetricsReportsSend extends Command
{
    protected function configure(): void
    {
        $this->setName('metrics:reports-send')
            ->setDescription('Send the scheduled metric reports for the periods that closed');
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
                // Not sent where the application keeps the report from its owner now.
                if (Reports::access($owner, $entry) === false) {
                    continue;
                }
                $title = $entry['name'] . ' — ' . Window::of($entry['report']->definition['grain'], $entry['period'])->label();
                // Its cards drawn once: charts as images carried inline in a mail, or their numbers.
                try {
                    $body   = '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>' . ReportBoard::html($entry, $entry['period'], $owner);
                    $images = ReportBoard::images();
                } finally {
                    ReportBoard::leave();
                }
                $csv = ReportBoard::csv($entry, $entry['period'], $owner);
                foreach (Reports::destinations((string) $row['recipients']) as $destination) {
                    $this->send($destination, $title, $body, $images, $csv, (int) $row['reportid'], $entry['period']);
                }
                $reports->markSent((int) $row['reportid'], $entry['period']);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln('<error>Report ' . $row['reportid'] . ': ' . $e->getMessage() . '</error>');
                \Pramnos\Logs\Logger::log('Report ' . $row['reportid'] . ' not sent: ' . $e->getMessage(), 'metrics');
            }
        }
        $output->writeln(sprintf('Sent %d reports.', $sent), $sent > 0 ? OutputInterface::VERBOSITY_NORMAL : OutputInterface::VERBOSITY_VERBOSE);

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * One destination of one report: mailed with its cards and a CSV attached, posted as JSON to
     * a webhook, told in a conversation channel, or saved as a CSV in the application's storage.
     *
     * @param array{kind: string, target: string} $destination
     * @param array<string, string>                $images
     * @param string                                $csv    Rendered once for every destination
     * @param string                                $period The window key the CSV/webhook/storage name by
     */
    private function send(array $destination, string $title, string $body, array $images, string $csv, int $reportId, string $period): void
    {
        switch ($destination['kind']) {
            case 'mail':
                $mail = $this->mail();
                $mail->setTo($destination['target']);
                $mail->setSubject($title);
                $mail->setBody($body);
                foreach ($images as $name => $png) {
                    $mail->addInlineImageData($png, $name);
                }
                $mail->addAttachmentData($csv, preg_replace('/[^A-Za-z0-9_-]+/', '-', $title) . '.csv', 'text/csv');
                if (!$mail->queue()) {
                    throw new \RuntimeException('the outbox refused the mail to ' . $destination['target']);
                }

                break;
            case 'webhook':
                $response = \Pramnos\Http\Client::post($destination['target'])->timeout(10)->json(['report' => $title, 'csv' => $csv])->send();
                if (!$response->successful()) {
                    throw new \RuntimeException('the webhook answered ' . $response->status());
                }

                break;
            case 'conversation':
                $id = ctype_digit($destination['target']) ? (int) $destination['target'] : $destination['target'];
                if (\Pramnos\Conversations\SystemPost::post($id, $title) === null) {
                    throw new \RuntimeException('nobody to post to: ' . $destination['target']);
                }

                break;
            case 'storage':
                \Pramnos\Storage\Storage::put('metrics-reports/' . $reportId . '/' . $period . '.csv', $csv);

                break;
        }
    }

    /** A mail to fill in: overridable, for a test or another transport. */
    protected function mail(): \Pramnos\Email\Email
    {
        return new \Pramnos\Email\Email();
    }
}
