<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Make\StubRenderer;

#[CoversClass(StubRenderer::class)]
class StubRendererTest extends TestCase
{
    // =========================================================================
    // Fallback stubs (no on-disk templates required)
    // =========================================================================

    private function makeRenderer(): StubRenderer
    {
        // Pass a non-existent directory so the renderer always falls back to embedded stubs
        return new StubRenderer('/tmp/__nonexistent_stubs_dir__');
    }

    public function testRenderMiddlewareFallback(): void
    {
        $renderer = $this->makeRenderer();
        $result = $renderer->render('middleware', [
            'namespace' => 'App\\Middleware',
            'class'     => 'RateLimit',
        ]);

        $this->assertStringContainsString('namespace App\\Middleware', $result);
        $this->assertStringContainsString('class RateLimit', $result);
        $this->assertStringContainsString('MiddlewareInterface', $result);
        $this->assertStringContainsString('handle(Request', $result);
    }

    public function testRenderEventFallback(): void
    {
        $renderer = $this->makeRenderer();
        $result = $renderer->render('event', [
            'namespace' => 'App\\Events',
            'class'     => 'UserRegistered',
        ]);

        $this->assertStringContainsString('namespace App\\Events', $result);
        $this->assertStringContainsString('class UserRegistered', $result);
        $this->assertStringContainsString('<?php', $result);
    }

    public function testRenderListenerFallback(): void
    {
        $renderer = $this->makeRenderer();
        $result = $renderer->render('listener', [
            'namespace' => 'App\\Listeners',
            'class'     => 'SendWelcomeEmail',
        ]);

        $this->assertStringContainsString('namespace App\\Listeners', $result);
        $this->assertStringContainsString('class SendWelcomeEmail', $result);
        $this->assertStringContainsString('ListenerInterface', $result);
    }

    public function testRenderMigrationFallback(): void
    {
        $renderer = $this->makeRenderer();
        $result = $renderer->render('migration', [
            'namespace'   => 'App\\Migrations',
            'class'       => 'CreateUsersTable',
            'description' => 'create users table',
            'date'        => '01/01/2025 12:00',
            'up_body'     => '        // up',
            'down_body'   => '        // down',
        ]);

        $this->assertStringContainsString('namespace App\\Migrations', $result);
        $this->assertStringContainsString('class CreateUsersTable', $result);
        $this->assertStringContainsString('create users table', $result);
        $this->assertStringContainsString('// up', $result);
        $this->assertStringContainsString('// down', $result);
        $this->assertStringContainsString('extends Migration', $result);
    }

    public function testRenderSeederFallback(): void
    {
        $renderer = $this->makeRenderer();
        $result = $renderer->render('seeder', [
            'namespace' => 'App\\Seeders',
            'class'     => 'UserSeeder',
            'table'     => '#PREFIX#users',
            'date'      => '01/01/2025',
            'fields'    => "                'name' => 'value',",
            'count'     => '10',
        ]);

        $this->assertStringContainsString('namespace App\\Seeders', $result);
        $this->assertStringContainsString('class UserSeeder', $result);
        $this->assertStringContainsString('#PREFIX#users', $result);
        $this->assertStringContainsString('extends Seeder', $result);
    }

    public function testRenderControllerFallback(): void
    {
        $renderer = $this->makeRenderer();
        $result = $renderer->render('controller', [
            'namespace' => 'App\\Controllers',
            'class'     => 'ProductController',
            'view'      => 'product',
        ]);

        $this->assertStringContainsString('namespace App\\Controllers', $result);
        $this->assertStringContainsString('class ProductController', $result);
        $this->assertStringContainsString('extends Controller', $result);
    }

    public function testRenderModelFallback(): void
    {
        $renderer = $this->makeRenderer();
        $result = $renderer->render('model', [
            'namespace'  => 'App\\Models',
            'class'      => 'Product',
            'table'      => '#PREFIX#products',
            'primaryKey' => 'productid',
        ]);

        $this->assertStringContainsString('namespace App\\Models', $result);
        $this->assertStringContainsString('class Product', $result);
        $this->assertStringContainsString('extends Model', $result);
        $this->assertStringContainsString('#PREFIX#products', $result);
        $this->assertStringContainsString('productid', $result);
    }

    public function testRenderTestFallback(): void
    {
        $renderer = $this->makeRenderer();
        $result = $renderer->render('test', [
            'namespace' => 'App\\Models',
            'class'     => 'Product',
            'route'     => 'product',
        ]);

        $this->assertStringContainsString('class ProductTest', $result);
        $this->assertStringContainsString('TestCase', $result);
        $this->assertStringContainsString('App\\Models', $result);
    }

    public function testRenderControllerTestFallback(): void
    {
        $renderer = $this->makeRenderer();
        $result = $renderer->render('controller_test', [
            'namespace' => 'App\\Controllers',
            'class'     => 'Product',
            'route'     => 'product',
        ]);

        $this->assertStringContainsString('class ProductTest', $result);
        $this->assertStringContainsString('TestClient', $result);
        $this->assertStringContainsString('/product', $result);
    }

    public function testRenderUnknownStubReturnsEmpty(): void
    {
        $renderer = $this->makeRenderer();
        $result = $renderer->render('nonexistent_stub', []);
        $this->assertSame('', $result);
    }

    public function testRenderSubstitutesAllTokens(): void
    {
        $renderer = $this->makeRenderer();
        $result = $renderer->render('middleware', [
            'namespace' => 'Test\\NS',
            'class'     => 'MyMid',
        ]);

        // No unresolved tokens remaining
        $this->assertStringNotContainsString('{{ namespace }}', $result);
        $this->assertStringNotContainsString('{{ class }}', $result);
    }

    public function testGetFallbackStubDirectlyForAllKnownTypes(): void
    {
        $renderer = $this->makeRenderer();
        $knownTypes = ['middleware', 'event', 'listener', 'migration', 'seeder',
                       'controller', 'model', 'test', 'controller_test'];

        foreach ($knownTypes as $type) {
            $stub = $renderer->getFallbackStub($type);
            $this->assertNotEmpty($stub, "Fallback stub for '$type' should not be empty");
            $this->assertStringContainsString('<?php', $stub, "Stub '$type' should start with <?php");
        }
    }

    // =========================================================================
    // On-disk stub loading (using actual stubs from the framework)
    // =========================================================================

    public function testRenderUsesOnDiskStubIfPresent(): void
    {
        // Use a temp dir with a known stub file
        $tmpDir = sys_get_temp_dir() . '/pramnos_stub_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/hello.stub', 'Hello {{ name }}!');

        $renderer = new StubRenderer($tmpDir);
        $result = $renderer->render('hello', ['name' => 'World']);

        $this->assertSame('Hello World!', $result);

        // Cleanup
        unlink($tmpDir . '/hello.stub');
        rmdir($tmpDir);
    }
}
