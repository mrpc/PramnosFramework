<?php

declare(strict_types=1);

namespace SpaFieldsTestApp {
    /**
     * Stand-in application declaring a Svelte SPA, so the generator takes the
     * branch a scaffolded SPA project would.
     */
    class Application extends \Pramnos\Application\Application
    {
        public $applicationInfo = [
            'namespace' => 'SpaFieldsTestApp',
            'app_style' => 'spa',
            'spa_stack' => 'svelte',
        ];
        public $appName = '';
        public function init($settingsFile = '') {}
    }
}

namespace Pramnos\Tests\Unit\Console {

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;
use Pramnos\Framework\Factory;

/** Exposes the SPA field descriptors and the settings they are read from. */
class SpaFieldsProbe extends MakeCommandBase
{
    protected function configure() {}
    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ) {
        return 0;
    }

    /** @return array{0: list<array<string,mixed>>, 1: string} */
    public function exposeFields(string $table): array
    {
        return $this->spaFieldsFor($table);
    }

    public function exposeApiPrefix(): string
    {
        return $this->apiPrefix();
    }

    public function exposeSourceDir(): string
    {
        return $this->spaSourceDir();
    }

    public function exposeCreateScreen(string $name): string
    {
        return $this->createSpaScreen($name);
    }

    public function exposeBlankScreen(string $name): string
    {
        return $this->createBlankSpaScreen($name);
    }

    public function exposeComponent(string $name): string
    {
        return $this->createSpaComponent($name);
    }

    public function setTable(string $table): void
    {
        $this->dbtable = $table;
    }
}

/**
 * **The SPA generator now reads the same introspection the MVC generator does.**
 *
 * This is the whole of the reported asymmetry. `createSpaScreen()` called
 * `editableColumns()`, which returns column *names*, while the MVC path called
 * `introspectTableAsWizardColumns()` on the same table and got the logical type,
 * nullability, the `COLUMN COMMENT` and every foreign key — and turned them into
 * a checkbox for a boolean, a `<textarea>` for text, a date input for a date and
 * a searchable picker for a foreign key.
 *
 * The consequence was not cosmetic. A text box over a `boolean` column stores
 * the string `"on"`. A text box over a foreign key asks somebody to type a
 * numeric id they have no way to look up. A text box over a `timestamp` accepts
 * anything and the insert fails at the database. The generated screen was a
 * demo; the MVC screen was a feature.
 *
 * Every assertion here is paired with the reversal that reddens it, in its own
 * docblock — a guard is not proven until you break the thing it guards. Two are
 * called out specifically because the obvious version of them cannot fail:
 * the `NON_EDITABLE_COLUMNS` filter (which must be shown to match on the *name*)
 * and the field count (which must be compared against the fixture's own column
 * count, not against zero).
 */
#[\PHPUnit\Framework\Attributes\Group('mysql')]
class MakeSpaFieldsTest extends TestCase
{
    private SpaFieldsProbe $command;
    private \Pramnos\Console\Application $app;
    private \Pramnos\Database\Database $db;

    /** Columns the fixture table declares, in order. */
    private const FIXTURE_COLUMNS = [
        'widgetid', 'name', 'notes', 'active', 'quantity', 'price',
        'released', 'seen_at', 'password', 'stationid',
    ];

    /** Of those, the ones a generated form must not offer. */
    private const FIXTURE_EXCLUDED = ['widgetid', 'password'];

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        \Pramnos\Application\Settings::loadSettings(
            ROOT . '/tests/fixtures/app/settings.php'
        );

        $this->app = new \Pramnos\Console\Application();
        $internal  = new \SpaFieldsTestApp\Application();
        // The base constructor reloads applicationInfo from app.php, wiping the
        // property default — so the fixture's values are set afterwards.
        $internal->applicationInfo = [
            'namespace' => 'SpaFieldsTestApp',
            'app_style' => 'spa',
            'spa_stack' => 'svelte',
        ];
        $this->app->internalApplication = $internal;

        $this->command = new SpaFieldsProbe();
        $this->command->setApplication($this->app);

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->createFixture();
    }

    protected function tearDown(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        $this->db->query('DROP TABLE IF EXISTS spa_widgets');
        $this->db->query('DROP TABLE IF EXISTS spa_stations');
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');

        $this->rmdir(ROOT . '/frontend');
        $this->rmdir(ROOT . '/admin-ui');
    }

    /**
     * A table with one column of each interesting kind.
     *
     * The types are the point: a boolean, a text, an integer, a decimal, a date,
     * a timestamp, a secret and a foreign key. `spa_stations` exists so the
     * foreign key has somewhere to point, and carries a `name` column so the
     * label-column guess has something to find.
     */
    private function createFixture(bool $withComment = true, bool $withFk = true): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        $this->db->query('DROP TABLE IF EXISTS spa_widgets');
        $this->db->query('DROP TABLE IF EXISTS spa_stations');
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');

        $this->db->query(
            'CREATE TABLE spa_stations ('
            . 'stationid BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'name VARCHAR(255) NOT NULL DEFAULT \'\''
            . ') ENGINE=InnoDB'
        );

        $comment = $withComment ? " COMMENT 'Owning station'" : '';
        $fk = $withFk
            ? ', CONSTRAINT fk_spa_widget_station FOREIGN KEY (stationid)'
                . ' REFERENCES spa_stations (stationid)'
            : '';

        $this->db->query(
            'CREATE TABLE spa_widgets ('
            . 'widgetid BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'name VARCHAR(255) NOT NULL DEFAULT \'\','
            . 'notes TEXT NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 0,'
            . 'quantity INT NULL,'
            . 'price DECIMAL(10,2) NULL,'
            . 'released DATE NULL,'
            . 'seen_at TIMESTAMP NULL,'
            . 'password VARCHAR(100) NOT NULL DEFAULT \'\','
            . 'stationid BIGINT NULL' . $comment
            . $fk
            . ') ENGINE=InnoDB'
        );
    }

    /**
     * Change the fixture's schema mid-test.
     *
     * These tests rebuild and alter the same table repeatedly, which is only
     * possible because the generator reads the schema **fresh** rather than from
     * getColumns()'s hour-long cache — see
     * {@see \Pramnos\Database\Database::getColumns()}'s `$fresh` parameter. A
     * cached read makes every test after the first assert against the first
     * one's schema, and the failures look like generator bugs.
     *
     * @param string $sql An ALTER statement against spa_widgets.
     */
    private function alterFixture(string $sql): void
    {
        $this->db->query($sql);
    }

    /** @return array<string, array<string, mixed>> descriptors keyed by name */
    private function fieldsByName(string $table = 'spa_widgets'): array
    {
        [$fields] = $this->command->exposeFields($table);
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field['name']] = $field;
        }

        return $byName;
    }

    private function rmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->rmdir($full) : unlink($full);
        }
        rmdir($path);
    }

    // ── The types ───────────────────────────────────────────────────────────

    /**
     * Every column's logical type reaches the descriptor.
     *
     * Asserted as a set rather than one at a time, because the claim is that the
     * descriptor *follows the column* — a generator that hard-coded `'string'`
     * would pass any single-type assertion.
     *
     * The reversal: change `active` to `VARCHAR` in the fixture and this test
     * must fail on that column, because the descriptor has to follow the schema
     * rather than the column's name.
     */
    public function testEveryColumnsLogicalTypeReachesTheDescriptor(): void
    {
        // Act
        $fields = $this->fieldsByName();

        // Assert
        $this->assertSame('string', $fields['name']['type']);
        $this->assertSame('text', $fields['notes']['type']);
        $this->assertSame('boolean', $fields['active']['type'],
            'tinyint(1) is the MySQL convention for a boolean');
        $this->assertSame('integer', $fields['quantity']['type']);
        $this->assertSame('decimal', $fields['price']['type']);
        $this->assertSame('date', $fields['released']['type']);
        $this->assertSame('timestamp', $fields['seen_at']['type']);
    }

    /**
     * A boolean really is reported as a boolean and not as a string.
     *
     * Stated on its own because it is the single most costly case: a text box
     * over a boolean stores the string `"on"` when the form is submitted, which
     * is truthy for ever afterwards.
     *
     * The reversal: redefine `active` as `VARCHAR(3)` and the assertion must
     * change with it.
     */
    public function testABooleanColumnIsNotAString(): void
    {
        // Act
        $fields = $this->fieldsByName();

        // Assert
        $this->assertSame('boolean', $fields['active']['type']);
        $this->assertNotSame('string', $fields['active']['type']);
    }

    // ── Nullability ─────────────────────────────────────────────────────────

    /**
     * `NOT NULL` becomes `nullable: false`, which the form renders as
     * `required` — and a nullable column must not be marked required, or the
     * form refuses a save the database would have accepted.
     *
     * The reversal: make `name` nullable in the fixture and the first assertion
     * must fail.
     */
    public function testNullabilityFollowsTheColumn(): void
    {
        // Act
        $fields = $this->fieldsByName();

        // Assert
        $this->assertFalse($fields['name']['nullable'], 'name is NOT NULL');
        $this->assertTrue($fields['notes']['nullable'], 'notes is NULL');
        $this->assertTrue($fields['quantity']['nullable']);
    }

    // ── Labels ──────────────────────────────────────────────────────────────

    /**
     * A `COLUMN COMMENT` is the label when there is one.
     *
     * This is what makes a generated screen readable without an edit: the
     * schema already says what `stationid` is called, and the MVC generator has
     * always used it.
     */
    public function testTheColumnCommentIsTheLabel(): void
    {
        // Act
        $fields = $this->fieldsByName();

        // Assert
        $this->assertSame('Owning station', $fields['stationid']['label']);
    }

    /**
     * With no comment, the label falls back to the humanised column name — it
     * must **not** keep the previous column's comment or stay empty.
     *
     * This is the reversal of the test above, run as a test: the fixture is
     * rebuilt without the comment, and the label has to change. A generator
     * that read the comment once and reused it would pass the test above and
     * fail here.
     */
    public function testWithoutACommentTheLabelIsTheHumanisedName(): void
    {
        // Arrange — the same table, no COLUMN COMMENT.
        $this->createFixture(withComment: false);

        // Act
        $fields = $this->fieldsByName();

        // Assert
        $this->assertSame('Stationid', $fields['stationid']['label']);
        $this->assertNotSame('Owning station', $fields['stationid']['label']);
        // And a multi-word column name is separated rather than run together.
        $this->assertSame('Seen At', $fields['seen_at']['label']);
    }

    // ── Foreign keys ────────────────────────────────────────────────────────

    /**
     * A foreign key becomes a picker pointed at the referenced resource's own
     * list endpoint, with the value and label columns named.
     *
     * The endpoint carries the application's `api_prefix` rather than a
     * hard-coded one, and `labelKey` is the guess (`name`, `title`, `label`,
     * `username`, `slug`) resolved against the referenced table — which is why
     * the fixture's `spa_stations` has a `name` column.
     */
    public function testAForeignKeyBecomesAPicker(): void
    {
        // Act
        $fields = $this->fieldsByName();

        // Assert
        $fk = $fields['stationid']['fk'];
        $this->assertIsArray($fk, 'a FK column must carry a picker target');
        // The referenced table is `spa_stations`, singularised — the endpoint is
        // derived from the schema, not guessed from the column name.
        $this->assertSame('/api/1.0/spa_station', $fk['endpoint']);
        $this->assertSame('stationid', $fk['valueKey']);
        $this->assertSame('name', $fk['labelKey'],
            'the label column is guessed from the referenced table');
    }

    /**
     * Without the constraint there is no picker, and the column is an ordinary
     * integer.
     *
     * This is the reversal of the test above: drop the foreign key from the
     * fixture and `fk` must become null. A generator that guessed "a column
     * ending in `id` is a foreign key" would pass the test above and fail here
     * — and would also invent pickers for columns that reference nothing.
     */
    public function testWithoutAConstraintThereIsNoPicker(): void
    {
        // Arrange
        $this->createFixture(withFk: false);

        // Act
        $fields = $this->fieldsByName();

        // Assert
        $this->assertNull($fields['stationid']['fk']);
        $this->assertSame('biginteger', $fields['stationid']['type']);
    }

    /**
     * A column that is not a foreign key carries no picker, in the same table
     * where another column does.
     *
     * Guards against a descriptor builder that attached the table's single
     * foreign key to every column.
     */
    public function testOnlyTheForeignKeyColumnCarriesAPicker(): void
    {
        // Act
        $fields = $this->fieldsByName();

        // Assert
        $this->assertNotNull($fields['stationid']['fk']);
        $this->assertNull($fields['name']['fk']);
        $this->assertNull($fields['quantity']['fk']);
    }

    // ── The exclusions ──────────────────────────────────────────────────────

    /**
     * A secret column is absent, and the primary key with it.
     *
     * `NON_EDITABLE_COLUMNS` is why a generated screen does not print a password
     * hash in an admin table and invite an administrator to type over it —
     * which stores a plain string where a hash belongs and locks the account
     * out. The filter used to be applied by `editableColumns()`; changing where
     * the columns come from without carrying it forward would be a regression
     * with no symptom until somebody generated a CRUD over `users`.
     */
    public function testSecretAndKeyColumnsAreExcluded(): void
    {
        // Act
        $fields = $this->fieldsByName();

        // Assert
        $this->assertArrayNotHasKey('password', $fields);
        $this->assertArrayNotHasKey('widgetid', $fields);
    }

    /**
     * **The filter matches the column's name, not something incidental.**
     *
     * The absence assertion above cannot tell "excluded because it is called
     * `password`" from "absent because the introspection missed it". So the same
     * column is renamed to `passphrase` — which is not in the list — and must
     * now **appear**. If it stays absent, the exclusion was never doing the
     * work the other test credits it with.
     */
    public function testTheExclusionMatchesTheNameAndNotSomethingIncidental(): void
    {
        // Arrange — same column, same type, a name the list does not carry.
        $this->alterFixture(
            'ALTER TABLE spa_widgets CHANGE password passphrase VARCHAR(100)'
            . ' NOT NULL DEFAULT \'\''
        );

        // Act
        $fields = $this->fieldsByName();

        // Assert
        $this->assertArrayHasKey('passphrase', $fields,
            'a column not on the list must be offered — otherwise the '
            . 'exclusion test above is proving nothing'
        );
        $this->assertSame('string', $fields['passphrase']['type']);
    }

    /**
     * **Every column is read, not merely some.**
     *
     * `assertGreaterThan(0, count($fields))` answers "did this read anything"
     * and cannot answer "did it read every column" — a generator that stopped
     * after the first column would satisfy it. The expected count is computed
     * from the fixture's own column list, so adding a column to the fixture
     * without the generator seeing it fails this test.
     */
    public function testEveryColumnIsReadExceptTheExcludedOnes(): void
    {
        // Arrange
        $expected = count(self::FIXTURE_COLUMNS) - count(self::FIXTURE_EXCLUDED);

        // Act
        [$fields, $key] = $this->command->exposeFields('spa_widgets');

        // Assert
        $this->assertCount($expected, $fields);
        $this->assertSame('widgetid', $key);
        // And the names are the fixture's, in the fixture's order.
        $this->assertSame(
            array_values(array_diff(self::FIXTURE_COLUMNS, self::FIXTURE_EXCLUDED)),
            array_column($fields, 'name')
        );
    }

    /**
     * A table that does not exist yet produces no fields rather than a failure.
     *
     * The schema-first workflow is `create:migration`, then `create:crud`, then
     * migrate — so the generator runs before the table exists, and it has always
     * been allowed to.
     */
    public function testAnAbsentTableProducesNoFieldsRatherThanAnError(): void
    {
        // Act
        [$fields, $key] = $this->command->exposeFields('spa_nothing_here');

        // Assert
        $this->assertSame([], $fields);
        $this->assertNotSame('', $key, 'the conventional key is still derived');
    }

    // ── What app.php decides ────────────────────────────────────────────────

    /**
     * `api_prefix` is read from app.php rather than hard-coded.
     *
     * A generated screen with a hard-coded prefix 404s in exactly the projects
     * that configured one — and the endpoint a foreign-key picker points at is
     * built from the same value, so getting it wrong breaks two things.
     */
    public function testApiPrefixIsReadFromAppPhp(): void
    {
        // Arrange
        $this->app->internalApplication->applicationInfo['api_prefix'] = '/v2';

        // Act
        $prefix = $this->command->exposeApiPrefix();
        $fields = $this->fieldsByName();

        // Assert
        $this->assertSame('/v2', $prefix);
        $this->assertSame('/v2/spa_station', $fields['stationid']['fk']['endpoint']);
    }

    /**
     * The default is the prefix `init` writes, so a project that never
     * configured one still generates a working screen.
     */
    public function testApiPrefixDefaultsToWhatInitWrites(): void
    {
        // Act / Assert
        $this->assertSame('/api/1.0', $this->command->exposeApiPrefix());
    }

    /**
     * `spa_source_dir` is honoured, so a project that moved its front end gets
     * its screens where the build looks for them.
     *
     * The generator used to hard-code `frontend/`, while `project:resync` read
     * the setting — so a project with `admin-ui/` had generated screens written
     * into a directory nothing builds, and a resync that reported them missing.
     *
     * Asserted in both directions: the screen lands in `admin-ui/` **and not**
     * in `frontend/`. The positive assertion alone passes if the generator
     * writes to both.
     */
    public function testSpaSourceDirIsHonoured(): void
    {
        // Arrange
        $this->app->internalApplication->applicationInfo['spa_source_dir'] = 'admin-ui/';

        // Act
        $this->assertSame('admin-ui/', $this->command->exposeSourceDir());
        $result = $this->command->exposeCreateScreen('widget');

        // Assert
        $this->assertStringContainsString('OK', $result);
        $this->assertFileExists(ROOT . '/admin-ui/screens/Widget.svelte');
        $this->assertFileDoesNotExist(ROOT . '/frontend/screens/Widget.svelte');
        // The components follow the screen, or the build breaks.
        $this->assertFileExists(ROOT . '/admin-ui/components/DataTable.svelte');
    }

    // ── The generated screen ────────────────────────────────────────────────

    /**
     * The descriptors reach the screen, so the form renders the controls the
     * columns call for.
     *
     * Asserted on the generated file rather than on the descriptors alone: the
     * descriptors being right and the screen not receiving them is a failure
     * mode of its own, and it is the one that produces a screen that looks
     * exactly like the old one.
     */
    public function testTheDescriptorsReachTheGeneratedScreen(): void
    {
        // Arrange — the entity is `widget`, the fixture table is `spa_widgets`,
        // which is what `--table` is for.
        $this->command->setTable('spa_widgets');

        // Act
        $this->command->exposeCreateScreen('widget');
        $screen = (string) file_get_contents(ROOT . '/frontend/screens/Widget.svelte');

        // Assert — the types are in the file …
        $this->assertStringContainsString('"type": "boolean"', $screen);
        $this->assertStringContainsString('"type": "date"', $screen);
        $this->assertStringContainsString('"type": "timestamp"', $screen);
        // … the labels the schema supplied …
        $this->assertStringContainsString('"label": "Owning station"', $screen);
        // … the FK target …
        $this->assertStringContainsString('"endpoint": "/api/1.0/spa_station"', $screen);
        // … and nothing that should not be there.
        $this->assertStringNotContainsString('"name": "password"', $screen);
    }

    /**
     * The generated screen is pretty-printed.
     *
     * It lands in a file somebody edits to relabel a column, and a single-line
     * JSON blob of thirty descriptors is not something anybody edits — they
     * replace it, and lose the types with it.
     */
    public function testTheDescriptorsArePrettyPrintedForEditing(): void
    {
        // Arrange
        $this->command->setTable('spa_widgets');

        // Act
        $this->command->exposeCreateScreen('widget');
        $screen = (string) file_get_contents(ROOT . '/frontend/screens/Widget.svelte');

        // Assert — one descriptor key per line.
        $this->assertStringContainsString("\n        \"name\": \"name\"", $screen);
    }

    // ── The two new doors ───────────────────────────────────────────────────

    /**
     * `create:screen --blank` writes a screen with no list.
     *
     * The CRUD screen is a poor starting point for a dashboard: two thirds of it
     * is list plumbing to delete, and what remains imports components it no
     * longer uses.
     *
     * **Both halves are asserted.** An absence assertion on its own passes on an
     * empty file, so the same claim is made positively about the non-blank path
     * in the same test.
     */
    public function testABlankScreenHasNoListAndTheCrudScreenDoes(): void
    {
        // Act
        $this->command->exposeBlankScreen('Dashboard');
        $blank = (string) file_get_contents(ROOT . '/frontend/screens/Dashboard.svelte');

        $this->command->exposeCreateScreen('widget');
        $crud = (string) file_get_contents(ROOT . '/frontend/screens/Widget.svelte');

        // Assert — on the import line, not on a mention of the name: a docblock
        // that talks about DataTable would satisfy a grep for the word.
        $this->assertStringNotContainsString(
            "import DataTable from '../components/DataTable.svelte';", $blank
        );
        $this->assertStringContainsString(
            "import DataTable from '../components/DataTable.svelte';", $crud
        );
        // Both take the route, because that is what every screen here does.
        $this->assertStringContainsString('let { route }', $blank);
        $this->assertStringContainsString('let { route }', $crud);
    }

    /**
     * A blank screen registers itself, so it is reachable.
     *
     * A screen the registry does not name is a file the bundler does not even
     * include — generated, reported as written, and unreachable.
     */
    public function testABlankScreenIsRegistered(): void
    {
        // Act
        $this->command->exposeBlankScreen('Dashboard');
        $registry = (string) file_get_contents(ROOT . '/frontend/screens/registry.js');

        // Assert
        $this->assertStringContainsString(
            "import Dashboard from './Dashboard.svelte';", $registry
        );
        $this->assertStringContainsString("name: 'dashboard'", $registry);
    }

    /**
     * `create:component` writes the component **and its test**.
     *
     * That pairing is the point of the command rather than a nicety:
     * `create:service` writes a test stub, which is why services in a
     * scaffolded project have tests, and the front end had no such command,
     * which is why components do not.
     */
    public function testCreateComponentWritesBothFiles(): void
    {
        // Act
        $result = $this->command->exposeComponent('StatusBadge');

        // Assert
        $this->assertFileExists(ROOT . '/frontend/components/StatusBadge.svelte');
        $this->assertFileExists(ROOT . '/frontend/__tests__/StatusBadge.test.js');
        $this->assertStringContainsString('OK', $result);

        // The test renders the component rather than merely importing it — a
        // test that only imports passes for a component that renders nothing.
        $test = (string) file_get_contents(ROOT . '/frontend/__tests__/StatusBadge.test.js');
        $this->assertStringContainsString('render(StatusBadge', $test);
    }

    /**
     * The shared components are written once and never overwritten.
     *
     * The whole value of shipping a DataTable is that projects extend it, so a
     * generator that refreshed it would undo that work on the next
     * `create:crud`. `project:resync --spa-components` is the deliberate way to
     * take a newer version.
     */
    public function testSharedComponentsAreNeverOverwritten(): void
    {
        // Arrange — generate once, then mark the component as the project's.
        $this->command->exposeCreateScreen('widget');
        $path = ROOT . '/frontend/components/DataTable.svelte';
        file_put_contents($path, "// THE PROJECT EDITED THIS\n");

        // Act — a second generator run, for a different entity.
        $this->command->exposeCreateScreen('gadget');

        // Assert — the sentinel survived.
        $this->assertSame(
            "// THE PROJECT EDITED THIS\n",
            (string) file_get_contents($path),
            'a project that has edited its DataTable must keep it'
        );
    }
}

}
