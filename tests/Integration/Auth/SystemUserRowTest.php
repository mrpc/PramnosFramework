<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application as CoreApplication;
use Pramnos\Application\Settings;
use Pramnos\Auth\Application as AuthApplication;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The machine account an application's client-credentials tokens hang on.
 *
 * `createSystemUserRow()` is a documented seam — the one thing in that class needing a database —
 * so ten statements had never run: every test of `systemUserId()` overrides it, including the one
 * written earlier today.
 *
 * What the row must be is the substance. It is an account that can hold tokens and can never be
 * used to sign in as anybody:
 *
 * - **`usertype` 1**, below every administrative threshold, so a token issued to an application
 *   cannot be mistaken for one issued to an operator;
 * - **a generated `sys_*` username** with sixteen hex characters from `random_bytes`, because two
 *   applications registering in the same second must not collide on it;
 * - **`active` and `validated`**, or the account would fail the checks a token presentation makes
 *   and every client-credentials call would be refused for a reason no log explains.
 *
 * Runs on every backend: {@see SystemUserRowPostgreSQLTest} re-runs it against
 * PostgreSQL/TimescaleDB, where the id comes from a sequence rather than `LAST_INSERT_ID()`.
 */
#[CoversClass(AuthApplication::class)]
class SystemUserRowTest extends BaseTestCase
{
    private $db;

    private mixed $savedDatabase = null;

    /** @var list<int> Rows this test created */
    private array $created = [];

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());

        $saved = &\Pramnos\Database\Database::getInstance();
        $this->savedDatabase = $saved;
        $saved = null;

        $this->db = Factory::getDatabase();

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

        CoreApplication::getInstance()->database = $this->db;
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $userid) {
            try {
                $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $userid)->delete();
            } catch (\Throwable) {
                // Already gone.
            }
        }
        $this->created = [];

        $restore = &\Pramnos\Database\Database::getInstance();
        $restore = $this->savedDatabase;

        parent::tearDown();
    }

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** Creates a row through the real seam and remembers it for cleanup. */
    private function createRow(): int
    {
        $application = new AuthApplication(new \Pramnos\Application\Controller());
        $application->appid = 9;

        $id = (new \ReflectionMethod(AuthApplication::class, 'createSystemUserRow'))
            ->invoke($application);

        if ($id > 0) {
            $this->created[] = $id;
        }

        return $id;
    }

    /** The row is created and its id comes back. */
    public function testTheRowIsCreatedAndItsIdComesBack(): void
    {
        // Act
        $id = $this->createRow();

        // Assert
        $this->assertGreaterThan(1, $id, 'the id must be above the guest and system rows');

        $row = $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $id)->first();
        $this->assertTrue($row && $row->numRows > 0, 'no row was written');
    }

    /**
     * The account is `usertype` 1 — below every administrative threshold.
     *
     * The reason a token issued to an application can never be mistaken for one issued to an
     * operator. A machine account at 90 would pass every admin check in the framework.
     */
    public function testTheAccountIsBelowEveryAdministrativeThreshold(): void
    {
        // Act
        $id  = $this->createRow();
        $row = $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $id)->first();

        // Assert
        $this->assertSame(1, (int) $row->fields['usertype']);
    }

    /**
     * The account is active and validated.
     *
     * Or a token presentation fails the account checks and every client-credentials call is
     * refused — with nothing in the log naming the account as the reason.
     */
    public function testTheAccountIsUsable(): void
    {
        // Act
        $id  = $this->createRow();
        $row = $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $id)->first();

        // Assert
        $this->assertSame(1, (int) $row->fields['active']);
        $this->assertSame(1, (int) $row->fields['validated']);
    }

    /**
     * The username is generated, and two are never the same.
     *
     * Sixteen hex characters from `random_bytes`. Two applications registering in the same second
     * must not collide — and `users.username` is unique, so a collision is a registration that
     * fails rather than one that shares an account.
     */
    public function testTheUsernameIsGeneratedAndUnique(): void
    {
        // Act
        $first  = $this->createRow();
        $second = $this->createRow();

        $one = $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $first)->first();
        $two = $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $second)->first();

        // Assert
        $this->assertStringStartsWith('sys_', (string) $one->fields['username']);
        $this->assertNotSame(
            (string) $one->fields['username'],
            (string) $two->fields['username'],
            'two machine accounts share a username'
        );
        $this->assertSame(
            20,
            strlen((string) $one->fields['username']),
            'sys_ plus sixteen hex characters'
        );
    }

    /**
     * The email is derived from the username, on a domain that resolves nowhere.
     *
     * `@system.local`. The column is often required and often unique, so it cannot be blank — and
     * it must not be a real address, because anything that mails an account would mail it.
     */
    public function testTheEmailIsDerivedAndUnroutable(): void
    {
        // Act
        $id  = $this->createRow();
        $row = $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $id)->first();

        // Assert
        $this->assertSame(
            $row->fields['username'] . '@system.local',
            (string) $row->fields['email']
        );
    }
}
