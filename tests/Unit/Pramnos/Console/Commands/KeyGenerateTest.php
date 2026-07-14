<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\KeyGenerate;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the key:generate console command.
 *
 * key:generate produces a strong application key and writes it to APP_KEY in the
 * project .env. It is fully testable without a database. Key invariants:
 *
 *  - --show prints a key and writes NO files.
 *  - A normal run writes APP_KEY into a fresh .env.
 *  - When APP_KEY already exists, the command refuses to overwrite it without --force.
 *  - --force replaces an existing APP_KEY (and warns about the destructive rotation).
 *  - A missing .env is seeded from .env.example when one is present.
 *  - Other .env lines are preserved when the key is set/replaced.
 *
 * All tests use a temporary directory as targetBaseDir so no real .env is touched.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(KeyGenerate::class)]
class KeyGenerateTest extends TestCase
{
    private string $projectDir;
    private CommandTester $tester;
    private ?string $originalPhpSelf = null;

    /**
     * Bootstrap: fresh temp dir + a KeyGenerate command whose targetBaseDir
     * points at it, attached to a minimal console application.
     */
    protected function setUp(): void
    {
        // Symfony's completion command reads $_SERVER['PHP_SELF'] in configure().
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->projectDir = sys_get_temp_dir() . '/pramnos_kg_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir, 0777, true);

        $command = new KeyGenerate();
        $command->targetBaseDir = $this->projectDir;

        $app = new Application('test', '1.0');
        $app->add($command);
        $app->setAutoExit(false);

        $this->tester = new CommandTester($app->find('key:generate'));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function envPath(): string
    {
        return $this->projectDir . DIRECTORY_SEPARATOR . '.env';
    }

    // =========================================================================
    // --show
    // =========================================================================

    /**
     * --show must print a key and write nothing: this is the "inspect only"
     * path used when a developer wants a key without mutating their .env.
     */
    public function testShowPrintsKeyAndWritesNothing(): void
    {
        // Act
        $exitCode = $this->tester->execute(['--show' => true]);

        // Assert — succeeds and prints a base64: key
        $this->assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        $this->assertStringContainsString('base64:', $this->tester->getDisplay());

        // Assert — no .env was created (the whole point of --show)
        $this->assertFileDoesNotExist($this->envPath(), '--show must not write any file');
    }

    // =========================================================================
    // Normal run
    // =========================================================================

    /**
     * A normal run against an empty temp dir must create a .env that contains a
     * non-empty APP_KEY line.
     */
    public function testWritesKeyIntoFreshEnv(): void
    {
        // Act
        $exitCode = $this->tester->execute([]);

        // Assert — success
        $this->assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());

        // Assert — .env now exists with a non-empty APP_KEY
        $this->assertFileExists($this->envPath());
        $contents = (string) file_get_contents($this->envPath());
        $this->assertMatchesRegularExpression(
            '/^APP_KEY=base64:.+$/m',
            $contents,
            '.env must contain a populated APP_KEY line'
        );
    }

    // =========================================================================
    // Refuse-without-force / --force
    // =========================================================================

    /**
     * When APP_KEY already has a value, running without --force must fail and
     * leave the existing key untouched. This prevents accidental key rotation,
     * which would invalidate encrypted data and sessions.
     */
    public function testRefusesToOverwriteExistingKeyWithoutForce(): void
    {
        // Arrange — a .env with an existing key
        file_put_contents($this->envPath(), "APP_KEY=base64:PREEXISTINGKEY\nFOO=bar\n");

        // Act
        $exitCode = $this->tester->execute([]);

        // Assert — refused
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--force', $this->tester->getDisplay());

        // Assert — the original key is still there (nothing was overwritten)
        $this->assertStringContainsString(
            'APP_KEY=base64:PREEXISTINGKEY',
            (string) file_get_contents($this->envPath())
        );
    }

    /**
     * With --force, an existing APP_KEY must be replaced with a new value while
     * other lines are preserved, and a rotation warning must be shown.
     */
    public function testForceReplacesExistingKeyAndPreservesOtherLines(): void
    {
        // Arrange
        file_put_contents($this->envPath(), "APP_KEY=base64:PREEXISTINGKEY\nFOO=bar\n");

        // Act
        $exitCode = $this->tester->execute(['--force' => true]);

        // Assert — success + destructive-rotation warning
        $this->assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        $this->assertStringContainsString('Warning', $this->tester->getDisplay());

        $contents = (string) file_get_contents($this->envPath());

        // Assert — the old key is gone and a new one is present
        $this->assertStringNotContainsString('PREEXISTINGKEY', $contents, 'old key must be replaced');
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:.+$/m', $contents);

        // Assert — unrelated lines survive the rewrite
        $this->assertStringContainsString('FOO=bar', $contents, 'other .env lines must be preserved');
    }

    // =========================================================================
    // .env.example seeding
    // =========================================================================

    /**
     * When .env is missing but .env.example exists, the command must seed the
     * new .env from the example (so app-specific defaults are retained) and set
     * the APP_KEY line in it.
     */
    public function testSeedsEnvFromExampleWhenEnvMissing(): void
    {
        // Arrange — only an example file, with an empty APP_KEY placeholder
        file_put_contents(
            $this->projectDir . DIRECTORY_SEPARATOR . '.env.example',
            "APP_KEY=\nDB_HOST=localhost\n"
        );

        // Act
        $exitCode = $this->tester->execute([]);

        // Assert — success and .env created from the example
        $this->assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        $this->assertFileExists($this->envPath());

        $contents = (string) file_get_contents($this->envPath());

        // Assert — example content carried over and the empty key is now populated
        $this->assertStringContainsString('DB_HOST=localhost', $contents);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:.+$/m', $contents);
    }

    /**
     * An empty APP_KEY value counts as "unset", so a normal run must populate it
     * without requiring --force.
     */
    public function testEmptyKeyIsTreatedAsUnset(): void
    {
        // Arrange — a present-but-empty key line
        file_put_contents($this->envPath(), "APP_KEY=\n");

        // Act — no --force
        $exitCode = $this->tester->execute([]);

        // Assert — succeeds and fills the key in
        $this->assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        $this->assertMatchesRegularExpression(
            '/^APP_KEY=base64:.+$/m',
            (string) file_get_contents($this->envPath())
        );
    }
}
