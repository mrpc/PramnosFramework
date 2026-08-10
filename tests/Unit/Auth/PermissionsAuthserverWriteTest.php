<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Permissions;

/**
 * Records what a query builder was asked to do, without a database.
 */
class RecordingQueryBuilder
{
    /** @var array<string, mixed> The call log shared with the test. */
    public array $log;

    /**
     * @param array<string, mixed> $log Shared recorder, passed by reference
     */
    public function __construct(array &$log)
    {
        $this->log = &$log;
    }

    /** Record the target table. */
    public function table($table): static
    {
        $this->log['table'] = $table;

        return $this;
    }

    /** Record one equality condition. */
    public function where($column, $value = null): static
    {
        $this->log['where'][$column] = $value;

        return $this;
    }

    /** Record an IS NULL condition, which is not expressible via where(). */
    public function whereNull($column): static
    {
        $this->log['whereNull'][] = $column;

        return $this;
    }

    /** Record that a delete was issued. */
    public function delete(): bool
    {
        $this->log['deleted'] = true;

        return true;
    }

    /**
     * Record the inserted row.
     *
     * @param array<string, mixed> $values
     */
    public function insert(array $values): bool
    {
        $this->log['insert'] = $values;

        return true;
    }
}

/**
 * A connection that hands out recording query builders.
 */
class RecordingDatabase
{
    /** @var string Table prefix, as the real Database exposes it. */
    public string $prefix = '';

    /** @var array<string, mixed> The call log shared with the test. */
    public array $log = [];

    /** Hand out a builder writing into the shared log. */
    public function queryBuilder(): RecordingQueryBuilder
    {
        return new RecordingQueryBuilder($this->log);
    }

    /** The real Database flushes a query-cache category here. */
    public function cacheflush($category = null): bool
    {
        $this->log['cacheflushed'] = true;

        return true;
    }
}

/**
 * Exposes the store selection and the write path over a recording connection.
 */
class PermissionsWriteProbe extends Permissions
{
    /** @var RecordingDatabase The stand-in connection. */
    public RecordingDatabase $connection;

    /** @var array<string, bool> Which tables this "installation" has. */
    public array $tables = [];

    public function __construct()
    {
        // No parent constructor: nothing here needs an application context.
        $this->connection = new RecordingDatabase();
    }

    protected function db()
    {
        return $this->connection;
    }

    /** Expose the resolved store. */
    public function store(): string
    {
        return $this->activeStore();
    }

    /** How many times the store has been looked up. */
    public int $lookups = 0;

    protected function tableExists($database, $table)
    {
        $this->lookups++;

        return $this->tables[$table] ?? false;
    }
}

/**
 * Covers where `Pramnos\Auth\Permissions` writes, and which store it picks.
 *
 * The read path moved to `authserver.permissions` before the write path did, so
 * for a while `allow()` and `deny()` still targeted `<prefix>permissions` — a
 * table no migration creates. A grant could be neither made nor revoked on any
 * installation that had not hand-built it, and the class would happily report
 * "no such permission" about a grant it had just refused to store.
 */
class PermissionsAuthserverWriteTest extends TestCase
{
    /**
     * The maintained store is chosen whenever it exists.
     *
     * It ships with the `auth` feature, so every installation with users has
     * it, and it is what the rest of the framework reads and writes.
     */
    public function testTheNewStoreIsChosenWhenPresent(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = ['authserver.permissions' => true];

        // Act + Assert
        $this->assertSame('authserver', $permissions->store());
    }

    /**
     * The new store wins even when a legacy table exists beside it.
     *
     * An installation with both hand-built the old table before the new one
     * existed. Reading the new one is correct — everything else in the
     * framework does — and the migration guide covers moving the old rows.
     */
    public function testTheNewStoreWinsWhenBothExist(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = [
            'authserver.permissions' => true,
            'permissions'            => true,
        ];

        // Act + Assert
        $this->assertSame('authserver', $permissions->store());
    }

    /**
     * The legacy table is used only where the new store is absent.
     *
     * This is what keeps an installation that predates the move working
     * untouched: its rows stay authoritative because there is nothing else to
     * read.
     */
    public function testTheLegacyTableIsUsedOnlyWithoutTheNewStore(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = ['permissions' => true];

        // Act + Assert
        $this->assertSame('legacy', $permissions->store());
    }

    /**
     * Neither table means "none" — not a guess in either direction.
     */
    public function testNeitherTableMeansNoStore(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();

        // Act + Assert
        $this->assertSame('none', $permissions->store());
    }

    /**
     * An allow is written as an `allow` row for the user, at normal priority.
     *
     * The old row is deleted first so that a second call replaces rather than
     * accumulates — flipping allow to deny must not leave both on record.
     */
    public function testAllowWritesAnAllowRowForTheUser(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = ['authserver.permissions' => true];

        // Act
        $permissions->allow(7, 'customer', 'view');

        // Assert
        $log = $permissions->connection->log;
        $this->assertSame('authserver.permissions', $log['table']);
        $this->assertTrue($log['deleted'], 'an existing row is replaced, not duplicated');
        $this->assertSame('user', $log['insert']['subject_type']);
        $this->assertSame(7, $log['insert']['subject_id']);
        $this->assertSame('customer', $log['insert']['object_type']);
        $this->assertNull($log['insert']['object_id'], 'no element means all objects of the type');
        $this->assertSame('view', $log['insert']['action']);
        $this->assertSame('allow', $log['insert']['grant_type']);
        $this->assertSame(100, $log['insert']['priority']);
    }

    /**
     * A deny is stored above allow, so it wins a tie.
     *
     * That is the tie-break the resolver and the effective-permissions view
     * apply; storing a deny at the same priority as an allow would make the
     * outcome depend on row order.
     */
    public function testDenyIsStoredAboveAllow(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = ['authserver.permissions' => true];

        // Act
        $permissions->deny(7, 'customer', 'delete');

        // Assert
        $log = $permissions->connection->log;
        $this->assertSame('deny', $log['insert']['grant_type']);
        $this->assertGreaterThan(100, $log['insert']['priority']);
    }

    /**
     * The `admin` privilege becomes the `*` action.
     *
     * The two names mean the same thing in their respective models — everything
     * on this object — and the read path matches on `*`, so writing `admin`
     * verbatim would store a grant nothing could ever match.
     */
    public function testAdminIsWrittenAsTheWildcardAction(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = ['authserver.permissions' => true];

        // Act
        $permissions->allow(7, 'customer', 'admin');

        // Assert
        $this->assertSame('*', $permissions->connection->log['insert']['action']);
    }

    /**
     * A grant on one element is scoped to that object id.
     */
    public function testAnElementBecomesTheObjectId(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = ['authserver.permissions' => true];

        // Act
        $permissions->allow(7, 'customer', 'view', '42');

        // Assert
        $this->assertSame('42', $permissions->connection->log['insert']['object_id']);
    }

    /**
     * A group becomes a role, which is what the new model calls it.
     */
    public function testAGroupIsWrittenAsARole(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = ['authserver.permissions' => true];

        // Act
        $permissions->allow(3, 'customer', 'view', '', 'module', 'group');

        // Assert
        $this->assertSame('role', $permissions->connection->log['insert']['subject_type']);
    }

    /**
     * A subject type the new model cannot express is refused, not guessed at.
     *
     * The legacy table let `subjecttype` be any string. Writing such a row under
     * `user` or `role` would create a grant that silently belongs to somebody
     * else; writing it verbatim would create one that nothing can ever match.
     * Neither is acceptable, so nothing is written.
     */
    public function testAnUnrepresentableSubjectTypeIsNotWritten(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = ['authserver.permissions' => true];

        // Act
        $permissions->allow(3, 'customer', 'view', '', 'module', 'department');

        // Assert
        $this->assertArrayNotHasKey('insert', $permissions->connection->log);
        $this->assertArrayNotHasKey('deleted', $permissions->connection->log);
    }

    /**
     * Removing a permission deletes the row and inserts nothing.
     *
     * A removal that also wrote a `deny` would be a different thing entirely:
     * deny is an answer, absence is not, and the tri-state call distinguishes
     * them.
     */
    public function testRemovingDeletesWithoutInserting(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = ['authserver.permissions' => true];

        // Act
        $permissions->removePermission(7, 'customer', 'view');

        // Assert
        $this->assertTrue($permissions->connection->log['deleted']);
        $this->assertArrayNotHasKey('insert', $permissions->connection->log);
    }

    /**
     * An unscoped grant is matched with IS NULL, not `= NULL`.
     *
     * `object_id = NULL` is never true in SQL, so a delete written that way
     * would match nothing and the "replace" step would silently accumulate
     * duplicate rows instead.
     */
    public function testAnUnscopedGrantIsMatchedWithIsNull(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = ['authserver.permissions' => true];

        // Act
        $permissions->removePermission(7, 'customer', 'view');

        // Assert
        $this->assertContains('object_id', $permissions->connection->log['whereNull']);
    }

    /**
     * "No store" is re-checked, never remembered.
     *
     * This class is reached through a process-wide singleton. "No store" is the
     * one answer that stops being true — migrations create the tables — and a
     * cached "none" makes anything holding an instance from before refuse every
     * permission for the life of the process. A queue worker, a daemon, or the
     * auto-migration path itself would do exactly that.
     */
    public function testTheAbsenceOfAStoreIsNotRemembered(): void
    {
        // Arrange — nothing provisioned yet
        $permissions = new PermissionsWriteProbe();
        $this->assertSame('none', $permissions->store());

        // Act — migrations run, and the table now exists
        $permissions->tables = ['authserver.permissions' => true];

        // Assert — the same instance notices
        $this->assertSame('authserver', $permissions->store());
    }

    /**
     * A store that was found is remembered.
     *
     * The opposite case: a table will not disappear underneath a running
     * process, and asking the catalogue on every permission question would make
     * each one cost two extra round trips.
     */
    public function testAFoundStoreIsLookedUpOnce(): void
    {
        // Arrange
        $permissions = new PermissionsWriteProbe();
        $permissions->tables = ['authserver.permissions' => true];

        // Act
        $permissions->store();
        $lookupsAfterFirst = $permissions->lookups;
        $permissions->store();
        $permissions->store();

        // Assert
        $this->assertSame(
            $lookupsAfterFirst,
            $permissions->lookups,
            'a found store must not be re-queried'
        );
    }
}
