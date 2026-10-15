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
        Widgets\HealthChecks::class,
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

        $classes = ($config['framework'] ?? true) === false ? [] : self::FRAMEWORK;
        foreach ((array) ($config['widgets'] ?? []) as $class) {
            $classes[] = $class;
        }

        $widgets = [];
        foreach ($classes as $class) {
            if (is_string($class) && is_subclass_of($class, Widget::class)) {
                $widget = new $class();
                $widgets[$widget->id()] = $widget;
            } else {
                \Pramnos\Logs\Logger::log('Dashboard widget ' . (is_string($class) ? $class : gettype($class)) . ' is not a ' . Widget::class . '; it was left out.', 'dashboard');
            }
        }

        return array_replace($widgets, self::$registered);
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
                return $widget->allowed($user);
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /** For a test: forget what was registered in code. */
    public static function reset(): void
    {
        self::$registered = [];
    }
}
