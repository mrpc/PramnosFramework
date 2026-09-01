<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * Retention policies on a backend without TimescaleDB, and the schema helpers around them.
 *
 * `SchemaBuilder` has two implementations of every policy: a native TimescaleDB one and a software
 * one that stores a row in `pramnos.framework_policies` for the PolicyEngine daemon to act on. The
 * software half is what runs on MySQL and on plain PostgreSQL — most deployments — and
 * `removeSoftwarePolicy()` had never executed once.
 *
 * That is the shape of gap that costs data. A retention policy is «delete rows older than this», so
 * a removal that silently did nothing leaves a daemon deleting from a table somebody has decided to
 * keep — and the operator's evidence that they stopped it is a method that returned `true`.
 *
 * Beside it, three helpers that the policy code depends on and that had gaps of their own:
 * `policyInterval()` reading a policy back, `primaryKeyColumns()` (which is how a hypertable
 * candidate is checked), and `withSchema()`.
 *
 * ## The two lanes are not symmetric here, on purpose
 *
 * This one runs against MySQL, which has no TimescaleDB, so every call takes the **software** path.
 * {@see SchemaPolicyStorePostgreSQLTest} runs against the container that *does* have the extension,
 * so the same calls take the **native** path. Same assertions where the behaviour is meant to be the
 * same, and the class says which is which where it is not — the point of having both is that a
 * policy is either registered or it is not, whichever machinery is underneath.
 */
class SchemaPolicyStoreTest extends DatabaseTestCase
{
    /** A table name unique to the run, so a leftover row cannot make a later run pass. */
    private string $target = '';

    /**
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'mysql',
            'server'   => 'db',
            'user'     => 'root',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 3306,
        ];
    }

    /** @return string[] */
    protected static function ownedTables(): array
    {
        return ['policy_probe'];
    }

    /**
     * A table with a two-column primary key.
     *
     * Composite on purpose: `primaryKeyColumns()` returns a list, and a single-column fixture cannot
     * tell «returns the key» from «returns the first column it found», which is the mistake the
     * `ORDER BY ORDINAL_POSITION` in the MySQL branch exists to prevent.
     *
     * @return string[]
     */
    protected static function schemaStatements(): array
    {
        return [
            'CREATE TABLE policy_probe (
                tenant_id INTEGER NOT NULL,
                created_at TIMESTAMP NOT NULL,
                payload VARCHAR(64) NULL,
                PRIMARY KEY (tenant_id, created_at)
            )',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = 'policy_probe';

        // The software policy store, from the shipped migration rather than from DDL here: a test
        // that builds its own version of a framework table can pass while the shipped one is broken.
        $application = (new \ReflectionClass(\Pramnos\Application\Application::class))
            ->newInstanceWithoutConstructor();
        $application->database = $this->db;

        (new \Pramnos\Framework\Migrations\Core\CreatePramnosSchema($application))->up();
        (new \Pramnos\Framework\Migrations\Core\CreateFrameworkPoliciesTable($application))->up();

        /*
         * A native retention policy attaches to a hypertable and to nothing else.
         *
         * On the lane with the extension the fixture has to become one first — which is why the
         * primary key is `(tenant_id, created_at)`: TimescaleDB requires the partitioning column to be
         * part of every unique key on the table.
         */
        if ($this->hasTimescale()) {
            $this->schema()->createHypertable($this->target, 'created_at');
        }

        $this->schema()->removeRetentionPolicy($this->target);
    }

    protected function tearDown(): void
    {
        try {
            $this->schema()->removeRetentionPolicy($this->target);
        } catch (\Throwable) {
            // Nothing registered.
        }

        parent::tearDown();
    }

    private function schema(): \Pramnos\Database\SchemaBuilder
    {
        return $this->db->schema();
    }

    private function hasTimescale(): bool
    {
        return $this->schema()->getCapabilities()->hasTimescaleDB();
    }

    /**
     * A registered retention policy can be read back, and removing it makes it gone.
     *
     * The removal is the assertion this class exists for. `removeSoftwarePolicy()` had never run, and
     * a retention policy is «delete rows older than this» — so a removal that returned `true` while
     * doing nothing leaves a daemon deleting from a table somebody has decided to keep, with the
     * operator holding a `true` as their evidence that they stopped it.
     *
     * Read back through `policyInterval()` rather than by querying the store, so the assertion is
     * about what the framework will report next time rather than about a row this test happens to
     * know the shape of.
     */
    public function testAPolicyIsReadableAndRemovable(): void
    {
        // Arrange & Act
        $added = $this->schema()->addRetentionPolicy($this->target, '30 days', 'created_at');

        // Assert — registered
        $this->assertTrue($added, 'the policy was not registered');
        $this->assertNotNull(
            $this->schema()->policyInterval($this->target, 'retention'),
            'the policy was registered and cannot be read back'
        );

        // Act — removed
        $removed = $this->schema()->removeRetentionPolicy($this->target);

        // Assert — gone
        $this->assertTrue($removed);
        $this->assertNull(
            $this->schema()->policyInterval($this->target, 'retention'),
            'the policy survived its own removal'
        );
    }

    /**
     * Registering twice updates the one policy rather than adding a second.
     *
     * The software path guards this explicitly, and the comment on that guard records why: the check
     * that was supposed to prevent it answered a flat `false` off TimescaleDB, so every run of the
     * ensure command added another row and N identical policies issued the same `DELETE` N times
     * against the same table.
     *
     * Asserted through the interval rather than by counting rows, because that is the question a
     * second registration is really about: after asking twice, which one applies?
     */
    public function testRegisteringTwiceLeavesOnePolicy(): void
    {
        if ($this->hasTimescale()) {
            $this->markTestSkipped(
                'The native path is idempotent in the extension; the software store is what had the '
                . 'duplicate-row problem, and that lane is MySQL.'
            );
        }

        // Arrange
        $this->schema()->addRetentionPolicy($this->target, '30 days', 'created_at');

        // Act
        $this->schema()->addRetentionPolicy($this->target, '90 days', 'created_at');

        // Assert
        $this->assertSame(
            '90 days',
            $this->schema()->policyInterval($this->target, 'retention'),
            'the second registration did not replace the first'
        );

        $rows = $this->db->query(
            'SELECT COUNT(*) AS n FROM '
            . $this->schema()->resolveTableName('pramnos.framework_policies')
            . " WHERE policy_type = 'retention' AND target = '" . $this->target . "'"
        );
        $this->assertSame(1, (int) $rows->fields['n'], 'a second policy row was added beside the first');
    }

    /**
     * A table with no policy reports none, rather than reporting something.
     *
     * The distinction the software reader is built around: «no policy store yet» and «a store with no
     * row for this table» both mean *no policy*, and both have to come back as `null` rather than as
     * an exception — a fresh installation asking about a table it has never registered is the normal
     * case, not an error.
     */
    public function testATableWithNoPolicyReportsNone(): void
    {
        // Act & Assert
        $this->assertNull($this->schema()->policyInterval('a_table_nobody_registered', 'retention'));
        $this->assertNull($this->schema()->policyInterval($this->target, 'compression'));
    }

    /**
     * `primaryKeyColumns()` returns the whole key, in order.
     *
     * Two columns, because a single-column fixture cannot tell «returns the primary key» from
     * «returns the first column it found» — and the order matters to every caller that decides
     * whether a table can become a hypertable, which requires the partitioning column to be part of
     * the key.
     */
    public function testThePrimaryKeyIsReportedWholeAndInOrder(): void
    {
        // Act
        $columns = $this->schema()->primaryKeyColumns($this->target);

        // Assert
        $this->assertSame(['tenant_id', 'created_at'], $columns);
    }

    /**
     * A table without a primary key reports an empty list, not a list with an empty string in it.
     *
     * A caller checking `in_array('created_at', $columns)` behaves identically either way, and a
     * caller checking `count($columns) > 0` does not — so «no key» has to be an empty array.
     */
    public function testATableWithNoPrimaryKeyReportsAnEmptyList(): void
    {
        // Arrange
        $this->db->query('CREATE TABLE policy_probe_nokey (a INTEGER NULL)');

        try {
            // Act
            $columns = $this->schema()->primaryKeyColumns('policy_probe_nokey');

            // Assert
            $this->assertSame([], $columns);
        } finally {
            $this->db->query('DROP TABLE IF EXISTS policy_probe_nokey');
        }
    }

    /**
     * `withSchema()` returns a scoped copy and leaves the original alone.
     *
     * A clone rather than a mutation, which is the whole reason the method exists: a caller reaching
     * into the `pramnos` schema for one statement must not silently move every later statement there
     * too. Asserted by resolving a name through both afterwards, since that is what the override
     * actually changes.
     */
    public function testWithSchemaScopesACopyAndNotTheOriginal(): void
    {
        // Arrange
        $original = $this->schema();

        // Act
        $scoped = $original->withSchema('pramnos');

        // Assert
        $this->assertNotSame($original, $scoped, 'withSchema() mutated the builder it was called on');
        $this->assertStringContainsString(
            'pramnos',
            $scoped->resolveTableName('framework_policies'),
            'the copy is not scoped to the schema it was given'
        );
        $this->assertStringNotContainsString(
            'pramnos',
            $original->resolveTableName('policy_probe'),
            'the original was scoped too, so every later statement moved schema'
        );
    }

    /**
     * An empty schema clears the override rather than scoping to nothing.
     *
     * `withSchema('')` is what a caller writes to say «back to the default», and treating it as a
     * literal schema name would produce `"".table` on PostgreSQL and `_table` on MySQL — both of
     * which fail at the server, one statement later, with a message about a table nobody named.
     */
    public function testAnEmptySchemaMeansTheDefault(): void
    {
        // Act
        $resolved = $this->schema()->withSchema('pramnos')->withSchema('')
            ->resolveTableName('policy_probe');

        // Assert
        $this->assertSame($this->schema()->resolveTableName('policy_probe'), $resolved);
    }
}
