<?php

declare(strict_types=1);

namespace Pramnos\Framework\Testing;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Pramnos\Auth\Gate;

/**
 * Gives every test its own gate.
 *
 * Register it in `phpunit.xml`:
 *
 * ```xml
 * <extensions>
 *     <bootstrap class="Pramnos\Framework\Testing\GateIsolation"/>
 * </extensions>
 * ```
 *
 * {@see Gate} keeps its abilities, policies and hooks in statics, which is right for a
 * request and wrong for a test run: a `Gate::before(fn () => true)` registered by one test
 * would answer for every test after it, and the failure would appear somewhere else
 * entirely — in a test asserting that an ordinary user is *refused*, which is exactly the
 * assertion nobody expects to be affected by an unrelated file.
 *
 * This is the third extension of this shape, and it was written **with** the feature rather
 * than after the failures, which is the whole point of having the pattern. The other two
 * cost 135 failures and three, respectively, before anybody worked out what was happening.
 *
 * Resetting at `PreparationStarted` puts it before `setUp()`, so a test that defines its
 * own abilities still gets exactly what it asked for.
 *
 * @see RequestIdentityIsolation
 * @see DocumentIsolation
 */
final class GateIsolation implements Extension, PreparationStartedSubscriber
{
    /**
     * Subscribes to the start of every test.
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
        $facade->registerSubscriber($this);
    }

    /**
     * Forgets every ability, policy and hook before the test that is about to run.
     *
     * @param PreparationStarted $event The event, whose payload is not needed here
     * @return void
     */
    public function notify(PreparationStarted $event): void
    {
        Gate::reset();
    }
}
