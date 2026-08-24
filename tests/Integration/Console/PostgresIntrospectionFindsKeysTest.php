<?php

declare(strict_types=1);

namespace PgKeysTestApp {
    /** A Svelte SPA project, so the generator takes the SPA branch. */
    class Application extends \Pramnos\Application\Application
    {
        public $applicationInfo = [
            'namespace' => 'PgKeysTestApp',
            'app_style' => 'spa',
            'spa_stack' => 'svelte',
        ];
        public $appName = '';
        public function init($settingsFile = '') {}
    }
}

namespace Pramnos\Tests\Integration\Console {

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\MakeCommandBase;
use Pramnos\Framework\Factory;

/** Exposes the introspection the generators read. */
class PgKeysProbe extends MakeCommandBase
{
    protected function configure() {}
    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ) {
        return 0;
    }

    /** @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>} */
    public function exposeIntrospect(string $table): array
    {
        return $this->introspectTableAsWizardColumns($table);
    }

    /** @return array{0: list<array<string,mixed>>, 1: string} */
    public function exposeFields(string $table): array
    {
        return $this->spaFieldsFor($table);
    }

    public function exposePrimaryKey(string $table): string
    {
        return $this->primaryKeyFor($table);
    }

    public function exposeConventionKey(string $table): string
    {
        return $this->getSingularPrimaryKey($table);
    }
}

/**
 * **On PostgreSQL the generators could not find a key, or a foreign key.**
 *
 * Two faults in schema introspection, both older than the generator work that
 * surfaced them, and both affecting the MVC generator equally — this is shared
 * code, not something a new path introduced.
 *
 * **1. `ForeignKey` read the wrong view.** It was computed from
 * `information_schema.constraint_column_usage`, and for a FOREIGN KEY that view
 * lists the column of the *referenced* table. So on
 * `streams(station_id) → stations(id)` the flag came back **true on `id`** — the
 * primary key — and **false on `station_id`**, the actual foreign key. It was
 * never true for a foreign key on any table.
 *
 * Everything gated on it therefore saw no foreign keys: the generated Svelte
 * form rendered a number input where its searchable picker belongs, the MVC form
 * rendered a bare input instead of its select2, and `unsigned` is decided from
 * the same flag so generated migrations differed too. `ForeignTable` and
 * `ForeignColumn`, computed from `key_column_usage` in the same row, were
 * correct all along — only the flag disagreed with them.
 *
 * **2. `primaryKeyFor()` asked MySQL's question.** It read `Key` and
 * `Column_key`, which are the MySQL projection's names; PostgreSQL answers
 * `PrimaryKey` as a boolean. So the loop could never match and the
 * `<singular>id` convention was the answer for every PostgreSQL table —
 * measured at 3 correct out of 88 on one real schema.
 *
 * That one is worse than a wrong default usually is, because **the read path
 * never touches the key**: a generated CRUD lists perfectly and fails on the
 * first save or delete, after somebody has started trusting it.
 *
 * **Why this file is an integration test.** A fixture cannot catch either.
 * A hand-built fixture names its columns after the convention, so fault 2 is
 * invisible; and fault 1 needs two real tables with a real constraint between
 * them. Both were invisible until the introspection ran against a live
 * PostgreSQL schema.
 */
#[\PHPUnit\Framework\Attributes\Group('postgresql')]
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class PostgresIntrospectionFindsKeysTest extends TestCase
{
    private PgKeysProbe $command;
    private \Pramnos\Console\Application $app;
    private \Pramnos\Database\Database $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }
        Settings::loadSettings(ROOT . '/tests/fixtures/app/pg_settings.php');

        $this->app = new \Pramnos\Console\Application();
        $internal  = new \PgKeysTestApp\Application();
        $internal->applicationInfo = [
            'namespace' => 'PgKeysTestApp',
            'app_style' => 'spa',
            'spa_stack' => 'svelte',
        ];
        $this->app->internalApplication = $internal;

        $this->command = new PgKeysProbe();
        $this->command->setApplication($this->app);

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Two real tables with a real constraint between them, and — crucially —
        // primary keys that are NOT what the `<singular>id` convention would
        // guess. `pgk_stations` keys on `id`, not `pgk_stationid`; and
        // `pgk_streams` too. A fixture that used the convention would pass fault
        // 2 without exercising it, which is how it survived this long.
        $this->db->query('DROP TABLE IF EXISTS pgk_streams CASCADE');
        $this->db->query('DROP TABLE IF EXISTS pgk_stations CASCADE');
        $this->db->query(
            'CREATE TABLE pgk_stations ('
            . 'id BIGSERIAL PRIMARY KEY,'
            . 'name VARCHAR(100) NOT NULL DEFAULT \'\''
            . ')'
        );
        $this->db->query(
            'CREATE TABLE pgk_streams ('
            . 'id BIGSERIAL PRIMARY KEY,'
            . 'station_id BIGINT NULL REFERENCES pgk_stations (id),'
            . 'url TEXT NULL,'
            . 'active BOOLEAN NOT NULL DEFAULT false'
            . ')'
        );
        $this->db->forgetColumns('pgk_streams');
        $this->db->forgetColumns('pgk_stations');
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS pgk_streams CASCADE');
        $this->db->query('DROP TABLE IF EXISTS pgk_stations CASCADE');
        $this->db->forgetColumns('pgk_streams');
        $this->db->forgetColumns('pgk_stations');
    }

    /** The raw getColumns() row for one column, keyed by field name. */
    private function rawColumns(string $table): array
    {
        $result = $this->db->getColumns($table, null, false, true);
        $rows   = [];
        while ($result->fetch()) {
            $rows[$result->fields['Field']] = $result->fields;
        }

        return $rows;
    }

    // ── Fault 1: the ForeignKey flag ────────────────────────────────────────

    /**
     * The flag is true on the referencing column and false on the primary key.
     *
     * Both halves are asserted, because the old behaviour was not "the flag is
     * missing" — it was **inverted**: true on `id`, false on `station_id`. A
     * test that only checked `station_id` would have caught it; one that only
     * checked `id` would have passed it. Both directions say which fault this is.
     *
     * The reversal that reddens it: put `constraint_column_usage` back in the
     * ForeignKey subquery.
     */
    public function testTheForeignKeyFlagIsTrueOnTheReferencingColumn(): void
    {
        // Act
        $rows = $this->rawColumns('pgk_streams');

        // Assert — the actual foreign key.
        $this->assertContains(
            $rows['station_id']['ForeignKey'], [true, 't', '1', 1],
            'station_id references pgk_stations, so the flag must be true'
        );
        // …and the primary key is not a foreign key.
        $this->assertContains(
            $rows['id']['ForeignKey'], [false, 'f', '0', 0, null, ''],
            'id is the primary key of this table, not a foreign key'
        );
    }

    /**
     * The flag agrees with the `ForeignTable` beside it.
     *
     * That disagreement was the whole tell: the right data was already in the
     * row under the right name, and only the flag contradicted it. Asserting the
     * agreement rather than each value separately is what makes this a statement
     * about the row being coherent.
     */
    public function testTheFlagAgreesWithTheForeignTableBesideIt(): void
    {
        // Act
        $rows = $this->rawColumns('pgk_streams');

        // Assert
        foreach ($rows as $name => $fields) {
            $flag  = in_array($fields['ForeignKey'], [true, 't', '1', 1], true);
            $table = (string) ($fields['ForeignTable'] ?? '');

            $this->assertSame(
                $table !== '',
                $flag,
                "column {$name}: ForeignKey said "
                . var_export($fields['ForeignKey'], true)
                . " while ForeignTable said " . var_export($table, true)
            );
        }
    }

    /**
     * The primary-key flag still answers correctly.
     *
     * It moved to the same view as its neighbour — it had been right through the
     * old one by coincidence, since for a PRIMARY KEY constraint that view does
     * list the table's own columns — so this is the regression guard for the
     * move rather than for the bug.
     */
    public function testThePrimaryKeyFlagStillAnswers(): void
    {
        // Act
        $rows = $this->rawColumns('pgk_streams');

        // Assert
        $this->assertContains($rows['id']['PrimaryKey'], [true, 't', '1', 1]);
        $this->assertContains(
            $rows['station_id']['PrimaryKey'], [false, 'f', '0', 0, null, '']
        );
    }

    /**
     * The introspection therefore reports the foreign key, which is what every
     * generator actually consumes.
     *
     * `introspectTableAsWizardColumns()` gates its `$foreignKeys` list on the
     * flag, so an inverted flag meant an empty list for every table — and an
     * empty list is indistinguishable from a table that genuinely has no
     * constraints.
     */
    public function testTheIntrospectionReportsTheForeignKey(): void
    {
        // Act
        [$columns, $foreignKeys] = $this->command->exposeIntrospect('pgk_streams');

        // Assert
        $this->assertCount(1, $foreignKeys);
        $this->assertSame('station_id', $foreignKeys[0]['column']);
        $this->assertSame('pgk_stations', $foreignKeys[0]['on']);
        $this->assertSame('id', $foreignKeys[0]['references']);
        // And the columns are still there, so nothing was traded for it.
        $this->assertNotSame([], $columns);
    }

    /**
     * And a generated SPA field for that column carries a picker.
     *
     * This is the headline of the SPA generator work, and on PostgreSQL it was
     * unreachable: the descriptor's `fk` was null, so `Field.svelte` rendered a
     * number input asking for an id nobody can look up.
     */
    public function testTheGeneratedFieldCarriesAPicker(): void
    {
        // Act
        [$fields] = $this->command->exposeFields('pgk_streams');
        $byName   = array_column($fields, null, 'name');

        // Assert
        $this->assertArrayHasKey('station_id', $byName);
        $this->assertIsArray(
            $byName['station_id']['fk'],
            'a foreign-key column must carry a picker target on PostgreSQL too'
        );
        $this->assertSame('id', $byName['station_id']['fk']['valueKey']);
        $this->assertSame('name', $byName['station_id']['fk']['labelKey']);
        $this->assertStringEndsWith(
            '/pgk_station', $byName['station_id']['fk']['endpoint']
        );
        // A column that is not a foreign key still carries none.
        $this->assertNull($byName['url']['fk']);
    }

    /**
     * A boolean column is a boolean on PostgreSQL as well.
     *
     * Asserted here because the MySQL side of this needed a fix of its own —
     * `DATA_TYPE` hid `tinyint(1)` — and the two drivers reaching the same
     * answer by different routes is the thing worth pinning.
     */
    public function testABooleanColumnIsRecognised(): void
    {
        // Act
        [$fields] = $this->command->exposeFields('pgk_streams');
        $byName   = array_column($fields, null, 'name');

        // Assert
        $this->assertSame('boolean', $byName['active']['type']);
    }

    // ── Fault 2: the primary key ────────────────────────────────────────────

    /**
     * The key is read from the table rather than guessed from its name.
     *
     * `pgk_streams` keys on `id`. The convention guesses `pgk_streamid`, which
     * does not exist — and because the read path never touches the key, a
     * generated CRUD built on that name lists perfectly and fails on the first
     * save or delete.
     *
     * The convention's answer is asserted alongside, so the test states what it
     * is protecting against rather than only what it wants: if the two ever
     * coincide, this test stops proving anything and says so by failing.
     */
    public function testThePrimaryKeyIsReadFromTheTable(): void
    {
        // Act
        $actual     = $this->command->exposePrimaryKey('pgk_streams');
        $convention = $this->command->exposeConventionKey('pgk_streams');

        // Assert
        $this->assertSame('id', $actual);
        $this->assertNotSame(
            $convention,
            $actual,
            'the fixture must be a table where the convention is wrong, or this '
            . 'test cannot fail'
        );
    }

    /**
     * The same for the referenced table, whose key is also not conventional.
     *
     * Two tables rather than one because the first could pass on a lucky
     * singularisation.
     */
    public function testTheReferencedTablesKeyIsAlsoRead(): void
    {
        // Act / Assert
        $this->assertSame('id', $this->command->exposePrimaryKey('pgk_stations'));
    }

    /**
     * The field descriptors exclude the real primary key, not the guessed one.
     *
     * `spaFieldsFor()` drops the key from the editable set. Guessing it wrong
     * meant the *real* key — `id` — was offered as an editable field while the
     * guessed name was excluded from a set it was never in: a form that invites
     * an operator to type over a serial primary key.
     */
    public function testTheRealKeyIsExcludedFromTheEditableFields(): void
    {
        // Act
        [$fields, $key] = $this->command->exposeFields('pgk_streams');
        $names = array_column($fields, 'name');

        // Assert
        $this->assertSame('id', $key);
        $this->assertNotContains('id', $names,
            'the primary key must not be offered for editing');
        $this->assertContains('station_id', $names);
        $this->assertContains('url', $names);
    }

    /**
     * A table whose key really is conventional still resolves, and the
     * convention remains the fallback for a table that does not exist.
     *
     * Both stated so the fix reads as "ask, then fall back" rather than "ask
     * instead of" — the convention has a job, it was just doing everybody's.
     */
    public function testTheConventionRemainsTheFallback(): void
    {
        // Arrange — a table whose key matches the convention.
        $this->db->query('DROP TABLE IF EXISTS pgk_widgets CASCADE');
        $this->db->query(
            'CREATE TABLE pgk_widgets (pgk_widgetid BIGSERIAL PRIMARY KEY, x INT)'
        );
        $this->db->forgetColumns('pgk_widgets');

        try {
            // Act / Assert — read from the table, and it agrees with convention.
            $this->assertSame(
                'pgk_widgetid', $this->command->exposePrimaryKey('pgk_widgets')
            );

            // …and a table that does not exist falls back to the convention
            // rather than failing, which the schema-first workflow depends on.
            $this->assertSame(
                'pgk_nothingid',
                $this->command->exposePrimaryKey('pgk_nothings')
            );
        } finally {
            $this->db->query('DROP TABLE IF EXISTS pgk_widgets CASCADE');
            $this->db->forgetColumns('pgk_widgets');
        }
    }
}

}
