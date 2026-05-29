<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm\Concerns;

/**
 * Model lifecycle events.
 *
 * Provides a simple observer/callback system without requiring an external
 * event dispatcher.  Events fire at key points in the save/delete lifecycle.
 *
 * ## Supported events
 * `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`
 *
 * A listener returning `false` from a "before" event (`creating`, `updating`,
 * `deleting`) cancels the operation.
 *
 * ## Registration
 *
 * ### Via observer object:
 * ```php
 * class PostObserver {
 *     public function creating(Post $post): void { ... }
 *     public function created(Post $post): void  { ... }
 * }
 * Post::observe(new PostObserver());
 * ```
 *
 * ### Via callback:
 * ```php
 * Post::on('created', function(Post $post) { ... });
 * ```
 *
 */
trait HasEvents
{
    /**
     * Event callbacks, keyed by event name then by model class.
     * Structure: ['creating' => [ClassName => [callable, ...]], ...]
     *
     * @var array<string, array<string, callable[]>>
     */
    private static array $eventListeners = [];

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register a single callback for a specific event on this model class.
     */
    public static function on(string $event, callable $callback): void
    {
        self::$eventListeners[$event][static::class][] = $callback;
    }

    /**
     * Register an observer object.  Public methods whose names match event
     * names are registered automatically.
     */
    public static function observe(object $observer): void
    {
        $events = ['creating', 'created', 'updating', 'updated', 'deleting', 'deleted'];
        foreach ($events as $event) {
            if (method_exists($observer, $event)) {
                self::on($event, [$observer, $event]);
            }
        }
    }

    /**
     * Remove all listeners for this model class (useful in test tearDown).
     */
    public static function flushEventListeners(): void
    {
        $class = static::class;
        foreach (self::$eventListeners as $event => &$classes) {
            unset($classes[$class]);
        }
    }

    // -------------------------------------------------------------------------
    // Firing
    // -------------------------------------------------------------------------

    /**
     * Fire $event, passing $this as the argument.
     *
     * Returns false if any listener explicitly returns false (cancels the
     * operation for "before" events).  Returns true otherwise.
     */
    protected function fireEvent(string $event): bool
    {
        $listeners = self::$eventListeners[$event][static::class] ?? [];
        foreach ($listeners as $callback) {
            if ($callback($this) === false) {
                return false;
            }
        }
        return true;
    }
}
