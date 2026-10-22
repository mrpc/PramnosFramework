<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Application\ServiceProvider;

/**
 * Bootstraps the conversations, when `conversations` is among the application's features: the
 * channels a browser may listen on.
 */
class ConversationsServiceProvider extends ServiceProvider
{
    /** Nothing to bind. */
    public function register(): void
    {
    }

    /** The conversations' channel rules, where broadcasting is set up. */
    public function boot(): void
    {
        $container = $this->app->getContainer();
        if ($container->has('broadcasting.channels')) {
            Realtime::authorize($container->get('broadcasting.channels'));
        }
    }
}
