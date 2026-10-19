<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Dashboard\Layouts;
use Pramnos\Dashboard\Widget;
use Pramnos\Dashboard\WidgetData;
use Pramnos\Dashboard\WidgetRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `dashboard:precompute` — work out the data of the shared dashboard cards ({@see Widget::shared()})
 * ahead of anybody opening the dashboard, and keep it ({@see WidgetData}): each card with its
 * default settings, and with every setting somebody saved in the area's layouts.
 *
 * ```bash
 * php bin/pramnos dashboard:precompute                 # the administration's dashboard
 * php bin/pramnos dashboard:precompute --area=partner
 * ```
 *
 * From cron every five minutes, with `'dashboard' => ['data_ttl' => 600]` in app.php so an entry
 * outlives the interval and nobody waits for a card.
 *
 * Exits `1` when a card could not be worked out; the others are kept all the same.
 */
class DashboardPrecompute extends Command
{
    protected function configure(): void
    {
        $this->setName('dashboard:precompute')
            ->setDescription('Work out the shared dashboard cards\' data ahead of anybody opening the dashboard')
            ->addOption('area', null, InputOption::VALUE_REQUIRED, 'The dashboard whose saved layouts are read', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shared = array_filter(WidgetRegistry::all(), static fn (Widget $widget): bool => $widget->shared());
        $wanted = [];
        foreach ($shared as $widget) {
            $card = (clone $widget)->configure([]);
            $wanted[$card->dataKey()] = $card;
        }
        try {
            $layouts = $this->savedLayouts((string) $input->getOption('area'));
        } catch (\Throwable $e) {
            $output->writeln('<comment>The saved layouts could not be read, the defaults only:</comment> ' . $e->getMessage());
            $layouts = [];
        }
        foreach ($layouts as $layout) {
            foreach ($layout as $item) {
                $id = (string) ($item['id'] ?? '');
                if (isset($shared[$id])) {
                    $card = (clone $shared[$id])->configure((array) ($item['settings'] ?? []));
                    $wanted[$card->dataKey()] = $card;
                }
            }
        }

        $failed = 0;
        foreach ($wanted as $key => $card) {
            $started = microtime(true);
            try {
                WidgetData::refresh($card);
                $output->writeln(sprintf('%s  %d ms', $key, (int) round((microtime(true) - $started) * 1000)));
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln('<error>' . $key . ':</error> ' . $e->getMessage());
            }
        }
        $output->writeln(sprintf('%d card(s) worked out%s.', count($wanted) - $failed, $failed > 0 ? ', ' . $failed . ' failed' : ''));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The layouts saved for the area (seam so a test can hand others).
     *
     * @return list<list<array<string, mixed>>>
     */
    protected function savedLayouts(string $area): array
    {
        return (new Layouts())->saved($area);
    }
}
