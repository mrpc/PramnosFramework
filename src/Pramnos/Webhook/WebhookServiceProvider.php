<?php

declare(strict_types=1);

namespace Pramnos\Webhook;

use Pramnos\Application\ServiceProvider;
use Pramnos\Application\Settings;

/**
 * Service provider for the WebhookHandler.
 *
 * Binds 'webhook.secret' and 'webhook.repo_dir' into the container from
 * app.php configuration so application code can retrieve them without
 * reading Settings directly.
 *
 * Activation in app.php:
 *
 * ```php
 * 'features' => ['webhook'],
 * 'webhook' => [
 *     'secret'   => $_ENV['WEBHOOK_SECRET'] ?? '',
 *     'repo_dir' => ROOT,
 *     'log_channel' => 'webhook',
 *     'timeout'  => 120,
 * ],
 * ```
 *
 * @package PramnosFramework
 * @subpackage Webhook
 */
class WebhookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container;

        $container->bind('webhook', function () {
            $secret   = (string) (Settings::getSetting('webhook.secret') ?? '');
            $repoDir  = (string) (Settings::getSetting('webhook.repo_dir') ?? '');
            $channel  = (string) (Settings::getSetting('webhook.log_channel') ?? 'webhook');
            $timeout  = (int) (Settings::getSetting('webhook.timeout') ?? 120);

            return new WebhookHandler(
                secret:     $secret,
                repoDir:    $repoDir ?: (defined('ROOT') ? ROOT : getcwd()),
                logChannel: $channel,
                timeout:    $timeout,
            );
        });
    }

    public function boot(): void
    {
        // No routes to register — webhook.php is a standalone entry point.
    }
}
