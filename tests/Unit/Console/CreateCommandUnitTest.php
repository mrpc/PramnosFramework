<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Create;

/**
 * Unit tests for Create command stub rendering and file-generation helpers.
 *
 * The createMiddleware() and generateTestStub() methods work against the
 * filesystem; we redirect ROOT to a temp directory so nothing is written
 * outside /tmp.
 */
class CreateCommandUnitTest extends TestCase
{
    private string $tmpDir;
    private Create $command;

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

        $this->command = new Create();
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
