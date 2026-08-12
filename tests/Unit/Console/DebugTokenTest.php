<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\DebugToken;
use Pramnos\Debug\DebugAccess;
use Symfony\Component\Console\Application as SymfonyApp;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command that hands out a debug grant.
 *
 * What it prints is a credential for a live server, so the tests care about two
 * things: that the link it prints actually works, and that it refuses — visibly
 * — when there is no key to sign with, rather than printing something that looks
 * like a token and is not.
 */
#[CoversClass(DebugToken::class)]
class DebugTokenTest extends TestCase
{
    /** @var string|null The APP_KEY as the environment had it */
    private ?string $originalKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Symfony's console reads PHP_SELF and SCRIPT_NAME to name itself; under
        // PHPUnit neither is set, and basename(null) is deprecated on PHP 8.
        foreach (['PHP_SELF', 'SCRIPT_NAME'] as $key) {
            if (!isset($_SERVER[$key])) {
                $_SERVER[$key] = 'pramnos';
            }
        }

        $this->originalKey = getenv('APP_KEY') === false ? null : (string) getenv('APP_KEY');
        putenv('APP_KEY=test-key-for-debug-token');
        $_ENV['APP_KEY'] = 'test-key-for-debug-token';

        $_GET    = [];
        $_COOKIE = [];
        DebugAccess::reset();
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            putenv('APP_KEY=' . $this->originalKey);
            $_ENV['APP_KEY'] = $this->originalKey;
        }

        DebugAccess::reset();
        parent::tearDown();
    }

    /**
     * Wrap the command in a tester.
     */
    private function tester(): CommandTester
    {
        $command = new DebugToken();
        (new SymfonyApp())->add($command);

        return new CommandTester($command);
    }

    /**
     * Registered under the name an operator types.
     */
    public function testItIsNamedDebugToken(): void
    {
        // Arrange & Act
        $command = new DebugToken();

        // Assert
        $this->assertSame('debug:token', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('ttl'));
        $this->assertTrue($command->getDefinition()->hasOption('url'));
    }

    /**
     * The printed link carries a token that actually verifies.
     *
     * The end-to-end check of the whole feature: what the command prints is what
     * DebugAccess will accept. A test that only asserted "some output" would pass
     * for a command that printed a plausible-looking string signed with the
     * wrong thing.
     */
    public function testThePrintedTokenVerifies(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['--url' => 'https://example.com/']);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertMatchesRegularExpression('/_debug=(\d+\.[0-9a-f]{64})/', $text, $text);

        preg_match('/_debug=(\d+\.[0-9a-f]{64})/', $text, $matches);
        $this->assertTrue(
            DebugAccess::verify($matches[1]),
            'The token the operator is handed must be one the application accepts'
        );
    }

    /**
     * Without an application key it refuses, and says why.
     *
     * Printing an unusable token would send somebody to a live server to debug
     * why the toolbar "does not work".
     */
    public function testItRefusesWithoutAnApplicationKey(): void
    {
        // Arrange
        putenv('APP_KEY');
        unset($_ENV['APP_KEY']);
        $tester = $this->tester();

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('key:generate', $tester->getDisplay());
    }

    /**
     * Durations are read in the units an operator would type.
     */
    public function testTtlAcceptsTheUsualUnits(): void
    {
        foreach (['90' => 90, '30m' => 1800, '2h' => 7200, '1d' => 43200] as $input => $expected) {
            // Arrange
            $tester = $this->tester();

            // Act
            $tester->execute(['--ttl' => (string) $input, '--url' => 'https://example.com/']);
            preg_match('/_debug=(\d+)\./', $tester->getDisplay(), $matches);

            // Assert — 1d is above the ceiling and comes back capped, which is
            // the point of asserting on the issued expiry rather than the input
            $this->assertEqualsWithDelta(
                time() + $expected,
                (int) $matches[1],
                5,
                'ttl: ' . $input
            );
        }
    }

    /**
     * A ttl it cannot read is an error, not a silent default.
     *
     * Quietly issuing an hour for `--ttl=2 hours` would give an operator a
     * shorter grant than they asked for and no clue that it happened.
     */
    public function testAnUnreadableTtlIsRefused(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['--ttl' => 'two hours']);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('--ttl', $tester->getDisplay());
    }

    /**
     * The output says the link is a credential.
     *
     * It opens the query log of a live server. Somebody pasting it into a chat
     * should have been told what it is.
     */
    public function testTheOutputWarnsThatTheLinkIsACredential(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $tester->execute(['--url' => 'https://example.com/']);
        $text = $tester->getDisplay();

        // Assert
        $this->assertStringContainsString('credential', $text);
        $this->assertStringContainsString('_debug=off', $text, 'and how to end it');
        $this->assertStringContainsString('Valid until', $text);
    }

    /**
     * An empty ttl is refused rather than treated as zero.
     */
    public function testAnEmptyTtlIsRefused(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['--ttl' => '']);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
    }

    /**
     * With no --url the command falls back through what it knows.
     *
     * A command that could not guess the host should still hand over the token —
     * the host is the part the operator can supply from memory, and the token is
     * the part they cannot.
     */
    public function testTheLinkIsStillPrintedWithoutAUrl(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $code = $tester->execute([]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertMatchesRegularExpression('/_debug=\d+\.[0-9a-f]{64}/', $text);
    }

    /**
     * The site URL setting is used when no --url is given.
     *
     * The common case on a real installation: the operator types
     * `debug:token` and gets a link they can click.
     */
    public function testTheSiteUrlSettingIsUsed(): void
    {
        // Arrange — remember what was there, so the shared static settings
        // store is left exactly as it was found. Restoring to '' instead would
        // leave a value behind that no other test put there.
        $original = \Pramnos\Application\Settings::getSetting('siteurl');
        \Pramnos\Application\Settings::setSetting('siteurl', 'https://live.example.com', false);
        $tester = $this->tester();

        try {
            // Act
            $tester->execute([]);

            // Assert — the trailing slash is added, not doubled
            $this->assertStringContainsString('https://live.example.com/?_debug=', $tester->getDisplay());
        } finally {
            \Pramnos\Application\Settings::setSetting('siteurl', $original, false);
        }
    }

    /**
     * A URL that already has a query string gets the parameter appended.
     */
    public function testAUrlWithAQueryStringGetsAnAmpersand(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $tester->execute(['--url' => 'https://example.com/orders?page=2']);

        // Assert
        $this->assertStringContainsString('?page=2&_debug=', $tester->getDisplay());
    }
}
