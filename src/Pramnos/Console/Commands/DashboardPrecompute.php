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
 * php bin/pramnos dashboard:precompute --app=backoffice   # the cards of another application
 * ```
 *
 * The cards, and how long their data is kept, are the application's: `--app`, or the area's own
 * application where there is one (`app/admin.php` for `--area=admin`), or the one the console
 * started. Each card is handed a controller of that application, so a `data()` that builds a
 * model with `$this->controller?->getModel()` has one; there is no person and no request.
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
            ->addOption('area', null, InputOption::VALUE_REQUIRED, 'The dashboard whose saved layouts are read', 'admin')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'The application whose cards are worked out (default: the area\'s, where app/<area>.php exists)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $area     = (string) $input->getOption('area');
        $name     = $input->getOption('app') ?? (defined('APP_PATH') && is_file(APP_PATH . DIRECTORY_SEPARATOR . $area . '.php') ? $area : null);
        if (!is_string($name) || $name === '') {
            return $this->precompute($area, $output);
        }
        $previous = \Pramnos\Application\Application::getInstance($name)->makeCurrentInstance();
        try {
            return $this->precompute($area, $output);
        } finally {
            \Pramnos\Application\Application::restoreCurrentInstance($previous);
        }
    }

    /** Work out the current application's shared cards for an area. */
    private function precompute(string $area, OutputInterface $output): int
    {
        $shared     = array_filter(WidgetRegistry::all(), static fn (Widget $widget): bool => $widget->shared());
        $controller = $this->controller();
        $wanted     = [];
        $want       = static function (Widget $widget, array $settings) use (&$wanted, $controller): void {
            $base = (clone $widget)->configure($settings);
            foreach ($base->precomputeSettings(Widget::cleanSettings($widget, $settings)) as $variant) {
                $card = (clone $widget)->configure((array) $variant)->setController($controller);
                $wanted[$card->dataKey()] = $card;
            }
        };
        foreach ($shared as $widget) {
            $want($widget, []);
        }
        try {
            $layouts = $this->savedLayouts($area);
        } catch (\Throwable $e) {
            $output->writeln('<comment>The saved layouts could not be read, the defaults only:</comment> ' . $e->getMessage());
            $layouts = [];
        }
        foreach ($layouts as $layout) {
            foreach ($layout as $item) {
                $id = (string) ($item['id'] ?? '');
                if (isset($shared[$id])) {
                    $want($shared[$id], (array) ($item['settings'] ?? []));
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
     * A controller of the current application for the cards, as the dashboard hands them its own;
     * null without an application.
     */
    protected function controller(): ?\Pramnos\Application\Controller
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return $app !== null ? new \Pramnos\Application\Controller($app) : null;
    }

    /**
     * The layouts saved for the area. An application may add layouts nobody saved — the settings
     * people use without saving them — though a card's own variants are
     * {@see Widget::precomputeSettings()}'s.
     *
     * @return list<list<array<string, mixed>>>
     */
    protected function savedLayouts(string $area): array
    {
        return (new Layouts())->saved($area);
    }
}
