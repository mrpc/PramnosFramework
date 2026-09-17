<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\DatabaseCapabilities;
use Pramnos\Database\MigrationRunner;

/**
 * An application that asks for TimescaleDB gets it, or is told why not.
 *
 * WHAT: that the migration preamble creates the extension when the settings declare it,
 *       leaves it alone when they do not, and **stops the run** with instructions when it
 *       cannot be created.
 * WHY:  a scaffolded project declaring `'timescale' => true` had to be told, by a person,
 *       to run `CREATE EXTENSION timescaledb` before the first `migrate`. Nothing said so,
 *       and the wrong order is punished silently: every hypertable conversion is guarded,
 *       so it is recorded as applied having done nothing, and the schema ends up
 *       permanently behind its own history. Measured on one installation — the wrong order
 *       produced 7 hypertables and 3 permanently failed migrations; rebuilt in the right
 *       order, 10 and none.
 *
 *       Stopping is the point. The damage is not the missing extension; it is carrying on
 *       without it and recording success.
 *
 * A unit test with a double rather than a real database: what is being decided here is
 * *whether* to issue one statement and *what to do* when it does not take — and the
 * integration version of this cost a created-and-dropped database per test, which in a full
 * run exhausted PostgreSQL's connection limit and took nine unrelated tests with it. The
 * statement itself is one line and was verified by hand.
 */
#[CoversClass(MigrationRunner::class)]
class TimescaleExtensionIsEnsuredTest extends TestCase
{
    /**
     * With the flag set and no extension, the statement is issued.
     *
     * It succeeds outright in Docker and anywhere the application role is superuser —
     * which is most development environments, and removes the trap entirely for everybody
     * who never touches a production database.
     */
    public function testTheExtensionIsCreatedWhenTheApplicationAsksForIt(): void
    {
        // Arrange — absent at first, present once created.
        $statements = [];
        $db = $this->database(true, $statements, createSucceeds: true);

        // Act
        $this->ensure($db);

        // Assert
        $this->assertContains('CREATE EXTENSION IF NOT EXISTS timescaledb', $statements);
    }

    /**
     * Without the flag, nothing is created.
     *
     * An application that never asked for TimescaleDB must not have an extension installed
     * into its database as a side effect of running migrations.
     */
    public function testNothingIsCreatedWhenTheApplicationDidNotAskForIt(): void
    {
        // Arrange
        $statements = [];
        $db = $this->database(false, $statements, createSucceeds: true);

        // Act
        $this->ensure($db);

        // Assert
        $this->assertSame([], $statements);
    }

    /**
     * When the extension is already there, the statement is not issued.
     */
    public function testAnExistingExtensionIsLeftAlone(): void
    {
        // Arrange
        $statements = [];
        $db = $this->database(true, $statements, createSucceeds: true, alreadyInstalled: true);

        // Act
        $this->ensure($db);

        // Assert
        $this->assertSame([], $statements);
    }

    /**
     * When it cannot be created, the run stops and the message says what to do.
     *
     * The half that matters. `CREATE EXTENSION timescaledb` needs superuser — it is not a
     * trusted extension — and `shared_preload_libraries` must contain it, which needs a
     * restart. A role with neither is normal on shared and managed hosting, so the
     * statement fails on precisely the installations that need the help.
     *
     * The message is asserted, not just the exception: a stop nobody can act on is a stop
     * that gets worked around by deleting the check.
     */
    public function testWhenItCannotBeCreatedTheRunStopsAndSaysWhatToDo(): void
    {
        // Arrange — a role without the right, which is what managed hosting looks like.
        $statements = [];
        $db = $this->database(true, $statements, createSucceeds: false);

        // Act — captured rather than asserted inside a `catch`: PHPUnit's own failure
        // exception extends RuntimeException, so a `fail()` in the `try` would be caught
        // here and re-asserted as though it were the runner's.
        $message = null;
        try {
            $this->ensure($db);
        } catch (\RuntimeException $stopped) {
            $message = $stopped->getMessage();
        }

        // Assert
        $this->assertNotNull(
            $message,
            'the run must stop rather than migrate without the extension'
        );
        $this->assertStringContainsString('shared_preload_libraries', $message);
        $this->assertStringContainsString('CREATE EXTENSION timescaledb', $message);
        $this->assertStringContainsString("'timescale' => true", $message);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** Run just the preamble, which is where the decision is. */
    private function ensure(Database $db): void
    {
        $method = new \ReflectionMethod(MigrationRunner::class, 'ensureTimescaleExtension');
        $method->invoke(new MigrationRunner($db));
    }

    /**
     * A connection that records its statements and decides whether the CREATE takes.
     *
     * @param array<int, string> $statements Filled with every statement issued
     */
    private function database(
        bool $wantsTimescale,
        array &$statements,
        bool $createSucceeds,
        bool $alreadyInstalled = false
    ): Database {
        $installed = $alreadyInstalled;

        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $db->type      = 'postgresql';
        $db->timescale = $wantsTimescale;

        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$statements, &$installed, $createSucceeds) {
                if (str_contains($sql, 'CREATE EXTENSION')) {
                    $statements[] = trim($sql);
                    if (!$createSucceeds) {
                        throw new \Exception(
                            'ERROR:  permission denied to create extension "timescaledb"'
                        );
                    }
                    $installed = true;

                    return true;
                }

                // The capability probe.
                $result          = new \stdClass();
                $result->numRows = $installed ? 1 : 0;

                return $result;
            }
        );

        // Nothing cached from another test in this process.
        (new DatabaseCapabilities($db))->forgetDetected();

        return $db;
    }
}
