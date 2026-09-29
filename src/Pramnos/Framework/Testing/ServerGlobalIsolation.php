<?php

declare(strict_types=1);

namespace Pramnos\Framework\Testing;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Gives every test the `$_SERVER` the run started with.
 *
 * Register it in `phpunit.xml`:
 *
 * ```xml
 * <extensions>
 *     <bootstrap class="Pramnos\Framework\Testing\ServerGlobalIsolation"/>
 * </extensions>
 * ```
 *
 * A controller test sets `REQUEST_METHOD`, `HTTP_HOST` or a header, and a good many start with
 * `$_SERVER = []` to be sure of what is there. In production the process ends with the request;
 * in a test run the next test inherits it. Fourteen of the framework's own test classes emptied
 * it, and the tests that paid were elsewhere: a console command found no `PHP_SELF` and Symfony
 * warned, `Request::getURL()` found a port and no host — each only in a full run, each looking
 * like a fault in the test that reported it.
 *
 * The snapshot is taken when the extension boots, after the bootstrap script, so what a project's
 * `tests/bootstrap.php` puts in `$_SERVER` is part of what every test starts from. It is restored
 * at `PreparationStarted`, before `setUp()`, so a test that sets up a request still gets it.
 *
 * @see RequestIdentityIsolation for the same treatment of the sealed identity.
 */
final class ServerGlobalIsolation implements Extension, PreparationStartedSubscriber
{
    /** @var array<string, mixed> `$_SERVER` as the run found it */
    private array $snapshot = [];

    /**
     * Records `$_SERVER` and subscribes to the start of every test.
     *
     * @param Configuration       $configuration PHPUnit's resolved configuration (unused)
     * @param Facade              $facade        Where subscribers are registered
     * @param ParameterCollection $parameters    Parameters from the XML element (unused)
     * @return void
     */
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters
    ): void {
        $this->snapshot = $_SERVER;
        $facade->registerSubscriber($this);
    }

    /**
     * Puts `$_SERVER` back before the test that is about to run.
     *
     * @param PreparationStarted $event The event, whose payload is not needed here
     * @return void
     */
    public function notify(PreparationStarted $event): void
    {
        $_SERVER = $this->snapshot;
    }
}
