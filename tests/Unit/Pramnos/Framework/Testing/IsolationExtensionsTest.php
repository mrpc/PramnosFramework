<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Framework\Testing;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Code\TestDoxBuilder;
use PHPUnit\Metadata\MetadataCollection;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\Telemetry\Duration;
use PHPUnit\Event\Telemetry\GarbageCollectorStatus;
use PHPUnit\Event\Telemetry\HRTime;
use PHPUnit\Event\Telemetry\Info;
use PHPUnit\Event\Telemetry\MemoryUsage;
use PHPUnit\Event\Telemetry\Snapshot;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use Pramnos\Document\Document;
use Pramnos\Framework\Testing\DocumentIsolation;
use Pramnos\Framework\Testing\RequestIdentityIsolation;
use Pramnos\Http\RequestIdentity;

/**
 * The two PHPUnit extensions that keep process-wide singletons from leaking
 * between tests.
 *
 * These are the least self-testing pieces of code in the framework: their whole
 * purpose is to stop a failure appearing somewhere else, and if either one silently
 * stopped working, the suite would keep passing until some unrelated test began
 * failing for reasons that have nothing to do with it. That happened twice — 135
 * failures once and three another time — which is why both the subscription and the
 * reset are asserted here rather than trusted.
 *
 * They live in `src/` rather than `tests/` because `/tests` is export-ignored: a
 * scaffolded application inherits the same singletons and can only register a class
 * the package actually ships.
 */
class IsolationExtensionsTest extends TestCase
{
    /**
     * Builds a real `PreparationStarted` event.
     *
     * The extensions do not read the event, but `notify()` is typed against it and
     * PHPUnit's event classes are final, so a stub is not an option. Building the
     * real thing once here keeps that noise out of the tests.
     *
     * @return PreparationStarted An event with zeroed telemetry
     */
    private function makeEvent(): PreparationStarted
    {
        $noMemory = MemoryUsage::fromBytes(0);
        $noTime   = Duration::fromSecondsAndNanoseconds(0, 0);

        $snapshot = new Snapshot(
            HRTime::fromSecondsAndNanoseconds(0, 0),
            $noMemory,
            $noMemory,
            new GarbageCollectorStatus(
                0,
                0,
                0,
                0,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null
            )
        );

        return new PreparationStarted(
            new Info($snapshot, $noTime, $noMemory, $noTime, $noMemory),
            new TestMethod(
                self::class,
                'aTest',
                __FILE__,
                1,
                TestDoxBuilder::fromClassNameAndMethodName(self::class, 'aTest'),
                MetadataCollection::fromArray([]),
                \PHPUnit\Event\TestData\TestDataCollection::fromArray([])
            )
        );
    }

    /**
     * The identity extension clears an identity sealed by an earlier test.
     *
     * This is the exact leak that produced 135 failures: a middleware test seals an
     * identity, the process keeps it, and the next controller test finds itself
     * signed in as somebody it never authenticated.
     */
    public function testRequestIdentityIsolationClearsASealedIdentity(): void
    {
        // Arrange — an earlier test's identity, sealed and process-wide
        RequestIdentity::seal(new \stdClass(), 'apiKey', 'a-token');
        $this->assertTrue(
            RequestIdentity::isSealed(),
            'Precondition: the leak this guards against must be reproducible.'
        );

        // Act — what happens before the next test's setUp()
        (new RequestIdentityIsolation())->notify($this->makeEvent());

        // Assert — nothing survives into the next test
        $this->assertFalse(RequestIdentity::isSealed());
        $this->assertNull(RequestIdentity::user());
        $this->assertNull(RequestIdentity::issuedToken());
        $this->assertSame('', RequestIdentity::via());
    }

    /**
     * The document extension discards a document configured by an earlier test.
     *
     * The mutation asserted here is the one that actually bit: a test setting
     * `->type = 'json'` on what it believed was its own document was writing to the
     * shared HTML one, and the toolbar then refused to inject into an HTML page.
     */
    public function testDocumentIsolationDiscardsAMutatedDocument(): void
    {
        // Arrange — an earlier test mutates the shared HTML document
        $document       = Document::getInstance('html');
        $document->type = 'json';

        // Act
        (new DocumentIsolation())->notify($this->makeEvent());

        // Assert — a fresh instance, not the mutated one
        $fresh = Document::getInstance('html');
        $this->assertNotSame($document, $fresh, 'The cache must have been discarded.');
        $this->assertSame(
            'html',
            $fresh->type,
            'A document asked for by type must report that type, whatever a previous test did.'
        );
    }

    /**
     * `Document::reset()` also restores the default type.
     *
     * The default is separate state from the instance cache: a test calling
     * `getInstance('json')` with `$setDefault` makes `json` the answer to
     * `getInstance()` with no argument, for every test after it.
     */
    public function testDocumentIsolationRestoresTheDefaultType(): void
    {
        // Arrange — an earlier test makes a non-HTML type the default
        Document::getInstance('raw', true);

        // Act
        (new DocumentIsolation())->notify($this->makeEvent());

        // Assert — the untyped request is HTML again
        $this->assertSame('html', Document::getInstance()->type);
    }

    /**
     * Both extensions attempt a real subscription, and subscribe *themselves*.
     *
     * A reset method nobody calls is the failure mode with no symptom: the suite
     * stays green and the leak comes back. Proving the subscription from inside a
     * test run takes a small trick — PHPUnit seals its event facade once the run has
     * started, so any registration after that throws
     * {@see EventFacadeIsSealedException}. That exception is therefore the proof that
     * `bootstrap()` reached `registerSubscriber()` rather than doing nothing; a
     * bootstrap that had forgotten to register would return quietly.
     *
     * What gets registered is `$this` — which is why each extension is one class
     * implementing both interfaces instead of an extension plus an anonymous
     * subscriber. The two `assertInstanceOf` calls are what makes "it registered
     * something" and "it registered a PreparationStarted subscriber" the same claim.
     *
     * @return void
     */
    public function testBothExtensionsRegisterThemselvesAsSubscribers(): void
    {
        foreach ([new RequestIdentityIsolation(), new DocumentIsolation()] as $extension) {
            // Arrange
            $configuration = (new \PHPUnit\TextUI\Configuration\Builder())->build([]);
            $sealed        = false;

            // Act
            try {
                $extension->bootstrap($configuration, new Facade(), ParameterCollection::fromArray([]));
            } catch (EventFacadeIsSealedException) {
                $sealed = true;
            }

            // Assert — the call reached the event facade, and what it registers is $this
            $this->assertTrue(
                $sealed,
                get_class($extension) . '::bootstrap() did not try to register a subscriber.'
            );
            $this->assertInstanceOf(Extension::class, $extension);
            $this->assertInstanceOf(PreparationStartedSubscriber::class, $extension);
        }
    }
}
