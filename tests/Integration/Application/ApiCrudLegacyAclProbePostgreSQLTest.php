<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApiCrudController;
use Pramnos\Database\Database;

/**
 * Asking whether the legacy ACL table exists must not look like a fault.
 *
 * `ApiCrudController::legacyAclExists()` used to answer the question with
 * `SELECT EXISTS(SELECT 1 FROM permissions)`. A select against a table that is not there is
 * an *error* on its way to being an answer: PostgreSQL raises
 * `relation "permissions" does not exist`, and the connection logs it before the `catch`
 * inside the probe ever sees it. So every API request on an installation with no legacy ACL
 * — which is every new installation — wrote a line reading like a failure. On one server it
 * was the single most frequent error in the log, and it was the framework asking a question.
 *
 * The return value was always correct, which is exactly why it survived: the answer was
 * right and the log said something had gone wrong.
 *
 * This has to be a PostgreSQL test rather than a unit test. It is a **dialect** fact: MySQL's
 * failed select produces no such log line, so the same bug is invisible there and a mocked
 * connection cannot report it at all.
 *
 * Requires the Docker TimescaleDB/PostgreSQL container (host: timescaledb).
 */
class ApiCrudLegacyAclProbePostgreSQLTest extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';
        $this->db->connect(true);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    /**
     * The probe answers "no" for a table that is not there, and logs nothing doing it.
     *
     * The log file is the assertion, because the *answer* was never the bug.
     */
    public function testTheProbeAnswersWithoutLoggingAnError(): void
    {
        // Arrange
        $log = (new \ReflectionMethod(\Pramnos\Logs\LogManager::class, 'getDefaultLogPath'))
            ->invoke(null) . DIRECTORY_SEPARATOR . 'pramnosframework.log';
        clearstatcache(true, $log);
        $before = is_file($log) ? (int) filesize($log) : 0;

        // Act
        $answer = (new LegacyAclProbe($this->db))->probe();

        // Assert
        $this->assertFalse($answer, 'a table no migration creates does not exist');

        clearstatcache(true, $log);
        $this->assertSame(
            $before,
            is_file($log) ? (int) filesize($log) : 0,
            'a table-existence check must not write an error to the log'
        );
    }

    /**
     * And the reason the old form could not be kept: on this driver it does log.
     *
     * Asserted rather than described, because it is the whole argument for the change. If
     * PostgreSQL ever stopped logging a failed select, this test would fail and the comment
     * in `legacyAclExists()` would need rewriting rather than trusting.
     */
    public function testASelectAgainstAMissingTableIsWhatUsedToBeLogged(): void
    {
        // Arrange
        $log = (new \ReflectionMethod(\Pramnos\Logs\LogManager::class, 'getDefaultLogPath'))
            ->invoke(null) . DIRECTORY_SEPARATOR . 'pramnosframework.log';
        clearstatcache(true, $log);
        $before = is_file($log) ? (int) filesize($log) : 0;

        // Act — the discarded implementation, verbatim
        try {
            $this->db->queryBuilder()
                ->table('#PREFIX#a_table_no_migration_creates')
                ->exists();
        } catch (\Throwable) {
            // As the probe did: the answer arrives, the log line has already been written.
        }

        // Assert
        clearstatcache(true, $log);
        $this->assertGreaterThan(
            $before,
            is_file($log) ? (int) filesize($log) : 0,
            'if this stops being true, the probe no longer needs its comment'
        );
    }
}

/**
 * The real `legacyAclExists()`, with the two seams pointed somewhere a test can control.
 *
 * `legacyAclExists()` itself is deliberately not overridden — it is what is under test.
 */
class LegacyAclProbe extends ApiCrudController
{
    public function __construct(private Database $connection)
    {
    }

    protected function db(): Database
    {
        return $this->connection;
    }

    /** A table no migration creates, which is the state the bug was visible in. */
    protected function legacyAclTable(): string
    {
        return '#PREFIX#a_table_no_migration_creates';
    }

    public function probe(): bool
    {
        return $this->legacyAclExists();
    }
}
