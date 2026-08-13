<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Service;
use Pramnos\Database\QueryBuilder;
use Pramnos\Debug\Collectors\ServicesCollector;
use Pramnos\Debug\DebugBar;

/**
 * The base class that makes a service visible and stops it re-implementing
 * the same three lines of database wiring.
 *
 * Two invariants dominate these tests, because both have already gone wrong
 * elsewhere in the framework:
 *
 * 1. **Instrumentation never changes behaviour.** With no toolbar registered —
 *    production, and every CLI run — every recording call must be a no-op, and a
 *    failure inside the debug layer must not surface in the caller.
 * 2. **The connection is resolved on first use, never at construction.** A unit
 *    test that instantiates a service and asserts on pure logic must not open a
 *    database connection to do it.
 */
#[CoversClass(Service::class)]
class ServiceTest extends TestCase
{
    /**
     * The toolbar is a singleton; a collector left registered by one test would
     * otherwise count the services of the next one.
     */
    protected function tearDown(): void
    {
        DebugBar::reset();
        parent::tearDown();
    }

    /**
     * Register a fresh collector and hand it back.
     */
    private function collecting(): ServicesCollector
    {
        DebugBar::reset();
        $collector = new ServicesCollector();
        DebugBar::getInstance()->addCollector($collector);
        return $collector;
    }

    /**
     * Constructing a service is enough to make it appear in the toolbar.
     *
     * This is the entire point of the base class: the Domain tab was empty in a
     * services-oriented project because a plain class offers nothing to observe.
     * Nobody has to remember to instrument anything — inheriting is the opt-in.
     */
    public function testConstructingAServiceRecordsIt(): void
    {
        // Arrange
        $collector = $this->collecting();

        // Act
        new ServiceTestDouble();

        // Assert
        $data = $collector->collect();
        $this->assertSame(1, $data['count']);
        $this->assertSame('ServiceTestDouble', $data['services'][0]['class']);
        // Recorded, but nothing timed: measure() is the opt-in half.
        $this->assertSame(0, $data['ops']);
    }

    /**
     * The connection is not resolved until something asks for it.
     *
     * Asserted on the property rather than by watching for a connection,
     * because that is the fact that matters: an eagerly-filled property is how
     * an ordinary unit test ends up needing a database server.
     */
    public function testTheConnectionIsNotOpenedAtConstructionTime(): void
    {
        // Arrange & Act
        $service  = new ServiceTestDouble();
        $property = (new \ReflectionClass(Service::class))->getProperty('database');

        // Assert
        $this->assertNull($property->getValue($service));
    }

    /**
     * An injected connection is the one used, and it is used as given.
     *
     * The injection point is what makes a service testable at all, so it must
     * not be second-guessed by the base class.
     */
    public function testAnInjectedConnectionIsTheOneReturned(): void
    {
        // Arrange
        $database = \Pramnos\Database\Database::getInstance();

        // Act
        $service = new ServiceTestDouble($database);

        // Assert
        $this->assertSame($database, $service->connection());
    }

    /**
     * With nothing injected, the connection comes from the framework factory —
     * and is then kept, so a service does not resolve it once per call.
     */
    public function testTheDefaultConnectionIsResolvedOnceAndReused(): void
    {
        // Arrange
        $service = new ServiceTestDouble();

        // Act
        $first  = $service->connection();
        $second = $service->connection();

        // Assert
        $this->assertInstanceOf(\Pramnos\Database\Database::class, $first);
        $this->assertSame($first, $second);
    }

    /**
     * queryBuilder() hands back a builder on this service's connection, with the
     * table already applied when one is named.
     *
     * The builder is the only layer that knows the dialect (schema on
     * PostgreSQL, prefix on MySQL, per-driver quoting), so the shortest path
     * from a service to its data has to lead here — otherwise services grow
     * hand-built SQL, which is the bug class rule 12 exists for.
     */
    public function testQueryBuilderStartsFromTheNamedTable(): void
    {
        // Arrange
        $service = new ServiceTestDouble();

        // Act
        $bare    = $service->builder();
        $ontable = $service->builder('users');

        // Assert
        $this->assertInstanceOf(QueryBuilder::class, $bare);
        $this->assertInstanceOf(QueryBuilder::class, $ontable);
        // The table reaches the generated SQL — proof it was applied rather than
        // silently dropped.
        $this->assertStringContainsString('users', $ontable->toSql());
    }

    /**
     * measure() returns exactly what the callback returned, and records the call.
     *
     * A wrapper that alters the value would be unusable in the one place it is
     * meant to be used: around the `return` of a service method.
     */
    public function testMeasureReturnsTheCallbackValueAndRecordsTheCall(): void
    {
        // Arrange
        $collector = $this->collecting();
        $service   = new ServiceTestDouble();

        // Act
        $result = $service->work(fn(): array => ['rows' => 3]);

        // Assert
        $this->assertSame(['rows' => 3], $result);
        $data = $collector->collect();
        $this->assertSame(1, $data['ops']);
        $this->assertSame('work', $data['operations'][0]['op']);
        $this->assertSame('ServiceTestDouble', $data['operations'][0]['class']);
        // A duration is recorded, even for work too fast to register as a whole
        // millisecond — the key must exist for the panel to draw a row.
        $this->assertGreaterThanOrEqual(0.0, $data['operations'][0]['ms']);
    }

    /**
     * A call that threw is still recorded, and the exception still propagates.
     *
     * The failing call is the one worth seeing in the toolbar. Swallowing it to
     * get the timing would turn a debugging aid into a bug-hiding one, so both
     * halves are asserted together.
     */
    public function testAFailedCallIsRecordedAndTheExceptionIsRethrown(): void
    {
        // Arrange
        $collector = $this->collecting();
        $service   = new ServiceTestDouble();

        // Act
        try {
            $service->work(function (): void {
                throw new \RuntimeException('the query failed');
            });
            $this->fail('The exception should have propagated.');
        } catch (\RuntimeException $e) {
            // Assert — same exception, unwrapped
            $this->assertSame('the query failed', $e->getMessage());
        }

        // Assert — and the attempt is in the toolbar
        $this->assertSame(1, $collector->collect()['ops']);
    }

    /**
     * With no collector registered, everything still works and nothing is
     * recorded.
     *
     * This is the production and CLI path — the toolbar registers collectors
     * only in debug mode — so it is the path that runs billions of times and
     * must cost nothing but a null check.
     */
    public function testWithoutAToolbarNothingIsRecordedAndNothingBreaks(): void
    {
        // Arrange
        DebugBar::reset();

        // Act
        $service = new ServiceTestDouble();
        $result  = $service->work(fn(): string => 'done');

        // Assert
        $this->assertSame('done', $result);
        $this->assertNull(DebugBar::getInstance()->getCollector('services'));
    }

    /**
     * A collector registered under `services` that is not a ServicesCollector is
     * ignored rather than called.
     *
     * An application is free to register its own collectors, and a name clash
     * must not turn into a call to a method that does not exist — mid-request,
     * inside a service, for the sake of a panel.
     */
    public function testAForeignCollectorUnderTheSameNameIsIgnored(): void
    {
        // Arrange
        DebugBar::reset();
        DebugBar::getInstance()->addCollector(new ServiceTestForeignCollector());

        // Act
        $service = new ServiceTestDouble();
        $result  = $service->work(fn(): int => 7);

        // Assert
        $this->assertSame(7, $result);
        $this->assertInstanceOf(
            ServiceTestForeignCollector::class,
            DebugBar::getInstance()->getCollector('services')
        );
    }
}

/**
 * A service with the base class's protected surface exposed for assertion.
 *
 * Deliberately minimal: what is under test is the base, and a double that added
 * logic of its own would blur which of the two produced a result.
 */
class ServiceTestDouble extends Service
{
    /**
     * The connection the base resolved.
     */
    public function connection(): \Pramnos\Database\Database
    {
        return $this->database();
    }

    /**
     * A builder from the base, optionally on a table.
     */
    public function builder(?string $table = null): QueryBuilder
    {
        return $this->queryBuilder($table);
    }

    /**
     * Run something through measure() under a fixed operation name.
     *
     * @param  callable $callback
     * @return mixed
     */
    public function work(callable $callback): mixed
    {
        return $this->measure('work', $callback);
    }
}

/**
 * Something else registered under the `services` name.
 *
 * Exists only to prove the base class checks the type of what it found rather
 * than trusting the name it looked up.
 */
class ServiceTestForeignCollector implements \Pramnos\Debug\Collectors\CollectorInterface
{
    /** The clashing name. */
    public function name(): string
    {
        return 'services';
    }

    /** Nothing to collect; this collector exists to be found, not to work. */
    public function collect(): array
    {
        return [];
    }
}
