<?php

declare(strict_types=1);

namespace Pramnos\Tests\Support;

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
 * {@see RequestIdentity} is request-scoped by design: a web request establishes
 * who is calling, and the process ends. A test run is the one place where that
 * assumption does not hold — thousands of "requests" share a single PHP process,
 * so an identity sealed by one test answers for every test after it.
 *
 * That is not a hypothetical. It produced 135 failures across tests that had
 * nothing to do with authentication: a controller test would run after a
 * middleware test and find itself signed in as somebody it had never heard of.
 *
 * Doing it here rather than in each test's `setUp()` is deliberate. The state is
 * reached indirectly — a controller calls a middleware, a middleware seals an
 * identity — so any list of "tests that need to reset it" is a list that goes
 * out of date silently. Resetting before every test cannot.
 */
final class RequestIdentityIsolation implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters
    ): void {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                RequestIdentity::reset();
            }
        });
    }
}
