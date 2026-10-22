<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Conversations\Beacon\Config;
use Pramnos\Conversations\Beacon\EmergencyKey;
use Pramnos\Conversations\Beacon\Primary;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The beacon, from the primary: `sync` the team to it and `import` what was written there — each
 * every minute, scheduled by the framework, and nothing where this installation is not a beacon's
 * primary; `test` a person's drill; `keys` a new signing pair.
 *
 *   ./pramnos conversations:beacon sync
 *   ./pramnos conversations:beacon import
 *   ./pramnos conversations:beacon test 48211
 *   ./pramnos conversations:beacon keys
 */
class ConversationsBeacon extends Command
{
    protected function configure(): void
    {
        $this->setName('conversations:beacon')
            ->setDescription('The conversations\' beacon: sync the team, import what was written there, drill, make keys')
            ->addArgument('what', InputArgument::REQUIRED, 'sync, import, test or keys')
            ->addArgument('person', InputArgument::OPTIONAL, 'For test: the account sent to the beacon');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $what = (string) $input->getArgument('what');
        if ($what === 'keys') {
            $pair = EmergencyKey::generate();
            $output->writeln("signing_key (the primary only): {$pair['signing_key']}");
            $output->writeln("public_key (public_keys on the beacon): {$pair['public_key']}");

            return Command::SUCCESS;
        }
        if (Config::value('role') !== 'primary' || (string) Config::value('beacon_url', '') === '') {
            $output->writeln('No beacon here: app/beacon.php names none for this primary.');

            return Command::SUCCESS;
        }
        switch ($what) {
            case 'sync':
                $done = Primary::sync();
                $output->writeln($done['ok'] ? 'The beacon knows ' . $done['people'] . ' people.' : '<error>Not synced: ' . $done['why'] . '.</error>');

                // A beacon that is away is the watcher's to see (`last_sync_at`) and is in the log;
                // a scheduled command failing every minute would only bury it.
                return Command::SUCCESS;
            case 'import':
                $done = Primary::import();
                $output->writeln($done['ok'] ? $done['imported'] . ' messages taken from the beacon.' : '<error>Not imported: ' . $done['why'] . '.</error>');

                return Command::SUCCESS;
            case 'test':
                $person = (int) $input->getArgument('person');
                if ($person <= 0) {
                    $output->writeln('<error>Whose browser? conversations:beacon test <account id></error>');

                    return Command::INVALID;
                }
                $done = Primary::drill($person);
                $output->writeln('The watcher\'s drill: ' . ($done['watcher'] === null ? 'no drill_url' : 'answered ' . $done['watcher'])
                    . '; the browser ' . ($done['told'] ? 'was told to go to the beacon for 5 minutes.' : 'was not told: no broadcasting here.'));

                return Command::SUCCESS;
        }
        $output->writeln('<error>sync, import, test or keys.</error>');

        return Command::INVALID;
    }
}
