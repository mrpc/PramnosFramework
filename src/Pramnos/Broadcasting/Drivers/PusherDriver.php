<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Drivers;

use Pramnos\Broadcasting\Drivers\DriverInterface;

/**
 * Broadcasting driver that publishes events via the Pusher HTTP API.
 *
 * Works with the Pusher cloud service and any Pusher-compatible server, in
 * particular Laravel Reverb (which exposes a Pusher-compatible HTTP endpoint
 * and can be run locally for development).
 *
 * ## Requirements
 *
 * This driver requires the optional Composer package:
 *
 *   composer require pusher/pusher-php-server
 *
 * A RuntimeException is thrown at construction time when the package is not
 * installed, so the error is surfaced at start-up rather than at the first
 * broadcast attempt.
 *
 * ## Configuration (app.php)
 *
 * ```php
 * 'broadcasting' => [
 *     'default' => 'pusher',
 *     'pusher'  => [
 *         'driver'     => 'pusher',
 *         'app_id'     => env('PUSHER_APP_ID'),
 *         'app_key'    => env('PUSHER_APP_KEY'),
 *         'app_secret' => env('PUSHER_APP_SECRET'),
 *         'cluster'    => env('PUSHER_APP_CLUSTER', 'eu'),
 *         'encrypted'  => true,
 *         // For Reverb (self-hosted Pusher-compatible server):
 *         // 'host'    => '127.0.0.1',
 *         // 'port'    => 8080,
 *         // 'scheme'  => 'http',
 *     ],
 * ]
 * ```
 *
 * ## Reverb (local dev)
 *
 * Laravel Reverb provides a Pusher-compatible WebSocket server. Point this
 * driver at Reverb by setting 'host', 'port', 'scheme' and 'encrypted'=false.
 *
 * @package     PramnosFramework
 * @subpackage  Broadcasting\Drivers
 */
class PusherDriver implements DriverInterface
{
    private const PUSHER_CLASS = 'Pusher\\Pusher';

    /** The Pusher SDK instance (typed as mixed to avoid compile-time resolution). */
    private mixed $pusher;

    /**
     * @param array{
     *   app_id: string,
     *   app_key: string,
     *   app_secret: string,
     *   cluster?: string,
     *   encrypted?: bool,
     *   host?: string,
     *   port?: int,
     *   scheme?: string,
     * } $config
     *
     * @throws \RuntimeException When pusher/pusher-php-server is not installed.
     */
    public function __construct(private readonly array $config)
    {
        if (!class_exists(self::PUSHER_CLASS)) {
            throw new \RuntimeException(
                'PusherDriver requires the pusher/pusher-php-server Composer package. '
                . 'Install it with: composer require pusher/pusher-php-server'
            );
        }

        $options = $this->buildOptions($config);
        $class   = self::PUSHER_CLASS;

        $this->pusher = new $class(
            $config['app_key']    ?? '',
            $config['app_secret'] ?? '',
            $config['app_id']     ?? '',
            $options
        );
    }

    /**
     * Broadcast an event to a Pusher channel.
     *
     * The payload is JSON-encoded and sent as the event data. The channel
     * and event names may use any format valid for Pusher (e.g. 'users.42',
     * 'private-orders', 'presence-room').
     */
    public function broadcast(string $channel, string $event, array $payload): void
    {
        $this->pusher->trigger($channel, $event, $payload);
    }

    /**
     * The canonical name used to identify this driver in the BroadcastingManager.
     */
    public function name(): string
    {
        return 'pusher';
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build the options array for the Pusher SDK constructor.
     *
     * When 'host' is set in config, the driver operates in Reverb/self-hosted
     * mode and disables SSL verification for local dev setups.
     */
    private function buildOptions(array $config): array
    {
        $options = [
            'cluster'   => $config['cluster']   ?? 'eu',
            'encrypted' => $config['encrypted'] ?? true,
            'useTLS'    => $config['encrypted'] ?? true,
        ];

        if (!empty($config['host'])) {
            $options['host']             = $config['host'];
            $options['port']             = $config['port']   ?? 8080;
            $options['scheme']           = $config['scheme'] ?? 'http';
            $options['useTLS']           = ($config['scheme'] ?? 'http') === 'https';
            $options['curl_options']     = [CURLOPT_SSL_VERIFYPEER => false];
        }

        return $options;
    }
}
