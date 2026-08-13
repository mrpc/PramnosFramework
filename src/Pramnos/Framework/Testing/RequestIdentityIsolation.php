<?php

declare(strict_types=1);

namespace Pramnos\Framework\Testing;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Pramnos\Http\RequestIdentity;

/**
 * Gives every test its own request.
 *
 * Register it in `phpunit.xml`:
 *
 * ```xml
 * <extensions>
 *     <bootstrap class="Pramnos\Framework\Testing\RequestIdentityIsolation"/>
 * </extensions>
 * ```
 *
 * {@see RequestIdentity} is request-scoped by design: a web request establishes who
 * is calling, and the process ends. A test run is the one place where that assumption
 * does not hold — thousands of "requests" share a single PHP process, so an identity
 * sealed by one test answers for every test after it.
 *
 * That is not a hypothetical. It produced 135 failures across the framework's own
 * tests, in tests that had nothing to do with authentication: a controller test would
 * run after a middleware test and find itself signed in as somebody it had never
 * heard of.
 *
 * Doing it here rather than in each test's `setUp()` is deliberate. The state is
 * reached indirectly — a controller calls a middleware, a middleware seals an
 * identity — so any list of "tests that need to reset it" is a list that goes out of
 * date silently. Resetting before every test cannot.
 *
 * It runs at `PreparationStarted`, which is before `setUp()`, so a test that
 * deliberately establishes an identity still gets what it asked for.
 *
 * @see DocumentIsolation for the same treatment of the other process-wide singleton.
 */
final class RequestIdentityIsolation implements Extension, PreparationStartedSubscriber
{
    /**
     * Subscribes to the start of every test.
     *
     * @param Configuration     $configuration PHPUnit's resolved configuration (unused)
     * @param Facade            $facade        Where subscribers are registered
     * @param ParameterCollection $parameters   Parameters from the XML element (unused)
     * @return void
     */
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters
    ): void {
        $facade->registerSubscriber($this);
    }

    /**
     * Clears the sealed identity before the test that is about to run.
     *
     * @param PreparationStarted $event The event, whose payload is not needed here
     * @return void
     */
    public function notify(PreparationStarted $event): void
    {
        RequestIdentity::reset();
    }
}
