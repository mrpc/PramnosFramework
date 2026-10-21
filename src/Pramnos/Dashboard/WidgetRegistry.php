<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

/**
 * Every widget the dashboard can show: the framework's, the ones `app/app.php` lists, and any
 * registered in code.
 *
 * ```php
 * // app/app.php
 * 'dashboard' => [
 *     'widgets'   => [\App\Widgets\OpenOrders::class],
 *     'framework' => false,   // leave out the framework's own (users, API, database, health)
 * ],
 *
 * // or in code, from a service provider
 * WidgetRegistry::register(new OpenOrders());
 * ```
 *
 * A widget registered under an id that is already taken replaces the earlier one, so an
 * application can redraw a framework widget under its id and keep everybody's layouts.
 */
final class WidgetRegistry
{
    /** The framework's own, in the order a new dashboard shows them. */
    public const FRAMEWORK = [
        Widgets\ActiveUsers::class,
        Widgets\ApiPerformance::class,
        Widgets\DatabaseOverview::class,
        Widgets\QueueStatus::class,
        Widgets\ScheduleStatus::class,
        Widgets\EmailActivity::class,
        Widgets\Messages::class,
        Widgets\AiSpend::class,
        Widgets\HealthChecks::class,
        Widgets\RecentErrors::class,
        Widgets\WebVitals::class,
        Widgets\HelpNews::class,
        Widgets\SessionsOnline::class,
    ];

    /** @var array<string, Widget> Registered in code */
    private static array $registered = [];

    /** Add a widget, or replace the one under its id. */
    public static function register(Widget|string $widget): void
    {
        $widget = is_string($widget) ? new $widget() : $widget;
        self::$registered[$widget->id()] = $widget;
    }

    /**
     * Every widget, by id: the framework's, then app.php's, then the registered — a later one
     * under the same id winning.
     *
     * @return array<string, Widget>
     */
    public static function all(): array
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $config = is_object($app) ? (array) ($app->applicationInfo['dashboard'] ?? []) : [];

        $widgets = self::framework($config['framework'] ?? true);
        foreach ((array) ($config['widgets'] ?? []) as $class) {
            if (is_string($class) && is_subclass_of($class, Widget::class)) {
                $widget = new $class();
                $widgets[$widget->id()] = $widget;
            } else {
                \Pramnos\Logs\Logger::log('Dashboard widget ' . (is_string($class) ? $class : gettype($class)) . ' is not a ' . Widget::class . '; it was left out.', 'dashboard');
            }
        }

        return array_replace($widgets, self::$registered);
    }

    /**
     * The framework's widgets, as app.php chooses them: all of them (`true`, the default), none
     * (`false`), or a list of ids — only those, in that order:
     * `'dashboard' => ['framework' => ['ai.spend', 'logs.errors']]`.
     *
     * @return array<string, Widget>
     */
    private static function framework(mixed $chosen): array
    {
        if ($chosen === false) {
            return [];
        }
        $all = [];
        foreach (self::FRAMEWORK as $class) {
            $widget = new $class();
            $all[$widget->id()] = $widget;
        }
        if (!is_array($chosen)) {
            return $all;
        }

        $widgets = [];
        foreach ($chosen as $id) {
            if (isset($all[$id])) {
                $widgets[$id] = $all[$id];
            } else {
                \Pramnos\Logs\Logger::log('Dashboard: the framework has no widget ' . (is_scalar($id) ? (string) $id : gettype($id)) . '; it was left out.', 'dashboard');
            }
        }

        return $widgets;
    }

    /** One widget by id, or null. */
    public static function get(string $id): ?Widget
    {
        return self::all()[$id] ?? null;
    }

    /**
     * The widgets this person may have.
     *
     * @return array<string, Widget>
     */
    public static function available(?object $user): array
    {
        return array_filter(self::all(), static function (Widget $widget) use ($user): bool {
            try {
                $role = self::card($widget->id())['min_role'] ?? null;

                // app.php can raise the bar on a card, never lower it: the card's own rule still holds.
                return $widget->allowed($user) && (!is_string($role) || $role === '' || \Pramnos\User\Roles::atLeast($user, $role));
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /**
     * Whether a new dashboard has the card: `'dashboard' => ['cards' => ['logs.errors' =>
     * ['default' => false]]]` in app.php, or the widget's own {@see Widget::byDefault()}.
     */
    public static function byDefault(Widget $widget): bool
    {
        $default = self::card($widget->id())['default'] ?? null;

        return is_bool($default) ? $default : $widget->byDefault();
    }

    /**
     * What app.php says about one card, by id: `default` (on a new dashboard or not) and
     * `min_role` (a role from which it is offered, on top of its own rule) — for a card the
     * application did not write, which it cannot change otherwise.
     *
     * @return array<string, mixed>
     */
    private static function card(string $id): array
    {
        $app   = \Pramnos\Application\Application::currentInstance();
        $cards = is_object($app) ? ($app->applicationInfo['dashboard']['cards'] ?? []) : [];

        return is_array($cards) && is_array($cards[$id] ?? null) ? $cards[$id] : [];
    }

    /** @var array<string, MessageChannel> Message channels registered in code */
    private static array $channels = [];

    /** Add a message channel for the `messages` card, or replace the one under its id. */
    public static function channel(MessageChannel|string $channel): void
    {
        $channel = is_string($channel) ? new $channel() : $channel;
        self::$channels[$channel->id()] = $channel;
    }

    /**
     * The message channels, by id: email first where there is a send history, then app.php's
     * `'dashboard' => ['channels' => [...]]`, then those registered in code — a later one under
     * the same id winning.
     *
     * @return array<string, MessageChannel>
     */
    public static function channels(): array
    {
        $channels = [];
        if ((new \Pramnos\Email\SendHistory())->available()) {
            $channels['email'] = new Channels\EmailMessages();
        }
        $app = \Pramnos\Application\Application::currentInstance();
        foreach ((array) (is_object($app) ? ($app->applicationInfo['dashboard']['channels'] ?? []) : []) as $class) {
            if (is_string($class) && is_subclass_of($class, MessageChannel::class)) {
                $channel = new $class();
                $channels[$channel->id()] = $channel;
            } else {
                \Pramnos\Logs\Logger::log('Message channel ' . (is_string($class) ? $class : gettype($class)) . ' is not a ' . MessageChannel::class . '; it was left out.', 'dashboard');
            }
        }

        return array_replace($channels, self::$channels);
    }

    /** For a test: forget what was registered in code. */
    public static function reset(): void
    {
        self::$registered = [];
        self::$channels   = [];
    }
}
