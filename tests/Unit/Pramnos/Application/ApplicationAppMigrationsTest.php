<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;

/**
 * Unit tests for the application-declared auto-migration directories feature:
 * Application::getApplicationMigrationDirs() and
 * Application::autoMigrationsIncludeFramework().
 *
 * WHAT: auto-run can scan directories an application declares in app.php
 *       (`'migrations' => ['paths' => [...], 'framework' => bool]`), in addition
 *       to — or, with 'framework' => false, instead of — the framework feature
 *       directories.
 * WHY: an application baseline (e.g. APP_PATH/Migrations) should auto-run on the
 *       same fingerprint fast-path as framework migrations, and an app whose own
 *       schema collides with a framework table (a bespoke `sessions` layout, for
 *       instance) must be able to opt out of the framework directories entirely
 *       without giving up auto-run for its own migrations.
 *
 * These are pure config-reading helpers (no DB), so they are unit-tested via an
 * anonymous subclass that injects applicationInfo and exposes the protected
 * methods. new Application('test_app') avoids the heavy getInstance() bootstrap.
 */
#[CoversClass(Application::class)]
class ApplicationAppMigrationsTest extends TestCase
{
    /**
     * @param array<string,mixed> $migrations The app.php 'migrations' section
     *                                         (null to omit the key entirely).
     */
    private function makeApp(?array $migrations): Application
    {
        return new class ('test_app', $migrations) extends Application {
            /** @param array<string,mixed>|null $migrations */
            public function __construct(string $appName, private ?array $migrations)
            {
                parent::__construct($appName);
                if ($this->migrations !== null) {
                    $this->applicationInfo['migrations'] = $this->migrations;
                } else {
                    unset($this->applicationInfo['migrations']);
                }
            }

            /** @return string[] */
            public function exposeAppDirs(): array
            {
                return $this->getApplicationMigrationDirs();
            }

            public function exposeIncludeFramework(): bool
            {
                return $this->autoMigrationsIncludeFramework();
            }
        };
    }

    /**
     * frameworkMigrationPool() is the on-demand resolution pool: it exposes ALL
     * framework migrations keyed by slug, NOT feature-gated and independent of
     * `migrations.framework`, so an app migration can declare a dependency on
     * any framework slug (e.g. a queue table) and have it pulled in on demand.
     */
    public function testFrameworkMigrationPoolIsKeyedBySlugAndUngated(): void
    {
        $pool = $this->makeApp(['framework' => false])->frameworkMigrationPool();

        // A known framework slug from a NON-core, NON-enabled feature (queue) is
        // present, proving the pool is not feature-gated.
        $this->assertArrayHasKey('create_delayed_jobs_table', $pool);
        $this->assertInstanceOf(\Pramnos\Database\Migration::class, $pool['create_delayed_jobs_table']);

        // Keys are slugs → the map the MigrationRunner looks dependencies up by.
        foreach ($pool as $slug => $migration) {
            $this->assertSame($slug, $migration->getSlug());
        }
    }

    /**
     * With no 'migrations' key, behaviour is unchanged: no app directories and
     * framework migrations still included — the backward-compatible default that
     * keeps existing applications running exactly as before.
     */
    public function testDefaultsAreBackwardCompatible(): void
    {
        $app = $this->makeApp(null);

        $this->assertSame([], $app->exposeAppDirs());
        $this->assertTrue($app->exposeIncludeFramework());
    }

    /**
     * Declared paths that exist on disk are returned (canonicalised); paths that
     * do not exist are silently skipped so a stale entry never breaks auto-run.
     */
    public function testExistingPathsAreReturnedAndMissingOnesSkipped(): void
    {
        $existing = __DIR__; // this test's own directory certainly exists
        $missing  = __DIR__ . '/does-not-exist-' . bin2hex(random_bytes(4));

        $app = $this->makeApp(['paths' => [$existing, $missing]]);

        $this->assertSame([realpath($existing)], $app->exposeAppDirs());
    }

    /**
     * 'framework' => false makes the app opt out of the framework feature
     * directories — the escape hatch for an app whose schema collides with a
     * framework table.
     */
    public function testFrameworkFalseOptsOut(): void
    {
        $app = $this->makeApp(['paths' => [__DIR__], 'framework' => false]);

        $this->assertFalse($app->exposeIncludeFramework());
    }

    /**
     * 'framework' => true is honoured explicitly (same as the default), and an
     * absent 'framework' key falls back to true.
     */
    public function testFrameworkTrueAndOmittedBothIncludeFramework(): void
    {
        $this->assertTrue($this->makeApp(['framework' => true])->exposeIncludeFramework());
        $this->assertTrue($this->makeApp(['paths' => [__DIR__]])->exposeIncludeFramework());
    }

    /**
     * A malformed 'migrations' section (non-array, or 'paths' not an array) is
     * tolerated as "no app directories" rather than throwing.
     */
    public function testMalformedConfigYieldsNoDirs(): void
    {
        $this->assertSame([], $this->makeApp(['paths' => 'not-an-array'])->exposeAppDirs());
        $this->assertSame([], $this->makeApp([])->exposeAppDirs());
    }
}
