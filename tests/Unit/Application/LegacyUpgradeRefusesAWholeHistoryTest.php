<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\MigrationRunner;

/**
 * The second replay came through the legacy path, in a web request.
 *
 * Two days after 91 migrations were replayed through `migrate`, a single web request on the
 * same installation reached `Application::exec()` → `checkversion()` → `upgrade()`. That
 * path keys the ledger by `$version` rather than by slug, found no `0.137` … `0.147` rows
 * and replayed eleven more.
 *
 * One of them decompresses a 70-chunk hypertable, and it did — in a client request, behind
 * the maintenance mode `runMigration()` correctly raises, at a measured cost of roughly
 * thirty to sixty meter readings that were never recorded. `migrate:status` reported "0
 * pending" throughout, because it reads slugs.
 *
 * So the guard has to be at **both** entry points, and this is the one with no status
 * command to warn anybody first. It also defeated a deliberate guard the application had
 * set — `autoExecute = false` on its own copy of that decompression, precisely to keep it
 * out of a client request. The work happened in a client request anyway, through a
 * different migration, on the path that does not consult the new runner at all.
 */
#[CoversClass(Application::class)]
class LegacyUpgradeRefusesAWholeHistoryTest extends TestCase
{
    /**
     * An application whose ledger knows nothing and whose migrations are counted, not run.
     *
     * @param int $count How many entries `app/migrations.php` holds
     */
    private function probe(int $count): object
    {
        $map = [];
        for ($i = 1; $i <= $count; $i++) {
            $map['0.' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)] = 'Migration' . $i;
        }

        return new class ($map) extends Application {
            /** @var list<string> Migrations this run actually executed. */
            public array $executed = [];

            /** @param array<string,string> $map */
            public function __construct(private array $map)
            {
                // The real constructor reads configuration, connects and starts a session.
                // None of that is involved in deciding whether to run a history.
            }

            protected function legacyMigrationMap(): array
            {
                return $this->map;
            }

            /**
             * Nothing is recorded — which is the state that produced the replay.
             *
             * @param string $version
             */
            public function checkversion($version = '')
            {
                return false;
            }

            public function runMigration($class)
            {
                $this->executed[] = (string) $class;
            }
        };
    }

    /**
     * A whole history is not auto-run in a web request.
     *
     * The reported incident: eleven unrecorded migrations, one of them a decompression, on
     * a path that runs while somebody is waiting for a page.
     */
    public function testAWholeHistoryIsNotAutoRun(): void
    {
        // Arrange
        $app = $this->probe(MigrationRunner::WHOLE_HISTORY + 1);

        // Act
        $app->upgrade();

        // Assert
        $this->assertSame(
            [],
            $app->executed,
            'a history was auto-run inside a request; the second replay is still possible'
        );
    }

    /**
     * Exactly the threshold is already a history.
     *
     * Boundary, and asserted because an off-by-one here is the difference between the guard
     * firing and not on the run that matters.
     */
    public function testTheThresholdItselfIsRefused(): void
    {
        // Arrange
        $app = $this->probe(MigrationRunner::WHOLE_HISTORY);

        // Act
        $app->upgrade();

        // Assert
        $this->assertSame([], $app->executed);
    }

    /**
     * And an ordinary upgrade still runs.
     *
     * The half that must not break: this path exists so that deploying an application with
     * one or two new migrations needs no shell. Refusing those would move every deployment
     * to the command line, which is a bigger change than the one being made.
     */
    public function testAnOrdinaryUpgradeStillRuns(): void
    {
        // Arrange — below the threshold
        $app = $this->probe(MigrationRunner::WHOLE_HISTORY - 1);

        // Act
        $app->upgrade();

        // Assert
        $this->assertCount(MigrationRunner::WHOLE_HISTORY - 1, $app->executed);
    }

    /**
     * A single new migration is the common case and runs.
     */
    public function testOneNewMigrationRuns(): void
    {
        // Arrange
        $app = $this->probe(1);

        // Act
        $app->upgrade();

        // Assert
        $this->assertSame(['Migration1'], $app->executed);
    }

    /**
     * Nothing declared, nothing run — and no refusal either.
     *
     * The control for the count: a guard that fired on an empty map would break every
     * application that has no `migrations.php` at all, which is most of them.
     */
    public function testAnApplicationWithNoLegacyMigrationsIsUnaffected(): void
    {
        // Arrange
        $app = $this->probe(0);

        // Act
        $app->upgrade();

        // Assert
        $this->assertSame([], $app->executed);
    }
}
