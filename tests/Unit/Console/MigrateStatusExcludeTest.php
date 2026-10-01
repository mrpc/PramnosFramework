<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Console\Commands\MigrateStatus;
use Pramnos\Database\MigrationLoader;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `migrate:status` with `'migrations' => ['exclude' => [...]]`.
 *
 * An exclusion is an application saying a framework table is its own. The report has to
 * show the excluded migration as skipped, never as pending, and say what an exclusion
 * cannot do by itself: a later migration that alters the same table still runs. It also
 * has to catch a typo, because a misspelt slug excludes nothing and nothing else says so.
 *
 * No database is used, as in {@see MigrateStatusScopeTest}: the scope is answerable without
 * a connection, and the framework tree this test reads is real.
 */
#[CoversClass(MigrateStatus::class)]
#[CoversClass(MigrationLoader::class)]
class MigrateStatusExcludeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
        FeatureRegistry::reset();
        FeatureRegistry::initDefaults();
        FeatureRegistry::loadFromConfig(['auth']);
    }

    protected function tearDown(): void
    {
        FeatureRegistry::reset();
    }

    /**
     * Runs `migrate:status` for an application whose app.php is $applicationInfo and whose
     * database was never opened.
     *
     * @param array<string, mixed> $applicationInfo
     */
    private function reportFor(array $applicationInfo): string
    {
        $consoleApp = new class ('Test', '0.0') extends \Pramnos\Console\Application {
            /** Straight to Symfony's constructor: no command registration, no side effects. */
            public function __construct(string $name, string $version)
            {
                \Symfony\Component\Console\Application::__construct($name, $version);
                $this->internalApplication = new class extends \Pramnos\Application\Application {
                    /** @var \Pramnos\Database\Database|null */
                    public $database = null;

                    /** No initialisation: the report needs only applicationInfo. */
                    public function __construct()
                    {
                    }
                };
            }
        };
        $consoleApp->internalApplication->applicationInfo = $applicationInfo;
        $consoleApp->add(new MigrateStatus());

        $tester = new CommandTester($consoleApp->find('migrate:status'));
        $this->assertSame(0, $tester->execute([]));

        return $tester->getDisplay();
    }

    /**
     * The Status cell of the row for $slug, without its parenthesised reason.
     */
    private function statusOf(string $display, string $slug): string
    {
        foreach (explode("\n", $display) as $line) {
            $cells = array_map('trim', explode('|', $line));
            if (($cells[1] ?? '') !== $slug) {
                continue;
            }

            return $cells[4] ?? '';
        }

        return '';
    }

    /**
     * An excluded migration is reported as skipped, with the exclusion as the reason.
     */
    public function testAnExcludedMigrationIsSkippedNotPending(): void
    {
        // Arrange & Act
        $display = $this->reportFor([
            'features'   => ['auth'],
            'migrations' => ['exclude' => ['create_usernotes_table']],
        ]);

        // Assert
        $this->assertSame('Skipped (excluded)', $this->statusOf($display, 'create_usernotes_table'));
        // A neighbour that is not excluded is still pending — the list is not a feature gate.
        $this->assertSame('Pending', $this->statusOf($display, 'create_userdetails_table'));
    }

    /**
     * Excluding the migration that creates a table names the pending ones that still alter it.
     *
     * The case the exclusion alone gets wrong: `usertokens` excluded at creation, and two
     * later migrations that add columns to it would still run against a table the
     * application said was its own.
     */
    public function testPendingMigrationsOnTheSameTableAreNamed(): void
    {
        // Arrange & Act
        $display = $this->reportFor([
            'features'   => ['auth'],
            'migrations' => ['exclude' => ['create_usertokens_table']],
        ]);

        // Assert — the advice names the table and both migrations that change it
        $flat = preg_replace('/\s+/', ' ', $display);
        $this->assertStringContainsString('usertokens is excluded (create_usertokens_table), but', $flat);
        $this->assertStringContainsString('add_token_lookup_to_usertokens', $flat);
        $this->assertStringContainsString('add_oidc_context_to_usertokens', $flat);
        // Said once for the table, not once per migration.
        $this->assertSame(1, substr_count($flat, 'usertokens is excluded'));
    }

    /**
     * Once the later migrations are excluded too, there is nothing left to warn about.
     */
    public function testNoAdviceWhenEveryWriterOfTheTableIsExcluded(): void
    {
        // Arrange & Act
        $display = $this->reportFor([
            'features'   => ['auth'],
            'migrations' => ['exclude' => [
                'create_usertokens_table',
                'add_token_lookup_to_usertokens',
                'add_oidc_context_to_usertokens',
                'add_missing_foreign_keys_to_existing_tables',
                'cascade_usertokens_application',
            ]],
        ]);

        // Assert — nothing left on usertokens (another table may still be advised about)
        $this->assertStringNotContainsString('usertokens is excluded', preg_replace('/\s+/', ' ', $display));
    }

    /**
     * A slug that matches no migration is reported — a typo would otherwise exclude nothing.
     */
    public function testAnUnknownSlugIsReported(): void
    {
        // Arrange & Act
        $display = $this->reportFor([
            'features'   => ['auth'],
            'migrations' => ['exclude' => ['create_usernote_table']],
        ]);

        // Assert
        $this->assertStringContainsString('"create_usernote_table", which matches no migration', $display);
    }

    /**
     * A slug from a feature this installation has switched off is not called unknown.
     *
     * The migration exists, only not in scope here, and calling it a typo would send
     * somebody looking for a mistake they did not make.
     */
    public function testASlugInADisabledFeatureIsNotUnknown(): void
    {
        // Arrange — broadcasting is off; its migration exists on disk
        $base  = MigrationLoader::resolveFrameworkMigrationsBase();
        $slugs = array_keys(MigrationLoader::slugsFromDirectories([$base . '/broadcasting']));
        $this->assertNotEmpty($slugs, 'the broadcasting feature ships a migration');

        // Act
        $unknown = MigrationLoader::unknownExclusions([$slugs[0]], [$base . '/auth']);

        // Assert
        $this->assertSame([], $unknown);
    }

    /**
     * The tables a migration writes are read from its schema-builder calls, prefix removed.
     */
    public function testTablesWrittenByReadsTheSchemaCalls(): void
    {
        // Arrange — a real framework migration that alters usertokens with #PREFIX#
        $base = MigrationLoader::resolveFrameworkMigrationsBase();
        require_once glob($base . '/auth/*_add_oidc_context_to_usertokens.php')[0];
        $migration = (new \ReflectionClass(\Pramnos\Framework\Migrations\Auth\AddOidcContextToUsertokens::class))
            ->newInstanceWithoutConstructor();

        // Act
        $tables = MigrationLoader::tablesWrittenBy($migration);

        // Assert
        $this->assertSame(['usertokens'], $tables);
    }

    /**
     * With nothing excluded there is nothing to check, and no directory is read.
     */
    public function testNoExclusionsMeansNoUnknownOnes(): void
    {
        // Act & Assert — a directory that does not exist proves nothing was scanned
        $this->assertSame([], MigrationLoader::unknownExclusions([], ['/nonexistent']));
    }
}
