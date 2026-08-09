<?php
namespace Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;

/**
 * Exposes the protected static config loader so it can be exercised directly,
 * without having to construct a full Application (which would need APP_PATH,
 * URL and sURL to point at a throw-away project tree).
 */
class ApplicationInfoLoaderProbe extends Application
{
    /**
     * Public shim over Application::loadApplicationInfo().
     *
     * @param  string $file Absolute path of the configuration file
     * @return array        Whatever the loader decided the app info is
     */
    public static function load($file)
    {
        return parent::loadApplicationInfo($file);
    }
}

/**
 * Covers how an application configuration file (app/app.php) is read.
 *
 * The invariant under test: the file is OPTIONAL. A project created by
 * `composer require mrpc/pramnosframework` has a vendor/ directory but no
 * app/app.php yet — and the very command that creates it (`pramnos init`)
 * runs through the console front controller, which constructs an
 * Application. Before this behaviour existed, that constructor did a bare
 * `require APP_PATH/app.php` and killed `init` with an uncatchable fatal
 * error, making the framework impossible to bootstrap into a fresh project.
 */
class ApplicationInfoLoadingTest extends TestCase
{
    /** Directory holding the temporary config fixtures for one test. */
    private string $tmpDir = '';

    /**
     * Create a private scratch directory for the fixture config files.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-appinfo-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    /**
     * Remove the scratch directory and everything in it.
     */
    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    /**
     * A missing configuration file must yield an empty array rather than a
     * fatal error. This is the `pramnos init` bootstrap case: the framework is
     * installed, app/app.php does not exist yet, and the console application
     * still has to boot far enough for `init` to create it.
     */
    public function testMissingConfigFileYieldsEmptyArray(): void
    {
        // Arrange — a path that deliberately does not exist
        $missing = $this->tmpDir . '/app.php';

        // Act
        $info = ApplicationInfoLoaderProbe::load($missing);

        // Assert — empty, not null and not an error: every consumer reads the
        // individual keys with isset()/??, so [] is a safe neutral value.
        $this->assertSame([], $info);
    }

    /**
     * An existing configuration file is returned verbatim, so the normal
     * (scaffolded project) path is unaffected by the missing-file tolerance.
     */
    public function testExistingConfigFileIsReturnedAsIs(): void
    {
        // Arrange
        $file = $this->tmpDir . '/app.php';
        file_put_contents(
            $file,
            "<?php\nreturn ['name' => 'Demo', 'namespace' => 'Demo', 'features' => ['auth']];\n"
        );

        // Act
        $info = ApplicationInfoLoaderProbe::load($file);

        // Assert
        $this->assertSame('Demo', $info['name']);
        $this->assertSame('Demo', $info['namespace']);
        $this->assertSame(['auth'], $info['features']);
    }

    /**
     * A configuration file returning an ArrayAccess object keeps working — the
     * old code assigned whatever `require` returned, so anything array-like
     * must survive the new loader unchanged (backwards compatibility).
     */
    public function testObjectConfigFileIsPreserved(): void
    {
        // Arrange — a config that returns an object rather than a plain array
        $file = $this->tmpDir . '/app.php';
        file_put_contents(
            $file,
            "<?php\nreturn new \\ArrayObject(['name' => 'Obj']);\n"
        );

        // Act
        $info = ApplicationInfoLoaderProbe::load($file);

        // Assert — returned as-is, and still readable with array syntax
        $this->assertInstanceOf(\ArrayObject::class, $info);
        $this->assertSame('Obj', $info['name']);
    }

    /**
     * A configuration file that returns something other than an array (a
     * half-written file, or one missing its `return`) degrades to [] instead of
     * assigning a scalar to $applicationInfo — which would make every
     * `$app->applicationInfo['x']` read throw further downstream.
     */
    public function testNonArrayConfigFileDegradesToEmptyArray(): void
    {
        // Arrange — valid PHP, invalid config
        $file = $this->tmpDir . '/app.php';
        file_put_contents($file, "<?php\nreturn 'not-an-array';\n");

        // Act
        $info = ApplicationInfoLoaderProbe::load($file);

        // Assert
        $this->assertSame([], $info);
    }
}
