<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

/**
 * Trait that adds broadcasting support to OrmModel subclasses.
 *
 * When mixed into a model, `created`, `updated`, and `deleted` events
 * automatically broadcast on the configured channel if the application's
 * BroadcastingManager is registered in the container.
 *
 * ## Usage
 *
 * ```php
 * class Message extends OrmModel
 * {
 *     use Broadcastable;
 *
 *     // Channel to broadcast on (defaults to model name in snake_case).
 *     protected string $broadcastChannel = 'messages';
 *
 *     // Whether to auto-broadcast on created/updated/deleted events.
 *     protected bool $broadcastOnCreate = true;
 *     protected bool $broadcastOnUpdate = true;
 *     protected bool $broadcastOnDelete = true;
 * }
 * ```
 *
 * The payload is the model's toArray() output plus a `_model` key with the
 * fully-qualified class name.
 *
 */
trait Broadcastable
{
    /** Channel name.  Defaults to snake_case of the short class name. */
    protected string $broadcastChannel = '';

    /** Whether created/updated/deleted events trigger a broadcast. */
    protected bool $broadcastOnCreate = true;
    protected bool $broadcastOnUpdate = true;
    protected bool $broadcastOnDelete = true;

    // =========================================================================
    // Lifecycle hooks
    // =========================================================================

    /**
     * Broadcast after a successful create.
     *
     * Call from a model 'created' event listener or override afterSave().
     */
    public function broadcastCreated(): void
    {
        if ($this->broadcastOnCreate) {
            $this->broadcastEvent('created');
        }
    }

    /**
     * Broadcast after a successful update.
     */
    public function broadcastUpdated(): void
    {
        if ($this->broadcastOnUpdate) {
            $this->broadcastEvent('updated');
        }
    }

    /**
     * Broadcast after a successful delete.
     */
    public function broadcastDeleted(): void
    {
        if ($this->broadcastOnDelete) {
            $this->broadcastEvent('deleted');
        }
    }

    // =========================================================================
    // Core broadcast method
    // =========================================================================

    /**
     * Dispatches a custom event on the model's channel.
     *
     * ```php
     * $message->broadcastEvent('typing', ['user_id' => 7]);
     * ```
     *
     * @param string               $event   Event name — will be prefixed with model name,
     *                                      e.g. 'message.created'.
     * @param array<string, mixed> $extra   Additional payload keys merged with model data.
     */
    public function broadcastEvent(string $event, array $extra = []): void
    {
        $manager = $this->resolveBroadcastingManager();
        if ($manager === null) {
            return;
        }

        $shortName  = $this->broadcastModelName();
        $channel    = $this->broadcastChannel ?: $shortName;
        $eventFull  = $shortName . '.' . $event;

        $payload    = array_merge(
            method_exists($this, 'toArray') ? $this->toArray() : [],
            $extra,
            ['_model' => static::class],
        );

        $manager->broadcast($channel, $eventFull, $payload);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Returns the snake_case short class name (used for channel + event prefix).
     */
    protected function broadcastModelName(): string
    {
        $short = (new \ReflectionClass($this))->getShortName();
        return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($short)));
    }

    /**
     * Resolves the BroadcastingManager from the application container, or null
     * when broadcasting is not configured.
     */
    private function resolveBroadcastingManager(): ?BroadcastingManager
    {
        try {
            $app = \Pramnos\Application\Application::getInstance();
            if ($app->container->has('broadcasting')) {
                return $app->container->get('broadcasting');
            }
        } catch (\Throwable) {
        }
        return null;
    }
}
