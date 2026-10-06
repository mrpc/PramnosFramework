<?php

declare(strict_types=1);

namespace Pramnos\Framework\Testing;

use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;
use PHPUnit\Event\TestSuite\TestSuiteForTestClass;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Gives every test class the process as the bootstrap script left it.
 *
 * Register it in `phpunit.xml`:
 *
 * ```xml
 * <extensions>
 *     <bootstrap class="Pramnos\Framework\Testing\ProcessStateIsolation"/>
 * </extensions>
 * ```
 *
 * A test run is one PHP process, and the framework keeps a good deal in it for the life of a
 * request: settings, the connection, the application, `Auth` and `Permissions`, two dozen
 * registries, the superglobals, and the environment `putenv()` changes. A class that changes any of it changes it for whichever class
 * the run puts next, so a test passes or fails by the order it runs in — and the failure
 * appears in a test that did nothing wrong. Run in random order, the framework's own suite
 * started at over twenty such failures per seed.
 *
 * So the state is recorded once, when this extension boots (after `tests/bootstrap.php`), and
 * put back before every test class. What the bootstrap set up — settings it loaded, an
 * application it initialised, drivers or features it registered — is there for every class.
 * What a class added is gone before the next one. Caches of table shapes and loaded users are
 * emptied outright, since the classes rebuild the tables they describe.
 *
 * Per class rather than per test: a class may set state up in `setUpBeforeClass()` and share
 * it with its tests, which is the class's business. Between classes, nothing is shared.
 *
 * @see ServerGlobalIsolation which does the same for `$_SERVER`, per test
 */
final class ProcessStateIsolation implements Extension, StartedSubscriber
{
    /**
     * Classes whose static properties hold process-wide state, restored as recorded.
     *
     * @var list<class-string>
     */
    private const STATEFUL = [
        \Pramnos\Application\Settings::class,
        \Pramnos\Application\Application::class,
        \Pramnos\Auth\SecondFactorRegistry::class,
        \Pramnos\Auth\Gate::class,
        \Pramnos\Auth\OrganizationScope::class,
        \Pramnos\Auth\WebhookEvents::class,
        \Pramnos\Email\MailAction::class,
        \Pramnos\Email\Unsubscribe::class,
        \Pramnos\Email\MailTypes::class,
        \Pramnos\Messaging\SystemMailTemplates::class,
        \Pramnos\Security\PersonalDataRegistry::class,
        \Pramnos\Health\HealthRegistry::class,
        \Pramnos\Theme\Theme::class,
        \Pramnos\Document\Document::class,
        \Pramnos\Event\ChangeFeed::class,
        \Pramnos\Application\FeatureRegistry::class,
        \Pramnos\Application\NavRegistry::class,
        \Pramnos\Mcp\PublicRegistry::class,
        \Pramnos\Debug\RequestId::class,
        \Pramnos\Database\HypertableRegistry::class,
        \Pramnos\Database\ContinuousAggregateRegistry::class,
        \Pramnos\Database\WriteSpool::class,
        \Pramnos\Changelog\ChangelogRenderer::class,
        \Pramnos\Http\AdminArea::class,
        \Pramnos\Http\SiteUrl::class,
        \Pramnos\Http\RequestIdentity::class,
        \Pramnos\Scheduling\Scheduler::class,
        \Pramnos\Scheduling\FrameworkSchedule::class,
        \Pramnos\Storage\Storage::class,
        \Pramnos\Search\Registry::class,
    ];

    /** @var array<class-string, array<string, mixed>> Static properties as recorded */
    private array $statics = [];

    /** @var array<string, mixed> Superglobals as recorded */
    private array $globals = [];

    /** @var array<string, object|null> Singletons kept in a method's static variable */
    private array $singletons = [];

    /** @var array<string, string> The process environment as recorded, from getenv() */
    private array $environment = [];

    /**
     * Records the state and subscribes to the start of every test class.
     *
     * @param Configuration       $configuration PHPUnit's resolved configuration (unused)
     * @param Facade              $facade        Where subscribers are registered
     * @param ParameterCollection $parameters    Parameters from the XML element (unused)
     * @return void
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $this->record();
        $facade->registerSubscriber($this);
    }

    /**
     * Puts the recorded state back before a test class starts.
     *
     * @param Started $event The suite that is starting; only a test class's suite counts
     * @return void
     */
    public function notify(Started $event): void
    {
        if ($event->testSuite() instanceof TestSuiteForTestClass) {
            $this->restore();
        }
    }

    /**
     * Take the record. Public so a project's own bootstrap can retake it after changing
     * something on purpose; the extension calls it once, at boot.
     *
     * @return void
     */
    public function record(): void
    {
        $this->statics = [];
        foreach (self::STATEFUL as $class) {
            if (class_exists($class)) {
                $this->statics[$class] = (new \ReflectionClass($class))->getStaticProperties();
            }
        }

        $this->globals = [
            '_SESSION' => $_SESSION ?? [],
            '_GET'     => $_GET,
            '_POST'    => $_POST,
            '_REQUEST' => $_REQUEST,
            '_COOKIE'  => $_COOKIE,
            '_ENV'     => $_ENV,
        ];
        $this->environment = getenv();

        $this->singletons = [
            'database'    => self::held(\Pramnos\Database\Database::class, 'instance')['default'] ?? null,
            'auth'        => self::copy(self::held(\Pramnos\Auth\Auth::class, 'instance')),
            'permissions' => self::copy(self::held(\Pramnos\Auth\Permissions::class, 'instance')),
        ];
    }

    /**
     * Put the record back, and empty the caches that describe tables and users.
     *
     * @return void
     */
    public function restore(): void
    {
        foreach ($this->statics as $class => $properties) {
            foreach ($properties as $name => $value) {
                (new \ReflectionProperty($class, $name))->setValue(null, $value);
            }
        }

        $_SESSION = $this->globals['_SESSION'];
        $_GET     = $this->globals['_GET'];
        $_POST    = $this->globals['_POST'];
        $_REQUEST = $this->globals['_REQUEST'];
        $_COOKIE  = $this->globals['_COOKIE'];
        $_ENV     = $this->globals['_ENV'];

        // The environment, which a test changes with putenv() — `APP_DEBUG=1` left behind made
        // every later class a development environment. Variables added since are removed.
        $current = getenv();
        foreach (array_diff_key($current, $this->environment) as $name => $unused) {
            putenv($name);
        }
        foreach ($this->environment as $name => $value) {
            if (($current[$name] ?? null) !== $value) {
                putenv($name . '=' . $value);
            }
        }

        // Replaced only where something is held: asking a method for its instance when it has
        // none would build one, before the class has loaded whatever it means to use.
        if (isset(self::held(\Pramnos\Database\Database::class, 'instance')['default'])
            || $this->singletons['database'] !== null) {
            $connection = &\Pramnos\Database\Database::getInstance();
            $connection = $this->singletons['database'];
        }
        if (self::held(\Pramnos\Auth\Auth::class, 'instance') !== null || $this->singletons['auth'] !== null) {
            $auth = &\Pramnos\Auth\Auth::getInstance();
            $auth = self::copy($this->singletons['auth']);
        }
        if (self::held(\Pramnos\Auth\Permissions::class, 'instance') !== null || $this->singletons['permissions'] !== null) {
            $permissions = &\Pramnos\Auth\Permissions::getInstance();
            $permissions = self::copy($this->singletons['permissions']);
        }

        // Caches, emptied rather than restored: they describe tables and rows that the
        // classes create and drop between them.
        \Pramnos\Http\Request::resetInstance();
        \Pramnos\Debug\DebugBar::reset();
        \Pramnos\Debug\DebugAccess::reset();
        \Pramnos\User\Token::forgetSchemaCache();
        \Pramnos\Queue\QueueManager::forgetSchemaCache();
        \Pramnos\Application\Model::$columnCache = [];
        \Pramnos\User\User::clearUserCache();
    }

    /**
     * What a `getInstance()` method holds in its static variable, without calling it.
     *
     * @param class-string $class
     * @return mixed
     */
    private static function held(string $class, string $variable): mixed
    {
        return (new \ReflectionMethod($class, 'getInstance'))->getStaticVariables()[$variable] ?? null;
    }

    /**
     * A copy, so what a class registers on the singleton does not reach the record.
     *
     * @return object|null
     */
    private static function copy(mixed $object): ?object
    {
        return is_object($object) ? clone $object : null;
    }
}
