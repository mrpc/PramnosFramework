<?php

declare(strict_types=1);

namespace Pramnos\Event\Transport;

use Pramnos\Event\QueuedListener;

/**
 * Which transport each queued listener uses: the installation's, unless the listener is named.
 *
 * ```php
 * // app.php
 * 'events' => [
 *     'transport' => 'stream',                                  // the installation's; 'queue' by default
 *     'listeners' => [App\Listeners\SyncCrm::class => 'queue'], // a listener of its own choosing
 * ],
 * ```
 *
 * A transport that is not known here, or cannot carry events (its tables are missing), is passed
 * over for the queue, and the log says so once per process — an event is never lost to a
 * setting. `bin/pramnos events:status` shows what each listener would use before it is deployed.
 */
final class Transports
{
    /** The log the choices are written to. */
    public const LOG = 'events';

    /** @var array<string, EventTransport> The transports by name */
    private static array $known = [];

    /** @var array<string, true> The settings already said to be passed over */
    private static array $warned = [];

    /** Make a transport known by its name — the framework's, or an application's own. */
    public static function register(EventTransport $transport): void
    {
        self::$known[$transport->name()] = $transport;
    }

    /**
     * The transports known here, by name: the queue always.
     *
     * @return array<string, EventTransport>
     */
    public static function all(): array
    {
        return [QueueTransport::NAME => self::$known[QueueTransport::NAME] ?? new QueueTransport()] + self::$known;
    }

    /**
     * What a listener is set to use: its own setting, or the installation's.
     *
     * @param QueuedListener|class-string<QueuedListener> $listener
     */
    public static function configured(QueuedListener|string $listener): string
    {
        $class    = is_string($listener) ? $listener : $listener::class;
        $settings = self::settings();
        $own      = $settings['listeners'][$class] ?? null;

        return is_string($own) && $own !== '' ? $own : (is_string($settings['transport'] ?? null) && $settings['transport'] !== '' ? $settings['transport'] : QueueTransport::NAME);
    }

    /**
     * The transport a listener's events go by: the one it is set to, where it can carry them, or
     * the queue.
     *
     * @param QueuedListener|class-string<QueuedListener> $listener
     */
    public static function for(QueuedListener|string $listener): EventTransport
    {
        $name      = self::configured($listener);
        $transport = self::all()[$name] ?? null;
        if ($transport !== null && ($name === QueueTransport::NAME || $transport->available())) {
            return $transport;
        }
        if (!isset(self::$warned[$name])) {
            self::$warned[$name] = true;
            \Pramnos\Logs\Logger::log('Events set to go by "' . $name . '" go by the queue: '
                . ($transport === null ? 'no such transport here.' : 'it cannot carry events here (run migrate?).'), self::LOG);
        }

        return self::all()[QueueTransport::NAME];
    }

    /** Forget the transports an application registered and the warnings given — for tests. */
    public static function reset(): void
    {
        self::$known  = [];
        self::$warned = [];
    }

    /** @return array<string, mixed> The application's `events` settings */
    private static function settings(): array
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return is_object($app) && is_array($app->applicationInfo['events'] ?? null) ? $app->applicationInfo['events'] : [];
    }
}
