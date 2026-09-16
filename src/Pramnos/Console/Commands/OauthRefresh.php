<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Auth\OAuth2\Client\Connection;
use Pramnos\Auth\OAuth2\Client\ConnectionStore;
use Pramnos\Auth\OAuth2\Client\OAuthClient;
use Pramnos\Auth\OAuth2\Client\OAuthClientException;
use Pramnos\Auth\OAuth2\Client\Provider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * oauth:refresh — renew third-party tokens before they expire.
 *
 * **Why a scheduled command rather than a check where the token is used.** Refreshing on
 * use keeps a *busy* connection alive and does nothing at all for an idle one — and an
 * idle connection is exactly the one that dies, because several providers expire the
 * refresh token itself if it is never exercised. Instagram's long-lived token lasts sixty
 * days. A connection nothing called for sixty-one days is gone, and the first anybody
 * hears of it is a feed that has been empty for a while, which looks like a quiet month.
 *
 * Schedule it from a service provider:
 *
 * ```php
 * \Pramnos\Scheduling\Scheduler::command('oauth:refresh')->everyFifteenMinutes();
 * ```
 *
 * The application supplies the providers, because only it knows the client credentials and
 * which platforms it talks to. Subclass and override {@see providers()}:
 *
 * ```php
 * class OauthRefresh extends \Pramnos\Console\Commands\OauthRefresh
 * {
 *     protected function providers(): array
 *     {
 *         return ['google' => new Provider(name: 'google', ...)];
 *     }
 * }
 * ```
 *
 * A connection whose provider is not in that list is **skipped and counted**, never marked
 * dead: a provider missing from the configuration is a deployment that has not finished,
 * and killing live connections over it would turn a missing environment variable into
 * every user having to authorise again.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class OauthRefresh extends Command
{
    protected function configure(): void
    {
        $this->setName('oauth:refresh')
            ->setDescription('Refresh third-party OAuth2 tokens that are close to expiring')
            ->addOption(
                'within',
                null,
                InputOption::VALUE_REQUIRED,
                'Refresh connections expiring within this many seconds',
                '3600'
            )
            ->addOption(
                'provider',
                null,
                InputOption::VALUE_REQUIRED,
                'Only this provider',
                ''
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List what would be refreshed and contact nobody'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $providers = $this->providers();
        $only      = (string) $input->getOption('provider');
        $dryRun    = (bool) $input->getOption('dry-run');

        $store = $this->store();
        $due   = $store->dueForRefresh(max(0, (int) $input->getOption('within')));

        if ($only !== '') {
            $due = array_values(array_filter(
                $due,
                static fn (Connection $c): bool => $c->provider === $only
            ));
        }

        if ($due === []) {
            $output->writeln('<info>Nothing due.</info>');

            return Command::SUCCESS;
        }

        $refreshed = 0;
        $died      = 0;
        $failed    = 0;
        $skipped   = 0;

        foreach ($due as $connection) {
            $label = $connection->provider . ' / user ' . $connection->userId
                . ($connection->accountName !== '' ? ' (' . $connection->accountName . ')' : '');

            if (!isset($providers[$connection->provider])) {
                $output->writeln("  <comment>skipped</comment>   {$label} — not configured");
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $output->writeln("  <comment>would refresh</comment> {$label}");
                continue;
            }

            try {
                $store->refresh($connection, $this->client($providers[$connection->provider]));
                $output->writeln("  <info>refreshed</info> {$label}");
                $refreshed++;
            } catch (OAuthClientException $exception) {
                /*
                 * Terminal and transient are reported differently because they need
                 * different people. A dead connection is somebody's to go and re-authorise;
                 * a timeout is this run's problem and the next run's business. Lumping them
                 * together as "errors" is how a genuinely revoked grant sits unnoticed
                 * among network noise.
                 */
                if ($exception->isTerminal()) {
                    $output->writeln("  <error>dead</error>      {$label} — " . $exception->getMessage());
                    $died++;
                    continue;
                }

                $output->writeln("  <comment>failed</comment>    {$label} — " . $exception->getMessage());
                $failed++;
            }
        }

        if ($dryRun) {
            $output->writeln('<info>' . count($due) . ' connection(s) due.</info>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>%d refreshed, %d dead, %d failed, %d not configured.</info>',
            $refreshed,
            $died,
            $failed,
            $skipped
        ));

        /*
         * A dead connection is not a failed *run*. It is a fact that has been recorded,
         * and exiting non-zero for it would put a supervisor into a restart loop over
         * something no retry can fix. A transient failure is worth a non-zero exit: it is
         * the one a monitor should see, because it may still be happening.
         */
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The providers this application talks to, keyed by name.
     *
     * Empty here on purpose. The framework cannot know an application's client
     * credentials, and a default of "none" skips every connection and says so per line,
     * which is a readable way to discover that the subclass was never written.
     *
     * @return array<string, Provider>
     */
    protected function providers(): array
    {
        return [];
    }

    /** Overridable so a test can drive the command without a network. */
    protected function client(Provider $provider): OAuthClient
    {
        return new OAuthClient($provider);
    }

    /** Overridable so a test can drive the command without a database. */
    protected function store(): ConnectionStore
    {
        return new ConnectionStore();
    }
}
