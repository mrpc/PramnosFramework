<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\PushVapidGenerate;
use Pramnos\Push\Vapid;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command that gives an installation its push identity.
 *
 * Run once, and the second run is the dangerous one: **rotating the key invalidates every
 * existing subscription**. A browser that subscribed with the old public key cannot be pushed to
 * with the new private one, and it is never told — the notifications simply stop, on every device
 * at once, and the only symptom is silence.
 *
 * So the refusal to overwrite is not a nicety, and it is the first thing asserted here.
 */
#[CoversClass(PushVapidGenerate::class)]
class PushVapidGenerateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vapid-cmd-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * A fresh installation gets a usable pair, written where the loader looks for it.
     */
    public function testAFreshInstallationGetsAPair(): void
    {
        // Act
        $tester = $this->generate([]);

        // Assert
        $this->assertSame(0, $tester->getStatusCode());

        $pair = Vapid::load($this->root);
        $this->assertNotNull($pair, 'the loader has to find what the command wrote');
        $this->assertSame(65, strlen(Vapid::decode($pair['publicKey'])));
        $this->assertStringContainsString($pair['publicKey'], $tester->getDisplay(),
            'the public key is printed, because a developer may want it before the endpoint exists');
    }

    /**
     * The private key is not world-readable, and the directory is not either.
     *
     * `app/keys` on a shared host with the default 0755 is a private key any other account on
     * the machine can read.
     */
    public function testThePrivateKeyIsNotReadableByEverybody(): void
    {
        // Act
        $this->generate([]);

        // Assert
        $private = $this->root . '/' . Vapid::DIRECTORY . '/' . Vapid::PRIVATE_FILE;
        $this->assertSame('0600', substr(sprintf('%o', fileperms($private)), -4));
    }

    /**
     * A second run refuses, and says what would break.
     *
     * The whole point of the command having any logic at all.
     */
    public function testASecondRunRefuses(): void
    {
        // Arrange
        $this->generate([]);
        $first = Vapid::load($this->root);

        // Act
        $tester = $this->generate([]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('already has a VAPID key pair', $tester->getDisplay());
        $this->assertStringContainsString('--force', $tester->getDisplay());
        $this->assertSame(
            $first['publicKey'],
            Vapid::load($this->root)['publicKey'],
            'and it must not have quietly written a new one anyway'
        );
    }

    /**
     * `--force` replaces it, for somebody who does mean it.
     */
    public function testForceReplacesThePair(): void
    {
        // Arrange
        $this->generate([]);
        $first = Vapid::load($this->root)['publicKey'];

        // Act
        $tester = $this->generate(['--force' => true]);

        // Assert
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertNotSame($first, Vapid::load($this->root)['publicKey']);
    }

    /**
     * An installation with no contact address is told, rather than left with a pair a push
     * service may start refusing.
     *
     * RFC 8292's `sub` claim is where a provider writes to when something is wrong with what you
     * send — before it stops accepting it. Absent, the first sign of trouble is the notifications
     * stopping.
     */
    public function testAMissingContactSubjectIsAWarningNotAFailure(): void
    {
        // Act
        $tester = $this->generate([]);

        // Assert — the pair is still written; the warning is a warning
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertNotNull(Vapid::load($this->root));

        $display = $tester->getDisplay();
        $subject = Vapid::subject();

        if ($subject === '') {
            $this->assertStringContainsString('No contact subject', $display);
            $this->assertStringContainsString('RFC 8292', $display);
        } else {
            $this->assertStringContainsString($subject, $display);
        }
    }

    /**
     * It says to back the pair up, because there is no way to recover from losing it.
     *
     * Not advice for its own sake: with no pair there is no way to re-establish a subscription
     * from the server side at all. Every subscriber is gone, and the only fix is asking each of
     * them to subscribe again.
     */
    public function testItSaysToBackThePairUp(): void
    {
        // Act
        $display = $this->generate([])->getDisplay();

        // Assert
        $this->assertStringContainsString('Back this pair up', $display);
    }

    /**
     * A directory it cannot write to is reported, not thrown.
     */
    public function testAnUnwritableDirectoryIsReported(): void
    {
        // Arrange — a file where the keys directory should go
        mkdir($this->root . '/app', 0700, true);
        file_put_contents($this->root . '/app/keys', 'not a directory');

        // Act
        $tester = $this->generate([]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Could not', $tester->getDisplay());
    }

    /**
     * With a contact configured, it is printed instead of the warning.
     */
    public function testAConfiguredContactIsPrintedBack(): void
    {
        // Arrange
        \Pramnos\Application\Settings::clearSettings();
        \Pramnos\Application\Settings::setSetting('admin_mail', 'ops@example.com', false);

        try {
            // Act
            $display = $this->generate([])->getDisplay();

            // Assert
            $this->assertStringContainsString('mailto:ops@example.com', $display);
            $this->assertStringNotContainsString('No contact subject', $display);
        } finally {
            \Pramnos\Application\Settings::clearSettings();
        }
    }

    /**
     * A key path it cannot write to is reported rather than exiting 0 having written nothing.
     *
     * Distinct from the case above, where the directory could not be created at all. Here it
     * exists and the write fails — a wrong owner after a deploy, a full disk, something already
     * occupying the path. Unreported, the command succeeds having produced no pair, and the
     * failure surfaces much later as "notifications never arrive".
     */
    public function testAKeyPathThatCannotBeWrittenIsReported(): void
    {
        // Arrange — something is already sitting where the private key goes
        $keys = $this->root . '/' . Vapid::DIRECTORY;
        mkdir($keys . '/' . Vapid::PRIVATE_FILE, 0700, true);

        // Act
        $tester = $this->generate([]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Could not write', $tester->getDisplay());
    }

    /**
     * Left to itself, the command writes where the application lives.
     *
     * Every other test here points it at a temporary directory — which is the only safe way to
     * test a command whose output must never be committed — so without this the default could
     * be anything at all.
     */
    public function testItDefaultsToTheApplicationRoot(): void
    {
        // Act
        $where = (new class extends PushVapidGenerate {
            public function probeRoot(): string { return $this->root(); }
        })->probeRoot();

        // Assert
        $this->assertSame(defined('ROOT') ? (string) ROOT : (string) getcwd(), $where);
        $this->assertNotSame('', $where);
    }

    /**
     * A worker that exists but is missing handlers names them, one by one with its reason.
     *
     * The state of every project scaffolded before push: registered, caching, and discarding
     * every notification. Three identifiers would tell somebody to go and look them up; the
     * reason is what makes it actionable where it is read.
     */
    public function testAWorkerMissingHandlersNamesEachOne(): void
    {
        // Arrange
        $command = new class ($this->rootPath()) extends PushVapidGenerate {
            public function __construct(private string $where)
            {
                parent::__construct();
            }

            protected function root(): string { return $this->where; }

            protected function workerPath(): ?string { return '/somewhere/www/sw.js'; }

            protected function workerGaps(): array
            {
                return [
                    'push' => 'receives the notification',
                    'pushsubscriptionchange' => 'survives the browser rotating the subscription',
                ];
            }
        };

        $application = new Application();
        $application->add($command);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        // Act
        $tester->execute([], ['interactive' => false]);
        $display = $tester->getDisplay();

        // Assert
        $this->assertStringContainsString('/somewhere/www/sw.js', $display);
        $this->assertStringContainsString('push', $display);
        $this->assertStringContainsString('rotating the subscription', $display);
        $this->assertStringContainsString('before web push existed', $display);
    }

    /**
     * A worker that cannot receive is reported beside the new key pair.
     *
     * This command is where somebody sets push up, and a key pair on an installation whose
     * worker has no `push` listener is four parts out of five — which fails silently, on every
     * device, with no error anywhere.
     */
    public function testAWorkerThatCannotReceiveIsReported(): void
    {
        // Act — this checkout has no service worker of its own
        $display = $this->generate([])->getDisplay();

        // Assert — this checkout has no worker at all, which is its own answer
        $this->assertStringContainsString('cannot receive this yet', $display);
        $this->assertStringContainsString('no service worker', $display);
        $this->assertStringContainsString('delivered to one', $display);
    }

    private function rootPath(): string
    {
        return $this->root;
    }

    /** @param array<string, mixed> $input */
    private function generate(array $input): CommandTester
    {
        $command = new class ($this->root) extends PushVapidGenerate {
            public function __construct(private string $where)
            {
                parent::__construct();
            }

            protected function root(): string { return $this->where; }
        };

        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }
}
