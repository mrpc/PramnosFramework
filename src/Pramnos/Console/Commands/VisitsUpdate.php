<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Visits\GeoIp;
use Pramnos\Visits\Visits;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `visits:update` — refresh what counting the site's traffic reads from outside: DB-IP's City
 * Lite (unless `visits.geoip.path` names a file of the application's own) and Matomo's referrer
 * spam list, both into `var/visits/`. Scheduled monthly by the framework; a no-op without
 * `visits.sites`.
 *
 * ```bash
 * php bin/pramnos visits:update
 * ```
 */
class VisitsUpdate extends Command
{
    /** Matomo's referrer spam list: public domain, one host a line. */
    public const SPAM_LIST = 'https://raw.githubusercontent.com/matomo-org/referrer-spam-list/master/spammers.txt';

    /** @var (callable(string): ?string)|null How a URL is read — a test's */
    private $fetch;

    /** @param (callable(string): ?string)|null $fetch How a URL is read; the network's when null */
    public function __construct(?callable $fetch = null)
    {
        $this->fetch = $fetch;
        parent::__construct();
    }

    /** Name and description. */
    protected function configure(): void
    {
        $this->setName('visits:update')
            ->setDescription('Refresh the GeoIP database and the referrer spam list the site\'s traffic counting reads');
    }

    /** Fetch both; say what was taken and what was not. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (Visits::sites() === []) {
            $output->writeln('No site is declared in visits.sites: nothing to update.');

            return Command::SUCCESS;
        }
        $fetch = $this->fetch ?? static function (string $url): ?string {
            // @codeCoverageIgnoreStart
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'PramnosFramework visits:update']]));

            return is_string($body) ? $body : null;
            // @codeCoverageIgnoreEnd
        };
        $failed = false;
        if (empty(Visits::config()['geoip']['path'])) {
            try {
                $output->writeln('GeoIP: DB-IP City Lite ' . GeoIp::update($fetch) . ' → ' . GeoIp::path());
            } catch (\RuntimeException $e) {
                $output->writeln('<error>GeoIP: ' . $e->getMessage() . '</error>');
                $failed = true;
            }
        } else {
            $output->writeln('GeoIP: the application\'s own file, ' . GeoIp::path() . ' — not fetched.');
        }
        $list  = $fetch(self::SPAM_LIST);
        $hosts = is_string($list) ? array_values(array_filter(array_map('trim', explode("\n", $list)), static fn (string $h): bool => preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $h) === 1)) : [];
        if ($hosts === []) {
            $output->writeln('<error>Referrer spam list: could not be fetched; the one kept stays.</error>');

            return Command::FAILURE;
        }
        $file = (defined('ROOT') ? ROOT : getcwd()) . '/var/visits/referrer-spam.txt';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file . '.part', implode("\n", $hosts) . "\n");
        rename($file . '.part', $file);
        $output->writeln('Referrer spam list: ' . count($hosts) . ' hosts → ' . $file);

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
