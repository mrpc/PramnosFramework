<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Make\StubRenderer;

/**
 * Unit tests for StubRenderer.
 *
 * StubRenderer loads stub templates from disk and performs {{ token }}
 * substitution, falling back to embedded skeletons when stub files are absent.
 * Tests use a temp directory so the real scaffolding/templates/ directory is
 * never involved — results are reproducible regardless of the on-disk state.
 *
 * WHY these tests matter:
 * - render() must correctly substitute every {{ key }} token — a missed token
 *   would leave a literal placeholder in generated source files.
 * - The fallback path must produce valid, immediately-runnable PHP — scaffold
 *   commands must work even in environments without the templates directory.
 * - On-disk stubs must take precedence over embedded skeletons — this allows
 *   projects to override the default templates without touching framework code.
 */
#[CoversClass(StubRenderer::class)]
class StubRendererTest extends TestCase
{
    private string $tmpDir;
    private StubRenderer $renderer;

    protected function setUp(): void
    {
        // Arrange — isolated temp workspace; never touch real scaffolding dir
        $this->tmpDir = sys_get_temp_dir() . '/stub_renderer_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);

        // Renderer pointed at the empty temp dir → always hits fallback stubs
        $this->renderer = new StubRenderer($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fallback stubs — all known stub names
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The 'middleware' fallback stub must produce a PHP class implementing
     * MiddlewareInterface with the supplied namespace, class name, and handle().
     */
    public function testFallbackMiddlewareStub(): void
    {
        // Act
        $result = $this->renderer->render('middleware', [
            'namespace' => 'App\\Middleware',
            'class'     => 'Throttle',
        ]);

        // Assert — key structural elements present
        $this->assertStringContainsString('namespace App\\Middleware;', $result);
        $this->assertStringContainsString('class Throttle', $result);
        $this->assertStringContainsString('MiddlewareInterface', $result);
        $this->assertStringContainsString('public function handle(', $result);
    }

    /**
     * The 'event' fallback stub must produce a plain PHP class with strict_types
     * and the correct namespace and class name.
     */
    public function testFallbackEventStub(): void
    {
        // Act
        $result = $this->renderer->render('event', [
            'namespace' => 'App\\Events',
            'class'     => 'UserRegistered',
        ]);

        // Assert
        $this->assertStringContainsString('declare(strict_types=1);', $result);
        $this->assertStringContainsString('namespace App\\Events;', $result);
        $this->assertStringContainsString('class UserRegistered', $result);
    }

    /**
     * The 'listener' fallback stub must produce a class implementing
     * ListenerInterface with the handle() method signature.
     */
    public function testFallbackListenerStub(): void
    {
        // Act
        $result = $this->renderer->render('listener', [
            'namespace' => 'App\\Listeners',
            'class'     => 'SendWelcomeEmail',
        ]);

        // Assert
        $this->assertStringContainsString('class SendWelcomeEmail', $result);
        $this->assertStringContainsString('ListenerInterface', $result);
        $this->assertStringContainsString('public function handle(', $result);
    }

    /**
     * The 'migration' fallback stub must include both up() and down() method
     * bodies and substitute the description, class, namespace, up_body,
     * and down_body tokens.
     */
    public function testFallbackMigrationStub(): void
    {
        // Act
        $result = $this->renderer->render('migration', [
            'namespace'   => 'App\\Migrations',
            'class'       => 'CreateUsersTable',
            'description' => 'Create the users table',
            'up_body'     => '        // up',
            'down_body'   => '        // down',
        ]);

        // Assert — both lifecycle methods and the description token substituted
        $this->assertStringContainsString('class CreateUsersTable extends Migration', $result);
        $this->assertStringContainsString("'Create the users table'", $result);
        $this->assertStringContainsString('public function up()', $result);
        $this->assertStringContainsString('public function down()', $result);
    }

    /**
     * The 'seeder' fallback stub must include the table property, the for-loop,
     * and the {{ fields }} token substitution.
     */
    public function testFallbackSeederStub(): void
    {
        // Act
        $result = $this->renderer->render('seeder', [
            'namespace' => 'App\\Seeders',
            'class'     => 'UsersSeeder',
            'table'     => 'users',
            'count'     => '10',
            'fields'    => "                'name' => 'Name ' . \$i,",
        ]);

        // Assert
        $this->assertStringContainsString('class UsersSeeder extends Seeder', $result);
        $this->assertStringContainsString("\$table = 'users'", $result);
        $this->assertStringContainsString('for ($i = 1; $i <= 10;', $result);
        $this->assertStringContainsString("'name' => 'Name '", $result);
    }

    /**
     * The 'controller' fallback stub must include the view name token and the
     * display() method that returns a view.
     */
    public function testFallbackControllerStub(): void
    {
        // Act
        $result = $this->renderer->render('controller', [
            'namespace' => 'App\\Controllers',
            'class'     => 'UserController',
            'view'      => 'users',
        ]);

        // Assert
        $this->assertStringContainsString('class UserController extends Controller', $result);
        $this->assertStringContainsString('public function display()', $result);
        $this->assertStringContainsString("getView('users')", $result);
    }

    /**
     * The 'model' fallback stub must set the table name and primary key
     * properties from tokens.
     */
    public function testFallbackModelStub(): void
    {
        // Act
        $result = $this->renderer->render('model', [
            'namespace'  => 'App\\Models',
            'class'      => 'User',
            'table'      => '#PREFIX#users',
            'primaryKey' => 'userid',
        ]);

        // Assert
        $this->assertStringContainsString('class User extends Model', $result);
        $this->assertStringContainsString("'#PREFIX#users'", $result);
        $this->assertStringContainsString("'userid'", $result);
    }

    /**
     * The 'test' fallback stub must produce a PHPUnit TestCase with the class
     * name and an instantiation test.
     */
    public function testFallbackTestStub(): void
    {
        // Act
        $result = $this->renderer->render('test', [
            'namespace' => 'App\\Models',
            'class'     => 'User',
        ]);

        // Assert
        $this->assertStringContainsString('class UserTest extends TestCase', $result);
        $this->assertStringContainsString('testInstantiation', $result);
        $this->assertStringContainsString('App\\Models\\User', $result);
    }

    /**
     * The 'controller_test' fallback stub must produce a feature test with a
     * route-based HTTP assertion.
     */
    public function testFallbackControllerTestStub(): void
    {
        // Act
        $result = $this->renderer->render('controller_test', [
            'class' => 'User',
            'route' => 'users',
        ]);

        // Assert
        $this->assertStringContainsString('class UserTest extends TestCase', $result);
        $this->assertStringContainsString("get('/users')", $result);
        $this->assertStringContainsString('assertSuccessful', $result);
    }

    /**
     * An unknown stub name must return an empty string — no exception thrown —
     * so callers can check for empty output rather than catching exceptions.
     */
    public function testUnknownStubReturnsEmptyString(): void
    {
        // Act
        $result = $this->renderer->render('nonexistent_stub', ['foo' => 'bar']);

        // Assert — graceful empty fallback
        $this->assertSame('', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Token substitution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * All {{ key }} tokens in the rendered output must be replaced — if any
     * token is unsubstituted the generated file contains visible placeholders.
     */
    public function testRenderReplacesAllTokens(): void
    {
        // Act
        $result = $this->renderer->render('model', [
            'namespace'  => 'MyApp\\Models',
            'class'      => 'Product',
            'table'      => '#PREFIX#products',
            'primaryKey' => 'productid',
        ]);

        // Assert — no {{ }} placeholder left in output
        $this->assertStringNotContainsString('{{', $result);
        $this->assertStringNotContainsString('}}', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // On-disk stub takes precedence over fallback
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When a <stubName>.stub file exists in the stubs directory it must be used
     * instead of the embedded skeleton — this allows projects to customise
     * templates without touching framework source files.
     */
    public function testOnDiskStubOverridesFallback(): void
    {
        // Arrange — write a custom stub to the temp directory
        $stubContent = "<?php\n// Custom stub: {{ class }}\n";
        file_put_contents($this->tmpDir . '/mytemplate.stub', $stubContent);

        // Act
        $result = $this->renderer->render('mytemplate', ['class' => 'Foo']);

        // Assert — custom content used, not the embedded skeleton
        $this->assertStringContainsString('// Custom stub: Foo', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }
}
