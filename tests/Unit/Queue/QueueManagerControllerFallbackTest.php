<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Queue\QueueManager;

/**
 * The queue's commands do not need an administration screen to exist.
 *
 * Every command that touches a queue model has to produce a `Controller`, because
 * `Application\Model` will not be constructed without one. They asked for `Queueitems` — the
 * name of the *screen* for queued jobs — and `getController()` throws on a name it cannot
 * resolve, so `queue:process` could not start at all in an application that had no such
 * screen. Under a supervisor: respawn, fail, repeat, with a queue that never drains and a
 * start-up error naming a UI class the worker was never going to render anything with.
 */
#[CoversClass(QueueManager::class)]
class QueueManagerControllerFallbackTest extends TestCase
{
    /**
     * A worker starts even when the controller it names does not exist.
     *
     * `Application\Model` will not be constructed without a `Controller`, so every command
     * that touches a model has to produce one. The queue commands asked for `Queueitems` —
     * the name of the *administration screen* for queued jobs — and `getController()` throws
     * when a name does not resolve.
     *
     * So `queue:process` could not start at all in an application without that screen:
     * «Cannot find controller: Queueitems», from a background worker, about a UI class it was
     * never going to render anything with. On a server whose queue is how mass messages are
     * sent, that is the whole feature failing on a name — and it fails at start-up, so the
     * supervisor respawns it for ever.
     */
    public function testAMissingControllerFallsBackToAPlainOne(): void
    {
        // Arrange
        $refuses = new class {
            public function getController(string $name)
            {
                throw new \Exception('Cannot find controller: ' . $name);
            }
        };

        // Act
        $controller = QueueManager::controllerOrPlain($refuses, 'Queueitems');

        // Assert
        $this->assertInstanceOf(\Pramnos\Application\Controller::class, $controller);
    }

    /**
     * And the application's own controller is still used when it has one.
     *
     * The fallback must not quietly replace a subclass an application supplied — that
     * subclass is where a project puts the task handlers and the screen URLs.
     */
    public function testAnExistingControllerIsPreferred(): void
    {
        // Arrange
        $marker = new \stdClass();
        $provides = new class($marker) {
            public function __construct(private \stdClass $marker) {}
            public function getController(string $name): \stdClass
            {
                return $this->marker;
            }
        };

        // Act & Assert
        $this->assertSame($marker, QueueManager::controllerOrPlain($provides, 'Queueitems'));
    }
}
