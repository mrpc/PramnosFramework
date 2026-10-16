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
    /**
     * The library, with a constraint.
     *
     * Without one, `composer require` in a project whose `minimum-stability` is `dev` — which
     * every project scaffolded from this framework has, because it depends on the framework's
     * own `dev-main` — installs `dev-master`: whatever was pushed upstream this morning, into
     * the code that encrypts every notification. Composer is not wrong to do it and says nothing
     * about it.
     */
    public const PACKAGE = 'minishlink/web-push:^11.0';

    /**
     * What a current browser script has to handle.
     *
     * Identifiers rather than a version number, because a version number is a thing to forget
     * to bump — and because these are exactly what a page written against the current script
     * puts in its markup. A script missing one renders that markup and does nothing with it.
     *
     * @var list<string>
     */
    public const MARKERS = ['data-push-subscribe', 'data-push-invite', 'data-push-later', 'data-push-base', 'pf:notification-click'];

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
                . ' ' . $step['name'] . (($step['checked'] ?? '') !== '' ? ' (' . $step['checked'] . ')' : '')
                . ($step['done'] ? '' : ' — ' . $step['what'])
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
     * The parts, and whether each is there: the five push needs, then the icon a notification
     * shows and whether the site answers at `push/key` at all. `checked` names the file or
     * address a step looked at.
     *
     * @return list<array{name: string, what: string, done: bool, do: callable, checked?: string}>
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
                'name'    => 'Service worker',
                'checked' => $this->shown($this->workerPath()) ?: 'none found',
                'what' => $missing === []
                    ? ''
                    : 'no ' . implode(', ', array_keys($missing)) . ' handler, so a notification '
                        . 'is discarded silently',
                'done' => $missing === [],
                'do'   => fn ($in, $out): mixed => $this->fixServiceWorker($out),
            ],
            [
                'name' => 'Browser script',
                'what' => $this->hasBrowserScript()
                    ? 'the script here predates ' . implode(', ', static::MARKERS)
                        . ', so that part of it does nothing'
                    : 'nothing asks for permission, so nothing ever subscribes',
                'done' => $this->hasBrowserScript() && $this->browserScriptIsCurrent(),
                'do'   => fn ($in, $out): mixed => $this->writeBrowserScript($out),
            ],
            [
                'name'    => 'Icon',
                'checked' => $this->shown($this->iconPath()),
                'what'    => 'there is no icon there, so a notification shows a broken image or none',
                'done'    => $this->hasIcon(),
                'do'      => fn ($in, $out): mixed => $this->adviseIcon($out),
            ],
            $this->routingStep(),
        ];
    }

    /**
     * Whether `push/key` answers as the push controller does — 200 with the key, or 503 with
     * none yet, as JSON. Every other step can be green while an application's own routing sends
     * the address elsewhere (a controller allow-list answering 301), and then nothing subscribes.
     * Not checked without a site address to ask.
     *
     * @return array{name: string, what: string, done: bool, do: callable, checked: string}
     */
    protected function routingStep(): array
    {
        $url = $this->keyUrl();
        if ($url === null) {
            return ['name' => 'Routing', 'checked' => 'not checked: no site address (APP_URL)', 'what' => '', 'done' => true,
                'do' => static fn (): bool => true];
        }

        $answer = $this->fetch($url);
        $ok     = in_array($answer['status'], [200, 503], true) && $answer['json'];

        return [
            'name'    => 'Routing',
            'checked' => $url,
            'what'    => 'it answered ' . ($answer['status'] > 0 ? $answer['status'] : 'nothing') . ($answer['json'] ? '' : ', not JSON')
                . ', so the browser can never fetch the key — route `push` to the framework\'s Push controller',
            'done'    => $ok,
            'do'      => static fn ($in, $out): string => 'push/key is not routed to the push controller; nothing here can change the application\'s routing',
        ];
    }

    /** `push/key` under the configured site address, or null where none is configured. */
    protected function keyUrl(): ?string
    {
        return \Pramnos\Http\SiteUrl::isConfigured() ? \Pramnos\Http\SiteUrl::to('push/key') : null;
    }

    /**
     * One GET, without following a redirect: the status, and whether the answer is JSON.
     *
     * @return array{status: int, json: bool}
     */
    protected function fetch(string $url): array
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'follow_location' => 0, 'ignore_errors' => true]]);
        // The headers from the stream itself, the same on every PHP this supports.
        $stream  = @fopen($url, 'r', false, $context);
        $headers = $stream === false ? [] : (array) (stream_get_meta_data($stream)['wrapper_data'] ?? []);
        $stream === false || fclose($stream);
        $status  = preg_match('#^HTTP/\S+\s+(\d{3})#', (string) ($headers[0] ?? ''), $m) === 1 ? (int) $m[1] : 0;
        $json    = (bool) preg_grep('#^content-type:\s*application/json#i', $headers);

        return ['status' => $status, 'json' => $json];
    }

    /** The icon a notification shows: `push.icon` in app.php, under the web root, or the default. */
    protected function iconPath(): string
    {
        return $this->root() . '/www/' . ltrim(\Pramnos\Notification\Channels\PushChannel::iconSetting('icon'), '/');
    }

    protected function hasIcon(): bool
    {
        return preg_match('#^https?://#i', \Pramnos\Notification\Channels\PushChannel::iconSetting('icon')) === 1 || is_file($this->iconPath());
    }

    /** An icon is the application's own picture: said where to put it, not drawn. */
    protected function adviseIcon(OutputInterface $output): bool
    {
        $output->writeln('  not created — put a 192×192 PNG at ' . $this->iconPath()
            . ", or name yours with 'push' => ['icon' => 'path/under/www.png', 'badge' => '…'] in app.php");

        return true;
    }

    /** A path as the report shows it: from the project root. */
    protected function shown(?string $path): string
    {
        if ($path === null) {
            return '';
        }
        $root = rtrim($this->root(), '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    // ── The steps ────────────────────────────────────────────────────────────

    protected function hasTable(): bool
    {
        try {
            // Schema-qualified, as the migration creates it: `pushsubscriptions` alone is a
            // table no installation has, so the step reported the table missing for ever.
            return \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('pramnos.pushsubscriptions');
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
            $output->writeln('  skipped — run <info>composer require ' . self::PACKAGE . '</info> yourself');

            return true;
        }

        $output->writeln('  composer require ' . self::PACKAGE);

        /*
         * Reported, never assumed.
         *
         * A composer install needs the network and can fail for a dozen reasons that have
         * nothing to do with this application. Saying it worked when it did not would leave an
         * installation that reports itself ready and silently encrypts nothing.
         */
        $code = $this->shell(
            'cd ' . escapeshellarg($this->root())
                . ' && composer require ' . escapeshellarg(self::PACKAGE) . ' 2>&1'
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
            $configured = ServiceWorker::configured();
            $path       = $configured !== null && str_starts_with($configured, '/') ? $configured : $this->root() . '/www/' . ($configured ?? 'sw.js');
            $output->writeln('  no service worker — writing ' . $path);

            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) {
                return 'could not create ' . dirname($path);
            }

            // Filled as `pramnos init` fills it: a worker with `{{ APP_SLUG }}` in its cache name
            // shares that cache with every other installation written this way.
            $template = (string) @file_get_contents($this->templatePath());
            foreach (Init::serviceWorkerValues($this->appName()) as $token => $value) {
                $template = str_replace('{{ ' . $token . ' }}', $value, $template);
            }

            if (@file_put_contents($path, $template) === false) {
                return 'could not write ' . $path;
            }

            // Written is not running: a worker nothing registers receives nothing.
            $output->writeln('  <comment>nothing registers it yet</comment> — register it from the layout, '
                . "navigator.serviceWorker.register('" . basename($path) . "'), at the web root so its scope is the whole site");

            return true;
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
        return is_file($this->browserScriptPath());
    }

    /**
     * Is the script that is there the one this framework ships?
     *
     * A file that exists is not the same as a file that works. This one is generated
     * boilerplate, so a project usually has whatever version was current when `push:setup` last
     * ran — and a copy written before the soft prompt existed renders the invitation markup and
     * never unhides it. Nothing fails; the invitation is simply never shown, which is the exact
     * failure this command was written to end.
     *
     * Checked by the identifiers the current script must handle rather than by a version
     * number, because a version number is a thing to forget to bump.
     */
    protected function browserScriptIsCurrent(): bool
    {
        $source = @file_get_contents($this->browserScriptPath());

        if ($source === false) {
            return false;
        }

        foreach (static::MARKERS as $marker) {
            if (!str_contains($source, $marker)) {
                return false;
            }
        }

        return true;
    }

    protected function browserScriptPath(): string
    {
        return $this->root() . '/www/assets/js/push.js';
    }

    protected function writeBrowserScript(OutputInterface $output): mixed
    {
        $source = @file_get_contents($this->browserTemplatePath());

        if ($source === false) {
            return 'the browser script template is missing from the framework';
        }

        $name = $this->appName();
        $path = $this->browserScriptPath();

        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
            return 'could not create ' . dirname($path);
        }

        $output->writeln(
            (is_file($path) ? '  replacing the out-of-date ' : '  writing ') . $path
        );

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

    /** The application's name, as app.php gives it. */
    protected function appName(): string
    {
        return (string) (\Pramnos\Application\Application::currentInstance()?->applicationInfo['name'] ?? 'this application');
    }

    protected function root(): string
    {
        return defined('ROOT') ? (string) ROOT : (string) getcwd();
    }
}
