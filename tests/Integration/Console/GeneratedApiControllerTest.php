<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\MakeCommandBase;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** A concrete command exposing the API generator. */
class GeneratedApiDummyCommand extends MakeCommandBase
{
    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }

    public function callCreateApi(string $name): string
    {
        return (string) $this->createApi($name);
    }

    public function setDbTable(?string $table): void
    {
        $this->dbtable = $table;
    }

    public function setSchema(?string $schema): void
    {
        $this->schema = $schema;
    }
}

/**
 * `create:api` reading a real table — 39 statements, the largest single uncovered method left.
 *
 * Every existing test of this command stubs the generation method out, which is right for what
 * those tests are about (the argument handling, the empty-name refusal) and leaves the generator
 * itself never having run. So what had never been checked is the only thing this command produces:
 * a controller file whose contents are derived, column by column, from the live schema.
 *
 * That derivation is the subject. A generated API controller is read once and edited afterwards, so
 * a wrong guess is not a crash — it is a line somebody keeps, and the class of failure is specific:
 *
 * - **the primary key must be found, and must not become a writable field.** A `POST` body that can
 *   set the id is a request that can overwrite an arbitrary row, and the generated `put`/`post`
 *   blocks are exactly where that would be written in.
 * - **each column's type has to reach the right cast.** An integer column read with `strip_tags`
 *   silently stores 0 for anything non-numeric; a nullable number that coerces to 0 turns "not
 *   supplied" into a real value.
 * - **the documentation block has to name the fields**, because it is what an integrator reads to
 *   discover the endpoint, and a `sort` parameter documented against the wrong field list is a
 *   support conversation.
 *
 * It also exercises this morning's `ensureTargetDirectory()` fix from the exact angle that produced
 * it: `src/Api/Controllers` is two levels below the application root and neither level exists in
 * this repository, which is the case the old bare `mkdir()` failed at — silently, reporting a file
 * it had not written.
 *
 * Generates into the repository and removes it afterwards, like the other generator tests here.
 * Both backends: {@see GeneratedApiControllerPostgreSQLTest}, and that lane is not a formality —
 * the primary key is detected from a `PrimaryKey` flag on PostgreSQL and a `Key` column on MySQL,
 * two different branches for the one decision that matters most.
 */
#[CoversClass(MakeCommandBase::class)]
class GeneratedApiControllerTest extends BaseTestCase
{
    private $db;

    private GeneratedApiDummyCommand $command;

    private string $table = '';

    /** Paths to remove in tearDown, deepest first. */
    private array $written = [];

    private const ENTITY = 'ApiProbe';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        if (!defined('INCLUDES')) {
            define('INCLUDES', 'src');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->table = 'apiprobe_' . bin2hex(random_bytes(4));
        $this->createProbeTable();

        $this->command = new GeneratedApiDummyCommand();
        $this->command->setDbTable($this->table);

        $consoleApp = new class extends \Symfony\Component\Console\Application {
            public $internalApplication;
        };
        $consoleApp->internalApplication = new class extends Application {
            public $applicationInfo = ['namespace' => 'App'];

            public $appName = '';

            public function __construct()
            {
            }

            public function init($settingsFile = ''): void
            {
            }
        };
        $this->command->setApplication($consoleApp);
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /**
     * A table with one column per branch of the type switch.
     *
     * Raw DDL rather than the schema builder, because the point is what `getColumns()` reports back
     * for each declared type — the generator reads that report, not the builder's intent, and the
     * two are only the same thing if the round trip is what everybody assumes.
     */
    private function createProbeTable(): void
    {
        $quote = $this->db->type === 'postgresql' ? '"' : '`';
        $q = static fn (string $name): string => $quote . $name . $quote;

        if ($this->db->type === 'postgresql') {
            $this->db->query(
                'CREATE TABLE ' . $q($this->table) . ' ('
                . $q('probeid') . ' SERIAL PRIMARY KEY, '
                . $q('title') . ' VARCHAR(255) NOT NULL, '
                . $q('subtitle') . ' VARCHAR(255) NULL, '
                . $q('quantity') . ' INTEGER NOT NULL, '
                . $q('optional_count') . ' INTEGER NULL, '
                . $q('ratio') . ' DOUBLE PRECISION NULL, '
                . $q('is_active') . ' BOOLEAN NULL, '
                . $q('notes') . ' TEXT NULL'
                . ')'
            );
        } else {
            $this->db->query(
                'CREATE TABLE ' . $q($this->table) . ' ('
                . $q('probeid') . ' INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . $q('title') . " VARCHAR(255) NOT NULL COMMENT 'The title', "
                . $q('subtitle') . ' VARCHAR(255) NULL, '
                . $q('quantity') . ' INT NOT NULL, '
                . $q('optional_count') . ' INT NULL, '
                . $q('ratio') . ' DOUBLE NULL, '
                . $q('is_active') . ' TINYINT(1) NULL, '
                . $q('notes') . ' TEXT NULL'
                . ') ENGINE=InnoDB'
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->written = [];

        if ($this->table !== '') {
            $quote = $this->db->type === 'postgresql' ? '"' : '`';
            try {
                $this->db->query('DROP TABLE IF EXISTS ' . $quote . $this->table . $quote);
            } catch (\Throwable) {
                // Nothing to drop.
            }
        }

        parent::tearDown();
    }

    /** Where `createApi()` writes, and what to clean up afterwards. */
    private function expectedFile(string $entity): string
    {
        $dir = ROOT . DS . INCLUDES . DS . 'Api' . DS . 'Controllers';
        $file = $dir . DS . ucfirst($entity) . '.php';

        // Deepest first: the file, then the two directories the generator may have created.
        $this->written = [$file, $dir, ROOT . DS . INCLUDES . DS . 'Api'];

        return $file;
    }

    private function generate(string $entity = self::ENTITY): string
    {
        $file = $this->expectedFile($entity);
        $this->command->callCreateApi($entity);

        $this->assertFileExists($file, 'the generator reported success and wrote no file');

        return (string) file_get_contents($file);
    }

    // ── The file gets written at all ──────────────────────────────────────────

    /**
     * The controller is written, two directories deep, into a tree that did not exist.
     *
     * This is the case the old bare `mkdir()` failed at: `Api/Controllers` is two levels below the
     * root, so on a project adding its first API controller the parent is missing too, and the
     * generator went on to report a file it had not written. The assertion is the file, because the
     * summary line was never the problem.
     */
    public function testTheControllerIsWrittenIntoATreeThatDidNotExist(): void
    {
        // Arrange
        $file = $this->expectedFile(self::ENTITY);
        $this->assertFileDoesNotExist($file, 'a previous run left this behind');

        // Act
        $summary = $this->command->callCreateApi(self::ENTITY);

        // Assert
        $this->assertFileExists($file);
        $this->assertStringContainsString($file, $summary, 'the summary names a different path');
        $this->assertStringContainsString('Controller created', $summary);
    }

    /**
     * Generating the same entity twice is refused rather than overwriting.
     *
     * The file is edited after it is generated — that is the workflow — so a second run silently
     * replacing it would discard whatever was written by hand. Refusing means the developer decides.
     */
    public function testGeneratingTwiceIsRefused(): void
    {
        // Arrange
        $this->generate();

        // Act & Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already exists');
        $this->command->callCreateApi(self::ENTITY);
    }

    /**
     * A table that is not there is named in the refusal.
     *
     * The generator's whole input is the schema, so this is the most common way to run it wrongly —
     * a typo, or a migration not yet run — and the table name is the one thing the developer needs
     * back.
     */
    public function testAMissingTableIsNamedInTheRefusal(): void
    {
        // Arrange
        $this->command->setDbTable('no_such_probe_table');

        // Act & Assert
        try {
            $this->command->callCreateApi('MissingProbe');
            $this->fail('a controller was generated from a table that does not exist');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('no_such_probe_table', $exception->getMessage());
        }
    }

    // ── What the derivation produces ──────────────────────────────────────────

    /**
     * The class and namespace are the ones the summary promises.
     *
     * The namespace decides whether the file is autoloadable at all, and a controller PHP cannot
     * load is a 404 on an endpoint the developer has just been told exists.
     */
    public function testTheClassAndNamespaceMatchTheSummary(): void
    {
        // Act
        $content = $this->generate();

        // Assert
        $this->assertStringContainsString('namespace App\\Api\\Controllers;', $content);
        $this->assertStringContainsString('class ApiProbe extends', $content);
        $this->assertStringContainsString('ApiCrudController', $content);
    }

    /**
     * The primary key is found — on either backend, by its own route.
     *
     * The one derivation with two implementations: PostgreSQL reports a `PrimaryKey` flag and MySQL
     * a `Key` column reading `PRI`. Both lanes run this, which is the point — a generator that
     * found the key on one backend and left it empty on the other would produce a controller whose
     * every single-record route addresses nothing, and only on half the installations.
     */
    public function testThePrimaryKeyIsFound(): void
    {
        // Act
        $content = $this->generate();

        // Assert
        $this->assertStringContainsString('probeid', $content, 'the primary key was not detected');
    }

    /**
     * The primary key is not a writable field.
     *
     * A `POST` or `PUT` body that can set the id is a request that can be pointed at an arbitrary
     * row. The generated blocks are where that would appear, so the assertion is that no assignment
     * of the key from request input exists anywhere in the file.
     */
    public function testThePrimaryKeyIsNotWritableFromTheRequest(): void
    {
        // Act
        $content = $this->generate();

        // Assert
        $this->assertStringNotContainsString(
            '$model->probeid = \\Pramnos\\Http\\Request::staticGet',
            $content,
            'the request can set the primary key, so it can address another row'
        );
    }

    /**
     * Each column type reaches the cast it should.
     *
     * An integer read through `strip_tags` stores 0 for anything non-numeric, and a string read as
     * an integer loses the value entirely — both of which look like the API "not saving" rather
     * than like a wrong cast in a generated file nobody has reread.
     */
    public function testEachColumnTypeReachesItsOwnCast(): void
    {
        // Act
        $content = $this->generate();

        // Assert — integers are read as integers
        $this->assertMatchesRegularExpression(
            "/quantity'[^\n]*'int'/",
            $content,
            'an integer column is not read as an integer'
        );

        // Strings are stripped of tags, which integers must not be
        $this->assertStringContainsString('strip_tags', $content, 'no string column was sanitised');

        // And the documentation types follow the column types
        $this->assertStringContainsString('@apiSuccess {Number} data.quantity', $content);
        $this->assertStringContainsString('@apiSuccess {String} data.title', $content);
    }

    /**
     * A nullable number that arrives empty is stored as null, not as zero.
     *
     * The distinction the generated block goes out of its way to make, and it is a real one: 0 is a
     * quantity and null is "not supplied". Collapsing them means an optional numeric field can
     * never be left blank once it has been set.
     */
    public function testANullableNumberStaysNullRatherThanBecomingZero(): void
    {
        // Act
        $content = $this->generate();

        // Assert
        $this->assertStringContainsString('$model->optional_count', $content);
        $this->assertMatchesRegularExpression(
            '/optional_count == 0\) \{\s*\n\s*\$model->optional_count = null;/',
            $content,
            'an empty optional number is stored as 0, so it can never be cleared'
        );
    }

    /**
     * A NOT NULL column is documented as required and a nullable one as optional.
     *
     * The brackets in an `@apiBody` line are what an integrator reads to know which fields they
     * must send. Documented the wrong way round, the first request they write fails validation for a
     * reason the documentation says cannot happen.
     */
    public function testRequiredAndOptionalFieldsAreDocumentedAsSuch(): void
    {
        // Act
        $content = $this->generate();

        // Assert
        $this->assertStringContainsString('@apiBody {Number} quantity', $content);
        $this->assertStringContainsString('@apiBody {Number} [optional_count]', $content);
        $this->assertStringContainsString('@apiBody {String} [subtitle]', $content);
    }

    /**
     * The sortable field list names the table's columns.
     *
     * It is interpolated into the `sort` and `fields` parameter documentation, which is how an
     * integrator discovers what may be sorted on. An empty or stale list is a documented parameter
     * whose accepted values are a guess.
     */
    public function testTheDocumentedFieldListNamesTheColumns(): void
    {
        // Act
        $content = $this->generate();

        // Assert
        foreach (['probeid', 'title', 'quantity', 'notes'] as $column) {
            $this->assertStringContainsString($column, $content, $column . ' is not in the file');
        }

        $this->assertMatchesRegularExpression(
            '/\[sort\][^\n]*probeid, title/',
            $content,
            'the sortable field list is not the table\'s columns in order'
        );
    }

    /**
     * The generated file is valid PHP.
     *
     * The assertion that makes every other one here worth making: a file with a syntax error is one
     * `require` away from taking down the application that autoloads it, and the developer's first
     * sight of it is a fatal rather than a class.
     */
    public function testTheGeneratedFileIsValidPhp(): void
    {
        // Arrange
        $file = $this->expectedFile(self::ENTITY);
        $this->command->callCreateApi(self::ENTITY);

        // Act
        $output = [];
        $status = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        // Assert
        $this->assertSame(0, $status, 'the generated controller does not parse: ' . implode("\n", $output));
    }
}
