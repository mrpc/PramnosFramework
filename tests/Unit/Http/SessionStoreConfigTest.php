<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Http\Session;

/**
 * Reading the session store out of `app.php`, which is the half that did not work.
 *
 * WHAT: `'session' => ['handler' => 'redis']` in the settings is seen, and
 *       `sessionPathFromCache()` derives a save path from the cache's own host.
 *
 * WHY:  `Settings::getSetting()` casts an **array** setting to a `stdClass` on the way out.
 *       The code shipped for this read it with `is_array()`, which is false for an object —
 *       so a configured `session` block read as no configuration at all and sessions stayed
 *       on local files. Silently, on the one path whose whole purpose is to stop a silent
 *       single-server assumption.
 *
 *       `Cache` has `(array) Settings::getSetting('cache')` for exactly this reason, and
 *       that cast is a decade old. Not reading it was the mistake.
 */
#[CoversClass(Session::class)]
class SessionStoreConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    private string|false $envHandler;

    protected function setUp(): void
    {
        $this->saved      = (array) (new \ReflectionProperty(Settings::class, 'settings'))->getValue();
        $this->envHandler = getenv('APP_SESSION_HANDLER');
        putenv('APP_SESSION_HANDLER');
        unset($_ENV['APP_SESSION_HANDLER']);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $this->saved);

        if (is_string($this->envHandler)) {
            putenv('APP_SESSION_HANDLER=' . $this->envHandler);
        } else {
            putenv('APP_SESSION_HANDLER');
        }
    }

    /** @param array<string, mixed> $values */
    private function settings(array $values): void
    {
        (new \ReflectionProperty(Settings::class, 'settings'))
            ->setValue(null, array_merge($this->saved, $values));
    }

    private function read(string $key, string $envVar): string
    {
        return (new \ReflectionMethod(Session::class, 'configuredSessionValue'))
            ->invoke(null, $key, $envVar);
    }

    /**
     * A configured handler is read out of `app.php`.
     *
     * **The assertion for the bug.** `getSetting()` hands back a `stdClass`, so the
     * `is_array()` this was written with answered false and the whole block was ignored —
     * a session configuration that did nothing, on the feature added to stop sessions
     * silently staying local.
     */
    public function testAConfiguredHandlerIsRead(): void
    {
        // Arrange
        $this->settings(['session' => ['handler' => 'redis', 'path' => 'tcp://r:6379']]);

        // Act + Assert
        $this->assertSame('redis', $this->read('handler', 'APP_SESSION_HANDLER'));
        $this->assertSame('tcp://r:6379', $this->read('path', 'APP_SESSION_PATH'));
    }

    /**
     * The environment wins over the file.
     *
     * So a staging deployment can differ from production out of one checkout — the rule
     * every other deployment-shaped setting in this framework obeys.
     */
    public function testTheEnvironmentWinsOverTheFile(): void
    {
        // Arrange
        $this->settings(['session' => ['handler' => 'redis']]);
        putenv('APP_SESSION_HANDLER=memcached');

        // Act + Assert
        $this->assertSame('memcached', $this->read('handler', 'APP_SESSION_HANDLER'));
    }

    /** Nothing configured is an empty string, which leaves the store alone. */
    public function testNothingConfiguredIsEmpty(): void
    {
        // Arrange
        $settings = $this->saved;
        unset($settings['session']);
        (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $settings);

        // Act + Assert
        $this->assertSame('', $this->read('handler', 'APP_SESSION_HANDLER'));
    }

    /**
     * With no path, the cache's own host becomes the save path.
     *
     * An application that configured Redis for its cache has already said where Redis is.
     * A second copy of a hostname is a second thing to get wrong — silently, because a
     * wrong session host signs people out rather than erroring.
     */
    public function testThePathFallsBackToTheCacheHost(): void
    {
        // Arrange
        $this->settings(['cache' => ['method' => 'redis', 'hostname' => 'redis', 'port' => 6380]]);
        $from = new \ReflectionMethod(Session::class, 'sessionPathFromCache');

        // Act + Assert
        $this->assertSame('tcp://redis:6380', $from->invoke(null, 'redis'));
        // Memcached's save path has no scheme, which is its own format rather than an
        // oversight — a `tcp://` there is not parsed and the store silently is not used.
        $this->assertSame('redis:6380', $from->invoke(null, 'memcached'));
    }

    /**
     * A cache with no host contributes nothing, and PHP's own default applies.
     *
     * Inventing `tcp://127.0.0.1:6379` here would be right on a single host and wrong
     * everywhere else — and wrong in the direction that loses sessions.
     */
    public function testNoCacheHostMeansNoDerivedPath(): void
    {
        // Arrange
        $settings = $this->saved;
        unset($settings['cache']);
        (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $settings);
        $from = new \ReflectionMethod(Session::class, 'sessionPathFromCache');

        // Act + Assert
        $this->assertSame('', $from->invoke(null, 'redis'));
    }
}
