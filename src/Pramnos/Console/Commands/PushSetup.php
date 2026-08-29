<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Notification\Channels\PushChannel;
use Pramnos\Push\ServiceWorker;
use Pramnos\Push\Vapid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Everything web push needs, in one command.
 *
 * Push has five parts and four of them are invisible when they are missing: a table, a key pair,
 * an encryption library, a service worker that listens, and a page that asks. Miss any one and
 * the other four keep working perfectly — no error, no log line, and no notification.
 *
 * The guide had five numbered steps, which is five chances to stop after four. This does them,
 * says which were already done, and is safe to run again.
 *
 * ```
 * ./yourapp push:setup            # what it would do
 * ./yourapp push:setup --apply
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class PushSetup extends Command
{
    protected function configure(): void
    {
        $this->setName('push:setup')
            ->setDescription('Set up web push: migration, keys, service worker, browser script, library')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually do it.')
            ->addOption('no-install', null, InputOption::VALUE_NONE,
                'Skip `composer require` — for an environment with no network.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $steps = $this->inspect();

        $output->writeln('');

        foreach ($steps as $step) {
            $output->writeln(
                ($step['done'] ? '<info>  ok  </info>' : '<comment> todo </comment>')
                . ' ' . $step['name'] . ($step['done'] ? '' : ' — ' . $step['what'])
            );
        }

        $output->writeln('');

        $todo = array_values(array_filter($steps, static fn (array $s): bool => !$s['done']));

        if ($todo === []) {
            $output->writeln('<info>Push is set up.</info> A signed-in visitor is offered it on '
                . 'every page, and the privacy screen has a switch.');

            return Command::SUCCESS;
        }

        if (!$apply) {
            $output->writeln('<comment>Nothing done.</comment> Run again with <info>--apply</info>.');

            return Command::SUCCESS;
        }

        foreach ($todo as $step) {
            $output->writeln('');
            $output->writeln('<options=bold>' . $step['name'] . '</>');

            $result = ($step['do'])($input, $output);

            if ($result !== true) {
                $output->writeln('<error>Stopped: ' . (is_string($result) ? $result : 'failed') . '</error>');

                return Command::FAILURE;
            }
        }

        $output->writeln('');
        $output->writeln('<info>Done.</info> Nothing is subscribed yet — that happens when '
            . 'somebody accepts the prompt.');

        return Command::SUCCESS;
    }

    /**
     * The five parts, and whether each is there.
     *
     * @return list<array{name: string, what: string, done: bool, do: callable}>
     */
    protected function inspect(): array
    {
        $missing = ServiceWorker::missing();

        return [
            [
                'name' => 'Migration',
                'what' => 'the `pushsubscriptions` table does not exist',
                'done' => $this->hasTable(),
                'do'   => fn ($in, $out): mixed => $this->runMigrations($out),
            ],
            [
                'name' => 'VAPID key pair',
                'what' => 'this installation has no identity to sign with',
                'done' => Vapid::configured(),
                'do'   => fn ($in, $out): mixed => $this->generateKeys($out),
            ],
            [
                'name' => 'Encryption library',
                'what' => 'minishlink/web-push is not installed, so nothing can be encrypted',
                'done' => class_exists(ltrim(PushChannel::LIBRARY, '\\')),
                'do'   => fn ($in, $out): mixed => $this->installLibrary($in, $out),
            ],
            [
                'name' => 'Service worker',
                'what' => $missing === []
                    ? ''
                    : 'no ' . implode(', ', array_keys($missing)) . ' handler, so a notification '
                        . 'is discarded silently',
                'done' => $missing === [],
                'do'   => fn ($in, $out): mixed => $this->fixServiceWorker($out),
            ],
            [
                'name' => 'Browser script',
                'what' => 'nothing asks for permission, so nothing ever subscribes',
                'done' => $this->hasBrowserScript(),
                'do'   => fn ($in, $out): mixed => $this->writeBrowserScript($out),
            ],
        ];
    }

    // ── The steps ────────────────────────────────────────────────────────────

    protected function hasTable(): bool
    {
        try {
            return \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('pushsubscriptions');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function runMigrations(OutputInterface $output): mixed
    {
        $migrate = $this->getApplication()?->find('migrate');

        if ($migrate === null) {
            return 'the migrate command is not registered';
        }

        $migrate->run(
            new \Symfony\Component\Console\Input\ArrayInput(['--scope' => 'framework']),
            $output
        );

        return $this->hasTable() ? true : 'the table still is not there';
    }

    protected function generateKeys(OutputInterface $output): mixed
    {
        $generate = $this->getApplication()?->find('push:vapid-generate');

        if ($generate === null) {
            return 'the push:vapid-generate command is not registered';
        }

        $generate->run(new \Symfony\Component\Console\Input\ArrayInput([]), $output);

        return Vapid::configured() ? true : 'no pair was written';
    }

    protected function installLibrary(InputInterface $input, OutputInterface $output): mixed
    {
        if ($input->getOption('no-install')) {
            $output->writeln('  skipped — run <info>composer require minishlink/web-push</info> yourself');

            return true;
        }

        $output->writeln('  composer require minishlink/web-push');

        /*
         * Reported, never assumed.
         *
         * A composer install needs the network and can fail for a dozen reasons that have
         * nothing to do with this application. Saying it worked when it did not would leave an
         * installation that reports itself ready and silently encrypts nothing.
         */
        $code = $this->shell(
            'cd ' . escapeshellarg($this->root()) . ' && composer require minishlink/web-push 2>&1'
        );

        return $code === 0 ? true : 'composer exited with ' . $code;
    }

    /** The one call that reaches the network, alone so that everything around it is testable. */
    protected function shell(string $command): int
    {
        passthru($command, $code);

        return (int) $code;
    }

    /**
     * Where this installation's worker is, as a seam.
     *
     * `ServiceWorker::path()` reads `ROOT`, and `ROOT` is a constant — so without this the file
     * steps here cannot be exercised anywhere but the checkout they are running in.
     */
    protected function workerPath(): ?string
    {
        return ServiceWorker::path();
    }

    protected function fixServiceWorker(OutputInterface $output): mixed
    {
        $path = $this->workerPath();
        $block = $this->pushHandlers();

        if ($block === null) {
            return 'the service worker template is missing from the framework';
        }

        if ($path === null) {
            $path = $this->root() . '/www/sw.js';
            $output->writeln('  no service worker — writing ' . $path);

            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) {
                return 'could not create ' . dirname($path);
            }

            $template = @file_get_contents($this->templatePath());

            return @file_put_contents($path, (string) $template) !== false
                ? true
                : 'could not write ' . $path;
        }

        /*
         * Appended, not rewritten.
         *
         * The worker is the application's file: it caches the application's assets with the
         * application's strategy, and a project has usually edited it. Replacing it to add three
         * handlers would take that away to fix something additive.
         */
        $output->writeln('  appending the push handlers to ' . $path);

        return @file_put_contents($path, "\n" . $block, FILE_APPEND) !== false
            ? true
            : 'could not append to ' . $path;
    }

    protected function hasBrowserScript(): bool
    {
        return is_file($this->root() . '/www/assets/js/push.js');
    }

    protected function writeBrowserScript(OutputInterface $output): mixed
    {
        $source = @file_get_contents($this->browserTemplatePath());

        if ($source === false) {
            return 'the browser script template is missing from the framework';
        }

        $name = (string) (\Pramnos\Application\Application::currentInstance()
            ?->applicationInfo['name'] ?? 'this application');
        $path = $this->root() . '/www/assets/js/push.js';

        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
            return 'could not create ' . dirname($path);
        }

        $output->writeln('  writing ' . $path);

        return @file_put_contents($path, str_replace('{{APP_NAME}}', $name, $source)) !== false
            ? true
            : 'could not write ' . $path;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** The `Web push` block of the shipped worker, for appending to an older one. */
    protected function pushHandlers(): ?string
    {
        $template = @file_get_contents($this->templatePath());

        if ($template === false) {
            return null;
        }

        $at = strpos($template, '/*' . "\n" . ' * ─── Web push');

        return $at === false ? null : substr($template, $at);
    }

    protected function browserTemplatePath(): string
    {
        return dirname(__DIR__, 4) . '/scaffolding/templates/push-notifications.js.stub';
    }

    protected function templatePath(): string
    {
        // Four, not three: this file is in `src/Pramnos/Console/Commands`, so three levels up
        // is `src/` and the template was being looked for at `src/scaffolding/…`.
        return dirname(__DIR__, 4) . '/scaffolding/templates/service-worker.js.stub';
    }

    protected function root(): string
    {
        return defined('ROOT') ? (string) ROOT : (string) getcwd();
    }
}
