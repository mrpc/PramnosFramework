<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Pramnos\Debug\Collectors\ServicesCollector;

/**
 * The collector behind the Domain tab's Services section.
 *
 * What it has to get right is the difference between two questions that used to
 * be conflated: how many services took part (the tab badge) and how many calls
 * they made (the reason a request is slow). A request that used one service
 * forty times and one that used forty services once must not look the same.
 */
class ServicesCollectorTest extends TestCase
{
    /**
     * The payload key is `services`, and the toolbar's Domain tab reads it
     * alongside `models`. A rename here silently empties half a tab.
     */
    public function testItIsRegisteredUnderTheServicesKey(): void
    {
        // Arrange & Act
        $collector = new ServicesCollector();

        // Assert
        $this->assertSame('services', $collector->name());
    }

    /**
     * A request that touched no service still produces a payload, rather than
     * nothing: the tab distinguishes "no service ran" from "this response
     * carried no debug data", and it can only do that if the key is present.
     */
    public function testAnUntouchedRequestReportsZeroOfEverything(): void
    {
        // Arrange
        $collector = new ServicesCollector();

        // Act
        $data = $collector->collect();

        // Assert
        $this->assertSame(0, $data['count']);
        $this->assertSame(0, $data['ops']);
        $this->assertSame([], $data['services']);
        $this->assertSame([], $data['operations']);
    }

    /**
     * A service that ran but timed nothing is still recorded.
     *
     * This is the case the base class produces on its own — construction is
     * recorded automatically, `measure()` is opt-in — and it is the whole point
     * of the feature: the panel says "StatusService ran" for a project that has
     * not instrumented a single method.
     */
    public function testAServiceThatTimedNothingIsStillListed(): void
    {
        // Arrange
        $collector = new ServicesCollector();

        // Act
        $collector->record('App\Services\StatusService');
        $data = $collector->collect();

        // Assert
        $this->assertSame(1, $data['count'], 'the service is counted');
        $this->assertSame(0, $data['ops'], 'but it made no measured call');
        $this->assertSame(
            [['class' => 'App\\Services\\StatusService', 'ops' => 0, 'ms' => 0.0]],
            $data['services']
        );
        $this->assertSame([], $data['operations']);
    }

    /**
     * Distinct classes are counted once each, however often they are recorded.
     *
     * The badge counts service *types*, not instantiations — a controller that
     * news up the same service in three methods has one service in play, and a
     * badge reading 3 would be a false alarm about the shape of the code.
     */
    public function testTheSameServiceRecordedRepeatedlyCountsOnce(): void
    {
        // Arrange
        $collector = new ServicesCollector();

        // Act
        $collector->record('App\Services\StatusService');
        $collector->record('App\Services\StatusService');
        $collector->record('App\Services\BillingService');
        $data = $collector->collect();

        // Assert
        $this->assertSame(2, $data['count']);
        $this->assertCount(2, $data['services']);
    }

    /**
     * A measured call carries its own duration and adds to its service's total.
     *
     * Both numbers matter and neither can be derived from the other in the
     * panel: the per-call row is where one slow query hides, the per-service
     * total is what makes a chatty service obvious.
     */
    public function testAMeasuredCallIsListedAndAddedToItsServiceTotal(): void
    {
        // Arrange
        $collector = new ServicesCollector();

        // Act
        $collector->record('App\Services\BillingService', 'overdue', 12.345);
        $collector->record('App\Services\BillingService', 'invoice', 7.005);
        $data = $collector->collect();

        // Assert — durations are rounded to hundredths, the resolution a panel shows
        $this->assertSame(1, $data['count']);
        $this->assertSame(2, $data['ops']);
        $this->assertSame(
            ['class' => 'App\\Services\\BillingService', 'ops' => 2, 'ms' => 19.36],
            $data['services'][0]
        );
        $this->assertSame(
            [
                ['class' => 'App\\Services\\BillingService', 'op' => 'overdue', 'ms' => 12.35],
                ['class' => 'App\\Services\\BillingService', 'op' => 'invoice', 'ms' => 7.01],
            ],
            $data['operations']
        );
    }

    /**
     * A recorded operation also registers its service, even if the service was
     * never recorded on its own.
     *
     * A subclass is free to declare a constructor and never call
     * `parent::__construct()`, in which case the first thing the collector ever
     * hears about that class is one of its measured calls. Dropping it would
     * leave a panel listing calls by a service absent from the table above them.
     */
    public function testAnOperationAloneIsEnoughToRegisterItsService(): void
    {
        // Arrange
        $collector = new ServicesCollector();

        // Act
        $collector->record('App\Services\OrphanService', 'work', 1.0);
        $data = $collector->collect();

        // Assert
        $this->assertSame(1, $data['count']);
        $this->assertSame('App\\Services\\OrphanService', $data['services'][0]['class']);
        $this->assertSame(1, $data['services'][0]['ops']);
    }

    /**
     * A class name that does not resolve is kept verbatim.
     *
     * Reflection is used only to shorten the name for display. An anonymous
     * class, or a name from a test double, must not be the reason instrumentation
     * throws inside a response it is only annotating.
     */
    public function testAnUnloadableClassNameIsKeptAsGiven(): void
    {
        // Arrange
        $collector = new ServicesCollector();

        // Act
        $collector->record('Not\A\Real\Class\AtAll', 'work', 2.0);
        $data = $collector->collect();

        // Assert
        $this->assertSame('Not\A\Real\Class\AtAll', $data['services'][0]['class']);
        $this->assertSame('Not\A\Real\Class\AtAll', $data['operations'][0]['class']);
    }

    /**
     * A class that does load is shortened to its class name.
     *
     * The panel has one narrow column for this, and every service in a project
     * shares the same namespace prefix — so the prefix is the part that carries
     * no information and the part that pushes the name out of view.
     */
    public function testARealClassNameIsShortenedForDisplay(): void
    {
        // Arrange
        $collector = new ServicesCollector();

        // Act — any class that genuinely exists will do
        $collector->record(ServicesCollector::class, 'collect', 0.5);
        $data = $collector->collect();

        // Assert
        $this->assertSame('ServicesCollector', $data['services'][0]['class']);
        $this->assertSame('ServicesCollector', $data['operations'][0]['class']);
    }

    /**
     * A null duration is recorded as zero rather than skipped.
     *
     * `measure()` always supplies one, but the signature allows a caller to
     * record a named operation it did not time — and "the call happened, with no
     * duration" is more useful than losing the call.
     */
    public function testAnOperationWithoutADurationCountsAsZeroMilliseconds(): void
    {
        // Arrange
        $collector = new ServicesCollector();

        // Act
        $collector->record('App\Services\StatusService', 'snapshot');
        $data = $collector->collect();

        // Assert
        $this->assertSame(1, $data['ops']);
        $this->assertSame(0.0, $data['operations'][0]['ms']);
        $this->assertSame(0.0, $data['services'][0]['ms']);
    }
}
