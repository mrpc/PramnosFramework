<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Pramnos\Debug\Collectors\ExceptionsCollector;
use Pramnos\Debug\Collectors\ModelsCollector;
use Pramnos\Debug\Collectors\ViewsCollector;

/**
 * Unit tests for the debug collectors: ExceptionsCollector, ModelsCollector, and ViewsCollector.
 */
class CollectorsTest extends TestCase
{
    // ExceptionsCollector tests
    public function testExceptionsCollectorName(): void
    {
        $collector = new ExceptionsCollector();
        $this->assertSame('exceptions', $collector->name());
    }

    public function testExceptionsCollectorRecordsException(): void
    {
        $collector = new ExceptionsCollector();
        $exception = new \Exception("Test error message", 123);
        
        $collector->record($exception);
        $data = $collector->collect();
        
        $this->assertSame(1, $data['count']);
        $this->assertCount(1, $data['items']);
        
        $item = $data['items'][0];
        $this->assertSame('exception', $item['type']);
        $this->assertSame('Exception', $item['class']);
        $this->assertSame('Test error message', $item['message']);
        $this->assertStringContainsString('CollectorsTest.php', $item['file']);
        $this->assertGreaterThan(0, $item['line']);
    }

    public function testExceptionsCollectorRecordsPhpError(): void
    {
        $collector = new ExceptionsCollector();
        
        $file = defined('ROOT') && ROOT !== '' ? ROOT . '/file.php' : 'file.php';
        $collector->recordPhpError(E_WARNING, "Test PHP warning", $file, 45);
        $data = $collector->collect();
        
        $this->assertSame(1, $data['count']);
        
        $item = $data['items'][0];
        $this->assertSame('php_error', $item['type']);
        $this->assertSame('E_WARNING', $item['class']);
        $this->assertSame('Test PHP warning', $item['message']);
        
        $expectedFile = defined('ROOT') && ROOT !== '' ? '/file.php' : 'file.php';
        $this->assertSame($expectedFile, $item['file']);
        $this->assertSame(45, $item['line']);
        
        // Test custom error map fallback
        $collector->recordPhpError(999, "Unknown error", "/path/to/custom.php", 80);
        $data = $collector->collect();
        $this->assertSame(2, $data['count']);
        $this->assertSame('E_999', $data['items'][1]['class']);
    }

    // ModelsCollector tests
    public function testModelsCollectorName(): void
    {
        $collector = new ModelsCollector();
        $this->assertSame('models', $collector->name());
    }

    public function testModelsCollectorRecordsOperations(): void
    {
        $collector = new ModelsCollector();
        
        // Non-existent class fallback
        $collector->record('NonexistentClass', 'users', 'save', 42);
        
        // Existent class reflection short name
        $collector->record(self::class, 'tests', 'delete', 'key-123');
        
        $data = $collector->collect();
        
        // Count of unique model classes (2 unique classes: 'NonexistentClass' and self::class)
        $this->assertSame(2, $data['count']);
        // Total operations count
        $this->assertSame(2, $data['ops']);
        
        $this->assertSame('NonexistentClass', $data['operations'][0]['class']);
        $this->assertSame('users', $data['operations'][0]['table']);
        $this->assertSame('save', $data['operations'][0]['op']);
        $this->assertSame(42, $data['operations'][0]['key']);
        
        $this->assertSame('CollectorsTest', $data['operations'][1]['class']);
        $this->assertSame('tests', $data['operations'][1]['table']);
        $this->assertSame('delete', $data['operations'][1]['op']);
        $this->assertSame('key-123', $data['operations'][1]['key']);
    }

    // ViewsCollector tests
    public function testViewsCollectorName(): void
    {
        $collector = new ViewsCollector();
        $this->assertSame('views', $collector->name());
    }

    public function testViewsCollectorRecordsViews(): void
    {
        $collector = new ViewsCollector();
        
        $collector->record('home.index', '/var/www/html/src/Views/home/index.html.php', 12.3456, false);
        $collector->record('partials.header', '/var/www/html/src/Views/partials/header.html.php', 1.02, true);
        
        $data = $collector->collect();
        
        $this->assertSame(2, $data['count']);
        $this->assertSame(1, $data['cached']);
        
        $this->assertSame('home.index', $data['views'][0]['view']);
        $this->assertSame('index.html.php', $data['views'][0]['template']);
        $this->assertEquals(12.35, $data['views'][0]['render_ms']); // rounded to 2 decimals
        $this->assertFalse($data['views'][0]['from_cache']);
        
        $this->assertSame('header.html.php', $data['views'][1]['template']);
        $this->assertTrue($data['views'][1]['from_cache']);
    }
}
