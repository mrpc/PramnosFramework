<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\AuthTwoFactorCleanup;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * What `auth:twofactor-cleanup` actually sweeps.
 *
 * The command has tests, and all of them override `sweeps()` — so what had never run is the map
 * itself: which two tables are swept, and which service method sweeps each. The existing tests
 * prove the *loop* handles a failure per sweep; they say nothing about the sweeps being the right
 * ones, because the sweeps were the test's.
 *
 * That matters more than the ten statements. These are deletes. A sweep pointed at the wrong table
 * would delete rows nobody asked it to, and a sweep pointed at the right table with the wrong
 * predicate would delete live second factors — both while reporting success, because a `DELETE`
 * that matches nothing and a `DELETE` that matches everything look identical from the outside.
 *
 * So each sweep is run against real rows, with one row that must go and one that must stay.
 *
 * Runs on every backend: {@see TwoFactorSweepsPostgreSQLTest} re-runs it against
 * PostgreSQL/TimescaleDB, where `authserver.` is a real schema rather than a table-name prefix — so
 * "the sweep found its table" is a different claim on each.
 */
#[CoversClass(AuthTwoFactorCleanup::class)]
class TwoFactorSweepsTest extends BaseTestCase
{
    private $db;

    /** A user id nothing else in the suite uses. */
    private const OWNER = 918273;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        $app = Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();

        try {
            if (!$this->db->connected) {
                $this->db->connect();
            }
        } catch (\Throwable $exception) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }
        $app->database = $this->db;

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateTwofactorSetupTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateTwofactorEmailCodesTable::class,
        ], $this->db);

        $this->deleteOwnRows();
    }

    protected function tearDown(): void
    {
        $this->deleteOwnRows();
        parent::tearDown();
    }

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** Removes this class's rows from both tables, and nothing else's. */
    private function deleteOwnRows(): void
    {
        foreach (['authserver.twofactor_email_codes', 'authserver.twofactor_setup'] as $table) {
            try {
                $this->db->queryBuilder()->table($table)->where('userid', self::OWNER)->delete();
            } catch (\Throwable) {
                // The table may not exist on this backend; the sweep tests then skip.
            }
        }
    }

    /** The sweeps, from the command's own map. */
    private function sweeps(): array
    {
        return (new \ReflectionMethod(AuthTwoFactorCleanup::class, 'sweeps'))
            ->invoke(new AuthTwoFactorCleanup());
    }

    /** How many of this owner's rows a table holds. */
    private function rowsFor(string $table): int
    {
        $row = $this->db->queryBuilder()
            ->table($table)
            ->where('userid', self::OWNER)
            ->first();

        return $row && $row->numRows > 0 ? 1 : 0;
    }

    /**
     * The map names both tables, keyed by the name the command reports.
     *
     * The keys are operator-facing: the loop reports "sweeping <key>" and names the key that
     * failed, so a renamed key changes a message somebody reads in a schedule log to work out what
     * broke.
     */
    public function testBothTablesAreSweptUnderTheNamesTheCommandReports(): void
    {
        // Act
        $sweeps = $this->sweeps();

        // Assert
        $this->assertSame(
            ['twofactor_email_codes', 'twofactor_setup'],
            array_keys($sweeps),
            'the sweep names changed, and the schedule log names them'
        );

        foreach ($sweeps as $name => $sweep) {
            $this->assertIsCallable($sweep, $name . ' is not runnable');
        }
    }

    /**
     * The email-code sweep deletes an expired code and leaves a live one.
     *
     * Both halves, because a delete that matches everything and one that matches nothing are
     * indistinguishable from the outside — and the row that must survive is somebody in the middle
     * of signing in.
     */
    public function testTheEmailCodeSweepDeletesOnlyExpiredCodes(): void
    {
        // Arrange
        $table = 'authserver.twofactor_email_codes';
        $this->insertEmailCode($table, time() - 3600);   // expired an hour ago

        $sweeps = $this->sweeps();
        ($sweeps['twofactor_email_codes'])();

        // Assert — the expired one is gone
        $this->assertSame(0, $this->rowsFor($table), 'an expired code survived the sweep');

        // Arrange — and a live one
        $this->insertEmailCode($table, time() + 3600);

        // Act
        ($sweeps['twofactor_email_codes'])();

        // Assert
        $this->assertSame(1, $this->rowsFor($table), 'a live code was deleted mid-login');
    }

    /**
     * The setup sweep deletes a *used* session even when it has not expired.
     *
     * The predicate is `used = 1` **or** expired, and the first half is the interesting one: a
     * setup session that has done its job is rubbish immediately, and leaving it would let the same
     * temporary secret be presented again inside its fifteen minutes.
     */
    public function testTheSetupSweepDeletesAUsedSessionBeforeItExpires(): void
    {
        // Arrange — used, and still well inside its TTL
        $table = 'authserver.twofactor_setup';
        $this->insertSetupSession($table, used: 1, expiresAt: time() + 900);

        // Act
        ($this->sweeps()['twofactor_setup'])();

        // Assert
        $this->assertSame(0, $this->rowsFor($table), 'a used setup session was left behind');
    }

    /**
     * And it leaves an unused session that has not expired.
     *
     * Somebody halfway through scanning a QR code. Deleting that is a setup flow that fails at the
     * last step with nothing to explain it.
     */
    public function testTheSetupSweepLeavesAnUnusedUnexpiredSession(): void
    {
        // Arrange
        $table = 'authserver.twofactor_setup';
        $this->insertSetupSession($table, used: 0, expiresAt: time() + 900);

        // Act
        ($this->sweeps()['twofactor_setup'])();

        // Assert
        $this->assertSame(1, $this->rowsFor($table), 'somebody mid-setup had their session swept');
    }

    /**
     * Running a sweep on an empty table is not an error.
     *
     * The ordinary case on any installation that is up to date, and the schedule runs this daily.
     */
    public function testASweepOverNothingSucceeds(): void
    {
        // Act + Assert — no exception is the assertion
        foreach ($this->sweeps() as $name => $sweep) {
            $sweep();
            $this->addToAssertionCount(1);
        }
    }

    private function insertEmailCode(string $table, int $expiresAt): void
    {
        $this->db->queryBuilder()->table($table)->insert([
            'userid'     => self::OWNER,
            'purpose'    => 'login',
            'code_hash'  => hash('sha256', 'not-a-real-code'),
            'expires_at' => $expiresAt,
            'attempts'   => 0,
            'created_at' => time(),
        ]);
    }

    private function insertSetupSession(string $table, int $used, int $expiresAt): void
    {
        $this->db->queryBuilder()->table($table)->insert([
            'userid'      => self::OWNER,
            'temp_secret' => 'JBSWY3DPEHPK3PXP',
            'used'        => $used,
            'expires_at'  => $expiresAt,
            'created_at'  => time(),
        ]);
    }
}
