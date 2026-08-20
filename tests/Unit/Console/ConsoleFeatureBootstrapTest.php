<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application as InternalApplication;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Console\Application as ConsoleApplication;

/**
 * The console bootstrap must activate the features declared in app.php.
 *
 * `Application::getInstance()` reads app.php into `applicationInfo`, but the call
 * that turns its `features` list into FeatureRegistry state lived only in
 * `Application::init()` — which the web lifecycle runs and a console command does
 * not. `FeatureRegistry::isEnabled()` therefore answered false for every feature
 * inside every command, no matter what app.php said.
 *
 * The consequence was not cosmetic. A long-running daemon deciding anything from
 * a feature flag reached the opposite conclusion from the web application reading
 * the same file, and nothing anywhere reported that the two disagreed. Feature
 * state has to mean one thing per installation, not one thing per entry point.
 */
#[CoversClass(ConsoleApplication::class)]
class ConsoleFeatureBootstrapTest extends TestCase
{
    /** @var array<string,mixed>|null Saved applicationInfo to restore. */
    private ?array $savedInfo = null;

    /** @var array<string,string>|null Saved $_SERVER to restore. */
    private ?array $savedServer = null;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $app = InternalApplication::getInstance();
        $this->savedInfo = $app->applicationInfo;
    }

    protected function tearDown(): void
    {
        if ($this->savedInfo !== null) {
            InternalApplication::getInstance()->applicationInfo = $this->savedInfo;
        }
        if ($this->savedServer !== null) {
            $_SERVER = $this->savedServer;
        }

        // Leave the registry loaded with its defaults rather than emptied, so a
        // later test in the same process does not inherit a blank registry.
        FeatureRegistry::reset();
    }

    /**
     * Constructing the console application enables every feature app.php lists.
     *
     * The registry is emptied first and asserted empty, so the assertion after
     * construction can only pass because the bootstrap loaded the list — not
     * because some earlier test happened to enable the same feature.
     */
    public function testConsoleBootstrapEnablesFeaturesFromAppConfig(): void
    {
        // Arrange
        $app = InternalApplication::getInstance();
        $app->applicationInfo['features'] = ['queue', 'broadcasting'];

        FeatureRegistry::reset();
        $this->assertFalse(
            FeatureRegistry::isEnabled('queue'),
            'precondition: the registry starts with nothing enabled'
        );

        // Act
        new ConsoleApplication();

        // Assert
        $this->assertTrue(FeatureRegistry::isEnabled('queue'));
        $this->assertTrue(FeatureRegistry::isEnabled('broadcasting'));
        // A feature that was not declared must stay off, or the fix would be
        // "enable everything" rather than "honour the file".
        $this->assertFalse(FeatureRegistry::isEnabled('authserver'));
    }

    /**
     * An app.php with no `features` key is not an error — the registry simply
     * keeps only the always-on `core` feature.
     *
     * Covers the `?? []` branch, which is the state of every project that has not
     * opted into any feature.
     */
    public function testConsoleBootstrapToleratesMissingFeaturesKey(): void
    {
        // Arrange
        $app = InternalApplication::getInstance();
        unset($app->applicationInfo['features']);
        FeatureRegistry::reset();

        // Act
        new ConsoleApplication();

        // Assert
        $this->assertTrue(FeatureRegistry::isEnabled('core'), 'core is always enabled');
        $this->assertFalse(FeatureRegistry::isEnabled('queue'));
    }
}
