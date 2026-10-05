<?php

declare(strict_types=1);

namespace Pramnos\Tests\Support;

use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;
use PHPUnit\Event\TestSuite\TestSuiteForTestClass;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Pramnos\Application\Settings;

/**
 * Every test class starts with the suite's fixture settings and no database connection.
 * Both are process-wide singletons,
 * and the class before this one decided what they held: a PostgreSQL class left the
 * connection pointing at PostgreSQL, and the MySQL class after it sent backticks there. In a
 * sequential run the order was fixed, so a class that depended on its predecessor passed for
 * ever; under ParaTest each worker runs a different subset in a different order, and thirty
 * classes failed on what the class before them had left.
 *
 * Per class rather than per test: a class sets these up in setUp() and its own tests may share
 * them, which is the class's business. Between classes, nothing may.
 */
final class DatabaseStateIsolation implements Extension, StartedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);

    }

    /** Clear the settings and the default connection before each test class. */
    public function notify(Started $event): void
    {
        if (!$event->testSuite() instanceof TestSuiteForTestClass) {
            return;
        }

        // Only a connection that exists is dropped. Reaching for one that does not would build
        // it, and building it creates the Settings instance before the class has defined
        // CONFIG — and that instance reads the settings file once, at creation, so it would
        // stay empty for the rest of the run. Read, never created, through reflection.
        $instances = (new \ReflectionMethod(\Pramnos\Database\Database::class, 'getInstance'))
            ->getStaticVariables()['instance'] ?? [];
        if (isset($instances['default'])) {
            $connection = &\Pramnos\Database\Database::getInstance();
            $connection = null;
        }
        Settings::clearSettings();
        // Then the suite's standard fixture settings, the ones nearly every class found loaded
        // in a sequential run because some class before it had loaded them. Starting from
        // them on purpose is the same world in every order; a class that wants the
        // PostgreSQL fixture, or none, loads or clears its own as it always has.
        Settings::loadSettings(dirname(__DIR__) . '/fixtures/app/settings.php');

        // And no application at all. Per class, like the rest: a class that builds one in
        // setUpBeforeClass() shares it with its tests on purpose, and resetting it before
        // each test took it away from them. All of them rather than only the current one: a
        // class that put a constructor-less double in as `default` left it for every later
        // class whose controller asked getInstance() for the site.
        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, []);
        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'lastUsedApplication'))->setValue(null, null);

        // And the debug toolbar's collectors: the page cache refuses to store a page while
        // there are any, and a class that registered some left every later class's cache
        // empty.
        \Pramnos\Debug\DebugBar::reset();
        unset($_COOKIE[\Pramnos\Debug\DebugAccess::COOKIE]);

        // And the session: a `uid` a class signed in with made every later class's
        // getCurrentUser() load that user, from whatever database was connected by then.
        $_SESSION = [];

        // And the request's inputs: a `$_GET['_option']` one class left behind was the
        // route argument another class's controller read.
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        // And the request: whether one had been built decided whether a test's own
        // Request::$requestMethod survived the next getRequest().
        \Pramnos\Http\Request::resetInstance();
        \Pramnos\Debug\DebugAccess::reset();

        // And what the framework remembers about table shapes for the life of a process —
        // right in a request, stale here, where classes rebuild tables between them.
        \Pramnos\User\Token::forgetSchemaCache();
        \Pramnos\Queue\QueueManager::forgetSchemaCache();
        \Pramnos\Application\Model::$columnCache = [];
        \Pramnos\User\User::clearUserCache();

        // And the two singletons that keep what a class configured into them: Auth its
        // drivers and afterLogin() callbacks (one test's closure asserted inside another
        // class's sign-in), Permissions the store it detected and the rows it cached.
        $auth = &\Pramnos\Auth\Auth::getInstance();
        $auth = null;
        $permissions = &\Pramnos\Auth\Permissions::getInstance();
        $permissions = null;

        // And every registry the framework fills for the life of a process. A class that
        // registered a fake second factor, a gate ability or a health check left it for
        // whichever class the run happened to put next, so a test passed or failed by order.
        foreach ([
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
            \Pramnos\Storage\Storage::class,
            \Pramnos\Search\Registry::class,
        ] as $registry) {
            $registry::reset();
        }
    }
}
