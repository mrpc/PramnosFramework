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
        $this->addAuthAction(['display', 'card', 'activeusers', 'apistats', 'dbstats', 'database', 'cache', 'cacheitem', 'clearcache', 'savelayout', 'resetlayout',
            'applypreset', 'savepreset', 'deletepreset', 'presetdefault', 'copyboard', 'presets', 'newboard', 'boardsettings', 'deleteboard', 'people']);
        // POST with the session's token, or refused before the action runs: see Controller::exec().
        $this->addWriteAction(['clearcache', 'savelayout', 'resetlayout', 'applypreset', 'savepreset', 'deletepreset', 'presetdefault', 'copyboard', 'newboard', 'boardsettings', 'deleteboard']);
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

        $view = $this->boardView($this->board());

        // The figures as views of the older shape read them — worked out only if one does: the
        // cards of today read their own, and the health checks are not run for nobody.
        $view->activeUsers   = new \Pramnos\Dashboard\Widgets\LazyFigures(static fn (): array => \Pramnos\Dashboard\Widgets\Figures::activeUsers());
        $view->dbStats       = new \Pramnos\Dashboard\Widgets\LazyFigures(static fn (): array => \Pramnos\Dashboard\Widgets\Figures::database());
        $view->apiStats      = new \Pramnos\Dashboard\Widgets\LazyFigures(static fn (): array => \Pramnos\Dashboard\Widgets\Figures::api());
        $view->healthResults = new \Pramnos\Dashboard\Widgets\LazyFigures(static fn (): array => \Pramnos\Dashboard\Widgets\Figures::health());

        return $view->display('admin_dashboard');
    }

    /**
     * A board drawn inside another screen — what {@see \Pramnos\Dashboard\Board::render()} returns:
     * the same view as the dashboard page, without its heading and page frame, its controls
     * talking to this controller with `?board=`.
     *
     * @param string $board A board app.php lists, or this controller's own
     * @param string $title A heading over it; '' for none
     * @throws \InvalidArgumentException For a board app.php does not list: its saves would land elsewhere
     */
    protected function renderBoard(string $board, string $title = ''): string
    {
        if (!$this->isBoard($board)) {
            throw new \InvalidArgumentException('No dashboard board "' . $board . '": add it under \'dashboard\' => [\'boards\' => [...]] in app.php.');
        }
        $user = \Pramnos\User\User::current();
        if ($this->boardRefused($board, is_object($user) ? $user : null)) {
            return '';
        }
        $view             = $this->boardView($board);
        $view->embedded   = true;
        $view->boardTitle = $title;

        return (string) $view->display('admin_dashboard');
    }

    /**
     * A board drawn by a dashboard controller — {@see renderBoard()}, reached from outside without
     * a public instance method, which on a controller would read as an action.
     *
     * @throws \InvalidArgumentException For a board app.php does not list
     */
    public static function boardFor(self $controller, string $board, string $title = ''): string
    {
        return $controller->renderBoard($board, $title);
    }

    /**
     * The dashboard view of one board, filled for the signed-in person: their cards drawn, the
     * catalogue, and the addresses its controls post to.
     */
    protected function boardView(string $board): object
    {
        $view = $this->getView('dashboard');

        // This person's widgets, in their order and widths, each drawn — and the ones they may add.
        $user    = \Pramnos\User\User::current();
        $this->enterReport($board, is_object($user) ? $user : null);
        $layout  = is_object($user) ? $this->layouts()->for($user, $board) : [];
        $widgets = \Pramnos\Dashboard\WidgetRegistry::available(is_object($user) ? $user : null, $board);
        // Built here and assigned once: a View's properties are magic, and appending to one
        // in place is lost.
        $drawn = [];
        foreach ($layout as $item) {
            $drawn[] = \Pramnos\Dashboard\Layouts::isGroup($item) ? $this->groupFor($item, $widgets) : $this->cardFor(\Pramnos\Dashboard\WidgetRegistry::forCard($widgets, $item['id']), $item);
        }
        $view->widgets    = $drawn;
        $placed           = array_column(\Pramnos\Dashboard\Layouts::cards($layout), 'id');
        // A catalogue names a kind of card: the widget unconfigured, since drawing the board left the
        // last card's settings on a repeatable one — a report's section would be offered as its heading.
        $kind             = static fn (\Pramnos\Dashboard\Widget $w): string => (clone $w)->configure([])->title();
        $view->addable    = array_map($kind, array_diff_key($widgets, array_flip($placed)));
        // Every card this person may have, with what it shows and whether it is on the board: a
        // catalogue to search, which shows a card again once it is hidden.
        $catalogue = [];
        foreach ($widgets as $id => $widget) {
            // A widget a board has many cards of stays on offer once placed.
            $catalogue[$id] = ['title' => $kind($widget), 'description' => $widget->description(), 'placed' => !$widget->repeatable() && in_array($id, $placed, true), 'repeatable' => $widget->repeatable()];
        }
        $view->catalogue  = $catalogue;
        $view->board      = $board;
        $view->embedded   = false;
        $view->boardTitle = '';
        $view->saveUrl    = $this->boardUrl('savelayout', $board);
        $view->cardUrl    = $this->boardUrl('card', $board) . (\Pramnos\Metrics\ReportBoard::isReportBoard($board) ? '&at=' . rawurlencode((string) \Pramnos\Metrics\ReportBoard::context()['at']) : '');
        // Whether this person may arrange it: Customise is not offered on a report of somebody else's.
        $view->editable   = is_object($user) && $this->mayArrange($board, $user);
        $view->isReport   = \Pramnos\Metrics\ReportBoard::isReportBoard($board);
        $view->resetUrl   = $this->boardUrl('resetlayout', $board);

        // The presets this person may apply, and what they may do with them.
        $view->presets         = is_object($user) ? $this->presetStore()->visibleTo($user, $board) : [];
        $view->managesPresets  = is_object($user) && $this->managesPresets($user);
        $view->presetUrls      = [
            'apply'   => $this->boardUrl('applypreset', $board),
            'save'    => $this->boardUrl('savepreset', $board),
            'delete'  => $this->boardUrl('deletepreset', $board),
            'default' => $this->boardUrl('presetdefault', $board),
            'copy'    => $this->boardUrl('copyboard', $board),
            'list'    => $this->boardUrl('presets', $this->dashboardArea),
        ];
        $view->roles         = $this->usertypeChoices($board);
        $view->organizations     = $this->organizationChoices();
        $view->organizationLabel = $this->organizationLabel();
        $view->peopleUrl     = $view->managesPresets ? $this->boardUrl('people', $board) : '';
        $view->csrfField     = \Pramnos\Http\Middleware\CsrfMiddleware::tokenField();

        // A person's own board: its name over it, and — for its owner — its settings.
        $view->userBoard = is_object($user) && \Pramnos\Dashboard\UserBoards::isUserBoard($board) ? $this->userBoards()->find($board, $user) : null;
        if ($view->userBoard !== null) {
            $view->boardTitle = (string) $view->userBoard['name'];
        }
        $view->boardUrls = [
            'new'      => $this->boardUrl('newboard', $board),
            'settings' => $this->boardUrl('boardsettings', $board),
            'delete'   => $this->boardUrl('deleteboard', $board),
        ];

        return $view;
    }

    /**
     * The board a request is about: `?board=` when it names one app.php lists, this controller's
     * own otherwise — never a name nobody described, whose layout no screen would draw.
     */
    protected function board(): string
    {
        $asked = (string) ($_GET['board'] ?? $_POST['board'] ?? '');

        return $asked !== '' && $this->isBoard($asked) ? $asked : $this->dashboardArea;
    }

    /** Whether a name is a board: this controller's own, one app.php lists, or a person's own that this person sees. */
    protected function isBoard(string $board): bool
    {
        if ($board === $this->dashboardArea || in_array($board, \Pramnos\Dashboard\WidgetRegistry::boards(), true)) {
            return true;
        }
        $user = \Pramnos\User\User::current();
        // A report's board: a report this person may open.
        if (\Pramnos\Metrics\ReportBoard::isReportBoard($board)) {
            return is_object($user) && (new \Pramnos\Metrics\Reports())->find(\Pramnos\Metrics\ReportBoard::reportId($board), $user) !== null;
        }

        return \Pramnos\Dashboard\UserBoards::isUserBoard($board) && is_object($user) && $this->userBoards()->find($board, $user) !== null;
    }

    /**
     * Whether this person may not use a board: nobody signed in, or below a board's `min_role` in
     * app.php. Anybody signed in otherwise — this controller's own pages keep their own floor,
     * and each card on any board still asks its own allowed().
     */
    protected function boardRefused(string $board, ?object $user): bool
    {
        if ($user === null) {
            return true;
        }
        if ($board === $this->dashboardArea) {
            return false;
        }
        $app  = \Pramnos\Application\Application::currentInstance();
        $role = is_object($app) ? ($app->applicationInfo['dashboard']['boards'][$board]['min_role'] ?? null) : null;

        return is_string($role) && $role !== '' && !\Pramnos\User\Roles::atLeast($user, $role);
    }

    /**
     * Whether this person may arrange a board — move, add, set its cards. Every board they may use,
     * except a report's ({@see \Pramnos\Metrics\ReportBoard}): that one is its owner's alone, and
     * the others copy it.
     */
    protected function mayArrange(string $board, object $user): bool
    {
        if (!\Pramnos\Metrics\ReportBoard::isReportBoard($board)) {
            return true;
        }

        return (new \Pramnos\Metrics\Reports())->owns(\Pramnos\Metrics\ReportBoard::reportId($board), $user);
    }

    /**
     * Before a report's cards are drawn: the report entered for its period (`?at=YYYY-MM-DD`, today
     * by default), so each card reads its grain, comparison and columns. Nothing for another board.
     */
    protected function enterReport(string $board, ?object $user): void
    {
        if (!\Pramnos\Metrics\ReportBoard::isReportBoard($board) || $user === null) {
            return;
        }
        $entry = (new \Pramnos\Metrics\Reports())->find(\Pramnos\Metrics\ReportBoard::reportId($board), $user);
        if ($entry === null) {
            return;
        }
        $at  = (string) ($_GET['at'] ?? '');
        $day = \DateTime::createFromFormat('!Y-m-d', $at);
        \Pramnos\Metrics\ReportBoard::enter($entry, $day !== false && $day->format('Y-m-d') === $at ? $at : date('Y-m-d'), $user, adminUrl('MetricReports/rows'));
    }

    /** One of this controller's actions for a board — with `?board=` unless it is its own. */
    protected function boardUrl(string $action, string $board): string
    {
        // `display` is the controller's own address: the one its menu item names.
        $url = adminUrl($this->dashboardRoute() . ($action === 'display' ? '' : '/' . $action));

        return $board === $this->dashboardArea ? $url : $url . (str_contains($url, '?') ? '&' : '?') . 'board=' . rawurlencode($board);
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
        $board = $this->board();
        if ($this->boardRefused($board, $user) || !$this->mayArrange($board, $user)) {
            return \Pramnos\Http\Response::json(['error' => 'This board is not yours to change.'], 403);
        }
        $body   = json_decode((string) file_get_contents('php://input'), true);
        $layout = is_array($body) && array_key_exists('layout', $body) ? $body['layout'] : json_decode((string) ($_POST['layout'] ?? ''), true);

        return \Pramnos\Http\Response::json(['layout' => $this->layouts()->save($user, is_array($layout) ? $layout : [], $board)]);
    }

    /**
     * Forget the signed-in person's layout, so the default applies again, say so, and go back to
     * it — to the screen a board was drawn in, for one that is not this controller's own.
     */
    public function resetlayout(): void
    {
        $user  = \Pramnos\User\User::current();
        $board = $this->board();
        if (is_object($user) && !$this->boardRefused($board, $user)) {
            $this->layouts()->save($user, null, $board);
            $this->addMessage('The dashboard is back to the default.');
        }
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $site    = defined('sURL') ? (string) \sURL : '';
        $back    = $board !== $this->dashboardArea && $site !== '' && str_starts_with($referer, $site) ? $referer : $this->dashboardHome();
        $this->redirect($back);
    }

    // ── Presets ───────────────────────────────────────────────────────────────

    /**
     * Apply a preset to the signed-in person's board — a copy into their own layout, so later
     * changes are theirs. Cards they may not have are dropped and counted.
     */
    public function applypreset(): void
    {
        $user  = \Pramnos\User\User::current();
        $board = $this->board();
        if (!is_object($user) || $this->boardRefused($board, $user)) {
            $this->redirect($this->backToBoard($board));

            return;
        }
        $preset = $this->presetStore()->find((string) ($_POST['preset'] ?? ''), $user, $board);
        if ($preset === null) {
            $this->addError('That preset is not available.');
        } else {
            $kept    = $this->layouts()->save($user, $preset['layout'], $board);
            $asked   = \Pramnos\Dashboard\Layouts::cards(array_map(static fn ($item) => is_array($item) ? $item : ['id' => (string) $item], $preset['layout']));
            $dropped = count(array_unique(array_filter(array_column($asked, 'id')))) - count(\Pramnos\Dashboard\Layouts::cards($kept));
            $this->addMessage(sprintf(t('The preset "%s" is on your dashboard.'), $preset['name']));
            if ($dropped > 0) {
                $this->addMessage(sprintf(t('%d cards of this preset are not available to you.'), $dropped));
            }
        }
        $this->redirect($this->backToBoard($board));
    }

    /** Save the signed-in person's current board as a preset, shown to whom they chose. */
    public function savepreset(): void
    {
        $user  = \Pramnos\User\User::current();
        $board = $this->board();
        if (is_object($user) && !$this->boardRefused($board, $user)) {
            try {
                $this->presetStore()->save(
                    $user,
                    $board,
                    (string) ($_POST['name'] ?? ''),
                    $this->layouts()->for($user, $board),
                    $visibility = (string) ($_POST['visibility'] ?? \Pramnos\Dashboard\Presets::PRIVATE),
                    // One field per kind of audience, so the form needs no script to swap them.
                    (string) ($_POST['audience'] ?? match ($visibility) {
                        \Pramnos\Dashboard\Presets::ROLE         => $_POST['audience_role'] ?? '',
                        \Pramnos\Dashboard\Presets::ORGANIZATION => $_POST['audience_org'] ?? '',
                        default                                    => '',
                    })
                );
                $this->addMessage('The preset is saved.');
            } catch (\InvalidArgumentException $e) {
                $this->addError($e->getMessage());
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('Dashboard preset not saved: ' . $e->getMessage(), 'dashboard');
                $this->addError('The preset could not be saved.');
            }
        }
        $this->redirect($this->backToBoard($board));
    }

    /** Delete a saved preset: its owner's, or any for somebody who manages presets. */
    public function deletepreset(): void
    {
        $user  = \Pramnos\User\User::current();
        $board = $this->board();
        if (is_object($user)) {
            $deleted = $this->presetStore()->delete((int) ($_POST['preset'] ?? 0), $user, $this->managesPresets($user));
            $deleted ? $this->addMessage('The preset is deleted.') : $this->addError('That preset is not yours to delete.');
        }
        $this->redirect((string) ($_POST['back'] ?? '') === 'list' ? $this->boardUrl('presets', $this->dashboardArea) : $this->backToBoard($board));
    }

    /** Make a saved preset what a new board starts from for a role, or stop (an empty role). */
    public function presetdefault(): void
    {
        $user  = \Pramnos\User\User::current();
        $board = $this->board();
        if (is_object($user) && $this->managesPresets($user)) {
            $role = trim((string) ($_POST['role'] ?? ''));
            try {
                $this->presetStore()->setDefaultForRole((int) ($_POST['preset'] ?? 0), $role !== '' ? $role : null);
                $this->addMessage('The default is set.');
            } catch (\InvalidArgumentException $e) {
                $this->addError($e->getMessage());
            }
        } else {
            $this->addError('Only an administrator sets a default.');
        }
        $this->redirect((string) ($_POST['back'] ?? '') === 'list' ? $this->boardUrl('presets', $this->dashboardArea) : $this->backToBoard($board));
    }

    /**
     * Copy the signed-in administrator's board to another person — setting somebody up. The copy is
     * cleaned for its receiver, and recorded in the audit log.
     */
    public function copyboard(): void
    {
        $user   = \Pramnos\User\User::current();
        $board  = $this->board();
        $target = (int) ($_POST['userid'] ?? 0);
        if (!is_object($user) || !$this->managesPresets($user)) {
            $this->addError('Only an administrator copies a board to somebody else.');
        } else {
            $receiver = $target > 1 && $target !== (int) $user->userid ? $this->account($target) : null;
            // The picker offers only these; a hand-made post is held to the same.
            if ($receiver === null || (int) ($receiver->userid ?? 0) !== $target
                || (int) ($receiver->active ?? 0) !== 1 || (int) ($receiver->usertype ?? 0) < $this->boardFloor($board)) {
                $this->addError('There is no such account that can open this dashboard.');
            } else {
                $kept = $this->layouts()->save($receiver, $this->layouts()->for($user, $board), $board);
                \Pramnos\Auth\AuditLog::record('dashboard.board_copied', (int) $user->userid, $target, ['board' => $board, 'cards' => count($kept)], 'dashboard', $board);
                $this->addMessage(sprintf(t('Your dashboard was copied to %s.'), (string) ($receiver->username ?? $target)));
            }
        }
        $this->redirect($this->backToBoard($board));
    }

    /** The administration's list of every saved preset, with who sees it and whose default it is. */
    public function presets(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $user = \Pramnos\User\User::current();
        if (!is_object($user) || !$this->managesPresets($user)) {
            $this->addError('Only an administrator manages presets.');
            $this->redirect($this->dashboardHome());

            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = t('Dashboard presets');
        $owners = [];
        foreach ($this->presetStore()->all() as $row) {
            $owners[(int) $row['ownerid']] = true;
        }
        $names = [];
        try {
            if ($owners !== []) {
                foreach (\Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')->select(['userid', 'username'])
                    ->whereIn('userid', array_keys($owners))->getAllOrFail() as $row) {
                    $names[(int) $row['userid']] = (string) $row['username'];
                }
            }
        } catch (\Throwable) {
            // Without the names the list still answers, by id.
        }
        $view            = $this->getView('dashboard');
        $view->presets   = $this->presetStore()->all();
        $view->owners    = $names;
        $view->roles     = $this->usertypeChoices($this->dashboardArea);
        $view->csrfField = \Pramnos\Http\Middleware\CsrfMiddleware::tokenField();
        $view->urls      = ['delete' => $this->boardUrl('deletepreset', $this->dashboardArea), 'default' => $this->boardUrl('presetdefault', $this->dashboardArea)];

        return $view->display('presets');
    }

    // ── A person's own boards ─────────────────────────────────────────────────

    /**
     * Make a dashboard of one's own — empty, or from a preset of the board the form was on — and
     * go to it. It is in its owner's menu from now on.
     */
    public function newboard(): void
    {
        $user  = \Pramnos\User\User::current();
        $board = $this->board();
        if (!is_object($user)) {
            $this->redirect($this->dashboardHome());

            return;
        }
        $preset = (string) ($_POST['preset'] ?? '') !== '' ? $this->presetStore()->find((string) $_POST['preset'], $user, $board) : null;
        try {
            $id = $this->userBoards()->create($user, (string) ($_POST['name'] ?? ''), $preset['layout'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $this->addError($e->getMessage());
            $this->redirect($this->backToBoard($board));

            return;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Dashboard board not made: ' . $e->getMessage(), 'dashboard');
            $this->addError('The dashboard could not be made.');
            $this->redirect($this->backToBoard($board));

            return;
        }
        $this->addMessage('Your new dashboard is ready: choose Customise to add its cards.');
        $this->redirect($this->boardUrl('display', \Pramnos\Dashboard\UserBoards::name($id)));
    }

    /** Rename one's own board and say who has it in their menu. */
    public function boardsettings(): void
    {
        $user  = \Pramnos\User\User::current();
        $board = $this->board();
        $row   = is_object($user) && \Pramnos\Dashboard\UserBoards::isUserBoard($board) ? $this->userBoards()->find($board, $user) : null;
        if ($row === null || !$row['mine']) {
            $this->addError('Only its owner changes a dashboard.');
        } else {
            $visibility = (string) ($_POST['visibility'] ?? \Pramnos\Dashboard\Presets::PRIVATE);
            try {
                $this->userBoards()->update((int) $row['boardid'], $user, (string) ($_POST['name'] ?? ''), $visibility, (string) match ($visibility) {
                    \Pramnos\Dashboard\Presets::ROLE         => $_POST['audience_role'] ?? '',
                    \Pramnos\Dashboard\Presets::ORGANIZATION => $_POST['audience_org'] ?? '',
                    default                                    => '',
                });
                $this->addMessage('The dashboard is saved.');
            } catch (\InvalidArgumentException $e) {
                $this->addError($e->getMessage());
            }
        }
        $this->redirect($this->backToBoard($board));
    }

    /** Delete one's own board — or any, for an administrator — and go back to the dashboard. */
    public function deleteboard(): void
    {
        $user  = \Pramnos\User\User::current();
        $board = $this->board();
        $row   = is_object($user) && \Pramnos\Dashboard\UserBoards::isUserBoard($board) ? $this->userBoards()->find($board, $user) : null;
        if ($row !== null && $this->userBoards()->delete((int) $row['boardid'], $user, $this->managesPresets($user))) {
            $this->addMessage('The dashboard is deleted.');
        } else {
            $this->addError('That dashboard is not yours to delete.');
        }
        $this->redirect($this->dashboardHome());
    }

    /** The store of people's own boards. */
    protected function userBoards(): \Pramnos\Dashboard\UserBoards
    {
        return new \Pramnos\Dashboard\UserBoards();
    }

    /** Whether this person manages every preset — sets defaults, copies boards: an administrator. */
    protected function managesPresets(object $user): bool
    {
        try {
            return \Pramnos\User\Roles::atLeast($user, \Pramnos\User\Roles::ADMIN);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The types a preset or a board may be shared with, or a preset made the default for — the
     * application's own usertypes ({@see \Pramnos\User\UserTypes}, `'usertypes'` in app.php), level =>
     * label, from the board's floor up: nobody below it can open the board at all. Lowest first.
     *
     * @return array<int, string>
     */
    protected function usertypeChoices(string $board): array
    {
        $floor   = $this->boardFloor($board);
        $choices = [];
        foreach (\Pramnos\User\UserTypes::labels() as $level => $label) {
            if ((int) $level >= $floor && !in_array((int) $level, \Pramnos\User\UserTypes::EXACT, true)) {
                $choices[(int) $level] = (string) $label;
            }
        }
        ksort($choices);

        return $choices;
    }

    /**
     * The usertype from which a board can be opened: the administration area's floor, or the
     * board's `min_role` where that is higher.
     */
    protected function boardFloor(string $board): int
    {
        $floor = $this->adminFloor();
        $app   = \Pramnos\Application\Application::currentInstance();
        $role  = is_object($app) ? ($app->applicationInfo['dashboard']['boards'][$board]['min_role'] ?? null) : null;
        if (is_string($role) && $role !== '') {
            try {
                $floor = max($floor, \Pramnos\Dashboard\Presets::roleLevel($role));
            } catch (\Throwable) {
                // A role nobody defined raises nothing here; the board refuses on its own.
            }
        }

        return $floor;
    }

    /**
     * The organisations a preset or a board may be shared with, id => name — from the application's
     * {@see \Pramnos\Dashboard\OrganizationSource}; none where it has none, or says `false`, so the
     * choice is not offered.
     *
     * @return array<int|string, string>
     */
    protected function organizationChoices(): array
    {
        try {
            return \Pramnos\Dashboard\Presets::organizations()?->choices() ?? [];
        } catch (\Throwable $e) {
            // Named, class and all, where it is found: a source that fails otherwise just shows no
            // organisation choice, and nobody can tell why.
            \Pramnos\Logs\Logger::log('Dashboard organisation source failed in choices(): ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), 'dashboard');

            return [];
        }
    }

    /** What the application calls an organisation, for the dialog; `Organisation` when its source fails. */
    protected function organizationLabel(): string
    {
        try {
            return \Pramnos\Dashboard\Presets::organizations()?->label() ?? 'Organisation';
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Dashboard organisation source failed: ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), 'dashboard');

            return 'Organisation';
        }
    }

    /**
     * People an administrator may copy a board to, for the picker — `GET …/people?q=ann`: at least
     * two letters of a name, surname, username or email; at most 15; only active accounts that
     * can open the board, never oneself. Each {userid, name, username, email, type}.
     */
    public function people(): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');
        $user = \Pramnos\User\User::current();
        if (!is_object($user) || !$this->managesPresets($user)) {
            return \Pramnos\Http\Response::json(['error' => 'Only an administrator looks people up here.'], 403);
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return \Pramnos\Http\Response::json(['people' => []]);
        }
        $people = [];
        foreach ($this->peopleMatching($q, $this->boardFloor($this->board()), (int) $user->userid) as $row) {
            $name     = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? ''));
            $people[] = [
                'userid'   => (int) $row['userid'],
                'name'     => $name !== '' ? $name : (string) $row['username'],
                'username' => (string) $row['username'],
                'email'    => (string) ($row['email'] ?? ''),
                'type'     => \Pramnos\User\UserTypes::label((int) $row['usertype']),
            ];
        }

        return \Pramnos\Http\Response::json(['people' => $people]);
    }

    /**
     * Active accounts from a usertype up, matching some text, but one — the picker's rows.
     *
     * @return list<array<string, mixed>>
     */
    protected function peopleMatching(string $q, int $floor, int $except): array
    {
        // Bound, not built into the query; a `%` typed only widens the search.
        $like = '%' . $q . '%';
        try {
            return array_values(\Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')
                ->select(['userid', 'username', 'email', 'firstname', 'lastname', 'usertype'])
                ->where('active', 1)->where('usertype', '>=', $floor)->where('userid', '!=', $except)->where('userid', '>', 1)
                ->where(static function ($w) use ($like): void {
                    $w->whereLike('username', $like)->orWhereLike('email', $like)->orWhereLike('firstname', $like)->orWhereLike('lastname', $like);
                })
                ->orderBy('username')->limit(15)->getAllOrFail());
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Dashboard people lookup failed: ' . $e->getMessage(), 'dashboard');

            return [];
        }
    }

    /** An account by id, for copying a board to it; null when there is none. */
    protected function account(int $userId): ?object
    {
        try {
            $user = new \Pramnos\User\User($userId);
        } catch (\Throwable) {
            return null;
        }

        return (int) ($user->userid ?? 0) === $userId ? $user : null;
    }

    /** The store of presets. */
    protected function presetStore(): \Pramnos\Dashboard\Presets
    {
        return new \Pramnos\Dashboard\Presets();
    }

    /** Where to go back to after a board's form: the screen it was drawn in, or the dashboard. */
    protected function backToBoard(string $board): string
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $site    = defined('sURL') ? (string) \sURL : '';
        $back    = $site !== '' && str_starts_with($referer, $site)
            ? (string) strtok($referer, '#')
            : ($board === $this->dashboardArea ? $this->dashboardHome() : $this->boardUrl('display', $board));
        // The dialog's tab the form was on, so the page opens it again on its way back.
        $tab = (string) ($_POST['_tab'] ?? '');

        return preg_match('/^[a-z]{1,16}$/', $tab) === 1 ? $back . '#pf-presets=' . $tab : $back;
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
        $user  = \Pramnos\User\User::current();
        $board = $this->board();
        if ($board === $this->dashboardArea && $this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $id      = (string) ($id ?: ($_GET['id'] ?? ''));
        $widgets = \Pramnos\Dashboard\WidgetRegistry::available(is_object($user) ? $user : null, $board);
        $widget  = \Pramnos\Dashboard\WidgetRegistry::forCard($widgets, $id);
        if (!is_object($user) || $this->boardRefused($board, $user) || $widget === null) {
            return \Pramnos\Http\Response::make('', 404);
        }
        $this->enterReport($board, $user);

        $saved = array_column(\Pramnos\Dashboard\Layouts::cards($this->layouts()->for($user, $board)), null, 'id')[$id]
            ?? \Pramnos\Dashboard\Layouts::clean([['id' => $id]], $user, $board)[0];
        // A tab opened for the first time: its inside only, for the group's frame to hold.
        if (($_GET['part'] ?? '') === 'body') {
            return \Pramnos\Http\Response::make($this->drawWidget($widget->configure((array) $saved['settings'])), 200)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
        }
        $view         = $this->getView('dashboard');
        $view->widget = $this->cardFor($widget, $saved);

        return \Pramnos\Http\Response::make((string) $view->display('card'), 200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * What a view needs to draw one card: its place in the layout, its heading, its settings and
     * the choices for them, and its inside drawn with those settings.
     *
     * `$item['draw'] === false` for a tab not shown yet: its inside is left out, drawn when it is
     * first opened. In the item and not a parameter, so an override with the original signature
     * keeps loading — a parameter added to an overridable method is a fatal in every subclass. An
     * override that took a third `bool $draw` and passes it on is honoured too.
     *
     * @param array{id: string, size: string, settings?: array<string, mixed>, draw?: bool} $item
     * @return array<string, mixed>
     */
    protected function cardFor(\Pramnos\Dashboard\Widget $widget, array $item): array
    {
        $draw     = ($item['draw'] ?? true) !== false && (func_num_args() < 3 || func_get_arg(2) !== false);
        $settings = (array) ($item['settings'] ?? []);
        // Its own settings before anything is read from it: one widget serves every card of its kind.
        $widget->configure($settings);
        $options  = [];
        $fields   = [];
        foreach ($widget->options() as $name => $allowed) {
            $field          = \Pramnos\Dashboard\Widget::field($allowed);
            $options[$name] = $field['clean'] !== null ? null : $field['values'];
            $extra          = $field['extra'];
            // A list's own fields are drawn by the browser from this: their words go translated.
            foreach ((array) ($extra['fields'] ?? []) as $key => $sub) {
                foreach (['label', 'blank'] as $word) {
                    if (is_string($sub[$word] ?? null)) {
                        $extra['fields'][$key][$word] = \Pramnos\Dashboard\Widgets\Figures::words($sub[$word]);
                    }
                }
            }
            $fields[$name]  = ['input' => $field['input'], 'label' => $field['label'] ?? ucfirst(str_replace('_', ' ', (string) $name)),
                'values' => $field['values'], 'when' => $field['when']] + $extra;
        }

        return [
            'id'       => $item['id'],
            'size'     => $item['size'],
            // A report's card is headed by the heading it was given, or by nothing — its kind's name
            // is for the catalogue; a section draws its heading itself, as a line, not a card.
            'title'    => $widget instanceof \Pramnos\Metrics\Widgets\ReportWidget ? ($widget instanceof \Pramnos\Metrics\Widgets\SectionWidget ? '' : $widget->heading()) : $widget->title(),
            'label'    => $widget->title(),
            'plain'    => $widget instanceof \Pramnos\Metrics\Widgets\SectionWidget,
            // "Ask about this": the assistant opened with the card's question, where Ask is on.
            'ask'      => $widget instanceof \Pramnos\Metrics\Widgets\ReportWidget && \Pramnos\Llm\Analyst\Analyst::available()
                && ($question = $widget->askAbout()) !== null ? adminUrl('Ai') . '?q=' . rawurlencode($question) : null,
            'link'     => $widget->link(),
            'settings' => $settings,
            // name => [value => label], or null for a free value — what views of the first shape read
            'options'  => $options,
            // name => {input: select|text|date|number, label, values, when}
            'fields'   => $fields,
            // Null for a tab not shown yet: drawn when it is first opened.
            'html'     => $draw ? $this->drawWidget($widget->configure($settings)) : null,
            // When a shared card's figures were worked out; null for a card drawn now.
            'at'       => $draw && $widget->shared() ? $this->drawnAt : null,
        ];
    }

    /**
     * What a view needs to draw a group of tabs: its place, its heading, and each tab's card —
     * the active one drawn, the others drawn when first opened (`card?id=…&part=body`).
     *
     * @param array{id: string, size: string, tabs: list<array{id: string, settings: array<string, mixed>}>, active: string, title: string} $group
     * @param array<string, \Pramnos\Dashboard\Widget> $widgets
     * @return array<string, mixed>
     */
    protected function groupFor(array $group, array $widgets): array
    {
        $tabs = [];
        foreach ($group['tabs'] as $tab) {
            $card   = $this->cardFor(\Pramnos\Dashboard\WidgetRegistry::forCard($widgets, $tab['id']), ['id' => $tab['id'], 'size' => $group['size'], 'settings' => $tab['settings'], 'draw' => $tab['id'] === $group['active']]);
            $tabs[] = $card;
        }

        return [
            'group'  => true,
            'id'     => $group['id'],
            'size'   => $group['size'],
            'title'  => $group['title'],
            'active' => $group['active'],
            'tabs'   => $tabs,
        ];
    }

    /** When the shared card drawn last had its figures worked out. */
    private ?int $drawnAt = null;

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
        $this->drawnAt = null;
        try {
            $widget->setController($this);
            if ($widget->shared()) {
                // The figures as somebody — or dashboard:precompute — worked them out lately.
                $entry         = \Pramnos\Dashboard\WidgetData::read($widget);
                $this->drawnAt = $entry['at'];

                return $widget->present($entry['data']);
            }

            return $widget->render();
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
