<?php

declare(strict_types=1);

namespace Pramnos\Event;

/**
 * Contract for class-based event listeners.
 *
 * A listener class implements this interface and defines its logic in handle().
 * Register it with: Event::listen('event.name', MyListener::class)
 * or with an instance: Event::listen('event.name', new MyListener())
 */
interface ListenerInterface
{
    /**
     * Handle the incoming event.
     *
     * Return false to stop propagation to subsequent listeners.
     */
    public function handle(mixed ...$args): mixed;
}
