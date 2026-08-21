<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

use Pramnos\Application\ServiceProvider;
use Pramnos\Broadcasting\Apps\AppRegistryInterface;
use Pramnos\Broadcasting\Apps\AppSource;
use Pramnos\Broadcasting\Auth\ChannelRegistry;
use Pramnos\Application\Settings;
use Pramnos\Broadcasting\Drivers\DatabaseDriver;
use Pramnos\Broadcasting\Drivers\LogDriver;
use Pramnos\Broadcasting\Drivers\NullDriver;
use Pramnos\Broadcasting\Drivers\PusherDriver;
use Pramnos\Broadcasting\Drivers\RedisDriver;
use Pramnos\Broadcasting\Drivers\RedisStreamDriver;

/**
 * Bootstraps the broadcasting feature.
 *
 * Activated by listing 'broadcasting' in app.php features.
 *
 * ## Configuration (app.php)
 *
 * ```php
 * 'features' => ['broadcasting'],
 * 'broadcasting' => [
 *     'default' => 'redis', // 'null' (default) | 'log' | 'redis' | 'database' | 'pusher'
 *     'log_path' => ROOT . '/logs/broadcasting.log',
 *     'redis' => [          // redis pub/sub backplane (SSE + WebSocket)
 *         'host' => '127.0.0.1', 'port' => 6379, 'database' => 0,
 *         'password' => null, 'prefix' => '',
 *     ],
 *     'database' => [       // polling backplane for hosts without Redis
 *         'table' => 'broadcast_events',
 *     ],
 *     'pusher' => [         // Pusher / self-hosted Reverb
 *         'app_id' => '...', 'app_key' => '...', 'app_secret' => '...',
 *         'cluster' => 'eu', // or 'host'/'port'/'scheme' for Reverb
 *     ],
 * ],
 * ```
 *
 * Redis and database drivers additionally implement SubscribableDriverInterface,
 * so the same backplane can be *consumed* by an SSE stream or the WebSocket
 * server, not just published to.
 *
 * ## Container binding
 *
 * The provider registers a 'broadcasting' singleton in the container so that
 * any class (including the Broadcastable trait) can resolve it:
 *
 * ```php
 * $manager = $app->getContainer()->get('broadcasting');
 * $manager->broadcast('channel', 'event', ['key' => 'value']);
 * ```
 *
 */
class BroadcastingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $app = $this->app;

        $app->getContainer()->singleton('broadcasting', function () use ($app): BroadcastingManager {
            $config  = $app->applicationInfo['broadcasting'] ?? [];
            $default = $config['default'] ?? 'null';

            $manager = new BroadcastingManager(); // registers NullDriver automatically

            // Register LogDriver if configured
            $logPath = $config['log_path'] ?? null;
            $manager->addDriver(new LogDriver($logPath));

            // Redis pub/sub backplane. Registered unconditionally (the connection
            // is opened lazily on first use), so selecting 'redis' only fails at
            // broadcast/subscribe time if the phpredis extension is missing.
            $manager->addDriver(new RedisDriver($config['redis'] ?? []));

            // The stream backplane, registered on the same terms as pub/sub. It was
            // documented as a selectable driver — "use this one for SSE", because it
            // can replay a reconnect window — and never registered, so
            // `default => 'redis-stream'` threw, was caught below, and fell back to
            // the null driver. An application that followed the guide broadcast
            // nothing, and said so in one log line.
            $manager->addDriver(new RedisStreamDriver($config['redis'] ?? []));

            // Database polling backplane — only when a DB connection is available.
            if (($config['database'] ?? null) !== null && isset($app->database) && $app->database) {
                $table = is_array($config['database']) ? ($config['database']['table'] ?? 'broadcast_events') : 'broadcast_events';
                $manager->addDriver(new DatabaseDriver(new DatabaseEventStore($app->database, $table)));
            }

            // Pusher / Reverb — only when configured and the SDK is installed.
            $pusher = $config['pusher'] ?? null;
            if (is_array($pusher) && class_exists('\\Pusher\\Pusher')) {
                try {
                    $manager->addDriver(new PusherDriver($pusher));
                } catch (\Throwable $e) {
                    \Pramnos\Logs\Logger::log(
                        'Broadcasting: PusherDriver could not be registered: ' . $e->getMessage(),
                        'broadcasting',
                    );
                }
            }

            // End-to-end encryption for private-encrypted- channels. Absent unless
            // a key is configured, and a broadcast to such a channel then goes out
            // in the clear — the prefix alone does nothing, so this is what makes
            // the server keep its half of the contract with the client.
            $encryptionKey = (string) ($config['encryption_key'] ?? '');
            if ($encryptionKey !== '') {
                try {
                    $manager->useEncryption(
                        \Pramnos\Broadcasting\Encryption\ChannelEncrypter::fromBase64($encryptionKey)
                    );
                } catch (\Throwable $e) {
                    \Pramnos\Logs\Logger::log(
                        'Broadcasting: encryption key unusable, encrypted channels will not be '
                        . 'encrypted: ' . $e->getMessage(),
                        'broadcasting',
                    );
                }
            }

            // Set the default driver
            try {
                $manager->setDefault($default);
            } catch (\InvalidArgumentException) {
                // Configured driver not available — fall back to null
                $manager->setDefault('null');
                // The available names are in the message because the symptom of
                // landing here is an application that broadcasts nothing, and the
                // cause is usually one character.
                \Pramnos\Logs\Logger::log(
                    "Broadcasting: unknown driver '{$default}', falling back to null. "
                    . 'Available: ' . implode(', ', $manager->getDriverNames()) . '.',
                    'broadcasting',
                );
            }

            return $manager;
        });

        $this->registerChannelAuthorization();
    }

    /**
     * Register the two bindings channel authorization needs.
     *
     * `broadcasting.channels` is an empty {@see ChannelRegistry} the application
     * fills in its own provider — a registry with no rules denies every private
     * and presence channel, which is the right default for a deployment that
     * enabled the endpoint before writing any.
     *
     * `broadcasting.apps` resolves where app keys come from. It is a singleton so a
     * request performs at most one lookup, and it is built through
     * {@see AppSource} rather than from `FeatureRegistry`, so the answer does not
     * depend on which entry point asked. See that class for what went wrong when
     * it did.
     */
    private function registerChannelAuthorization(): void
    {
        $app = $this->app;

        $app->getContainer()->singleton('broadcasting.channels', static function (): ChannelRegistry {
            return new ChannelRegistry();
        });

        $app->getContainer()->singleton('broadcasting.apps', static function () use ($app): AppRegistryInterface {
            $info = $app->applicationInfo;

            // TTL 0 here: this binding serves web requests, which perform one
            // lookup and exit. The daemon builds its own registry with a TTL,
            // because a query per handshake blocks its whole select loop.
            return AppSource::registry(
                is_array($info['broadcasting'] ?? null) ? $info['broadcasting'] : [],
                is_array($info['features'] ?? null) ? $info['features'] : [],
                0
            );
        });
    }

    public function boot(): void
    {
        // Nothing to boot at framework level.
        // Application providers can call $app->getContainer()->get('broadcasting')
        // to add custom drivers after register() has run.
    }
}
