<?php

namespace Pramnos\Messaging;

use Pramnos\Application\ServiceProvider;

/**
 * Bootstraps the Messaging feature for applications that declare 'messaging'
 * in their app.php features list.
 *
 * The messaging system covers internal private messages, push/email
 * notifications, mass broadcasts, and the email outbox queue. All five
 * models (Mail, MailTemplate, Message, MassMessage, MassMessageRecipient)
 * become available as soon as the provider boots.
 *
 * Lifecycle:
 *   register() — runs before all boot() calls; safe for early bindings only.
 *   boot()     — runs after all providers have registered; safe for anything
 *                that depends on other features.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Messaging
 */
class MessagingServiceProvider extends ServiceProvider
{
    /**
     * Register early bindings.
     *
     * Nothing to bind at framework level — the messaging models are
     * instantiated on demand by application code.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap messaging services after all providers have registered.
     *
     * Hook point for applications that want to register event listeners,
     * scheduled cleanup tasks, or custom delivery handlers at boot time.
     */
    public function boot(): void
    {
    }
}
