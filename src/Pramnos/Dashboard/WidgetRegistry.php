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
 *     // or keep them in the catalogue but off a new dashboard:
 *     // 'framework_default' => false,
 *     // a board of its own, for a screen: its cards, and the ones a new board has, in order
 *     'boards' => ['wcm' => ['widgets' => [WcmMap::class], 'framework' => false, 'default' => ['wcm.map']]],
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
        Widgets\PinnedArtifacts::class,
        Widgets\HealthChecks::class,
        Widgets\RecentErrors::class,
        Widgets\WebVitals::class,
        Widgets\HelpNews::class,
        Widgets\SessionsOnline::class,
    ];

    /** The cards of a report ({@see \Pramnos\Metrics\ReportBoard}), in the order its catalogue lists them. */
    public const REPORT = [
        \Pramnos\Metrics\Widgets\SectionWidget::class,
        \Pramnos\Metrics\Widgets\TableWidget::class,
        \Pramnos\Metrics\Widgets\ChartWidget::class,
        \Pramnos\Metrics\Widgets\KpiWidget::class,
        \Pramnos\Metrics\Widgets\TopWidget::class,
        \Pramnos\Metrics\Widgets\GoalWidget::class,
        \Pramnos\Metrics\Widgets\CohortWidget::class,
        \Pramnos\Metrics\Widgets\MapWidget::class,
        \Pramnos\Metrics\Widgets\AnalysisWidget::class,
        \Pramnos\Metrics\Widgets\TextWidget::class,
        \Pramnos\Metrics\Widgets\RecordsWidget::class,
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
     * For a board ({@see board()}), its own `framework` and `widgets` replace the top-level ones
     * where it names them, and a widget whose {@see Widget::boards()} leaves the board out is not
     * offered on it. A board app.php does not describe — `admin`, unless it is listed — is the
     * top level, exactly as before boards existed.
     *
     * @return array<string, Widget>
     */
    public static function all(?string $board = null): array
    {
        $board  = self::catalogueOf($board);
        if ($board === \Pramnos\Metrics\ReportBoard::CATALOGUE) {
            return self::forReports();
        }
        $config = self::config($board);

        $widgets = self::framework($config['framework'] ?? true);
        foreach ((array) ($config['widgets'] ?? []) as $class) {
            if (is_string($class) && is_subclass_of($class, Widget::class)) {
                $widget = new $class();
                $widgets[$widget->id()] = $widget;
            } else {
                \Pramnos\Logs\Logger::log('Dashboard widget ' . (is_string($class) ? $class : gettype($class)) . ' is not a ' . Widget::class . '; it was left out.', 'dashboard');
            }
        }
        // A card for each metric the application declares, unless app.php says `'metrics' => false`.
        if (($config['metrics'] ?? true) !== false) {
            foreach (\Pramnos\Metrics\Metrics::all() as $metric) {
                $card = new Widgets\MetricCard($metric);
                $widgets[$card->id()] ??= $card;
            }
            // And a goal card, with metrics to set goals on.
            if (\Pramnos\Metrics\Metrics::all() !== []) {
                $widgets['metric.goal'] ??= new \Pramnos\Metrics\Widgets\DashboardGoalWidget();
            }
        }
        // The to-do card, where the list is installed.
        if (($config['todos'] ?? true) !== false && \Pramnos\Todos\Todos::offers('dashboard')) {
            $widgets['todos'] ??= new \Pramnos\Todos\TodosWidget();
        }
        $widgets = array_replace($widgets, self::$registered);
        if ($board === null) {
            return $widgets;
        }

        return array_filter($widgets, static function (Widget $widget) use ($board): bool {
            $boards = $widget->boards();

            return $boards === null || in_array($board, $boards, true);
        });
    }

    /**
     * What app.php says for a board: `'dashboard' => ['boards' => ['wcm' => [...]]]`, its keys over
     * the top level's — so a board names only what differs. The top level for a board it does
     * not list, and for none.
     *
     * Keys a board may carry: `widgets`, `framework`, `framework_default`, `cards` (merged by id
     * over the top level's), `default` (the ids a new board has, in order) and `presets`.
     *
     * @return array<string, mixed>
     */
    public static function config(?string $board = null): array
    {
        $board = self::catalogueOf($board);
        $app   = \Pramnos\Application\Application::currentInstance();
        $top = is_object($app) ? (array) ($app->applicationInfo['dashboard'] ?? []) : [];
        $own = $board !== null && is_array($top['boards'][$board] ?? null) ? $top['boards'][$board] : null;
        if ($own === null) {
            return $top;
        }
        $merged          = array_replace($top, $own);
        $merged['cards'] = array_replace((array) ($top['cards'] ?? []), (array) ($own['cards'] ?? []));

        return $merged;
    }

    /**
     * The board whose catalogue a board offers: a person's own board ({@see UserBoards}) offers the
     * administration dashboard's, a report's board ({@see \Pramnos\Metrics\ReportBoard}) the
     * reports' cards; any other, its own.
     */
    private static function catalogueOf(?string $board): ?string
    {
        if (\Pramnos\Metrics\ReportBoard::isReportBoard($board)) {
            return \Pramnos\Metrics\ReportBoard::CATALOGUE;
        }

        return UserBoards::isUserBoard($board) ? UserBoards::CATALOGUE : $board;
    }

    /**
     * A report's cards: the framework's ({@see REPORT}), and an application's — listed in app.php
     * or registered in code — whose `boards()` names `report`.
     *
     * @return array<string, Widget>
     */
    private static function forReports(): array
    {
        $widgets = [];
        $app     = \Pramnos\Application\Application::currentInstance();
        $listed  = is_object($app) ? (array) ($app->applicationInfo['dashboard']['widgets'] ?? []) : [];
        foreach (array_merge(self::REPORT, $listed) as $class) {
            if (is_string($class) && is_subclass_of($class, Widget::class)) {
                $widget = new $class();
                $widgets[$widget->id()] = $widget;
            }
        }

        return array_filter(array_replace($widgets, self::$registered), static fn (Widget $widget): bool => in_array(\Pramnos\Metrics\ReportBoard::CATALOGUE, $widget->boards() ?? [], true));
    }

    /**
     * The boards app.php describes, by name — what `?board=` may name besides a controller's own.
     *
     * @return list<string>
     */
    public static function boards(): array
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $boards = is_object($app) ? ($app->applicationInfo['dashboard']['boards'] ?? []) : [];

        return is_array($boards) ? array_values(array_map('strval', array_keys($boards))) : [];
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

    /**
     * The widget a card of a layout draws, from widgets by id: the one under the card's id, or —
     * for `<id>~<key>`, a key of up to 16 letters or digits — the {@see Widget::repeatable()} one
     * under `<id>`. Null for neither.
     *
     * @param array<string, Widget> $widgets
     */
    public static function forCard(array $widgets, string $cardId): ?Widget
    {
        if (isset($widgets[$cardId])) {
            return $widgets[$cardId];
        }
        $base = Widget::baseId($cardId);
        if ($base === $cardId || !isset($widgets[$base]) || !$widgets[$base]->repeatable()
            || preg_match('/^[a-z0-9]{1,16}$/', substr($cardId, strlen($base) + 1)) !== 1) {
            return null;
        }

        return $widgets[$base];
    }

    /** One widget by id, or null. */
    public static function get(string $id, ?string $board = null): ?Widget
    {
        return self::all($board)[$id] ?? null;
    }

    /**
     * The widgets this person may have, on a board or at the top level.
     *
     * @return array<string, Widget>
     */
    public static function available(?object $user, ?string $board = null): array
    {
        return array_filter(self::all($board), static function (Widget $widget) use ($user, $board): bool {
            try {
                $role = self::card($widget->id(), $board)['min_role'] ?? null;

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
     *
     * `'dashboard' => ['framework_default' => false]` keeps the framework's cards in the
     * catalogue and off a new dashboard, all of them — including one a later framework version
     * adds, which a list of ids under `cards` would silently miss. A card the application names
     * with `'default' => true` is still placed, and the application's own cards are untouched.
     */
    public static function byDefault(Widget $widget, ?string $board = null): bool
    {
        // A board that lists its defaults decides alone: those, and nothing else.
        $list = self::defaultIds($board);
        if ($list !== null) {
            return in_array($widget->id(), $list, true);
        }

        $default = self::card($widget->id(), $board)['default'] ?? null;
        if (is_bool($default)) {
            return $default;
        }

        if ((self::config($board)['framework_default'] ?? true) === false && in_array($widget::class, self::FRAMEWORK, true)) {
            return false;
        }

        return $widget->byDefault();
    }

    /**
     * The ids a new board has, in its order — a board's `default` list, its groups' tabs among
     * them — or null when it gives none and each card's own default applies.
     *
     * @return list<string>|null
     */
    public static function defaultIds(?string $board): ?array
    {
        $entries = self::defaultEntries($board);
        if ($entries === null) {
            return null;
        }

        return array_column(Layouts::cards(array_map(static fn ($entry) => is_array($entry) ? $entry : ['id' => (string) $entry], $entries)), 'id');
    }

    /**
     * A board's `default` list as app.php writes it: ids, and groups of tabs —
     * `['wcm.stats', ['tabs' => ['wcm.map', 'wcm.status'], 'title' => 'Areas']]`. Null without one.
     *
     * @return list<string|array<string, mixed>>|null
     */
    public static function defaultEntries(?string $board): ?array
    {
        $board = self::catalogueOf($board);
        if ($board === null) {
            return null;
        }
        $app  = \Pramnos\Application\Application::currentInstance();
        $list = is_object($app) ? ($app->applicationInfo['dashboard']['boards'][$board]['default'] ?? null) : null;

        return is_array($list) ? array_values(array_filter($list, static fn ($entry): bool => is_string($entry) || is_array($entry))) : null;
    }

    /**
     * What app.php says about one card, by id: `default` (on a new dashboard or not) and
     * `min_role` (a role from which it is offered, on top of its own rule) — for a card the
     * application did not write, which it cannot change otherwise.
     *
     * @return array<string, mixed>
     */
    private static function card(string $id, ?string $board = null): array
    {
        $cards = self::config($board)['cards'] ?? [];

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
