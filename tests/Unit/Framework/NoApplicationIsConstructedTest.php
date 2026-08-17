<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Drivers\DatabaseAuthDriver;
use Pramnos\Database\Database;
use Pramnos\Http\Middleware\ApiAuthMiddleware;
use Pramnos\Http\Middleware\UnifiedAuthMiddleware;
use Pramnos\Http\RequestIdentity;

/**
 * No low-level path constructs an application as a side effect.
 *
 * `Application::getInstance()` is a **factory**: given no existing instance it reads
 * `app.php`, defines constants and runs the whole constructor — database, language,
 * session. `currentInstance()` is the lookup, and its own docblock states the rule together
 * with the incident behind it: `Session::getFingerprint()` began asking for the
 * trusted-proxy list, and a reference application's login tests started failing on valid
 * tokens because a second application was being constructed underneath them.
 *
 * Five call sites in the authentication code were still using the factory, and every one of
 * them was already written as `if ($app)` — a guard for a null the call could not return.
 * So the guard was dead and the construction was live. The tell was there in the source the
 * whole time.
 *
 * This class asserts the shared invariant rather than each site's own behaviour, which is
 * covered by their existing tests. The invariant is what a future edit would break: reaching
 * for `getInstance()` because it is the name one remembers.
 */
class NoApplicationIsConstructedTest extends TestCase
{
    /** @var array<string, mixed> The application registry as it was found */
    private array $originalInstances = [];

    /** @var string|null The last-used application name as it was found */
    private ?string $originalLast = null;

    /**
     * Empties the application registry, which is the state that makes the difference
     * visible: with an application already present, the factory and the lookup are
     * indistinguishable.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $instances = new \ReflectionProperty(Application::class, 'appInstances');
        $last      = new \ReflectionProperty(Application::class, 'lastUsedApplication');

        $this->originalInstances = (array) $instances->getValue();
        $this->originalLast      = $last->getValue();

        $instances->setValue(null, []);
        $last->setValue(null, null);

        RequestIdentity::reset();
    }

    /**
     * Puts the registry back, so a later test that expects an application still finds one.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        (new \ReflectionProperty(Application::class, 'appInstances'))
            ->setValue(null, $this->originalInstances);
        (new \ReflectionProperty(Application::class, 'lastUsedApplication'))
            ->setValue(null, $this->originalLast);

        RequestIdentity::reset();
    }

    /**
     * A stand-in for an authenticated user row.
     *
     * @return object
     */
    private function user(): object
    {
        return (object) ['userid' => 4242, 'usertype' => 10];
    }

    /**
     * `ApiAuthMiddleware::setRequestUser()` records the user without building an application.
     *
     * The identity is still sealed — that is the part that must keep working. What must not
     * happen is a database connection and a session appearing because a middleware wanted to
     * write one property.
     *
     * @return void
     */
    public function testApiAuthMiddlewareDoesNotBuildAnApplication(): void
    {
        // Arrange
        // The API-key checker is a required collaborator and is never reached here —
        // `setRequestUser()` is called after the credential has already been accepted.
        $middleware = new ApiAuthMiddleware(fn(string $key): bool => true);
        $method     = new \ReflectionMethod($middleware, 'setRequestUser');

        // Act
        $method->invoke($middleware, $this->user(), 'accessToken');

        // Assert — the work happened…
        $this->assertTrue(RequestIdentity::isSealed());
        $this->assertSame(4242, RequestIdentity::subject());

        // …and nothing was constructed to make it happen
        $this->assertNull(
            Application::currentInstance(),
            'Sealing an identity must not construct an application.'
        );
    }

    /**
     * `UnifiedAuthMiddleware::sealIdentity()` likewise.
     *
     * A separate assertion rather than a loop: the two middlewares are separate classes with
     * separate copies of this code, and a future edit will touch one of them.
     *
     * @return void
     */
    public function testUnifiedAuthMiddlewareDoesNotBuildAnApplication(): void
    {
        // Arrange
        $middleware = new UnifiedAuthMiddleware();
        $method     = new \ReflectionMethod($middleware, 'sealIdentity');

        // Act
        $method->invoke($middleware, $this->user(), 'session');

        // Assert
        $this->assertTrue(RequestIdentity::isSealed());
        $this->assertSame('session', RequestIdentity::via());
        $this->assertNull(Application::currentInstance());
    }

    /**
     * `DatabaseAuthDriver` reads two booleans without building an application for them.
     *
     * The plainest case of the five, and the one that shows what the rule is for: reading a
     * configuration value is not a reason to construct a database connection.
     *
     * @return void
     */
    public function testTheDatabaseAuthDriverReadsItsConfigWithoutAnApplication(): void
    {
        // Arrange
        $driver = new DatabaseAuthDriver();
        $method = new \ReflectionMethod($driver, 'resolveConfig');

        // Act
        [$legacyMd5, $autoUpgrade] = $method->invoke($driver);

        // Assert — the documented defaults, from no application at all
        $this->assertFalse($legacyMd5);
        $this->assertTrue($autoUpgrade, 'auto_upgrade defaults to true.');
        $this->assertNull(Application::currentInstance());
    }

    /**
     * An explicitly-passed config still wins, with no application in the picture.
     *
     * Guards the half of `resolveConfig()` that the assertion above cannot see: the driver's
     * own config takes precedence over the application's, and the application read is a
     * fallback rather than the source.
     *
     * @return void
     */
    public function testTheDriversOwnConfigWinsOverTheApplications(): void
    {
        // Arrange
        $driver = new DatabaseAuthDriver(['legacy_md5' => true, 'auto_upgrade' => false]);

        // Act
        [$legacyMd5, $autoUpgrade] = (new \ReflectionMethod($driver, 'resolveConfig'))
            ->invoke($driver);

        // Assert
        $this->assertTrue($legacyMd5);
        $this->assertFalse($autoUpgrade);
        $this->assertNull(Application::currentInstance());
    }

    /**
     * An application that *does* exist is still used — the lookup is not a refusal.
     *
     * Without this, every assertion above would also pass if the calls had been deleted
     * outright. What changed is where the application comes from, not whether it is used.
     *
     * @return void
     */
    public function testAnExistingApplicationIsStillFoundAndUsed(): void
    {
        // Arrange — a real Application with its constructor skipped
        $app = new class extends Application {
            /** No database, language or session is wanted here. */
            public function __construct()
            {
            }
        };
        $app->applicationInfo = ['auth' => ['legacy_md5' => true]];

        (new \ReflectionProperty(Application::class, 'appInstances'))
            ->setValue(null, ['default' => $app]);
        (new \ReflectionProperty(Application::class, 'lastUsedApplication'))
            ->setValue(null, 'default');

        // Act — the driver reads its setting from it…
        [$legacyMd5] = (new \ReflectionMethod(new DatabaseAuthDriver(), 'resolveConfig'))
            ->invoke(new DatabaseAuthDriver());

        // …and the middleware records the user on it
        $middleware = new ApiAuthMiddleware(fn(string $key): bool => true);
        (new \ReflectionMethod($middleware, 'setRequestUser'))
            ->invoke($middleware, $this->user(), 'accessToken');

        // Assert
        $this->assertTrue($legacyMd5, 'The application config must still be read.');
        $this->assertSame(4242, (int) $app->currentUser->userid);
    }

    /**
     * `User::legacyMd5Allowed()` reads one setting without building an application for it.
     *
     * The answer must also be the **secure** default when there is no application to ask:
     * legacy MD5 password hashes are accepted only where an installation says so, and a
     * lookup that fails open would silently accept them everywhere.
     *
     * @return void
     */
    public function testTheUserClassReadsItsLegacyFlagWithoutAnApplication(): void
    {
        // Arrange
        $user   = new \Pramnos\User\User();
        $method = new \ReflectionMethod($user, 'legacyMd5Allowed');

        // Act
        $allowed = $method->invoke($user);

        // Assert
        $this->assertFalse($allowed, 'No application must mean no legacy MD5.');
        $this->assertNull(Application::currentInstance());
    }

    /**
     * `Database::displayError()` reports the error without building an application.
     *
     * The self-defeating case. Building one builds `Settings`, which queries the database —
     * the connection that just failed — and it made this method's own `else` branch
     * unreachable, so the `error_log()` fallback written for "no application" could never
     * run and a database error outside a request went nowhere at all.
     *
     * The assertion is that the call returns having constructed nothing. `error_log()` is
     * redirected to `/dev/null` by the test bootstrap precisely because this method is noisy,
     * so the log line itself is not what is checked here; the absence of an application is.
     *
     * @return void
     */
    public function testDisplayErrorReportsWithoutBuildingAnApplication(): void
    {
        // Arrange — a database that never connected, which is the state it complains from
        $database = new Database();
        $database->error_number = 1146;
        $database->error_text   = 'Table does not exist';

        // Act
        $database->displayError();

        // Assert
        $this->assertNull(
            Application::currentInstance(),
            'Reporting a database error must not construct an application, which would '
            . 'construct Settings, which queries the database.'
        );
    }
}
