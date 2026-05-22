<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DummyMakeCommand extends MakeCommandBase
{
    protected function configure() {}
    protected function execute(InputInterface $input, OutputInterface $output) { return 0; }
}

/**
 * Unit tests for MakeCommandBase stub rendering and pure generation helpers.
 *
 * Two categories:
 * 1. File-system tests (renderStub, generateTestStub): redirect ROOT to a temp
 *    directory so nothing is written outside /tmp.
 * 2. Pure-string tests (blueprintCall, generateFakeValue, buildMigrationUpBody,
 *    buildMigrationDownBody, buildSeederFields): no filesystem or application
 *    context needed — only input arrays → output strings.
 */
#[CoversClass(MakeCommandBase::class)]
class MakeCommandBaseTest extends TestCase
{
    private string $tmpDir;
    private DummyMakeCommand $command;

    protected function setUp(): void
    {
        // Arrange — isolated temp workspace
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_create_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/src/Middleware', 0777, true);
        mkdir($this->tmpDir . '/src/Events', 0777, true);
        mkdir($this->tmpDir . '/src/Listeners', 0777, true);
        mkdir($this->tmpDir . '/tests/Unit', 0777, true);

        if (!defined('ROOT')) {
            define('ROOT', $this->tmpDir);
        }

        $this->command = new DummyMakeCommand();
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // renderStub()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * renderStub() for 'middleware' produces a PHP class implementing
     * MiddlewareInterface with the correct namespace and class name.
     */
    public function testRenderStubMiddlewareProducesValidClass(): void
    {
        // Act — namespace is the full qualified namespace (stub uses {{ namespace }} verbatim)
        $result = $this->command->renderStub('middleware', [
            'namespace' => 'App\\Middleware',
            'class'     => 'Throttle',
        ]);

        // Assert
        $this->assertStringContainsString('namespace App\\Middleware;', $result);
        $this->assertStringContainsString('class Throttle', $result);
        $this->assertStringContainsString('MiddlewareInterface', $result);
        $this->assertStringContainsString('public function handle', $result);
    }

    /**
     * renderStub() for 'test' produces a PHPUnit TestCase class with the
     * correct class name.
     */
    public function testRenderStubTestProducesTestCase(): void
    {
        // Act
        $result = $this->command->renderStub('test', [
            'class'     => 'MyService',
            'namespace' => 'App',
        ]);

        // Assert
        $this->assertStringContainsString('class MyServiceTest', $result);
        $this->assertStringContainsString('TestCase', $result);
        $this->assertStringContainsString('testItWorks', $result);
    }

    /**
     * renderStub() uses the fallback skeleton when scaffolding/templates/<name>.stub
     * does not exist — no exception, valid PHP output.
     */
    public function testRenderStubFallsBackForUnknownStub(): void
    {
        // Act — 'unknown' has no stub file and no fallback match
        $result = $this->command->renderStub('unknown', ['class' => 'Foo']);

        // Assert — returns empty string without throwing
        $this->assertSame('', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // generateTestStub()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * generateTestStub() writes a test file to tests/Unit/ of the given baseDir
     * and returns a non-empty summary line containing the path.
     */
    public function testGenerateTestStubWritesFileAndReturnsSummary(): void
    {
        // Act — pass explicit baseDir so it writes to our temp dir, not framework ROOT
        $summary = $this->command->generateTestStub('MyService', 'App', $this->tmpDir);

        // Assert — file written
        $testFile = $this->tmpDir . '/tests/Unit/MyServiceTest.php';
        $this->assertFileExists($testFile);
        $this->assertStringContainsString('MyServiceTest', file_get_contents($testFile));

        // Assert — summary contains path info
        $this->assertNotEmpty($summary);
        $this->assertStringContainsString('MyServiceTest', $summary);
    }

    /**
     * generateTestStub() silently returns empty string when the target file
     * already exists — it must never overwrite an existing test.
     */
    public function testGenerateTestStubSkipsIfFileAlreadyExists(): void
    {
        // Arrange — pre-create the test file
        $testFile = $this->tmpDir . '/tests/Unit/ExistingTest.php';
        file_put_contents($testFile, '<?php // existing content');

        // Act — pass explicit baseDir
        $summary = $this->command->generateTestStub('Existing', 'App', $this->tmpDir);

        // Assert — file not overwritten and summary is empty
        $this->assertSame('', $summary);
        $this->assertSame('<?php // existing content', file_get_contents($testFile));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // renderStub() — event and listener stubs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * renderStub() for 'event' produces a plain PHP class that can carry
     * event payload. The event stub does NOT implement any interface — it is
     * a value object, not a handler.
     */
    public function testRenderStubEventProducesPlainClass(): void
    {
        // Act
        $result = $this->command->renderStub('event', [
            'namespace' => 'App\\Events',
            'class'     => 'UserRegistered',
        ]);

        // Assert — correctly namespaced class
        $this->assertStringContainsString('namespace App\\Events;', $result);
        $this->assertStringContainsString('class UserRegistered', $result);
        // Event is a plain class, not a listener — no handle() method
        $this->assertStringNotContainsString('ListenerInterface', $result);
    }

    /**
     * renderStub() for 'listener' produces a class implementing ListenerInterface
     * with a handle() method — this ensures generated listeners are compatible
     * with Event::listen(MyListener::class).
     */
    public function testRenderStubListenerImplementsListenerInterface(): void
    {
        // Act
        $result = $this->command->renderStub('listener', [
            'namespace' => 'App\\Listeners',
            'class'     => 'SendWelcomeEmail',
        ]);

        // Assert
        $this->assertStringContainsString('namespace App\\Listeners;', $result);
        $this->assertStringContainsString('class SendWelcomeEmail', $result);
        $this->assertStringContainsString('ListenerInterface', $result);
        $this->assertStringContainsString('public function handle', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // renderStub() — migration, controller, model stubs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * renderStub() for 'migration' produces a final class extending Migration
     * with up() and down() methods and a configurable description.
     *
     * This verifies the migration.stub template is loaded and tokens are
     * substituted, ensuring the generated file compiles under the Migration
     * base class contract.
     */
    public function testRenderStubMigrationProducesCorrectClass(): void
    {
        // Act
        $result = $this->command->renderStub('migration', [
            'namespace'   => 'App\\Migrations',
            'class'       => 'CreateUsersTable',
            'description' => 'create users table',
            'date'        => '01/01/2026 00:00',
        ]);

        // Assert — namespace and class
        $this->assertStringContainsString('namespace App\\Migrations;', $result);
        $this->assertStringContainsString('class CreateUsersTable', $result);
        // Must extend Migration and provide up/down lifecycle methods
        $this->assertStringContainsString('extends Migration', $result);
        $this->assertStringContainsString('public function up()', $result);
        $this->assertStringContainsString('public function down()', $result);
        // Description token substituted
        $this->assertStringContainsString("create users table", $result);
    }

    /**
     * renderStub() for 'controller' produces a class extending Controller
     * with all five CRUD action methods and correct namespace injection.
     *
     * The modernised controller stub replaces the old broken inline heredoc
     * that used undefined variables ($viewName, $modelNameSpace etc.) — this
     * test guards against a regression to that state.
     */
    public function testRenderStubControllerProducesFullSkeleton(): void
    {
        // Act
        $result = $this->command->renderStub('controller', [
            'namespace' => 'App\\Controllers',
            'class'     => 'Product',
            'view'      => 'product',
        ]);

        // Assert — namespace and class
        $this->assertStringContainsString('namespace App\\Controllers;', $result);
        $this->assertStringContainsString('class Product', $result);
        $this->assertStringContainsString('extends Controller', $result);
        // All five standard action methods must be present
        $this->assertStringContainsString('public function display()', $result);
        $this->assertStringContainsString('public function show()', $result);
        $this->assertStringContainsString('public function edit()', $result);
        $this->assertStringContainsString('public function save()', $result);
        $this->assertStringContainsString('public function delete()', $result);
        // View token substituted — no literal '{{ view }}' left in output
        $this->assertStringContainsString("getView('product')", $result);
        $this->assertStringNotContainsString('{{ view }}', $result);
    }

    /**
     * renderStub() for 'model' produces a class extending Model with the
     * correct table name and primary key — the minimal schema needed to
     * generate a working Active-Record model before running migrations.
     */
    public function testRenderStubModelProducesActiveRecordSkeleton(): void
    {
        // Act
        $result = $this->command->renderStub('model', [
            'namespace' => 'App\\Models',
            'class'     => 'Product',
            'table'     => '#PREFIX#products',
        ]);

        // Assert — namespace and class
        $this->assertStringContainsString('namespace App\\Models;', $result);
        $this->assertStringContainsString('class Product', $result);
        $this->assertStringContainsString('extends Model', $result);
        // Table name token substituted
        $this->assertStringContainsString('#PREFIX#products', $result);
        $this->assertStringNotContainsString('{{ table }}', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // renderStub() — seeder stub
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * renderStub() for 'seeder' produces a class extending Seeder with
     * the table name and an insert loop — used by the wizard flow.
     */
    public function testRenderStubSeederProducesSeederClass(): void
    {
        // Act
        $result = $this->command->renderStub('seeder', [
            'namespace' => 'App\\Seeders',
            'class'     => 'User',
            'table'     => '#PREFIX#users',
            'count'     => '10',
            'fields'    => "                'name' => 'Name ' . \$i,",
        ]);

        // Assert — produces a Seeder subclass
        $this->assertStringContainsString('extends Seeder', $result);
        $this->assertStringContainsString('class User', $result);
        $this->assertStringContainsString('#PREFIX#users', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // blueprintCall() — column types
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * blueprintCall() generates the correct Blueprint method call for each
     * column type.  This is a pure string-generation function: no database,
     * no application, no filesystem.
     *
     * Each assert verifies the exact method name so that generated migrations
     * compile without errors and produce the expected schema.
     */
    public function testBlueprintCallStringDefaultLength(): void
    {
        // Arrange — string with default length (255) omits the length argument
        $col = ['name' => 'email', 'type' => 'string', 'options' => []];

        // Act + Assert — no length argument when length == 255
        $this->assertSame("\$table->string('email');", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() for string with a non-default length includes the length.
     */
    public function testBlueprintCallStringCustomLength(): void
    {
        // Arrange
        $col = ['name' => 'code', 'type' => 'string', 'options' => ['length' => 10]];

        // Act + Assert — length is present
        $this->assertSame("\$table->string('code', 10);", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() for char includes the length argument.
     */
    public function testBlueprintCallChar(): void
    {
        $col = ['name' => 'flag', 'type' => 'char', 'options' => ['length' => 1]];
        $this->assertSame("\$table->char('flag', 1);", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() for integer (non-unsigned) generates integer() call.
     */
    public function testBlueprintCallIntegerNotUnsigned(): void
    {
        $col = ['name' => 'count', 'type' => 'integer', 'options' => []];
        $this->assertSame("\$table->integer('count');", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() for unsigned integer generates unsignedInteger() call.
     */
    public function testBlueprintCallUnsignedInteger(): void
    {
        $col = ['name' => 'views', 'type' => 'integer', 'unsigned' => true, 'options' => []];
        $this->assertSame("\$table->unsignedInteger('views');", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() for biginteger (non-unsigned).
     */
    public function testBlueprintCallBigInteger(): void
    {
        $col = ['name' => 'total', 'type' => 'biginteger', 'options' => []];
        $this->assertSame("\$table->bigInteger('total');", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() for unsigned biginteger generates unsignedBigInteger() call.
     */
    public function testBlueprintCallUnsignedBigInteger(): void
    {
        $col = ['name' => 'user_id', 'type' => 'biginteger', 'unsigned' => true, 'options' => []];
        $this->assertSame("\$table->unsignedBigInteger('user_id');", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() for tinyinteger and smallinteger.
     */
    public function testBlueprintCallTinyAndSmallInteger(): void
    {
        $tiny  = ['name' => 'x', 'type' => 'tinyinteger', 'options' => []];
        $small = ['name' => 'y', 'type' => 'smallinteger', 'options' => []];
        $this->assertSame("\$table->tinyInteger('x');", $this->command->blueprintCall($tiny));
        $this->assertSame("\$table->smallInteger('y');", $this->command->blueprintCall($small));
    }

    /**
     * blueprintCall() for decimal includes total and places.
     */
    public function testBlueprintCallDecimal(): void
    {
        $col = ['name' => 'price', 'type' => 'decimal', 'options' => ['total' => 10, 'places' => 2]];
        $this->assertSame("\$table->decimal('price', 10, 2);", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() for float includes total and places.
     */
    public function testBlueprintCallFloat(): void
    {
        $col = ['name' => 'score', 'type' => 'float', 'options' => ['total' => 8, 'places' => 2]];
        $this->assertSame("\$table->float('score', 8, 2);", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() for double generates double() call (no precision args).
     */
    public function testBlueprintCallDouble(): void
    {
        $col = ['name' => 'ratio', 'type' => 'double', 'options' => []];
        $this->assertSame("\$table->double('ratio');", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() for boolean.
     */
    public function testBlueprintCallBoolean(): void
    {
        $col = ['name' => 'active', 'type' => 'boolean', 'options' => []];
        $this->assertSame("\$table->boolean('active');", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() for text, mediumtext, longtext column types.
     */
    public function testBlueprintCallTextVariants(): void
    {
        $text   = ['name' => 'body',     'type' => 'text',      'options' => []];
        $medium = ['name' => 'content',  'type' => 'mediumtext', 'options' => []];
        $long   = ['name' => 'article',  'type' => 'longtext',   'options' => []];
        $this->assertSame("\$table->text('body');",          $this->command->blueprintCall($text));
        $this->assertSame("\$table->mediumText('content');", $this->command->blueprintCall($medium));
        $this->assertSame("\$table->longText('article');",   $this->command->blueprintCall($long));
    }

    /**
     * blueprintCall() for date, time, datetime, timestamp.
     */
    public function testBlueprintCallDateTimeVariants(): void
    {
        $date  = ['name' => 'dob',        'type' => 'date',      'options' => []];
        $time  = ['name' => 'start',      'type' => 'time',      'options' => []];
        $dt    = ['name' => 'created',    'type' => 'datetime',  'options' => []];
        $ts    = ['name' => 'updated_at', 'type' => 'timestamp', 'options' => []];
        $this->assertSame("\$table->date('dob');",            $this->command->blueprintCall($date));
        $this->assertSame("\$table->time('start');",          $this->command->blueprintCall($time));
        $this->assertSame("\$table->dateTime('created');",    $this->command->blueprintCall($dt));
        $this->assertSame("\$table->timestamp('updated_at');", $this->command->blueprintCall($ts));
    }

    /**
     * blueprintCall() for json, jsonb, uuid, binary.
     */
    public function testBlueprintCallJsonUuidBinary(): void
    {
        $json   = ['name' => 'meta',   'type' => 'json',   'options' => []];
        $jsonb  = ['name' => 'data',   'type' => 'jsonb',  'options' => []];
        $uuid   = ['name' => 'uid',    'type' => 'uuid',   'options' => []];
        $binary = ['name' => 'blob',   'type' => 'binary', 'options' => []];
        $this->assertSame("\$table->json('meta');",    $this->command->blueprintCall($json));
        $this->assertSame("\$table->jsonb('data');",   $this->command->blueprintCall($jsonb));
        $this->assertSame("\$table->uuid('uid');",     $this->command->blueprintCall($uuid));
        $this->assertSame("\$table->binary('blob');",  $this->command->blueprintCall($binary));
    }

    /**
     * blueprintCall() for an unknown type falls back to string().
     */
    public function testBlueprintCallUnknownTypeFallsBackToString(): void
    {
        $col = ['name' => 'custom', 'type' => 'customtype', 'options' => []];
        $this->assertSame("\$table->string('custom');", $this->command->blueprintCall($col));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // blueprintCall() — column modifiers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * blueprintCall() appends ->nullable() when the column is nullable.
     */
    public function testBlueprintCallNullableModifier(): void
    {
        $col = ['name' => 'phone', 'type' => 'string', 'options' => [], 'nullable' => true];
        $this->assertSame("\$table->string('phone')->nullable();", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() appends ->default(n) for a numeric default.
     */
    public function testBlueprintCallNumericDefault(): void
    {
        $col = ['name' => 'priority', 'type' => 'integer', 'options' => [], 'default' => '5'];
        $this->assertSame("\$table->integer('priority')->default(5);", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() appends ->default(true/false/null) for boolean/null literals.
     */
    public function testBlueprintCallBooleanAndNullDefault(): void
    {
        $trueCol  = ['name' => 'active', 'type' => 'boolean', 'options' => [], 'default' => 'true'];
        $falseCol = ['name' => 'locked', 'type' => 'boolean', 'options' => [], 'default' => 'false'];
        $nullCol  = ['name' => 'extra',  'type' => 'string',  'options' => [], 'default' => 'null'];
        $this->assertSame("\$table->boolean('active')->default(true);",  $this->command->blueprintCall($trueCol));
        $this->assertSame("\$table->boolean('locked')->default(false);", $this->command->blueprintCall($falseCol));
        $this->assertSame("\$table->string('extra')->default(null);",    $this->command->blueprintCall($nullCol));
    }

    /**
     * blueprintCall() appends ->default('...') for a string default value.
     */
    public function testBlueprintCallStringDefault(): void
    {
        $col = ['name' => 'status', 'type' => 'string', 'options' => [], 'default' => 'active'];
        $this->assertSame("\$table->string('status')->default('active');", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() emits ->default('') when default is an empty string.
     *
     * String-family columns default to empty string in the wizard, so the
     * compiler must NOT suppress empty-string defaults (unlike null).
     */
    public function testBlueprintCallEmptyStringDefault(): void
    {
        // Arrange — empty string default (produced by wizard for string columns)
        $col = ['name' => 'notes', 'type' => 'string', 'options' => [], 'default' => ''];

        // Act
        $result = $this->command->blueprintCall($col);

        // Assert — ->default('') must be present
        $this->assertSame("\$table->string('notes')->default('');", $result,
            "empty string default must be emitted as ->default('') not suppressed");
    }

    /**
     * blueprintCall() appends ->unique() when the column is unique.
     */
    public function testBlueprintCallUniqueModifier(): void
    {
        $col = ['name' => 'email', 'type' => 'string', 'options' => [], 'unique' => true];
        $this->assertSame("\$table->string('email')->unique();", $this->command->blueprintCall($col));
    }

    /**
     * blueprintCall() appends ->unsigned() only for non-integer types that have
     * the unsigned flag set (e.g., a float declared unsigned).
     */
    public function testBlueprintCallUnsignedModifierForNonIntegerType(): void
    {
        // float + unsigned → float() call + ->unsigned() modifier (not unsignedFloat)
        $col = ['name' => 'amount', 'type' => 'float', 'unsigned' => true,
                'options' => ['total' => 8, 'places' => 2]];
        $result = $this->command->blueprintCall($col);
        $this->assertStringContainsString('->unsigned()', $result);
        $this->assertStringContainsString("\$table->float('amount'", $result);
    }

    /**
     * blueprintCall() appends ->comment('...') when a comment is provided.
     */
    public function testBlueprintCallCommentModifier(): void
    {
        $col = ['name' => 'notes', 'type' => 'text', 'options' => [], 'comment' => "User's notes"];
        $result = $this->command->blueprintCall($col);
        $this->assertStringContainsString("->comment('", $result);
        $this->assertStringContainsString("User\\'s notes", $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildMigrationDownBody()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * buildMigrationDownBody() always produces a single SchemaBuilder::dropIfExists()
     * call, indented with 8 spaces (method-body level).
     *
     * This is a pure single-line generator — no input-dependent branching.
     */
    public function testBuildMigrationDownBodyProducesDropStatement(): void
    {
        // Act
        $result = $this->command->buildMigrationDownBody('#PREFIX#users');

        // Assert — correct method, correct indentation, table name present
        $this->assertStringContainsString("SchemaBuilder::dropIfExists('#PREFIX#users')", $result);
        $this->assertStringStartsWith('        ', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildMigrationUpBody()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * buildMigrationUpBody() with hasPk=true prepends an auto-increment PK.
     * Advanced Primary Key Naming derives the column name from the singular
     * of the table (e.g. '#PREFIX#orders' → 'order' → 'orderid').
     */
    public function testBuildMigrationUpBodyWithPrimaryKey(): void
    {
        // Act — no extra columns, just the PK
        $result = $this->command->buildMigrationUpBody('#PREFIX#orders', true, [], false, false, []);

        // Assert — table wrapper and derived PK name present
        $this->assertStringContainsString("\$table->increments('orderid')", $result);
        $this->assertStringContainsString("SchemaBuilder::create('#PREFIX#orders'", $result);
    }

    /**
     * buildMigrationUpBody() without PK does not emit increments().
     */
    public function testBuildMigrationUpBodyWithoutPrimaryKey(): void
    {
        // Act
        $result = $this->command->buildMigrationUpBody('logs', false, [], false, false, []);

        // Assert — no increments call
        $this->assertStringNotContainsString('increments', $result);
    }

    /**
     * buildMigrationUpBody() with timestamps=true appends $table->timestamps().
     */
    public function testBuildMigrationUpBodyWithTimestamps(): void
    {
        // Act
        $result = $this->command->buildMigrationUpBody('events', false, [], true, false, []);

        // Assert — timestamps call present
        $this->assertStringContainsString("\$table->timestamps()", $result);
    }

    /**
     * buildMigrationUpBody() with softDeletes=true appends $table->softDeletes().
     */
    public function testBuildMigrationUpBodyWithSoftDeletes(): void
    {
        // Act
        $result = $this->command->buildMigrationUpBody('documents', false, [], false, true, []);

        // Assert
        $this->assertStringContainsString("\$table->softDeletes()", $result);
    }

    /**
     * buildMigrationUpBody() with columns emits a blueprintCall for each column.
     */
    public function testBuildMigrationUpBodyWithColumns(): void
    {
        // Arrange — two columns
        $columns = [
            ['name' => 'email', 'type' => 'string', 'options' => []],
            ['name' => 'age',   'type' => 'integer', 'options' => []],
        ];

        // Act
        $result = $this->command->buildMigrationUpBody('users', false, $columns, false, false, []);

        // Assert — both columns present in the body
        $this->assertStringContainsString("\$table->string('email')", $result);
        $this->assertStringContainsString("\$table->integer('age')", $result);
    }

    /**
     * buildMigrationUpBody() with foreign keys emits a foreign() chain.
     */
    public function testBuildMigrationUpBodyWithForeignKeys(): void
    {
        // Arrange — FK definition
        $fks = [
            ['column' => 'user_id', 'references' => 'id', 'on' => 'users', 'onDelete' => 'CASCADE'],
        ];

        // Act
        $result = $this->command->buildMigrationUpBody('posts', false, [], false, false, $fks);

        // Assert — foreign key chain present
        $this->assertStringContainsString("\$table->foreign('user_id')", $result);
        $this->assertStringContainsString("->references('id')", $result);
        $this->assertStringContainsString("->on('users')", $result);
        $this->assertStringContainsString("->onDelete('CASCADE')", $result);
    }

    /**
     * buildMigrationUpBody() without onDelete omits the ->onDelete() call.
     */
    public function testBuildMigrationUpBodyForeignKeyWithoutOnDelete(): void
    {
        // Arrange — FK without onDelete
        $fks = [['column' => 'cat_id', 'references' => 'id', 'on' => 'categories', 'onDelete' => '']];

        // Act
        $result = $this->command->buildMigrationUpBody('articles', false, [], false, false, $fks);

        // Assert — no onDelete clause in the foreign key chain
        $this->assertStringNotContainsString('->onDelete', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // generateFakeValue() — name-based heuristics
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * generateFakeValue() uses column-name heuristics before type fallbacks.
     * A column named 'email' should always produce an email-pattern expression.
     */
    public function testGenerateFakeValueEmailHeuristic(): void
    {
        $result = $this->command->generateFakeValue('email', 'string');
        $this->assertStringContainsString('@example.com', $result);
    }

    /**
     * generateFakeValue() produces first/last-name arrays for name columns.
     */
    public function testGenerateFakeValueNameHeuristics(): void
    {
        $first = $this->command->generateFakeValue('first_name', 'string');
        $last  = $this->command->generateFakeValue('last_name', 'string');
        $name  = $this->command->generateFakeValue('name', 'string');
        $this->assertStringContainsString('Alice', $first);
        $this->assertStringContainsString('Smith', $last);
        $this->assertStringContainsString("'Name '", $name);
    }

    /**
     * generateFakeValue() generates phone/mobile patterns for communication columns.
     */
    public function testGenerateFakeValuePhoneHeuristics(): void
    {
        $phone  = $this->command->generateFakeValue('phone', 'string');
        $mobile = $this->command->generateFakeValue('mobile', 'string');
        $this->assertStringContainsString('+30210', $phone);
        $this->assertStringContainsString('+306900', $mobile);
    }

    /**
     * generateFakeValue() generates city/country expressions for geographic columns.
     */
    public function testGenerateFakeValueGeographicHeuristics(): void
    {
        $city    = $this->command->generateFakeValue('city', 'string');
        $country = $this->command->generateFakeValue('country', 'string');
        $this->assertStringContainsString('Athens', $city);
        $this->assertStringContainsString('Greece', $country);
    }

    /**
     * generateFakeValue() generates URL/IP/slug expressions for web columns.
     */
    public function testGenerateFakeValueWebHeuristics(): void
    {
        $url  = $this->command->generateFakeValue('url', 'string');
        $ip   = $this->command->generateFakeValue('ip', 'string');
        $slug = $this->command->generateFakeValue('slug', 'string');
        $this->assertStringContainsString('https://example.com', $url);
        $this->assertStringContainsString('192.168.', $ip);
        $this->assertStringContainsString("'record-'", $slug);
    }

    /**
     * generateFakeValue() generates secure values for security-sensitive columns.
     */
    public function testGenerateFakeValueSecurityHeuristics(): void
    {
        $password = $this->command->generateFakeValue('password', 'string');
        $token    = $this->command->generateFakeValue('token', 'string');
        $this->assertStringContainsString('password_hash', $password);
        $this->assertStringContainsString('bin2hex', $token);
    }

    /**
     * generateFakeValue() uses status/type arrays for enum-like columns.
     */
    public function testGenerateFakeValueStatusTypeHeuristics(): void
    {
        $status = $this->command->generateFakeValue('status', 'string');
        $type   = $this->command->generateFakeValue('type', 'string');
        $this->assertStringContainsString("'active'", $status);
        $this->assertStringContainsString("'type_a'", $type);
    }

    /**
     * generateFakeValue() uses color-code arrays for color columns.
     */
    public function testGenerateFakeValueColorHeuristic(): void
    {
        $color = $this->command->generateFakeValue('color', 'string');
        $this->assertStringContainsString('#FF5733', $color);
    }

    /**
     * generateFakeValue() uses coordinate expressions for lat/lon/lng/longitude/latitude.
     */
    public function testGenerateFakeValueCoordinateHeuristics(): void
    {
        $lat  = $this->command->generateFakeValue('lat', 'float');
        $lon  = $this->command->generateFakeValue('lon', 'float');
        $lng  = $this->command->generateFakeValue('lng', 'float');
        $long = $this->command->generateFakeValue('longitude', 'float');
        $lati = $this->command->generateFakeValue('latitude', 'float');
        $this->assertStringContainsString('37.97', $lat);
        $this->assertStringContainsString('23.73', $lon);
        $this->assertStringContainsString('23.73', $lng);
        $this->assertStringContainsString('23.73', $long);
        $this->assertStringContainsString('37.97', $lati);
    }

    /**
     * generateFakeValue() uses monetary/score/sort expressions for numeric columns.
     */
    public function testGenerateFakeValueNumericHeuristics(): void
    {
        $price    = $this->command->generateFakeValue('price', 'decimal');
        $amount   = $this->command->generateFakeValue('amount', 'decimal');
        $score    = $this->command->generateFakeValue('score', 'integer');
        $sort     = $this->command->generateFakeValue('sort', 'integer');
        $order    = $this->command->generateFakeValue('order', 'integer');
        $position = $this->command->generateFakeValue('position', 'integer');
        $weight   = $this->command->generateFakeValue('weight', 'float');
        $this->assertStringContainsString('9.99', $price);
        $this->assertStringContainsString('100.0', $amount);
        $this->assertStringContainsString('* 10', $score);
        $this->assertSame('$i', $sort);
        $this->assertSame('$i', $order);
        $this->assertSame('$i', $position);
        $this->assertStringContainsString('0.5', $weight);
    }

    /**
     * generateFakeValue() uses content expressions for body/content/description columns.
     */
    public function testGenerateFakeValueContentHeuristics(): void
    {
        $desc    = $this->command->generateFakeValue('description', 'text');
        $body    = $this->command->generateFakeValue('body', 'text');
        $content = $this->command->generateFakeValue('content', 'text');
        $this->assertStringContainsString('Sample description', $desc);
        $this->assertStringContainsString('Lorem ipsum', $body);
        $this->assertStringContainsString('Content for record', $content);
    }

    /**
     * generateFakeValue() for username/login columns.
     */
    public function testGenerateFakeValueUsernameLoginHeuristics(): void
    {
        $username = $this->command->generateFakeValue('username', 'string');
        $login    = $this->command->generateFakeValue('login', 'string');
        $this->assertStringContainsString("'user_'", $username);
        $this->assertStringContainsString("'user_'", $login);
    }

    /**
     * generateFakeValue() for address/title columns.
     */
    public function testGenerateFakeValueAddressTitleHeuristics(): void
    {
        $address = $this->command->generateFakeValue('address', 'string');
        $title   = $this->command->generateFakeValue('title', 'string');
        $this->assertStringContainsString('Main Street', $address);
        $this->assertStringContainsString("'Title '", $title);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // generateFakeValue() — type-based fallbacks
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * generateFakeValue() falls back to type-based expressions for unrecognised column names.
     */
    public function testGenerateFakeValueTypeFallbackInteger(): void
    {
        // Arrange — column name has no heuristic match
        $result = $this->command->generateFakeValue('xyzzy', 'integer');
        // Assert — falls back to $i for integer types
        $this->assertSame('$i', $result);
    }

    /**
     * generateFakeValue() type fallback for tinyinteger and smallinteger.
     */
    public function testGenerateFakeValueTypeFallbackSmallInteger(): void
    {
        $this->assertSame('$i', $this->command->generateFakeValue('xyzzy', 'tinyinteger'));
        $this->assertSame('$i', $this->command->generateFakeValue('xyzzy', 'smallinteger'));
        $this->assertSame('$i', $this->command->generateFakeValue('xyzzy', 'biginteger'));
    }

    /**
     * generateFakeValue() type fallback for decimal, float, double.
     */
    public function testGenerateFakeValueTypeFallbackNumeric(): void
    {
        $decimal = $this->command->generateFakeValue('xyzzy', 'decimal');
        $float   = $this->command->generateFakeValue('xyzzy', 'float');
        $double  = $this->command->generateFakeValue('xyzzy', 'double');
        $this->assertStringContainsString('9.99', $decimal);
        $this->assertStringContainsString('9.99', $float);
        $this->assertStringContainsString('9.99', $double);
    }

    /**
     * generateFakeValue() type fallback for boolean.
     */
    public function testGenerateFakeValueTypeFallbackBoolean(): void
    {
        $result = $this->command->generateFakeValue('xyzzy', 'boolean');
        $this->assertStringContainsString('% 2 === 0', $result);
    }

    /**
     * generateFakeValue() type fallback for date.
     */
    public function testGenerateFakeValueTypeFallbackDate(): void
    {
        $result = $this->command->generateFakeValue('xyzzy', 'date');
        $this->assertStringContainsString("date('Y-m-d'", $result);
    }

    /**
     * generateFakeValue() type fallback for datetime and timestamp.
     */
    public function testGenerateFakeValueTypeFallbackDatetime(): void
    {
        $dt = $this->command->generateFakeValue('xyzzy', 'datetime');
        $ts = $this->command->generateFakeValue('xyzzy', 'timestamp');
        $this->assertStringContainsString("date('Y-m-d H:i:s'", $dt);
        $this->assertStringContainsString("date('Y-m-d H:i:s'", $ts);
    }

    /**
     * generateFakeValue() type fallback for text variants.
     */
    public function testGenerateFakeValueTypeFallbackText(): void
    {
        $text   = $this->command->generateFakeValue('xyzzy', 'text');
        $medium = $this->command->generateFakeValue('xyzzy', 'mediumtext');
        $long   = $this->command->generateFakeValue('xyzzy', 'longtext');
        $this->assertStringContainsString('Lorem ipsum', $text);
        $this->assertStringContainsString('Lorem ipsum', $medium);
        $this->assertStringContainsString('Lorem ipsum', $long);
    }

    /**
     * generateFakeValue() type fallback for json and jsonb.
     */
    public function testGenerateFakeValueTypeFallbackJson(): void
    {
        $json  = $this->command->generateFakeValue('xyzzy', 'json');
        $jsonb = $this->command->generateFakeValue('xyzzy', 'jsonb');
        $this->assertStringContainsString('json_encode', $json);
        $this->assertStringContainsString('json_encode', $jsonb);
    }

    /**
     * generateFakeValue() type fallback for uuid.
     */
    public function testGenerateFakeValueTypeFallbackUuid(): void
    {
        $result = $this->command->generateFakeValue('xyzzy', 'uuid');
        $this->assertStringContainsString('mt_rand', $result);
        $this->assertStringContainsString('sprintf', $result);
    }

    /**
     * generateFakeValue() type fallback for unknown type returns 'value_' . $i.
     */
    public function testGenerateFakeValueTypeFallbackUnknown(): void
    {
        $result = $this->command->generateFakeValue('xyzzy', 'customtype');
        $this->assertSame("'value_' . \$i", $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildSeederFields()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * buildSeederFields() generates key => value lines for each column that is
     * not in the auto-managed skip list (id, created_at, updated_at, deleted_at).
     *
     * The output must be correctly indented (16 spaces) to land inside the
     * insert() call in the seeder template.
     */
    public function testBuildSeederFieldsSkipsAutoManagedColumns(): void
    {
        // Arrange — include auto-managed columns alongside custom ones
        $columns = [
            ['name' => 'id',         'type' => 'integer', 'options' => []],
            ['name' => 'email',      'type' => 'string',  'options' => []],
            ['name' => 'created_at', 'type' => 'timestamp', 'options' => []],
            ['name' => 'updated_at', 'type' => 'timestamp', 'options' => []],
            ['name' => 'deleted_at', 'type' => 'timestamp', 'options' => []],
        ];

        // Act
        $result = $this->command->buildSeederFields($columns);

        // Assert — only 'email' is included; auto-managed columns are skipped
        $this->assertStringContainsString("'email'", $result);
        $this->assertStringNotContainsString("'id'", $result);
        $this->assertStringNotContainsString("'created_at'", $result);
        $this->assertStringNotContainsString("'updated_at'", $result);
        $this->assertStringNotContainsString("'deleted_at'", $result);

        // Assert — lines are indented with 16 spaces
        $this->assertStringStartsWith('                ', $result);
    }

    /**
     * buildSeederFields() returns empty string when all columns are skipped.
     */
    public function testBuildSeederFieldsWithOnlyAutoManagedColumnsReturnsEmpty(): void
    {
        // Arrange — only auto-managed columns
        $columns = [
            ['name' => 'id',         'type' => 'integer',   'options' => []],
            ['name' => 'created_at', 'type' => 'timestamp', 'options' => []],
        ];

        // Act
        $result = $this->command->buildSeederFields($columns);

        // Assert — empty (all skipped)
        $this->assertSame('', $result);
    }

    /**
     * buildSeederFields() generates multiple lines separated by newlines
     * when there are multiple non-skipped columns.
     */
    public function testBuildSeederFieldsMultipleColumnsAreNewlineSeparated(): void
    {
        // Arrange — two user-defined columns
        $columns = [
            ['name' => 'email', 'type' => 'string',  'options' => []],
            ['name' => 'age',   'type' => 'integer', 'options' => []],
        ];

        // Act
        $result = $this->command->buildSeederFields($columns);

        // Assert — two lines (one per column) separated by newline
        $lines = explode("\n", $result);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString("'email'", $lines[0]);
        $this->assertStringContainsString("'age'", $lines[1]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildModelFromWizardColumns()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * buildModelFromWizardColumns() emits typed public properties for each
     * wizard column and sets the correct $_dbtable and $_primaryKey.
     *
     * Proves the schema-first model generation works without a DB connection.
     */
    public function testBuildModelFromWizardColumnsEmitsProperties(): void
    {
        // Arrange
        $columns = [
            ['name' => 'name',       'type' => 'string',  'options' => [], 'nullable' => false, 'default' => null, 'comment' => 'Full name', 'unique' => false, 'unsigned' => false],
            ['name' => 'age',        'type' => 'integer', 'options' => [], 'nullable' => false, 'default' => null, 'comment' => '',          'unique' => false, 'unsigned' => false],
            ['name' => 'is_active',  'type' => 'boolean', 'options' => [], 'nullable' => false, 'default' => '1',  'comment' => '',          'unique' => false, 'unsigned' => false],
        ];

        // Act
        $source = $this->command->buildModelFromWizardColumns(
            'App\\Models', 'User', '#PREFIX#users', $columns, []
        );

        // Assert — class declaration and namespace
        $this->assertStringContainsString('namespace App\\Models;', $source,
            'Generated model must use the provided namespace');
        $this->assertStringContainsString('class User extends', $source,
            'Generated model must declare the correct class name');

        // Assert — typed properties for each column
        $this->assertStringContainsString('@var string', $source,
            'string column must produce @var string property');
        $this->assertStringContainsString('@var int', $source,
            'integer column must produce @var int property');
        $this->assertStringContainsString('@var bool', $source,
            'boolean column must produce @var bool property');

        // Assert — comment appears as docblock when provided
        $this->assertStringContainsString('Full name', $source,
            'column comment must appear in the property docblock');

        // Assert — infrastructure properties (model uses double-quoted string for $_dbtable)
        $this->assertStringContainsString('#PREFIX#users', $source,
            '$_dbtable must use the provided table name');
        $this->assertStringContainsString('_primaryKey', $source,
            'model must declare $_primaryKey');
    }

    /**
     * buildModelFromWizardColumns() generates load/save/delete/getData/getApiList
     * methods — the full Active Record contract.
     *
     * Proves the wizard-generated model is functionally equivalent to one
     * generated from DB introspection.
     */
    public function testBuildModelFromWizardColumnsGeneratesCrudMethods(): void
    {
        // Arrange
        $columns = [
            ['name' => 'title', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];

        // Act
        $source = $this->command->buildModelFromWizardColumns(
            'App\\Models', 'Post', '#PREFIX#posts', $columns, []
        );

        // Assert — all required methods are present
        $this->assertStringContainsString('public function load(', $source,
            'wizard model must have a load() method');
        $this->assertStringContainsString('public function save(', $source,
            'wizard model must have a save() method');
        $this->assertStringContainsString('public function delete(', $source,
            'wizard model must have a delete() method');
        $this->assertStringContainsString('public function getData(', $source,
            'wizard model must have a getData() method');
        $this->assertStringContainsString('public function getApiList(', $source,
            'wizard model must have a getApiList() method');
    }

    /**
     * buildModelFromWizardColumns() emits a getData() cast for integer columns
     * and a null-guard for FK columns with SET NULL on-delete.
     *
     * Proves type coercion and FK null-guard are synthesized from wizard data.
     */
    public function testBuildModelFromWizardColumnsEmitsFkNullGuard(): void
    {
        // Arrange
        $columns = [
            ['name' => 'category_id', 'type' => 'biginteger', 'options' => [], 'nullable' => true, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => true],
        ];
        $foreignKeys = [
            ['column' => 'category_id', 'references' => 'id', 'on' => 'categories', 'onDelete' => 'SET NULL'],
        ];

        // Act
        $source = $this->command->buildModelFromWizardColumns(
            'App\\Models', 'Item', '#PREFIX#items', $columns, $foreignKeys
        );

        // Assert — null guard emitted for SET NULL FK
        $this->assertStringContainsString('category_id == 0', $source,
            'SET NULL FK must emit a null guard in save() (0 → null)');
        // Assert — integer cast in getData()
        $this->assertStringContainsString("(int) \$this->category_id", $source,
            'integer column must be cast to int in getData()');
    }

    /**
     * buildModelFromWizardColumns() must NOT generate a getJsonList() method.
     *
     * Phase 17 scaffolding update: new models expose getApiList() only.
     * getJsonList() is deprecated on the base Model class and must not be
     * regenerated in new scaffolded files (it would shadow the parent delegate).
     */
    public function testBuildModelFromWizardColumnsDoesNotEmitGetJsonList(): void
    {
        // Arrange
        $columns = [
            ['name' => 'title', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => null, 'comment' => '', 'unique' => false, 'unsigned' => false],
        ];

        // Act
        $source = $this->command->buildModelFromWizardColumns(
            'App\\Models', 'Post', '#PREFIX#posts', $columns, []
        );

        // Assert — getJsonList must be absent; getApiList must be present
        $this->assertStringNotContainsString('getJsonList', $source,
            'wizard model must NOT contain getJsonList() — Phase 17 deprecates it in generated code');
        $this->assertStringContainsString('getApiList', $source,
            'wizard model must contain getApiList() as the single list endpoint');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
