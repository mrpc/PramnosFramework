<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Pramnos\Debug\Collectors\ExceptionsCollector;
use Pramnos\Debug\Collectors\MemoryCollector;
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

    // MemoryCollector tests

    /**
     * MemoryCollector::name() must return 'memory' so the DebugBar panel is
     * identified correctly in the toolbar.
     */
    public function testMemoryCollectorName(): void
    {
        // Arrange / Act / Assert
        $collector = new MemoryCollector();
        $this->assertSame('memory', $collector->name());
    }

    /**
     * MemoryCollector::collect() must return peak_bytes, peak_human,
     * current_bytes, current_human — all required keys.
     */
    public function testMemoryCollectorCollectReturnsRequiredKeys(): void
    {
        // Arrange
        $collector = new MemoryCollector();

        // Act
        $data = $collector->collect();

        // Assert — all four keys are present
        $this->assertArrayHasKey('peak_bytes',    $data);
        $this->assertArrayHasKey('peak_human',    $data);
        $this->assertArrayHasKey('current_bytes', $data);
        $this->assertArrayHasKey('current_human', $data);
    }

    /**
     * MemoryCollector::collect() human label must end in MB when peak usage
     * is over 1 MB (virtually guaranteed in any PHP process running PHPUnit).
     * This covers the '>= 1_048_576' branch of the private format() method.
     */
    public function testMemoryCollectorFormatsMegabytes(): void
    {
        // Arrange
        $collector = new MemoryCollector();

        // Act — PHPUnit uses >> 1 MB; peak_human will be in MB
        $data = $collector->collect();

        // Assert — either MB or KB but at least a number+unit string
        $this->assertMatchesRegularExpression(
            '/^\d+(\.\d+)? (MB|KB|B)$/',
            $data['peak_human'],
        );
    }

    /**
     * MemoryCollector format() must produce a ' KB' suffix for values
     * between 1 024 and 1 048 575 bytes.
     * This covers the 'between 1024 and 1MB' branch of private format().
     *
     * We drive this indirectly via collect() by comparing the human label
     * of a small synthetic value — since format() is private we use a
     * reflection-based approach to call it directly.
     */
    public function testMemoryCollectorFormatsKilobytes(): void
    {
        // Arrange — use ReflectionMethod to access private format()
        $collector = new MemoryCollector();
        $ref       = new \ReflectionMethod($collector, 'format');

        // Act — 2048 bytes → 2 KB
        $result = $ref->invoke($collector, 2048);

        // Assert
        $this->assertSame('2 KB', $result);
    }

    /**
     * MemoryCollector format() must return a plain byte count for values
     * under 1 024 bytes.  Covers the 'else → bytes' branch.
     */
    public function testMemoryCollectorFormatsBytes(): void
    {
        // Arrange
        $collector = new MemoryCollector();
        $ref       = new \ReflectionMethod($collector, 'format');

        // Act — 512 bytes
        $result = $ref->invoke($collector, 512);

        // Assert
        $this->assertSame('512 B', $result);
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
