<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;

/**
 * `Settings::refresh()` — what a long-lived process forgets between tasks.
 *
 * A queue worker in `--daemon` read every setting once; an administrator's change since — a
 * provider, a key — never reached it, and a saved key was answered with "no API key". Refreshed,
 * the next read goes to the database; the settings file stays, because nothing would reload it.
 */
#[CoversClass(Settings::class)]
class SettingsRefreshTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['settings', 'fromFile', 'bulkLoaded'] as $name) {
            $this->saved[$name] = (new \ReflectionProperty(Settings::class, $name))->getValue();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            (new \ReflectionProperty(Settings::class, $name))->setValue(null, $value);
        }
        parent::tearDown();
    }

    /** The file's keys stay; a value read since — from the database, or set in memory — goes. */
    public function testTheFileStaysAndTheRestIsReadAgain(): void
    {
        // Arrange
        $file = tempnam(sys_get_temp_dir(), 'settings') . '.php';
        file_put_contents($file, "<?php return ['refresh_from_file' => 'kept'];");
        Settings::loadSettings($file);
        Settings::setSetting('refresh_read_since', 'stale', false);
        (new \ReflectionProperty(Settings::class, 'bulkLoaded'))->setValue(null, true);

        // Act
        Settings::refresh();
        unlink($file);

        // Assert
        $settings = (new \ReflectionProperty(Settings::class, 'settings'))->getValue();
        $this->assertSame('kept', $settings['refresh_from_file'] ?? null);
        $this->assertArrayNotHasKey('refresh_read_since', $settings);
        $this->assertFalse((new \ReflectionProperty(Settings::class, 'bulkLoaded'))->getValue(), 'the database is asked again');
    }
}
