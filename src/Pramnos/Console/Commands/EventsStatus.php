<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Event\Event;
use Pramnos\Event\QueuedListener;
use Pramnos\Event\Transport\Transports;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What each queued listener's events go by: the transport it is set to use, and the one it would
 * use here — the same, or the queue where that one cannot carry events. A dry run: nothing is
 * fired, so a change to `events` in app.php can be checked before it is deployed.
 */
class EventsStatus extends Command
{
    protected function configure(): void
    {
        $this->setName('events:status')
            ->setDescription('Show which transport each queued listener uses')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON instead of a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = self::rows();
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(['transports' => self::transports(), 'listeners' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        $output->writeln('Transports: ' . implode(', ', array_map(static fn (string $n, bool $ok): string => $n . ($ok ? '' : ' (cannot carry events here)'),
            array_keys(self::transports()), self::transports())));
        if ($rows === []) {
            $output->writeln('No queued listeners are registered.');

            return Command::SUCCESS;
        }
        $table = new Table($output);
        $table->setHeaders(['Event', 'Listener', 'Set to', 'Goes by']);
        foreach ($rows as $row) {
            $table->addRow([$row['event'], $row['listener'], $row['configured'], $row['effective'] === $row['configured'] ? $row['effective'] : $row['effective'] . ' (instead)']);
        }
        $table->render();

        return Command::SUCCESS;
    }

    /**
     * Every queued listener of every event, with its transport as set and as used.
     *
     * @return list<array{event: string, listener: string, configured: string, effective: string}>
     */
    public static function rows(): array
    {
        $rows = [];
        foreach (Event::events() as $event) {
            foreach (Event::getListeners($event) as $listener) {
                if (!$listener instanceof QueuedListener && !(is_string($listener) && is_a($listener, QueuedListener::class, true))) {
                    continue;
                }
                $rows[] = ['event' => $event, 'listener' => is_string($listener) ? $listener : $listener::class,
                    'configured' => Transports::configured($listener), 'effective' => Transports::for($listener)->name()];
            }
        }

        return $rows;
    }

    /** @return array<string, bool> The transports known here, and whether each can carry events */
    private static function transports(): array
    {
        return array_map(static fn ($t): bool => $t->available(), Transports::all());
    }
}
