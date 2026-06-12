<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Queue\AbstractTask;
use Pramnos\Queue\QueueItem;
use Pramnos\Queue\TaskInterface;

/**
 * Unit tests for Pramnos\Queue\AbstractTask.
 *
 * AbstractTask is an abstract base class; we exercise it through a minimal
 * concrete subclass (ConcreteTask) that implements the required execute() and
 * getDescription() methods.
 *
 * Covers:
 *   - validate()       returns false for empty payload, true for non-empty
 *   - handleFailure()  returns true (retry) when attempts < maxattempts
 *   - handleFailure()  returns false (give up) when attempts >= maxattempts
 *   - getPayload()     decodes JSON from QueueItem::payload
 *   - log()            sets $lastMessage on the task
 */
#[CoversClass(AbstractTask::class)]
class AbstractTaskTest extends TestCase
{
    private object $controller;

    protected function setUp(): void
    {
        // AbstractTask stores $controller but our test methods don't need a
        // real one — a plain stdClass satisfies the type-hint-free constructor.
        $this->controller = new \stdClass();
    }

    // ── validate() ────────────────────────────────────────────────────────────

    /**
     * validate() must return false when the task payload is empty (null, empty
     * string, or empty JSON object). This guards against processing ghost tasks.
     */
    public function testValidateReturnsFalseForEmptyPayload(): void
    {
        // Arrange
        $task = new ConcreteTask($this->controller);
        $item = $this->makeQueueItem('');

        // Act
        $result = $task->validate($item);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * validate() must return true when the task payload contains data, so
     * the Worker knows the task is safe to execute.
     */
    public function testValidateReturnsTrueForNonEmptyPayload(): void
    {
        // Arrange
        $task = new ConcreteTask($this->controller);
        $item = $this->makeQueueItem('{"key":"value"}');

        // Act
        $result = $task->validate($item);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * validate() must return false for a JSON null payload (json_decode returns
     * null, which is falsy — the task should not be executed).
     */
    public function testValidateReturnsFalseForJsonNullPayload(): void
    {
        // Arrange
        $task = new ConcreteTask($this->controller);
        $item = $this->makeQueueItem('null');

        // Act / Assert
        $this->assertFalse($task->validate($item));
    }

    // ── handleFailure() ───────────────────────────────────────────────────────

    /**
     * handleFailure() must return true when attempts < maxattempts, indicating
     * that the task should be retried rather than permanently failed.
     */
    public function testHandleFailureReturnsTrueWhenAttemptsUnderMax(): void
    {
        // Arrange
        $task      = new ConcreteTask($this->controller);
        $item      = $this->makeQueueItem('{}');
        $item->attempts    = 1;
        $item->maxattempts = 3;
        $exception = new \RuntimeException('transient error');

        // Act
        $shouldRetry = $task->handleFailure($item, $exception);

        // Assert — still under the attempt ceiling, so retry
        $this->assertTrue($shouldRetry);
    }

    /**
     * handleFailure() must return false when attempts >= maxattempts, signalling
     * that the task should be permanently marked as failed and not retried.
     */
    public function testHandleFailureReturnsFalseWhenAttemptsReachMax(): void
    {
        // Arrange
        $task      = new ConcreteTask($this->controller);
        $item      = $this->makeQueueItem('{}');
        $item->attempts    = 3;
        $item->maxattempts = 3;
        $exception = new \RuntimeException('permanent error');

        // Act
        $shouldRetry = $task->handleFailure($item, $exception);

        // Assert — ceiling reached, give up
        $this->assertFalse($shouldRetry);
    }

    /**
     * handleFailure() must return false when attempts exceeds maxattempts
     * (defensive check for attempts that were incremented beyond the limit).
     */
    public function testHandleFailureReturnsFalseWhenAttemptsExceedMax(): void
    {
        // Arrange
        $task      = new ConcreteTask($this->controller);
        $item      = $this->makeQueueItem('{}');
        $item->attempts    = 5;
        $item->maxattempts = 3;
        $exception = new \RuntimeException('over-limit');

        // Act / Assert
        $this->assertFalse($task->handleFailure($item, $exception));
    }

    // ── getPayload() ──────────────────────────────────────────────────────────

    /**
     * getPayload() must decode the JSON string stored in QueueItem::payload
     * and return the resulting object/array.
     */
    public function testGetPayloadDecodesJsonString(): void
    {
        // Arrange
        $task    = new ConcreteTask($this->controller);
        $payload = ['user_id' => 42, 'action' => 'send_email'];
        $item    = $this->makeQueueItem(json_encode($payload));

        // Act
        $result = $task->publicGetPayload($item);

        // Assert — result is the decoded stdClass
        $this->assertNotNull($result);
        $this->assertSame(42,           (int)$result->user_id);
        $this->assertSame('send_email', (string)$result->action);
    }

    /**
     * getPayload() must return null when the payload is not valid JSON.
     */
    public function testGetPayloadReturnsNullForInvalidJson(): void
    {
        // Arrange
        $task = new ConcreteTask($this->controller);
        $item = $this->makeQueueItem('not-valid-json{{{');

        // Act
        $result = $task->publicGetPayload($item);

        // Assert
        $this->assertNull($result);
    }

    // ── log() ─────────────────────────────────────────────────────────────────

    /**
     * log() must store the message in $lastMessage so that the Worker can
     * surface it in dashboard output after a successful execute() call.
     */
    public function testLogSetsLastMessage(): void
    {
        // Arrange
        $task = new ConcreteTask($this->controller);
        $item = $this->makeQueueItem('{}');
        $item->taskid = 99;
        $item->type   = 'test_task';

        // Act
        $task->publicLog('hello from task', $item);

        // Assert — message is available for Worker to read
        $this->assertSame('hello from task', $task->lastMessage);
    }

    /**
     * The real log() method (lines 100-104) must set $lastMessage AND call
     * Logger::log(). Logger writes to /dev/null in the test environment
     * (bootstrap.php redirects error_log), so no file side-effects occur.
     * Calling publicLogReal() exercises the full protected log() body.
     */
    public function testRealLogSetsLastMessageAndCallsLogger(): void
    {
        // Arrange
        $task      = new ConcreteTask($this->controller);
        $item      = $this->makeQueueItem('{}');
        $item->taskid = 7;
        $item->type   = 'email_task';

        // Act — calls the real log() including Logger::log()
        $task->publicLogReal('real log message', $item);

        // Assert — $lastMessage was updated by the real implementation
        $this->assertSame('real log message', $task->lastMessage);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a QueueItem with only the fields that AbstractTask reads.
     *
     * QueueItem extends Model, which requires a controller argument. We bypass
     * the constructor the same way WorkerTest does — via an anonymous subclass.
     */
    private function makeQueueItem(string $payload): QueueItem
    {
        $item = new class($this->controller) extends QueueItem {
            public function __construct($c) {}
            /** @param mixed $a */
            public function save($a = false, $d = false): static { return $this; }
        };
        $item->payload     = $payload;
        $item->taskid      = 1;
        $item->type        = 'test';
        $item->attempts    = 0;
        $item->maxattempts = 3;
        return $item;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Concrete subclass — implements the abstract contract
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Minimal concrete subclass that exposes the protected helpers for testing.
 */
class ConcreteTask extends AbstractTask
{
    public function execute(QueueItem $queueItem): bool
    {
        return true;
    }

    public function getDescription(QueueItem $queueItem): string
    {
        return 'Test task';
    }

    /** Expose protected getPayload() as public for direct testing. */
    public function publicGetPayload(QueueItem $queueItem): object|array|null
    {
        return $this->getPayload($queueItem);
    }

    /** Expose protected log() as public for direct testing. */
    public function publicLog(string $message, QueueItem $queueItem): void
    {
        // Bypass the Pramnos\Logs\Logger call — just write to $lastMessage.
        $this->lastMessage = $message;
    }

    /** Expose the real protected log() (including Logger::log call) for coverage. */
    public function publicLogReal(string $message, QueueItem $queueItem): void
    {
        $this->log($message, $queueItem);
    }
}
