<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\PushSetup;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command that exists because a guide with five steps is five chances to stop after four.
 *
 * Push has five parts and four of them are invisible when missing: a table, a key pair, an
 * encryption library, a worker that listens, and a page that asks. Miss any one and the other
 * four keep working perfectly — no error, no log line, and no notification.
 */
#[CoversClass(PushSetup::class)]
class PushSetupTest extends TestCase
{
    /**
     * Every part is reported, whether it is there or not.
     *
     * A checklist that only mentions what is missing leaves somebody wondering whether the rest
     * was checked at all.
     */
    public function testItReportsAllFiveParts(): void
    {
        // Act
        $display = $this->report(['migration' => true, 'keys' => true, 'library' => true,
            'worker' => true, 'script' => true, 'icon' => true, 'routing' => true, 'caching' => true])->getDisplay();

        // Assert
        foreach (['Migration', 'VAPID key pair', 'Encryption library', 'Service worker',
                  'Browser script', 'Icon', 'Routing', 'Worker caching'] as $part) {
            $this->assertStringContainsString($part, $display);
        }
    }

    /**
     * A missing part says what its absence costs.
     *
     * "todo: Browser script" is a filename. "nothing asks for permission, so nothing ever
     * subscribes" is the reason somebody has no subscriptions.
     */
    public function testAMissingPartSaysWhatItsAbsenceCosts(): void
    {
        // Act
        $display = $this->report(['script' => false])->getDisplay();

        // Assert
        $this->assertStringContainsString('nothing asks for permission', $display);
        $this->assertStringContainsString('todo', $display);
    }

    /**
     * Nothing happens without `--apply`.
     */
    public function testNothingHappensWithoutApply(): void
    {
        // Arrange
        $command = $this->command(['script' => false]);

        // Act
        $display = $this->execute($command, [])->getDisplay();

        // Assert
        $this->assertStringContainsString('Nothing done', $display);
        $this->assertSame([], $command->ran);
    }

    /**
     * A complete installation says so and does nothing.
     *
     * The command has to be safe to run again — it is the thing somebody runs when they are not
     * sure, and re-running it must not re-do anything.
     */
    public function testACompleteInstallationIsLeftAlone(): void
    {
        // Arrange
        $command = $this->command([]);

        // Act
        $tester = $this->execute($command, ['--apply' => true]);

        // Assert
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Push is set up', $tester->getDisplay());
        $this->assertSame([], $command->ran);
    }

    /**
     * Only the missing parts are done, and in order.
     */
    public function testOnlyTheMissingPartsAreDone(): void
    {
        // Arrange
        $command = $this->command(['keys' => false, 'script' => false]);

        // Act
        $this->execute($command, ['--apply' => true]);

        // Assert
        $this->assertSame(['keys', 'script'], $command->ran);
    }

    /**
     * A step that fails stops the run and says which one.
     *
     * Carrying on would leave an installation that reported four steps done and is missing the
     * one that mattered — which is the state this command exists to end.
     */
    public function testAFailedStepStopsTheRun(): void
    {
        // Arrange
        $command = $this->command(['keys' => false, 'script' => false]);
        $command->failOn = 'keys';

        // Act
        $tester = $this->execute($command, ['--apply' => true]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Stopped', $tester->getDisplay());
        $this->assertSame(['keys'], $command->ran, 'and nothing after it');
    }

    /**
     * The worker is appended to, never replaced.
     *
     * It is the application's file: it caches the application's assets with the application's
     * strategy, and a project has usually edited it. Rewriting it to add three handlers takes
     * that away to fix something additive.
     */
    public function testTheWorkerIsAppendedToNotReplaced(): void
    {
        // Arrange — a worker with a caching strategy somebody wrote
        $root = $this->tempRoot();
        mkdir($root . '/www', 0755, true);
        file_put_contents($root . '/www/sw.js', "const CACHE = 'ours-v9';\n// our own strategy\n");

        try {
            // Act
            $this->rooted($root)->probeFixWorker();

            // Assert
            $worker = (string) file_get_contents($root . '/www/sw.js');
            $this->assertStringContainsString('ours-v9', $worker, 'their file is still their file');
            $this->assertStringContainsString("addEventListener('push'", $worker);
            $this->assertStringContainsString('pushsubscriptionchange', $worker);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * With no worker at all, the whole shipped one is written.
     */
    public function testWithNoWorkerTheWholeTemplateIsWritten(): void
    {
        // Arrange
        $root = $this->tempRoot();
        mkdir($root, 0755, true);

        try {
            // Act
            $this->rooted($root)->probeFixWorker();

            // Assert
            $worker = (string) file_get_contents($root . '/www/sw.js');
            $this->assertStringContainsString("addEventListener('install'", $worker,
                'the caching half as well, not only the push handlers');
            $this->assertStringContainsString("addEventListener('push'", $worker);
            // Filled as init fills it: a literal {{ APP_SLUG }} would be every such site's cache name
            $this->assertStringNotContainsString('{{', $worker);
            $this->assertStringContainsString('assets\/spa', $worker);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * A worker written where there was none is written where app.php names it, and the run says
     * that nothing registers it yet — a written worker nobody registers receives nothing, and the
     * step reported done.
     */
    public function testANewWorkerGoesWhereConfiguredAndIsSaidToNeedRegistering(): void
    {
        // Arrange
        $root = $this->tempRoot();
        mkdir($root, 0755, true);
        $app  = \Pramnos\Application\Application::getInstance();
        $info = $app->applicationInfo;
        $app->applicationInfo['push'] = ['service_worker' => 'serviceWorker.js'];
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        try {
            // Act
            $result = $this->rooted($root)->probeFixWorkerTo($output);

            // Assert
            $this->assertTrue($result);
            $this->assertFileExists($root . '/www/serviceWorker.js');
            $this->assertStringContainsString("nothing registers it yet", $output->fetch());
        } finally {
            $app->applicationInfo = $info;
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * The routing step asks `push/key` and accepts what the push controller answers — 200 with a
     * key, 503 without one, as JSON — and nothing else: a 301 from an application's own routing,
     * or a page that is not JSON, is the reason nothing subscribes, and says so. Without a site
     * address there is nothing to ask, and that is said rather than failed.
     */
    public function testTheRoutingStepAsksPushKey(): void
    {
        // Arrange
        $step = static function (?string $url, int $status, bool $json): array {
            $command = new class ($url, $status, $json) extends PushSetup {
                public function __construct(private ?string $url, private int $status, private bool $json)
                {
                    parent::__construct();
                }

                /** The address given. */
                protected function keyUrl(): ?string { return $this->url; }

                /** The answer given. */
                protected function fetch(string $url): array { return ['status' => $this->status, 'json' => $this->json]; }

                /** @return array<string, mixed> */
                public function probe(): array { return $this->routingStep(); }
            };

            return $command->probe();
        };

        // Act
        $key      = $step('https://site.test/push/key', 200, true);
        $noKey    = $step('https://site.test/push/key', 503, true);
        $moved    = $step('https://site.test/push/key', 301, false);
        $page     = $step('https://site.test/push/key', 200, false);
        $silent   = $step('https://site.test/push/key', 0, false);
        $nowhere  = $step(null, 0, false);

        // Assert
        $this->assertSame([true, true, false, false, false, true], array_column([$key, $noKey, $moved, $page, $silent, $nowhere], 'done'));
        $this->assertSame('https://site.test/push/key', $key['checked']);
        $this->assertStringContainsString('answered 301, not JSON', $moved['what']);
        $this->assertStringContainsString('answered nothing', $silent['what']);
        $this->assertStringContainsString('not checked', $nowhere['checked']);
        $this->assertIsString(($moved['do'])(null, null), 'it cannot fix routing, and stops saying why');
        $this->assertTrue(($nowhere['do'])());
    }

    /**
     * The real request: an address that answers nothing is status 0 and not JSON, rather than an
     * error; and `keyUrl()` is the configured site's `push/key`.
     */
    public function testTheRealFetchAndAddress(): void
    {
        // Arrange
        $command = new class extends PushSetup {
            /** @return array{status: int, json: bool} */
            public function probeFetch(string $url): array { return $this->fetch($url); }

            public function probeKeyUrl(): ?string { return $this->keyUrl(); }
        };

        // Act
        $answer = $command->probeFetch('http://127.0.0.1:9/push/key');

        // Assert
        $this->assertSame(['status' => 0, 'json' => false, 'cache' => ''], $answer);
        $this->assertSame(\Pramnos\Http\SiteUrl::isConfigured() ? \Pramnos\Http\SiteUrl::to('push/key') : null, $command->probeKeyUrl());
    }

    /**
     * The worker is asked for as a browser would, and its `Cache-Control` decides: `no-cache`,
     * `no-store` or a `max-age` of five minutes at most is done; none, or a day, is said — and
     * the step never stops the run, since the fix is the server's. Without a worker, or a site
     * address, it is not checked.
     */
    public function testTheWorkerCachingStep(): void
    {
        // Arrange
        $root = $this->tempRoot();
        mkdir($root . '/www', 0755, true);
        file_put_contents($root . '/www/sw.js', '//');
        $step = static function (string $root, ?string $cache, bool $site = true): array {
            $command = new class ($root, $cache, $site) extends PushSetup {
                public function __construct(private string $where, private ?string $cache, private bool $site)
                {
                    parent::__construct();
                }

                protected function root(): string { return $this->where; }

                protected function workerPath(): ?string { return $this->cache === null ? null : $this->where . '/www/sw.js'; }

                protected function siteAddress(string $path): ?string { return $this->site ? 'https://site.test/' . $path : null; }

                protected function fetch(string $url): array { return ['status' => 200, 'json' => false, 'cache' => (string) $this->cache]; }

                /** @return array<string, mixed> */
                public function probe(): array { return $this->workerCachingStep(); }
            };

            return $command->probe();
        };

        try {
            // Act
            $answers = [];
            foreach (['no-cache', 'public, max-age=60', 'no-store', '', 'public, max-age=86400'] as $cache) {
                $answers[$cache] = $step($root, $cache);
            }
            $noWorker = $step($root, null);
            $noSite   = $step($root, 'no-cache', false);
            $output   = new \Symfony\Component\Console\Output\BufferedOutput();
            $did      = ($answers['']['do'])(null, $output);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }

        // Assert
        $this->assertSame([true, true, true, false, false], array_column(array_values($answers), 'done'));
        $this->assertSame('https://site.test/sw.js', $answers['no-cache']['checked']);
        $this->assertStringContainsString('no Cache-Control', $answers['']['what']);
        $this->assertStringContainsString('max-age=86400', $answers['public, max-age=86400']['what']);
        $this->assertSame([true, true], [$noWorker['done'], $noSite['done']]);
        $this->assertStringContainsString('not checked', $noWorker['checked']);
        $this->assertTrue(($noWorker['do'])());
        $this->assertTrue($did, 'said, and the run goes on');
        $this->assertStringContainsString('Cache-Control: no-cache', $output->fetch());
    }

    /**
     * The icon step: missing under the web root is work to do, and its "doing" is saying where to
     * put one — a picture is the application's own; there once, or configured as an address, it
     * is done.
     */
    public function testTheIconStep(): void
    {
        // Arrange
        $root = $this->tempRoot();
        mkdir($root . '/www/assets/img', 0755, true);
        $command = $this->rooted($root);
        $app     = \Pramnos\Application\Application::getInstance();
        $info    = $app->applicationInfo;
        $output  = new \Symfony\Component\Console\Output\BufferedOutput();

        try {
            // Act
            unset($app->applicationInfo['push']);
            $before = $command->probeHasIcon();
            $advice = $command->probeAdviseIcon($output);
            touch($root . '/www/assets/img/icon-192.png');
            $after = $command->probeHasIcon();
            unlink($root . '/www/assets/img/icon-192.png');
            $app->applicationInfo['push'] = ['icon' => 'https://cdn.test/i.png'];
            $remote = $command->probeHasIcon();
        } finally {
            $app->applicationInfo = $info;
            exec('rm -rf ' . escapeshellarg($root));
        }

        // Assert
        $this->assertSame([false, true, true, true], [$before, $advice, $after, $remote]);
        $this->assertStringContainsString('www/assets/img/icon-192.png', $output->fetch());
    }

    /**
     * The report names the file each step looked at, from the project root — so "no handler" can
     * be checked against the file somebody thinks it is.
     */
    public function testTheReportSaysWhichWorkerWasChecked(): void
    {
        // Arrange
        $root = $this->tempRoot();
        mkdir($root . '/www', 0755, true);
        file_put_contents($root . '/www/sw.js', '// no handlers');

        try {
            // Act
            $steps = array_column($this->rooted($root)->probeInspect(), null, 'name');

            // Assert
            $this->assertSame('www/sw.js', $steps['Service worker']['checked']);
            $this->assertSame('www/assets/img/icon-192.png', $steps['Icon']['checked']);
            $this->assertSame('/elsewhere/x.js', $this->rooted($root)->probeShown('/elsewhere/x.js'), 'outside the root, as it is');
            $this->assertSame('', $this->rooted($root)->probeShown(null));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * The browser script is written with this application's name in it.
     */
    public function testTheBrowserScriptIsWritten(): void
    {
        // Arrange
        $root = $this->tempRoot();
        mkdir($root, 0755, true);

        try {
            // Act
            $command = $this->rooted($root);
            $this->assertFalse($command->probeHasScript());
            $command->probeWriteScript();

            // Assert
            $this->assertTrue($command->probeHasScript());
            $script = (string) file_get_contents($root . '/www/assets/js/push.js');
            $this->assertStringContainsString('Notification.requestPermission', $script);
            $this->assertStringNotContainsString('{{APP_NAME}}', $script);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * A directory it cannot write into is reported, not thrown.
     */
    public function testAnUnwritableRootIsReported(): void
    {
        // Arrange — a file where the web root has to be
        $root = $this->tempRoot();
        mkdir($root, 0755, true);
        file_put_contents($root . '/www', 'not a directory');

        try {
            // Act
            $result = $this->rooted($root)->probeWriteScript();

            // Assert
            $this->assertIsString($result, 'a failure is a reason, not a boolean');
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * The push block is found in the shipped worker, and it is the whole tail of it.
     */
    public function testThePushBlockIsFoundInTheTemplate(): void
    {
        // Act
        $block = $this->rooted($this->tempRoot())->probeHandlers();

        // Assert
        $this->assertIsString($block);
        $this->assertStringStartsWith('/*', $block);
        $this->assertStringContainsString("addEventListener('push'", $block);
        $this->assertStringContainsString('NotAllowedError', $block);
    }

    /**
     * The real five-part inspection, on this checkout.
     *
     * Every other test here supplies the answers. Without this the list could describe five
     * parts that have nothing to do with push and the suite would stay green — and the list is
     * the whole product.
     */
    public function testTheRealInspectionDescribesEveryPart(): void
    {
        // Act
        $steps = (new class extends PushSetup {
            /** @return list<array<string, mixed>> */
            public function probeInspect(): array { return $this->inspect(); }

            /** Not the network: the push controller's answer. */
            protected function fetch(string $url): array { return ['status' => 200, 'json' => true, 'cache' => 'no-cache']; }
        })->probeInspect();

        // Assert
        $this->assertCount(8, $steps);

        foreach ($steps as $step) {
            $this->assertNotSame('', $step['name']);
            $this->assertIsBool($step['done']);
            $this->assertIsCallable($step['do']);

            if (!$step['done']) {
                $this->assertNotSame('', $step['what'],
                    $step['name'] . ' has to say what its absence costs');
            }
        }

        $this->assertSame(
            ['Migration', 'VAPID key pair', 'Encryption library', 'Service worker', 'Browser script', 'Icon', 'Routing', 'Worker caching'],
            array_column($steps, 'name')
        );
    }

    /**
     * `--no-install` reports the command to run rather than running it.
     *
     * For an environment with no network, and for somebody who would rather add the dependency
     * themselves — which is a reasonable thing to want from a command that edits composer.json.
     */
    public function testNoInstallSaysWhatToRunInstead(): void
    {
        // Arrange
        $command = new class extends PushSetup {
            protected function hasTable(): bool { return true; }

            protected function hasBrowserScript(): bool { return true; }

            protected function browserScriptIsCurrent(): bool { return true; }

            public function probeInstall(\Symfony\Component\Console\Input\InputInterface $in,
                \Symfony\Component\Console\Output\OutputInterface $out): mixed
            {
                return $this->installLibrary($in, $out);
            }
        };

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $input  = new \Symfony\Component\Console\Input\ArrayInput(
            ['--no-install' => true],
            $command->getDefinition()
        );

        // Act
        $result = $command->probeInstall($input, $output);

        // Assert
        $this->assertTrue($result);
        $this->assertStringContainsString('composer require minishlink/web-push', $output->fetch());
        $this->assertStringContainsString(':^', PushSetup::PACKAGE,
            'with a constraint: unconstrained, a dev-stability project installs dev-master');
    }

    /**
     * The migration step is the `migrate` command, not a second implementation of it.
     *
     * And it verifies afterwards: a migrate run that exits zero without creating the table —
     * the scope was wrong, the file was skipped — would otherwise be reported as done.
     */
    public function testTheMigrationStepRunsMigrateAndThenChecksTheTable(): void
    {
        // Arrange
        $command = $this->wired();
        $application = new \Symfony\Component\Console\Application();
        $application->add($command);
        $application->add($this->stub('migrate', $command));

        // Act
        $result = $command->probeMigrations();

        // Assert
        $this->assertTrue($result);
        $this->assertSame(['migrate'], $command->calls);
    }

    /**
     * A migrate run that leaves no table is a failure, however it exited.
     */
    public function testAMigrationThatLeavesNoTableIsAFailure(): void
    {
        // Arrange
        $command = $this->wired(table: false);
        $application = new \Symfony\Component\Console\Application();
        $application->add($command);
        $application->add($this->stub('migrate', $command));

        // Act
        $result = $command->probeMigrations();

        // Assert
        $this->assertSame('the table still is not there', $result);
    }

    /**
     * The key step is `push:vapid-generate`, and a run that writes no pair is a failure.
     *
     * `Vapid::configured()` reads the real installation, which in this checkout has a pair —
     * so the interesting half here is that the sub-command was found and run at all.
     */
    public function testTheKeyStepRunsTheGenerator(): void
    {
        // Arrange
        $command = $this->wired();
        $application = new \Symfony\Component\Console\Application();
        $application->add($command);
        $application->add($this->stub('push:vapid-generate', $command));

        // Act
        $result = $command->probeKeys();

        // Assert
        $this->assertSame(['push:vapid-generate'], $command->calls);
        $this->assertTrue($result === true || $result === 'no pair was written');
    }

    /**
     * An application that has not registered the sub-commands says so instead of crashing.
     *
     * `push:setup` can be registered on its own — somebody wiring commands by hand, a console
     * application that only has a few. A fatal `find()` there would be a worse answer than a line
     * saying which command is missing.
     */
    public function testMissingSubCommandsAreReportedNotFatal(): void
    {
        // Arrange — never added to an application, so there is nothing to find
        $command = $this->wired();

        // Act & Assert
        $this->assertSame('the migrate command is not registered', $command->probeMigrations());
        $this->assertSame(
            'the push:vapid-generate command is not registered',
            $command->probeKeys()
        );
    }

    /**
     * composer is run, and its exit code is the answer — not the fact that it was started.
     *
     * A composer require needs the network and fails for a dozen reasons that have nothing to do
     * with this application. Reporting it as done would leave an installation that says it is
     * ready and encrypts nothing.
     */
    public function testComposerFailureIsReportedRatherThanAssumedToHaveWorked(): void
    {
        // Arrange
        $command = $this->wired();
        $command->exitCode = 2;
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $input = new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition());

        // Act
        $result = $command->probeInstall($input, $output);

        // Assert
        $this->assertSame('composer exited with 2', $result);
        $this->assertStringContainsString(PushSetup::PACKAGE, $command->shelled);
    }

    /**
     * And a composer that succeeds is the step succeeding.
     */
    public function testComposerSuccessIsTheStepSucceeding(): void
    {
        // Arrange
        $command = $this->wired();
        $input = new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition());

        // Act
        $result = $command->probeInstall(
            $input,
            new \Symfony\Component\Console\Output\NullOutput()
        );

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Without a template there is nothing to write, and that is said rather than written.
     *
     * A truncated or missing stub would otherwise produce an empty `sw.js` that registers, caches
     * nothing and receives nothing — the exact silent state this command exists to end.
     */
    public function testAMissingTemplateIsReportedForBothFiles(): void
    {
        // Arrange
        $root = $this->tempRoot();
        mkdir($root, 0755, true);

        $command = new class ($root) extends PushSetup {
            public function __construct(private string $where)
            {
                parent::__construct();
            }

            protected function root(): string { return $this->where; }

            protected function workerPath(): ?string { return null; }

            protected function templatePath(): string { return '/no/such/service-worker.js.stub'; }

            protected function browserTemplatePath(): string { return '/no/such/push.js.stub'; }

            public function probeFixWorker(): mixed
            {
                return $this->fixServiceWorker(new \Symfony\Component\Console\Output\NullOutput());
            }

            public function probeWriteScript(): mixed
            {
                return $this->writeBrowserScript(new \Symfony\Component\Console\Output\NullOutput());
            }

            public function probeHandlers(): ?string { return $this->pushHandlers(); }
        };

        try {
            // Act & Assert
            $this->assertNull($command->probeHandlers());
            $this->assertSame(
                'the service worker template is missing from the framework',
                $command->probeFixWorker()
            );
            $this->assertSame(
                'the browser script template is missing from the framework',
                $command->probeWriteScript()
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * A web root that cannot be created is reported for the worker too.
     *
     * The same failure as the browser script, on the other file: a file sitting where `www/` has
     * to be. Both are directories the command creates, and both can fail.
     */
    public function testAWebRootThatCannotBeCreatedIsReportedForTheWorker(): void
    {
        // Arrange — a file where the web root has to be
        $root = $this->tempRoot();
        mkdir($root, 0755, true);
        file_put_contents($root . '/www', 'not a directory');

        try {
            // Act
            $result = $this->rooted($root)->probeFixWorker();

            // Assert
            $this->assertIsString($result);
            $this->assertStringContainsString('could not create', $result);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * The real `workerPath()` is this installation's worker, wherever `ServiceWorker` finds it.
     *
     * Every other test here overrides it. Without this the seam could point anywhere — at a
     * filename that never existed — and the suite would stay green while `push:setup` appended
     * three handlers to nothing.
     */
    public function testTheRealWorkerPathIsTheInstallationsWorker(): void
    {
        // Act
        $path = (new class extends PushSetup {
            public function probeWorkerPath(): ?string { return $this->workerPath(); }
        })->probeWorkerPath();

        // Assert
        $this->assertSame(\Pramnos\Push\ServiceWorker::path(), $path);
    }

    /** A stub sub-command that records that it was reached, and accepts `--scope`. */
    private function stub(string $name, object $owner): \Symfony\Component\Console\Command\Command
    {
        $command = new \Symfony\Component\Console\Command\Command($name);
        $command->addOption(
            'scope',
            null,
            \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED
        );
        $command->setCode(static function () use ($name, $owner): int {
            $owner->calls[] = $name;

            return 0;
        });

        return $command;
    }

    /** A command whose sub-commands and shell are real, and whose table answer is given. */
    private function wired(bool $table = true): object
    {
        return new class ($table) extends PushSetup {
            /** @var list<string> */
            public array $calls = [];

            public int $exitCode = 0;

            public string $shelled = '';

            public function __construct(private bool $table)
            {
                parent::__construct();
            }

            protected function hasTable(): bool
            {
                return $this->table;
            }

            protected function shell(string $command): int
            {
                $this->shelled = $command;

                return $this->exitCode;
            }

            public function probeMigrations(): mixed
            {
                return $this->runMigrations(new \Symfony\Component\Console\Output\NullOutput());
            }

            public function probeKeys(): mixed
            {
                return $this->generateKeys(new \Symfony\Component\Console\Output\NullOutput());
            }

            public function probeInstall(\Symfony\Component\Console\Input\InputInterface $in,
                \Symfony\Component\Console\Output\OutputInterface $out): mixed
            {
                return $this->installLibrary($in, $out);
            }
        };
    }

    /**
     * A script that is there but predates the soft prompt is reported as work to do.
     *
     * A file that exists is not a file that works. This one is generated boilerplate, so a
     * project has whatever version was current when `push:setup` last ran — and a copy written
     * before the invitation existed renders the invitation markup and never unhides it. Nothing
     * fails; the invitation is simply never shown, which is the exact failure this command
     * exists to end. Found on a real installation, by somebody saying they still could not find
     * where to turn notifications on.
     */
    public function testAnOutOfDateBrowserScriptIsNotDone(): void
    {
        // Arrange — the script as it was before the soft prompt
        $root = $this->tempRoot();
        mkdir($root . '/www/assets/js', 0755, true);
        file_put_contents(
            $root . '/www/assets/js/push.js',
            "// old\nvar b = document.querySelector('[data-push-subscribe]');\n"
        );

        try {
            $command = $this->rooted($root);

            // Act & Assert
            $this->assertTrue($command->probeHasScript(), 'the file is there');
            $this->assertFalse($command->probeScriptIsCurrent(), 'and it is not the current one');

            // And writing it says it is replacing rather than creating
            $output = new \Symfony\Component\Console\Output\BufferedOutput();
            $command->probeWriteScriptTo($output);

            $this->assertStringContainsString('replacing', $output->fetch());
            $this->assertTrue($command->probeScriptIsCurrent(), 'and now it is');
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * The script this framework ships handles every identifier a page may put in its markup.
     *
     * The other half of the same guard: the stub is what `MARKERS` describes, so a marker added
     * to a view without being handled in the script is caught here rather than by somebody
     * pressing a button that does nothing.
     */
    public function testTheShippedScriptHandlesEveryMarker(): void
    {
        // Arrange
        $root = $this->tempRoot();
        mkdir($root . '/www/assets/js', 0755, true);

        try {
            // Act
            $this->rooted($root)->probeWriteScript();
            $script = (string) file_get_contents($root . '/www/assets/js/push.js');

            // Assert
            foreach (PushSetup::MARKERS as $marker) {
                $this->assertStringContainsString($marker, $script);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    private function tempRoot(): string
    {
        return sys_get_temp_dir() . '/pushsetup-' . bin2hex(random_bytes(5));
    }

    /** A command whose idea of the project root is a temporary directory. */
    private function rooted(string $root): object
    {
        return new class ($root) extends PushSetup {
            public function __construct(private string $where)
            {
                parent::__construct();
            }

            protected function root(): string { return $this->where; }

            protected function workerPath(): ?string
            {
                return is_file($this->where . '/www/sw.js') ? $this->where . '/www/sw.js' : null;
            }

            public function probeFixWorker(): mixed
            {
                return $this->fixServiceWorker(new \Symfony\Component\Console\Output\NullOutput());
            }

            public function probeWriteScript(): mixed
            {
                return $this->writeBrowserScript(new \Symfony\Component\Console\Output\NullOutput());
            }

            public function probeHasScript(): bool { return $this->hasBrowserScript(); }

            public function probeScriptIsCurrent(): bool { return $this->browserScriptIsCurrent(); }

            public function probeWriteScriptTo(
                \Symfony\Component\Console\Output\OutputInterface $out
            ): mixed {
                return $this->writeBrowserScript($out);
            }

            public function probeHandlers(): ?string { return $this->pushHandlers(); }

            public function probeFixWorkerTo(\Symfony\Component\Console\Output\OutputInterface $out): mixed
            {
                return $this->fixServiceWorker($out);
            }

            public function probeHasIcon(): bool { return $this->hasIcon(); }

            public function probeAdviseIcon(\Symfony\Component\Console\Output\OutputInterface $out): bool
            {
                return $this->adviseIcon($out);
            }

            public function probeShown(?string $path): string { return $this->shown($path); }

            /** @return list<array<string, mixed>> */
            public function probeInspect(): array { return $this->inspect(); }

            /** Not the network. */
            protected function fetch(string $url): array { return ['status' => 200, 'json' => true, 'cache' => 'no-cache']; }
        };
    }

    /** @param array<string, bool> $state */
    private function report(array $state): CommandTester
    {
        return $this->execute($this->command($state), []);
    }

    /** @param array<string, mixed> $input */
    private function execute(object $command, array $input): CommandTester
    {
        $application = new \Symfony\Component\Console\Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    /**
     * A command whose five checks answer what the test says.
     *
     * @param array<string, bool> $state
     */
    private function command(array $state): object
    {
        return new class ($state) extends PushSetup {
            /** @var list<string> */
            public array $ran = [];

            public string $failOn = '';

            public function __construct(private array $state)
            {
                parent::__construct();
            }

            private function is(string $part): bool
            {
                return $this->state[$part] ?? true;
            }

            private function record(string $part): mixed
            {
                $this->ran[] = $part;

                return $this->failOn === $part ? 'no' : true;
            }

            protected function hasTable(): bool { return $this->is('migration'); }

            protected function hasBrowserScript(): bool { return $this->is('script'); }

            protected function browserScriptIsCurrent(): bool { return $this->is('script'); }

            /** Not the network. */
            protected function fetch(string $url): array { return ['status' => 200, 'json' => true, 'cache' => 'no-cache']; }

            protected function inspect(): array
            {
                $steps = parent::inspect();

                // The two the parent reads from the installation rather than from a method.
                $steps[1]['done'] = $this->is('keys');
                $steps[2]['done'] = $this->is('library');
                $steps[3]['done'] = $this->is('worker');
                $steps[5]['done'] = $this->is('icon');
                $steps[6]['done'] = $this->is('routing');
                $steps[7]['done'] = $this->is('caching');

                $names = ['migration', 'keys', 'library', 'worker', 'script', 'icon', 'routing', 'caching'];

                foreach ($steps as $index => $step) {
                    $steps[$index]['do'] = fn (): mixed => $this->record($names[$index]);
                }

                return $steps;
            }
        };
    }
}
