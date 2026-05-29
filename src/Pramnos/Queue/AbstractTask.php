<?php

declare(strict_types=1);

namespace Pramnos\Queue;

/**
 * Convenience base class for queue task handlers.
 *
 * Provides sensible defaults for validate(), handleFailure(), and log() so
 * concrete handlers only need to implement execute() and getDescription().
 *
 */
abstract class AbstractTask implements TaskInterface
{
    /**
     * The application controller, used for database access and service lookup.
     *
     * @var \Pramnos\Application\Controller
     */
    protected $controller;

    /**
     * Human-readable task type name shown in the admin dashboard.
     * Override in subclasses.
     *
     * @var string
     */
    public string $name = '';

    /**
     * The last status message set by this task — available to the Worker for
     * log output after a successful execute() call.
     *
     * @var string
     */
    public string $lastMessage = '';

    /**
     * @param \Pramnos\Application\Controller $controller
     */
    public function __construct($controller)
    {
        $this->controller = $controller;
    }

    // ── TaskInterface defaults ────────────────────────────────────────────────

    /**
     * {@inheritdoc}
     *
     * Default implementation: returns false when the payload is empty.
     * Override for stricter field-level validation.
     */
    public function validate(QueueItem $queueItem): bool
    {
        $payload = $this->getPayload($queueItem);
        return !empty($payload);
    }

    /**
     * {@inheritdoc}
     *
     * Default implementation: logs the error and returns true (retry) when
     * attempts < maxattempts, otherwise false (permanently failed).
     */
    public function handleFailure(QueueItem $queueItem, \Throwable $exception): bool
    {
        \Pramnos\Logs\Logger::log(
            'Task execution failed: ' . $exception->getMessage(),
            'queue_error'
        );
        return $queueItem->attempts < $queueItem->maxattempts;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Decode and return the task payload.
     *
     * @param  QueueItem $queueItem
     * @return object|array|null
     */
    protected function getPayload(QueueItem $queueItem): object|array|null
    {
        return json_decode((string)$queueItem->payload);
    }

    /**
     * Log a message associated with a specific queue item.
     *
     * The message is written to the 'queue' log channel and also stored in
     * $this->lastMessage for the Worker to surface in dashboard output.
     *
     * @param string   $message
     * @param QueueItem $queueItem
     */
    protected function log(string $message, QueueItem $queueItem): void
    {
        $this->lastMessage = $message;
        \Pramnos\Logs\Logger::log(
            '[Task #' . $queueItem->taskid . '] (' . $queueItem->type . '): ' . $message,
            'queue'
        );
    }
}
