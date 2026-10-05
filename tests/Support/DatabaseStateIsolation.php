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
 * Every test class starts with no settings loaded and no database connection.
 *
 * Which is what the first class of a sequential run sees. Both are process-wide singletons,
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
    }
}
