<?php

declare(strict_types=1);

namespace Pramnos\Framework\Testing;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Pramnos\Document\Document;

/**
 * Gives every test its own document.
 *
 * Register it in `phpunit.xml`:
 *
 * ```xml
 * <extensions>
 *     <bootstrap class="Pramnos\Framework\Testing\DocumentIsolation"/>
 * </extensions>
 * ```
 *
 * {@see Document} is per-request by design: a request builds one, renders through it
 * and the process ends. A test run is where that assumption fails — thousands of
 * "requests" share one PHP process, the document is a singleton per type, and it is
 * **mutable**: both framework code and tests write to `->type` and `->themeObject`.
 *
 * So one test's document answered for every test after it. Three separate failures in
 * a single working session were this, each appearing **only in a full run**: an earlier
 * test had left the shared HTML document reporting itself as `raw` or `json`, and the
 * debug toolbar then declined to inject into a page that was HTML all along. Each time
 * the fix looked like "state the type in this test", which is true and treats the
 * symptom — the next test to touch a document would find the same trap.
 *
 * Resetting here rather than in each `setUp()` is the same reasoning as
 * {@see RequestIdentityIsolation}: the state is reached indirectly — a controller asks
 * the Factory, the Factory asks the Document — so any list of "tests that need to reset
 * it" goes out of date silently. Resetting before every test cannot.
 *
 * It runs at `PreparationStarted`, which is before `setUp()`, so a test that
 * deliberately configures a document still gets what it asked for.
 */
final class DocumentIsolation implements Extension, PreparationStartedSubscriber
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
     * Discards every cached document before the test that is about to run.
     *
     * @param PreparationStarted $event The event, whose payload is not needed here
     * @return void
     */
    public function notify(PreparationStarted $event): void
    {
        Document::reset();
    }
}
