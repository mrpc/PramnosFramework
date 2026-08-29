<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Email\DnsAuthentication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the sending domain's DNS says about this installation's mail.
 *
 * The one part of deliverability an application cannot see. SPF, DKIM, DMARC and BIMI are
 * records on a domain: the message can be perfect and still be filed as spam, with nothing in
 * any log — the only symptom is mail quietly not arriving, reported months later as "I never
 * got the password reset".
 *
 * ```
 * ./yourapp mail:dns-check
 * ./yourapp mail:dns-check example.com --selector=mail
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MailDnsCheck extends Command
{
    protected function configure(): void
    {
        $this->setName('mail:dns-check')
            ->setDescription('Check SPF, DKIM, DMARC and BIMI on the sending domain')
            ->addArgument(
                'domain',
                InputArgument::OPTIONAL,
                'The From: domain. Taken from the administrator address if omitted.'
            )
            ->addOption(
                'selector',
                's',
                InputOption::VALUE_REQUIRED,
                'The DKIM selector. Read it from `DKIM-Signature: … s=…` in a message you sent.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = trim((string) $input->getArgument('domain'));

        if ($domain === '') {
            $domain = $this->sendingDomain();
        }

        if ($domain === '') {
            $output->writeln('<error>No domain given, and none could be worked out.</error>');
            $output->writeln('Pass one, or set an administrator address in the settings.');

            return Command::FAILURE;
        }

        $report = $this->inspector()->inspect($domain, (string) $input->getOption('selector'));

        $output->writeln('');
        $output->writeln('Sending domain: <info>' . $report['domain'] . '</info>');
        $output->writeln('');

        foreach ($report['checks'] as $name => $check) {
            $output->writeln($this->badge($check['ok'] ?? null) . ' <options=bold>'
                . strtoupper((string) $name) . '</> — ' . ($check['says'] ?? ''));

            if (!empty($check['record'])) {
                $records = (array) $check['record'];

                foreach ($records as $record) {
                    $output->writeln('     <comment>' . $record . '</comment>');
                }
            }

            if (!empty($check['fix'])) {
                $output->writeln('     ' . wordwrap((string) $check['fix'], 88, "\n     "));
            }

            $output->writeln('');
        }

        if ($report['ready']) {
            $output->writeln('<info>This domain meets the bulk-sender bar.</info> BIMI is a logo '
                . 'and is not part of it.');

            return Command::SUCCESS;
        }

        /*
         * A failure exit code, so this can be a deploy check.
         *
         * The information is worth having either way, but a command that always succeeds is one
         * nobody puts in a pipeline — and this is precisely the kind of thing that is correct on
         * the day it is set up and wrong two domain transfers later.
         */
        $output->writeln('<comment>Not yet.</comment> An SPF record and a DMARC record are what '
            . 'Gmail and Yahoo ask of a bulk sender; the lines above say what is missing.');

        return Command::FAILURE;
    }

    /** A seam: a test must not depend on what a real domain's DNS happens to say today. */
    protected function inspector(): DnsAuthentication
    {
        return new DnsAuthentication();
    }

    private function badge(?bool $ok): string
    {
        return match ($ok) {
            true  => '<info>  ok  </info>',
            false => '<error> gone </error>',
            null  => '<comment>  ??  </comment>',
        };
    }

    /**
     * The domain this installation sends as.
     *
     * The administrator address, then the site URL. Both are guesses — the real answer is the
     * `From:` header, which is composed per message — but they are the two places an
     * installation's own domain is written down.
     */
    protected function sendingDomain(): string
    {
        $address = (string) \Pramnos\Application\Settings::getSetting('admin_mail');

        if (str_contains($address, '@')) {
            return substr($address, strrpos($address, '@') + 1);
        }

        $url  = (string) \Pramnos\Application\Settings::getSetting('site_url');
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

        return preg_replace('~^www\.~i', '', $host) ?? '';
    }
}
