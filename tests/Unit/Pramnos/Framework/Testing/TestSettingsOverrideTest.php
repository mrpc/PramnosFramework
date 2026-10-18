<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Framework\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestEnvironment;

/**
 * `PRAMNOS_TEST_SETTINGS` runs a project's suite against another database without a second
 * bootstrap (FW-160, phase 3): a MySQL application moving to PostgreSQL runs the same suite once
 * with a PostgreSQL settings file and sees what breaks before the move does.
 */
#[CoversClass(TestEnvironment::class)]
class TestSettingsOverrideTest extends TestCase
{
    private string|false $saved;

    protected function setUp(): void
    {
        $this->saved = getenv('PRAMNOS_TEST_SETTINGS');
    }

    protected function tearDown(): void
    {
        $this->saved === false ? putenv('PRAMNOS_TEST_SETTINGS') : putenv('PRAMNOS_TEST_SETTINGS=' . $this->saved);
    }

    /**
     * Unset, the bootstrap's file is used; set, the file it names, a relative name read from the
     * project's root; a name that is not a file is refused rather than quietly falling back to
     * the database the run was meant to avoid.
     */
    public function testTheSettingsFileARunUses(): void
    {
        // Arrange
        $relative = 'tests/fixtures';
        @mkdir(ROOT . '/' . $relative, 0777, true);
        $file = $relative . '/testsettings.' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents(ROOT . '/' . $file, '<?php return [];');

        try {
            // Act
            putenv('PRAMNOS_TEST_SETTINGS');
            $default = TestEnvironment::settingsPath('/app/config/testsettings.php');
            putenv('PRAMNOS_TEST_SETTINGS=' . $file);
            $named = TestEnvironment::settingsPath('/app/config/testsettings.php');
            putenv('PRAMNOS_TEST_SETTINGS=app/config/nothing-here.php');
            try {
                TestEnvironment::settingsPath('/app/config/testsettings.php');
                $refused = null;
            } catch (\RuntimeException $e) {
                $refused = $e->getMessage();
            }
        } finally {
            @unlink(ROOT . '/' . $file);
        }

        // Assert
        $this->assertSame('/app/config/testsettings.php', $default);
        $this->assertSame(ROOT . DIRECTORY_SEPARATOR . $file, $named);
        $this->assertStringContainsString('app/config/nothing-here.php, which is not a file', (string) $refused);
    }
}
