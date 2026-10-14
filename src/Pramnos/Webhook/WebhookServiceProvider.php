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
 */
class WebhookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // getContainer(), not ->container: nothing creates the container, so
        // ->container is null and enabling this feature killed init() outright.
        $container = $this->app->getContainer();

        $container->bind('webhook', function () {
            // `'webhook' => [...]` in app.php, as documented above; a setting named `webhook.<key>`
            // still answers where the array has no such key.
            $config = (array) ($this->app->applicationInfo['webhook'] ?? []);
            $value  = static function (string $key, mixed $default) use ($config): mixed {
                if (array_key_exists($key, $config)) {
                    return $config[$key];
                }
                $setting = Settings::getSetting('webhook.' . $key);

                return $setting === false || $setting === null || $setting === '' ? $default : $setting;
            };
            $secret  = (string) $value('secret', '');
            $repoDir = (string) $value('repo_dir', '');
            $channel = (string) $value('log_channel', 'webhook');
            $timeout = (int) $value('timeout', 120);

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
