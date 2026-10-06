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
 * Every test class starts with the suite's fixture settings loaded.
 *
 * `Pramnos\Framework\Testing\ProcessStateIsolation`, registered before this, has already put
 * the process back as `tests/bootstrap.php` left it — which in this suite is no settings, no
 * connection and no application. This adds the one thing particular to the framework's own
 * suite: the standard fixture settings, which nearly every class found loaded in a sequential
 * run because some class before it had loaded them. Starting from them on purpose is the same
 * world in every order; a class that wants the PostgreSQL fixture, or none, loads or clears
 * its own as it always has.
 */
final class DatabaseStateIsolation implements Extension, StartedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);
    }

    /** Load the fixture settings before each test class. */
    public function notify(Started $event): void
    {
        if (!$event->testSuite() instanceof TestSuiteForTestClass) {
            return;
        }

        Settings::clearSettings();
        Settings::loadSettings(dirname(__DIR__) . '/fixtures/app/settings.php');
    }
}
