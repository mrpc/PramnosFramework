<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Api;
use Pramnos\Application\Application;
use Pramnos\Testing\TestClient;

/**
 * The site and the API in one process, as a test suite holds them.
 *
 * The API application took the site's registry slot when it was built, and a bare
 * `new TestClient()` took whichever application was built last. So after one API test, every
 * later request meant for the site was answered by the API's controllers — a page that should
 * be a 404 came back 200 with an empty body — and only in a full run, in an order nobody chose.
 */
#[CoversClass(Application::class)]
#[CoversClass(TestClient::class)]
class ApplicationRegistryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $registry = [];

    private ?string $current = null;

    /** Keep the registry as it was, so these tests change nothing for the ones after them. */
    protected function setUp(): void
    {
        $this->registry = (new \ReflectionProperty(Application::class, 'appInstances'))->getValue();
        $this->current  = (new \ReflectionProperty(Application::class, 'lastUsedApplication'))->getValue();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, $this->registry);
        (new \ReflectionProperty(Application::class, 'lastUsedApplication'))->setValue(null, $this->current);
    }

    /** A site application that needs no initialisation. */
    private function site(): Application
    {
        return new class extends Application {
            public $initialized = true;
        };
    }

    /** An API application that needs no initialisation. */
    private function api(): Api
    {
        return new class ('') extends Api {
            public $initialized = true;
        };
    }

    /**
     * Building the API leaves the site where it was.
     */
    public function testTheApiDoesNotReplaceTheSite(): void
    {
        // Arrange
        $site = $this->site();

        // Act
        $api = new Api('');

        // Assert
        $this->assertSame($site, Application::instanceNamed('default'));
        $this->assertSame($api, Application::instanceNamed('api'));
        $this->assertSame($api, Application::currentInstance(), 'the last one built is still the current one');
    }

    /**
     * A bare test client drives the current application: the API in a test that built it,
     * the site once the current application is reset, as it is at the start of every test.
     */
    public function testABareTestClientFollowsTheCurrentApplication(): void
    {
        // Arrange
        $site  = $this->site();
        $api   = $this->api();
        $appOf = static fn (TestClient $c): Application => (new \ReflectionProperty(TestClient::class, 'app'))->getValue($c);

        // Act & Assert — in the test that built the API
        $this->assertSame($api, $appOf(new TestClient()));

        // the next test starts here
        Application::resetCurrentInstance();
        $this->assertSame($site, $appOf(new TestClient()));
        $this->assertSame($site, Application::getInstance());
    }

    /**
     * With no site registered, a reset leaves no current application rather than a stale one.
     */
    public function testAResetWithoutASiteLeavesNone(): void
    {
        // Arrange
        (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, []);

        // Act
        Application::resetCurrentInstance();

        // Assert
        $this->assertNull(Application::currentInstance());
    }

    /**
     * While an application is made current, every lookup answers with it; restoring puts the
     * previous one back.
     */
    public function testMakingAnApplicationCurrentAndRestoring(): void
    {
        // Arrange
        $site = $this->site();
        $api  = new Api('');

        // Act
        $previous = $site->makeCurrentInstance();

        // Assert
        $this->assertSame('api', $previous);
        $this->assertSame($site, Application::currentInstance());
        $this->assertSame($site, Application::getInstance());

        Application::restoreCurrentInstance($previous);
        $this->assertSame($api, Application::currentInstance());
    }

    /**
     * An instance built without the constructor does not take another application's slot.
     */
    public function testAnUnregisteredInstanceGetsANameOfItsOwn(): void
    {
        // Arrange
        $site    = $this->site();
        $outside = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();

        // Act
        $outside->makeCurrentInstance();

        // Assert — current, and the site untouched
        $this->assertSame($outside, Application::currentInstance());
        $this->assertSame($site, Application::instanceNamed('default'));
    }

    /**
     * Nothing registered under a name is null, and nothing is built to answer.
     */
    public function testInstanceNamedBuildsNothing(): void
    {
        // Act & Assert
        $this->assertNull(Application::instanceNamed('nobody-' . bin2hex(random_bytes(3))));
    }
}
