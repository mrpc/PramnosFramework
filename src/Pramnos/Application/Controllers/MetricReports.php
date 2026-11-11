<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Dashboard\Presets;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Report;
use Pramnos\Metrics\ReportBoard;
use Pramnos\Metrics\Reports;
use Pramnos\Metrics\Window;

/**
 * `/admin/MetricReports` — reports built from the application's metrics ({@see Reports}): the
 * application's and the ones people save, share and have mailed.
 *
 * Actions:
 *   - display() — the reports this person may open
 *   - show()    — one report, `?report=<id>&at=YYYY-MM-DD`: its cards ({@see ReportBoard}) — which
 *                 its owner arranges there with Customise — with links to CSV and print
 *   - csv()     — the same as a CSV download
 *   - rows()    — the records behind a cell, where its metric lists them
 *   - edit()    — a new report, one of the person's own (`?report=<id>`), or a copy (`?copy=<id>`)
 *   - save()    — POST: create or update
 *   - delete()  — POST: the owner's, or anybody's for an administrator
 *   - menu()    — POST: show a report in the menu, or not (`Reports::editsMenu()`)
 *   - customise() / uncustomise() — POST: a person's own copy of a report in its place, or the original again
 *
 * Every number is read through the metric's own `allowed()`, so a shared report shows each person
 * only the rows they may see.
 */
class MetricReports extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.reports';

    /** The most records the drill-down lists. */
    private const ROWS = 500;

    /** Registers the actions: all behind sign-in, the writes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'show', 'csv', 'xlsx', 'rows', 'edit', 'save', 'delete', 'menu', 'customise', 'uncustomise']);
        $this->addWriteAction(['save', 'delete', 'menu', 'customise', 'uncustomise']);
        parent::__construct($application);
    }

    /** The reports this person may open. */
    public function display(): mixed
    {
        if ($this->refused()) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = t('Reports');
        $view            = $this->getView('metricreports', 'tpl');
        $view->reports   = $this->store()->visibleTo($this->user());
        $view->me        = (int) $this->user()->userid;
        $view->csrfField = \Pramnos\Http\Middleware\CsrfMiddleware::tokenField();
        $view->urls      = $this->urls();

        return $view->display();
    }

    /** One report, for a period: `?report=<id>&at=YYYY-MM-DD` (today by default). */
    public function show(): mixed
    {
        if ($this->refused() || ($entry = $this->entry((string) ($_GET['report'] ?? ''))) === null) {
            return null;
        }
        $at = self::day((string) ($_GET['at'] ?? ''));
        \Pramnos\Framework\Factory::getDocument()->title = $entry['name'];
        $window          = Window::of($entry['report']->definition['grain'], $at);
        $view            = $this->getView('metricreports', 'tpl');
        $view->entry     = $entry;
        $view->at        = $at;
        $view->period    = $window->label();
        $view->grain     = $window->grain;
        $view->previous  = $window->previous()->key();
        $view->next      = $window->next()->key();
        // The report's cards, as a board: its owner arranges them with Customise.
        $_GET['at']      = $at;
        try {
            $view->body = \Pramnos\Dashboard\Board::render(ReportBoard::board((string) $entry['id']));
        } finally {
            ReportBoard::leave();
        }
        $view->mine      = !$entry['app'] && (int) $entry['row']['ownerid'] === (int) $this->user()->userid;
        // The board's variables: a picker for each dimension the report reads (or the address sets)
        // that has listed values — a search field over a long list. Only those dimensions' values
        // are read: one of thousands is not listed for a report that never uses it.
        $variables       = ReportBoard::variables()['filters'];
        $view->variables = $variables;
        $used    = array_flip([...$entry['report']->dimensions(), ...array_map('strval', array_keys($variables))]);
        $pickers = [];
        // Only what the reader may choose: a dimension they may use, the values of their scope.
        $scope = Metrics::scopeOf($this->user()) ?? [];
        foreach (Metrics::dimensions() as $name => $dimension) {
            if (!isset($used[(string) $name]) || !$dimension->allows($this->user())) {
                continue;
            }
            $values = isset($scope[$name]) ? array_intersect_key($dimension->all(), array_flip($scope[$name])) : $dimension->all();
            if ($values !== [] && (!isset($scope[$name]) || count($values) > 1)) {
                $pickers[(string) $name] = ['label' => t($dimension->label), 'values' => $values, 'search' => count($values) > self::PICKER_LIST];
            }
        }
        // One assignment: an element written into the View's own property is lost (it answers a copy).
        $view->pickers = $pickers;
        // Always in sight: the application's `metrics.pinned_filters`; the rest behind Filters.
        $view->pinned  = array_values(array_intersect(array_map('strval', (array) (Metrics::config()['pinned_filters'] ?? [])), array_keys($pickers)));
        // A report of one's own with no cards yet — a new one — or a copy just made, opens to be arranged.
        $view->customise = $view->mine && (ReportBoard::layout($entry['report']) === [] || !empty($_GET['customise']));
        // The address stays the original's while its customised copy is shown in its place.
        $view->reportId  = (string) ($entry['customises'] ?? $entry['id']);
        $view->original  = $entry['customises'] ?? null;
        $view->inMenu    = $this->store()->shownInMenu($entry);
        // A report the application keeps in its menu itself (`metrics.menu_fixed`) is not offered to be shown or taken off.
        $view->editsMenu = Reports::editsMenu($this->user())
            && !in_array((string) ($entry['customises'] ?? $entry['id']), array_map('strval', (array) (Metrics::config()['menu_fixed'] ?? [])), true);
        $view->csrfField = \Pramnos\Http\Middleware\CsrfMiddleware::tokenField();
        $view->urls      = $this->urls();

        return $view->display('report');
    }

    /** Above this many values a dimension's picker is a search field rather than a list. */
    public const PICKER_LIST = 50;

    /** The report as a CSV download. */
    public function csv(): mixed
    {
        if ($this->refused() || ($entry = $this->entry((string) ($_GET['report'] ?? ''))) === null) {
            return null;
        }
        $at     = self::day((string) ($_GET['at'] ?? ''));
        $period = Window::of($entry['report']->definition['grain'], $at)->label();
        $name   = preg_replace('/[^A-Za-z0-9_-]+/', '-', $entry['name'] . '-' . $period);

        return \Pramnos\Http\Response::make("\xEF\xBB\xBF" . ReportBoard::csv($entry, $at, $this->user()), 200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . trim((string) $name, '-') . '.csv"');
    }

    /** The report as a spreadsheet: what its CSV holds, one sheet. */
    public function xlsx(): mixed
    {
        if ($this->refused() || ($entry = $this->entry((string) ($_GET['report'] ?? ''))) === null) {
            return null;
        }
        $at     = self::day((string) ($_GET['at'] ?? ''));
        $period = Window::of($entry['report']->definition['grain'], $at)->label();
        $name   = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $entry['name'] . '-' . $period), '-');

        return \Pramnos\Http\Response::make(\Pramnos\Export\Xlsx::fromCsv(ReportBoard::csv($entry, $at, $this->user()), $period), 200)
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '.xlsx"');
    }

    /** The records behind a cell: `?metric=&grain=&period=&f[name]=value`. */
    public function rows(): mixed
    {
        if ($this->refused()) {
            return null;
        }
        $name   = (string) ($_GET['metric'] ?? '');
        $metric = Metrics::all($this->user(), false)[$name] ?? null;
        $grain  = (string) ($_GET['grain'] ?? Window::MONTH);
        if ($metric === null || !$metric->hasRows() || !in_array($grain, Window::GRAINS, true)) {
            $this->addError(t('There is no such list.'));
            $this->redirect($this->urls()['list']);

            return null;
        }
        $filters = array_map('strval', array_filter((array) ($_GET['f'] ?? []), 'is_scalar'));
        $period  = Window::of($grain, self::day((string) ($_GET['period'] ?? '')));
        \Pramnos\Framework\Factory::getDocument()->title = $metric->label();
        try {
            $rows = Metrics::records($name, $grain, $period->key(), Metrics::scoped($name, $filters, $this->user(), null, true));
        } catch (\DomainException $e) {
            $this->addError($e->getMessage());
            $this->redirect($this->urls()['list']);

            return null;
        }
        // Come from a report's cell (`report`, `at`): the way back to it.
        $from          = (string) ($_GET['report'] ?? '') !== '' ? (new Reports())->find((string) $_GET['report'], $this->user()) : null;
        $view          = $this->getView('metricreports', 'tpl');
        $view->trail   = $this->trail($from, $metric, self::day((string) ($_GET['at'] ?? '')));
        $view->metric  = $metric;
        $view->period  = $period->label();
        $view->filters = $filters;
        $view->count   = count($rows);
        $view->rows    = array_slice($rows, 0, self::ROWS);
        $view->urls    = $this->urls();

        return $view->display('rows');
    }

    /**
     * The way back from the records behind a number: the reports, the report they came from (at
     * its day), the metric. An application overrides it to put the same in its own breadcrumbs.
     *
     * @param array<string, mixed>|null $report The report the cell was in, if it is known and theirs to open
     * @return list<array{label: string, url: ?string}>
     */
    protected function trail(?array $report, \Pramnos\Metrics\Metric $metric, string $at): array
    {
        $trail = [['label' => t('Reports'), 'url' => $this->urls()['list']]];
        if ($report !== null) {
            $trail[] = ['label' => (string) $report['name'], 'url' => $this->urls()['show'] . '?report=' . rawurlencode((string) ($report['customises'] ?? $report['id'])) . '&at=' . $at];
        }
        $trail[] = ['label' => t($metric->label()), 'url' => null];

        return $trail;
    }

    /** The form: a new report, one of the person's own, or a copy of one they may open. */
    public function edit(): mixed
    {
        if ($this->refused()) {
            return null;
        }
        $entry = null;
        foreach (['report', 'copy'] as $key) {
            if (($_GET[$key] ?? '') !== '' && ($entry = $this->entry((string) $_GET[$key])) === null) {
                return null;
            }
        }
        if (($_GET['report'] ?? '') !== '' && ($entry['app'] || (int) $entry['row']['ownerid'] !== (int) $this->user()->userid)) {
            $this->addError(t('Only its owner changes a report. Copy it to make one of your own.'));
            $this->redirect($this->urls()['list']);

            return null;
        }
        $report   = $entry['report'] ?? Report::fromArray(['name' => '', 'grain' => Window::MONTH, 'columns' => ['periods' => 12], 'sections' => []]);
        $words    = $entry === null ? trim((string) ($_GET['words'] ?? '')) : '';
        $preview  = '';
        $proposed = null;
        // A new report from words: the form filled in, and its numbers below — saved only when saved.
        if ($words !== '') {
            try {
                $report   = \Pramnos\Metrics\ReportProposal::fromWords($words, $this->user());
                $proposed = (string) $report->definition['name'];
                $preview  = Report::html($report->build(date('Y-m-d'), $this->user()));
            } catch (\InvalidArgumentException $e) {
                $this->addError($e->getMessage());
            }
        }
        \Pramnos\Framework\Factory::getDocument()->title = ($_GET['report'] ?? '') !== '' ? t('Edit a report') : t('New report');
        $view             = $this->getView('metricreports', 'tpl');
        $view->id         = ($_GET['report'] ?? '') !== '' ? (int) $entry['id'] : 0;
        $view->definition = $report->definition;
        $view->reportName = ($_GET['copy'] ?? '') !== '' ? sprintf(t('Copy of %s'), (string) $entry['name']) : ($proposed ?? (string) ($entry['name'] ?? ''));
        $view->columns    = $report->columnsAsText();
        // The report as text, for the advanced: only one that has no cards yet.
        $view->sectionsText = isset($report->definition['layout']) ? '' : $report->sectionsAsText();
        $view->words      = $words;
        $view->preview    = $preview;
        $view->copyOf     = ($_GET['copy'] ?? '') !== '' ? (string) $entry['id'] : '';
        $view->row        = ($_GET['report'] ?? '') !== '' ? $entry['row'] : null;
        $view->metrics    = Metrics::all($this->user(), false);
        $view->roles      = \Pramnos\User\UserTypes::labels();
        $view->csrfField  = \Pramnos\Http\Middleware\CsrfMiddleware::tokenField();
        $view->urls       = $this->urls();

        return $view->display('edit');
    }

    /** Create or change a report. */
    public function save(): void
    {
        if ($this->refused()) {
            return;
        }
        $id = (int) ($_POST['reportid'] ?? 0);
        try {
            $definition = [
                'name'     => (string) ($_POST['name'] ?? ''),
                'grain'    => (string) ($_POST['grain'] ?? Window::MONTH),
                'compare'  => (string) ($_POST['compare'] ?? ''),
                'columns'  => Report::columnsFromText((string) ($_POST['columns'] ?? '')),
                'sections' => Report::sectionsFromText((string) ($_POST['sections'] ?? '')),
            ];
            // The cards stay — the report's own, or the copied report's — unless the report was
            // written as text.
            $from = $id > 0 ? (string) $id : (string) ($_POST['copyof'] ?? '');
            if ($definition['sections'] === [] && $from !== '' && ($source = $this->store()->find($from, $this->user())) !== null) {
                $definition['layout'] = ReportBoard::layout($source['report']);
            }
            $id = $this->store()->save(
                $this->user(),
                $definition,
                (string) ($_POST['visibility'] ?? Presets::PRIVATE),
                (string) ($_POST['audience'] ?? ''),
                (string) ($_POST['schedule'] ?? Reports::NEVER),
                (string) ($_POST['recipients'] ?? ''),
                $id > 0 ? $id : null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError($e->getMessage());
            $this->redirect($this->urls()['edit'] . ($id > 0 ? '?report=' . $id : ''));

            return;
        }
        $this->addMessage(t('Saved.'));
        $this->redirect($this->urls()['show'] . '?report=' . $id);
    }

    /** Delete a saved report: the owner's, or anybody's for an administrator. */
    public function delete(): void
    {
        if ($this->refused()) {
            return;
        }
        $admin = \Pramnos\User\Roles::atLeast($this->user(), \Pramnos\User\Roles::ADMIN);
        if ($this->store()->delete((int) ($_POST['reportid'] ?? 0), $this->user(), $admin)) {
            $this->addMessage(t('Deleted.'));
        } else {
            $this->addError(t('There is no such report of yours.'));
        }
        $this->redirect($this->urls()['list']);
    }

    /** Show a report in the menu, or take it off: `report` and `on` in the form. */
    public function menu(): void
    {
        if ($this->refused()) {
            return;
        }
        $id = (string) ($_POST['report'] ?? '');
        $this->store()->setMenu($id, !empty($_POST['on']), $this->user())
            ? $this->addMessage(!empty($_POST['on']) ? t('Shown in the menu.') : t('Taken off the menu.'))
            : $this->addError(t('You may not change the menu.'));
        $this->redirect($this->urls()['show'] . '?report=' . rawurlencode($id));
    }

    /** A report of one's own in place of this one, to arrange: `report` in the form. */
    public function customise(): void
    {
        if ($this->refused()) {
            return;
        }
        $id = (string) ($_POST['report'] ?? '');
        try {
            $this->store()->customise($id, $this->user());
        } catch (\InvalidArgumentException $e) {
            $this->addError(t('There is no such report.'));
            $this->redirect($this->urls()['list']);

            return;
        }
        $this->addMessage(t('A copy of your own to arrange; the original stays as it is.'));
        $this->redirect($this->urls()['show'] . '?report=' . rawurlencode($id) . '&customise=1');
    }

    /** Back to the original report: one's own copy of it deleted. `report` in the form. */
    public function uncustomise(): void
    {
        if ($this->refused()) {
            return;
        }
        $id = (string) ($_POST['report'] ?? '');
        if ($this->store()->uncustomise($id, $this->user())) {
            $this->addMessage(t('Back to the original.'));
        }
        $this->redirect($this->urls()['show'] . '?report=' . rawurlencode($id));
    }

    /** Below the administration floor, or no metrics declared: the request stops here. */
    protected function refused(): bool
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return true;
        }
        if (Metrics::all() === []) {
            $this->addError(t('No metrics are declared here, so there are no reports.'));
            $this->redirect(adminUrl(''));

            return true;
        }

        return false;
    }

    /** One report this person may open, or none, said and sent back to the list. @return array<string, mixed>|null */
    protected function entry(string $id): ?array
    {
        $entry = $id !== '' ? $this->store()->find($id, $this->user()) : null;
        if ($entry === null) {
            $this->addError(t('There is no such report.'));
            $this->redirect($this->urls()['list']);
        }

        return $entry;
    }

    /** Where reports are kept: overridable, for a test or another connection. */
    protected function store(): Reports
    {
        return new Reports();
    }

    /** The person signed in. */
    protected function user(): object
    {
        return \Pramnos\User\User::current() ?? (object) ['userid' => 0, 'usertype' => 0];
    }

    /** @return array<string, string> The screen's addresses */
    protected function urls(): array
    {
        $base = adminUrl('MetricReports');

        return ['list' => $base, 'show' => $base . '/show', 'csv' => $base . '/csv', 'xlsx' => $base . '/xlsx', 'rows' => $base . '/rows', 'edit' => $base . '/edit', 'save' => $base . '/save', 'delete' => $base . '/delete', 'menu' => $base . '/menu', 'customise' => $base . '/customise', 'uncustomise' => $base . '/uncustomise'];
    }

    /** A day as given, or today. */
    private static function day(string $day): string
    {
        $at = \DateTime::createFromFormat('!Y-m-d', $day);
        if ($at !== false && $at->format('Y-m-d') === $day) {
            return $day;
        }
        // The period picker's own: a month (`2026-11`) or an ISO week (`2026-W45`), as its first day.
        if (preg_match('/^(\d{4})-(\d{2})$/', $day, $m) === 1 && checkdate((int) $m[2], 1, (int) $m[1])) {
            return $day . '-01';
        }
        if (preg_match('/^(\d{4})-W(\d{2})$/', $day, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 53) {
            return (new \DateTimeImmutable())->setISODate((int) $m[1], (int) $m[2])->format('Y-m-d');
        }
        if (preg_match('/^\d{4}$/', $day) === 1) {
            return $day . '-01-01';
        }

        return date('Y-m-d');
    }
}
