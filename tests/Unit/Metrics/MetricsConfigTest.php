<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Metrics\Metrics;

/**
 * Where the application's metrics settings come from (FW-473): its own `app.php`, and what the
 * administration's `app/admin.php` adds beside the reports' screens — a Jira address written
 * there was never read, so a section's issue was printed without its link.
 */
#[CoversClass(Metrics::class)]
final class MetricsConfigTest extends TestCase
{
    private mixed $before = null;

    protected function setUp(): void
    {
        $this->before = Application::getInstance()->applicationInfo['metrics'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->before === null) {
            unset(Application::getInstance()->applicationInfo['metrics']);
        } else {
            Application::getInstance()->applicationInfo['metrics'] = $this->before;
        }
        Metrics::reset();
    }

    /** The administration's adds what the application's does not say; where both say it, the application's wins. */
    public function testTheAdministrationsSettingsAreReadToo(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['metrics'] = ['pinned_filters' => ['market'], 'timezone' => 'UTC'];
        (new \ReflectionProperty(Metrics::class, 'area'))->setValue(null, ['jira' => 'https://jira.example/browse/%s', 'timezone' => 'Europe/Athens']);

        // Act
        $config = Metrics::config();
        Metrics::reset();

        // Assert
        $this->assertSame('https://jira.example/browse/%s', $config['jira'], 'written beside the reports\' screens');
        $this->assertSame(['market'], $config['pinned_filters']);
        $this->assertSame('UTC', $config['timezone'], 'the application\'s own wins');
        $this->assertNull((new \ReflectionProperty(Metrics::class, 'area'))->getValue(), 'read again after a reset');
    }
}
