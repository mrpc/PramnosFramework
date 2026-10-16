<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Application\Statistics\ActiveUsersService;
use Pramnos\Application\Statistics\DatabaseStatsService;
use Pramnos\Application\Statistics\ApiPerformanceService;
use Pramnos\Database\Inspector\DatabaseInspector;
use Pramnos\Database\Inspector\TimescaleInspector;

/**
 * Admin/ops overview dashboard controller.
 *
 * Provides ten actions:
 *   - display      — the signed-in person's dashboard: their widgets, in their order and widths
 *   - savelayout   — JSON: keep the person's layout (POST)
 *   - resetlayout  — forget it, back to the default (POST)
 *   - activeusers  — JSON: authenticated user counts per time window
 *   - apistats     — JSON: API performance summary (error rate, avg/p95/p99 latency)
 *   - dbstats      — JSON: database server metrics (size, connections, cache hit ratio)
 *   - database     — HTML detail page: DB processes, replication, table sizes, TimescaleDB detail
 *   - cache        — HTML detail page: cache adapter info, namespace list, item browser
 *   - cacheitem    — JSON: single cache item content (GET ?key=…)
 *   - clearcache   — JSON: clear all cache entries (POST)
 *
 * All of them require authentication and the administration floor.
 *
 * Note: This controller is distinct from \Pramnos\Auth\Controllers\Account, which
 * handles the end-user account management view. This controller is admin/ops-focused.
 *
 * Thin wrappers in scaffolded apps live at src/Controllers/Dashboard.php.
 *
 */
class DashboardController extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.dashboard';

    /** The usertype floor; 0 is the administration area's — {@see \Pramnos\Auth\AdminAccess::defaultUsertype()}, the `super` role unless set. */
    protected int $requiredUserType = 0;

    /** Whose layouts these are: an application with dashboards in two areas names each its own. */
    protected string $dashboardArea = 'admin';

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'card', 'activeusers', 'apistats', 'dbstats', 'database', 'cache', 'cacheitem', 'clearcache', 'savelayout', 'resetlayout']);
        // POST with the session's token, or refused before the action runs: see Controller::exec().
        $this->addWriteAction(['clearcache', 'savelayout', 'resetlayout']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * HTML overview dashboard combining all data widgets.
     *
     * Aggregates: active-user counts, DB metrics, API performance (last 24h),
     * and health check badges from HealthRegistry::runAll().
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Admin Dashboard';

        $view = $this->getView('dashboard');

        // This person's widgets, in their order and widths, each drawn — and the ones they may add.
        $user    = \Pramnos\User\User::current();
        $layout  = is_object($user) ? $this->layouts()->for($user, $this->dashboardArea) : [];
        $widgets = \Pramnos\Dashboard\WidgetRegistry::available(is_object($user) ? $user : null);
        // Built here and assigned once: a View's properties are magic, and appending to one
        // in place is lost.
        $drawn = [];
        foreach ($layout as $item) {
            $drawn[] = $this->cardFor($widgets[$item['id']], $item);
        }
        $view->widgets    = $drawn;
        $placed           = array_column($layout, 'id');
        $view->addable    = array_map(static fn ($w) => $w->title(), array_diff_key($widgets, array_flip($placed)));
        // Every card this person may have, with what it shows and whether it is on the board: a
        // catalogue to search, which shows a card again once it is hidden.
        $catalogue = [];
        foreach ($widgets as $id => $widget) {
            $catalogue[$id] = ['title' => $widget->title(), 'description' => $widget->description(), 'placed' => in_array($id, $placed, true)];
        }
        $view->catalogue  = $catalogue;
        $view->saveUrl    = adminUrl($this->dashboardRoute() . '/savelayout');
        $view->cardUrl    = adminUrl($this->dashboardRoute() . '/card');
        $view->resetUrl   = adminUrl($this->dashboardRoute() . '/resetlayout');

        // The figures as views of the older shape read them — worked out only if one does: the
        // cards of today read their own, and the health checks are not run for nobody.
        $view->activeUsers   = new \Pramnos\Dashboard\Widgets\LazyFigures(static fn (): array => \Pramnos\Dashboard\Widgets\Figures::activeUsers());
        $view->dbStats       = new \Pramnos\Dashboard\Widgets\LazyFigures(static fn (): array => \Pramnos\Dashboard\Widgets\Figures::database());
        $view->apiStats      = new \Pramnos\Dashboard\Widgets\LazyFigures(static fn (): array => \Pramnos\Dashboard\Widgets\Figures::api());
        $view->healthResults = new \Pramnos\Dashboard\Widgets\LazyFigures(static fn (): array => \Pramnos\Dashboard\Widgets\Figures::health());

        return $view->display('admin_dashboard');
    }

    /**
     * Keep the signed-in person's layout: a POST of `layout`, `[{id, size}, …]` as JSON. Answers
     * the layout as kept — cleaned of what they may not have.
     */
    public function savelayout(): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');
        $user = \Pramnos\User\User::current();
        if (!is_object($user)) {
            return \Pramnos\Http\Response::json(['error' => 'Sign in first.'], 401);
        }
        $body   = json_decode((string) file_get_contents('php://input'), true);
        $layout = is_array($body) && array_key_exists('layout', $body) ? $body['layout'] : json_decode((string) ($_POST['layout'] ?? ''), true);

        return \Pramnos\Http\Response::json(['layout' => $this->layouts()->save($user, is_array($layout) ? $layout : [], $this->dashboardArea)]);
    }

    /** Forget the signed-in person's layout, so the default applies again, say so, and go back to it. */
    public function resetlayout(): void
    {
        $user = \Pramnos\User\User::current();
        if (is_object($user)) {
            $this->layouts()->save($user, null, $this->dashboardArea);
            $this->addMessage('The dashboard is back to the default.');
        }
        $this->redirect($this->dashboardHome());
    }

    /**
     * Where the dashboard is reached — its own address by default; an application whose dashboard
     * is the area's home page answers that.
     */
    protected function dashboardHome(): string
    {
        return adminUrl($this->dashboardRoute());
    }

    /**
     * One card, frame and all, as the dashboard draws it — `GET …/card?id=…`: for a card just added,
     * or redrawn after its settings changed, without reloading the page. Drawn with this person's
     * saved width and settings for it, or the widget's own for a card not yet in their layout; a
     * card they may not have is 404.
     */
    public function card(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $user    = \Pramnos\User\User::current();
        $id      = (string) ($id ?: ($_GET['id'] ?? ''));
        $widgets = \Pramnos\Dashboard\WidgetRegistry::available(is_object($user) ? $user : null);
        if (!is_object($user) || !isset($widgets[$id])) {
            return \Pramnos\Http\Response::make('', 404);
        }

        $saved = array_column($this->layouts()->for($user, $this->dashboardArea), null, 'id')[$id]
            ?? \Pramnos\Dashboard\Layouts::clean([['id' => $id]], $user)[0];
        $view         = $this->getView('dashboard');
        $view->widget = $this->cardFor($widgets[$id], $saved);

        return \Pramnos\Http\Response::make((string) $view->display('card'), 200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * What a view needs to draw one card: its place in the layout, its heading, its settings and
     * the choices for them, and its inside drawn with those settings.
     *
     * @param array{id: string, size: string, settings?: array<string, mixed>} $item
     * @return array<string, mixed>
     */
    protected function cardFor(\Pramnos\Dashboard\Widget $widget, array $item): array
    {
        $settings = (array) ($item['settings'] ?? []);
        $options  = [];
        $fields   = [];
        foreach ($widget->options() as $name => $allowed) {
            $field          = \Pramnos\Dashboard\Widget::field($allowed);
            $options[$name] = $field['clean'] !== null ? null : $field['values'];
            $fields[$name]  = ['input' => $field['input'], 'label' => $field['label'] ?? ucfirst(str_replace('_', ' ', (string) $name)),
                'values' => $field['values'], 'when' => $field['when']];
        }

        return [
            'id'       => $item['id'],
            'size'     => $item['size'],
            'title'    => $widget->title(),
            'link'     => $widget->link(),
            'settings' => $settings,
            // name => [value => label], or null for a free value — what views of the first shape read
            'options'  => $options,
            // name => {input: select|text|date|number, label, values, when}
            'fields'   => $fields,
            'html'     => $this->drawWidget($widget->configure($settings)),
        ];
    }

    /** The store of layouts. */
    protected function layouts(): \Pramnos\Dashboard\Layouts
    {
        return new \Pramnos\Dashboard\Layouts();
    }

    /**
     * One widget's inside. A widget that throws is a short note, logged — not a dashboard that
     * fails to draw because one card could not.
     */
    protected function drawWidget(\Pramnos\Dashboard\Widget $widget): string
    {
        try {
            return $widget->setController($this)->render();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Dashboard widget ' . $widget->id() . ' failed: ' . $e->getMessage(), 'dashboard');

            return '<p class="pf-muted">This card could not be drawn: the log has why.</p>';
        }
    }

    /** The address the dashboard answers under: the controller's own name. */
    protected function dashboardRoute(): string
    {
        return static::class === self::class ? 'Dashboard' : (new \ReflectionClass($this))->getShortName();
    }

    /**
     * JSON endpoint: authenticated user counts per time window.
     * Suitable for AJAX dashboard widget refresh.
     *
     * Response shape:
     *   {"now": int, "last_1h": int, "last_24h": int, "last_7d": int, "last_30d": int}
     */
    public function activeusers(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        // The document type, not only the header: with the default HTML
        // document the request went on to render the theme *after* this
        // action echoed, so the response was the JSON followed by a
        // complete web page — and `fetch(...).then(r => r.json())` throws
        // on that. Every AJAX widget on the dashboard was failing that way.
        \Pramnos\Framework\Factory::getDocument('json');
        header('Content-Type: application/json');
        echo json_encode((new ActiveUsersService())->getCounts());
    }

    /**
     * JSON endpoint: API performance summary.
     * Accepts optional query-string parameter `window` (seconds, default 86400).
     *
     * Response shape: see ApiPerformanceService::getSummary() return type.
     */
    public function apistats(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        $validWindows = [
            ApiPerformanceService::WINDOW_1H,
            ApiPerformanceService::WINDOW_24H,
            ApiPerformanceService::WINDOW_7D,
        ];
        $window = (int) ($_GET['window'] ?? ApiPerformanceService::WINDOW_24H);
        if (!in_array($window, $validWindows, true)) {
            $window = ApiPerformanceService::WINDOW_24H;
        }

        // The document type, not only the header: with the default HTML
        // document the request went on to render the theme *after* this
        // action echoed, so the response was the JSON followed by a
        // complete web page — and `fetch(...).then(r => r.json())` throws
        // on that. Every AJAX widget on the dashboard was failing that way.
        \Pramnos\Framework\Factory::getDocument('json');
        header('Content-Type: application/json');
        echo json_encode((new ApiPerformanceService())->getSummary($window));
    }

    /**
     * JSON endpoint: database server metrics.
     *
     * Response shape: see DatabaseStatsService::getStats() return type.
     */
    public function dbstats(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        // The document type, not only the header: with the default HTML
        // document the request went on to render the theme *after* this
        // action echoed, so the response was the JSON followed by a
        // complete web page — and `fetch(...).then(r => r.json())` throws
        // on that. Every AJAX widget on the dashboard was failing that way.
        \Pramnos\Framework\Factory::getDocument('json');
        header('Content-Type: application/json');
        echo json_encode((new DatabaseStatsService())->getStats());
    }

    /**
     * Detailed database information page.
     *
     * Shows server version, size, connection counts, cache hit ratio, active
     * processes, table sizes, and (for PostgreSQL+TimescaleDB) hypertables,
     * continuous aggregates, and scheduled jobs.
     */
    public function database(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Database Details';

        $db    = \Pramnos\Framework\Factory::getDatabase();
        $stats = (new DatabaseStatsService())->getStats();

        $dbInspector = new DatabaseInspector($db);
        $processes   = $dbInspector->getProcessList();
        $tableSizes  = $dbInspector->getTableSizes();
        $replication = $dbInspector->getReplicationStatus();
        $tsData      = (new TimescaleInspector($db))->getData();

        /*
         * The view definitions are not here any more.
         *
         * A listing of every view's SQL is a schema-shape question, which is a developer's, and
         * this screen is an operator's: how big, how busy, how far behind. It is on
         * `/devpanel` — behind a development-mode lock — beside the index usage and the slow
         * statements, which are the same kind of question. `DatabaseInspector::getPublicViews()`
         * is unchanged; only this screen stopped asking.
         */

        $view              = $this->getView('dashboard');
        $view->stats       = $stats;
        $view->processes   = $processes;
        $view->tableSizes  = $tableSizes;
        $view->tsData      = $tsData;
        $view->replication = $replication;

        /*
         * A link to Adminer for a root account, from the screen that is already about the
         * database.
         *
         * Null for everybody else, and null when the package is not installed, so the view draws
         * nothing rather than an entry that answers 404. This screen answers "how big, how busy,
         * how far behind"; the question after that is always about a row, and that is the tool
         * for it.
         */
        $view->adminerUrl  = class_exists('\Pramnos\DevPanel\DevPanelController')
            ? \Pramnos\DevPanel\DevPanelController::adminerTabUrl()
            : null;

        return $view->display('database');
    }

    /**
     * Detailed cache information page.
     *
     * Shows cache adapter, categories list, and item browser.
     */
    public function cache(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Cache Details';

        $cache      = \Pramnos\Cache\Cache::getInstance();
        $cacheStats = $cache->getStats();
        $categories = $cache->getCategories();

        $items           = [];
        $cacheCategories = [];
        $namespaceStats  = [];
        $cacheItems      = [];

        foreach ($categories as $cat) {
            $catName          = is_array($cat) ? ($cat['name'] ?? $cat[0] ?? '') : (string) $cat;
            $catItems         = $cache->getAllItems($catName, 50);
            $cacheCategories[] = $catName;
            $namespaceStats[$catName] = count($catItems);
            if (!empty($catItems)) {
                $items[$catName] = $catItems;
                foreach ($catItems as $item) {
                    if (is_array($item) && !array_key_exists('namespace', $item)) {
                        $item['namespace'] = $catName;
                    }
                    $cacheItems[] = $item;
                }
            }
        }

        $method              = strtolower($cacheStats['method'] ?? 'none');
        $cacheStatus         = $method !== 'none' && $method !== '';
        $isMemcached         = in_array($method, ['memcached', 'memcache'], true);
        $memcachedLimitation = $isMemcached && empty($cacheItems) && ($cacheStats['items'] ?? 0) > 0;

        $view                             = $this->getView('dashboard');
        $view->cacheStats                 = $cacheStats;
        $view->categories                 = $categories;
        $view->items                      = $items;
        $view->cacheStatus                = $cacheStatus;
        $view->namespaceStats             = $namespaceStats;
        $view->cacheCategories            = $cacheCategories;
        $view->cacheItems                 = $cacheItems;
        $view->memcachedLimitation        = $memcachedLimitation;
        $view->memcachedLimitationMessage = $memcachedLimitation
            ? 'Memcached does not support listing individual cache items. Statistics are available but item details cannot be displayed. Consider using Redis for full cache management.'
            : '';
        return $view->display('cache');
    }

    /**
     * JSON endpoint: single cache item content.
     *
     * GET parameters: `key` (required) — the storage key as {@see cache()} lists
     * it — and `namespace` (optional), the category it was listed under.
     *
     * Response: {success: bool, content: mixed, metadata: {size, type, created, ttl}}
     */
    public function cacheitem(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        // The document type, not only the header: with the default HTML
        // document the request went on to render the theme *after* this
        // action echoed, so the response was the JSON followed by a
        // complete web page — and `fetch(...).then(r => r.json())` throws
        // on that. Every AJAX widget on the dashboard was failing that way.
        \Pramnos\Framework\Factory::getDocument('json');
        header('Content-Type: application/json');

        $key = $_GET['key'] ?? '';
        if ($key === '') {
            echo json_encode(['success' => false, 'error' => 'No key provided']);
            return;
        }

        try {
            /**
             * Read through the adapter, by the key the browser was given.
             *
             * This called `Cache::load($key)`, and the two keys are not the same
             * thing: `getAllItems()` reports the **storage** key, while `load()`
             * builds a storage key out of a *logical* id and the instance's
             * category. So the View button on the cache browser answered "Item
             * not found or expired" for every entry on the page — a screen whose
             * whole purpose is to show what is in the cache, unable to show any
             * of it.
             *
             * The namespace comes from the row the operator clicked. Without it
             * the adapter falls back to splitting the key on '_' to find the
             * entry's directory, which is right only for a single-word category:
             * `schema_columns_users` resolves to a directory called `schema`.
             *
             * Timeout 0 so the read does not apply an expiry of its own. This is
             * a viewer, and an expired entry is exactly what somebody is looking
             * for when they open it — the list marks it as expired and the
             * content is still what is stored.
             */
            $namespace = trim((string) ($_GET['namespace'] ?? ''));
            $cache     = \Pramnos\Cache\Cache::getInstance(
                $namespace !== '' ? $namespace : null
            );
            $adapter   = $cache->getAdapter();
            $content   = $adapter === null ? false : $adapter->load($key, 0);
            if ($content === false || $content === null) {
                echo json_encode(['success' => false, 'error' => 'Item not found or expired']);
                return;
            }

            $raw  = serialize($content);
            $size = strlen($raw);
            if ($size >= 1048576) {
                $sizeStr = round($size / 1048576, 2) . ' MB';
            } elseif ($size >= 1024) {
                $sizeStr = round($size / 1024, 2) . ' KB';
            } else {
                $sizeStr = $size . ' B';
            }

            echo json_encode([
                'success'  => true,
                'content'  => $content,
                'metadata' => [
                    'size'    => $sizeStr,
                    'type'    => gettype($content),
                    'created' => '—',
                    'ttl'     => '—',
                ],
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * JSON endpoint: clear all cache entries.
     * Must be called with POST method.
     *
     * Response: {success: bool}
     */
    public function clearcache(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            // The document type, not only the header: with the default HTML
        // document the request went on to render the theme *after* this
        // action echoed, so the response was the JSON followed by a
        // complete web page — and `fetch(...).then(r => r.json())` throws
        // on that. Every AJAX widget on the dashboard was failing that way.
        \Pramnos\Framework\Factory::getDocument('json');
        header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            return;
        }

        // The document type, not only the header: with the default HTML
        // document the request went on to render the theme *after* this
        // action echoed, so the response was the JSON followed by a
        // complete web page — and `fetch(...).then(r => r.json())` throws
        // on that. Every AJAX widget on the dashboard was failing that way.
        \Pramnos\Framework\Factory::getDocument('json');
        header('Content-Type: application/json');

        try {
            $cache = \Pramnos\Cache\Cache::getInstance();
            $cache->clear();
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

}
