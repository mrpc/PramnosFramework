<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Metrics\NoCodeMetrics;

/**
 * `/admin/MetricDefinitions` — a metric defined on the screen (BI roadmap phase 7i,
 * {@see NoCodeMetrics}), over a table and columns the application already allowed.
 *
 * Actions:
 *   - display() — every declaration, active or not
 *   - edit()    — a new one, or one of them (`?id=`)
 *   - save()    — POST: checks it, shows its query's planner cost, and asks to confirm before
 *                 writing anything (`confirm=1` on the second submit, the same fields carried as
 *                 hidden inputs) — create or update
 *   - active()  — POST: turns a declaration on or off, without deleting it
 *   - delete()  — POST: removes it for good
 */
class MetricDefinitions extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.reports';

    /** Registers the actions: all behind sign-in, the writes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'edit', 'save', 'active', 'delete']);
        $this->addWriteAction(['save', 'active', 'delete']);
        parent::__construct($application);
    }

    /** Every declaration, active or not. */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = t('Metrics defined on the screen');
        $view            = $this->getView('metricdefinitions', 'tpl');
        $view->rows      = NoCodeMetrics::all();
        $view->csrfField = \Pramnos\Http\Middleware\CsrfMiddleware::tokenField();
        $view->urls      = $this->urls();

        return $view->display('list');
    }

    /** A new declaration, or one of them to change. */
    public function edit(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $id  = (int) ($_GET['id'] ?? 0);
        $row = $id > 0 ? NoCodeMetrics::find($id) : null;
        if ($id > 0 && $row === null) {
            $this->addError(t('There is no such declaration.'));
            $this->redirect($this->urls()['list']);

            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = $id > 0 ? t('Edit a metric') : t('New metric');
        $view             = $this->getView('metricdefinitions', 'tpl');
        $view->id         = $id;
        $view->row        = $row;
        $view->tables     = NoCodeMetrics::allowedTables();
        $view->where      = $row === null ? '' : self::linesFromConditions(json_decode((string) $row['where_json'], true) ?: []);
        $view->dimensions = $row === null ? '' : self::linesFromDimensions(json_decode((string) $row['dimensions_json'], true) ?: []);
        $view->cost       = null;
        $view->csrfField  = \Pramnos\Http\Middleware\CsrfMiddleware::tokenField();
        $view->urls       = $this->urls();

        return $view->display('edit');
    }

    /** Checks the form, shows the planner's cost and asks to confirm, or — confirmed — saves it. */
    public function save(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $id   = (int) ($_POST['id'] ?? 0);
        $data = [
            'name' => (string) ($_POST['name'] ?? ''), 'label' => (string) ($_POST['label'] ?? ''), 'source_table' => (string) ($_POST['source_table'] ?? ''),
            'time_column' => (string) ($_POST['time_column'] ?? ''), 'fn' => (string) ($_POST['fn'] ?? ''), 'value_column' => trim((string) ($_POST['value_column'] ?? '')) ?: null,
            'where' => self::conditionsFromLines((string) ($_POST['where'] ?? '')), 'dimensions' => self::dimensionsFromLines((string) ($_POST['dimensions'] ?? '')),
            'currency' => trim((string) ($_POST['currency'] ?? '')),
        ];
        $checked = NoCodeMetrics::validate($data, $id > 0 ? $id : null);
        if (is_string($checked)) {
            $this->addError($checked);
            $this->redirect($this->urls()['edit'] . ($id > 0 ? '?id=' . $id : ''));

            return null;
        }
        if (($_POST['confirm'] ?? '') !== '1') {
            // Checked, not yet written: show the planner's estimate and ask again.
            \Pramnos\Framework\Factory::getDocument()->title = $id > 0 ? t('Edit a metric') : t('New metric');
            $view             = $this->getView('metricdefinitions', 'tpl');
            $view->id         = $id;
            $view->row        = $checked + ['declaration_id' => $id];
            $view->tables     = NoCodeMetrics::allowedTables();
            $view->where      = (string) ($_POST['where'] ?? '');
            $view->dimensions = (string) ($_POST['dimensions'] ?? '');
            $view->cost       = NoCodeMetrics::cost($checked);
            $view->csrfField  = \Pramnos\Http\Middleware\CsrfMiddleware::tokenField();
            $view->urls       = $this->urls();

            return $view->display('edit');
        }
        $saved = $id > 0 ? NoCodeMetrics::update($id, $data) : NoCodeMetrics::create($data, $this->user());
        if (is_string($saved)) {
            $this->addError($saved);
            $this->redirect($this->urls()['edit'] . ($id > 0 ? '?id=' . $id : ''));

            return null;
        }
        $this->addMessage(t('Saved.'));
        $this->redirect($this->urls()['list']);

        return null;
    }

    /** Turns a declaration on or off, without deleting it: `id` and `on` in the form. */
    public function active(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }
        NoCodeMetrics::setActive((int) ($_POST['id'] ?? 0), filter_var($_POST['on'] ?? '', FILTER_VALIDATE_BOOLEAN));
        $this->addMessage(t('Saved.'));
        $this->redirect($this->urls()['list']);
    }

    /** Removes a declaration for good. */
    public function delete(): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }
        NoCodeMetrics::delete((int) ($_POST['id'] ?? 0));
        $this->addMessage(t('Deleted.'));
        $this->redirect($this->urls()['list']);
    }

    /** The person signed in. */
    private function user(): object
    {
        return \Pramnos\User\User::current() ?? (object) ['userid' => 0, 'usertype' => 0];
    }

    /** @return array<string, string> The screen's addresses */
    private function urls(): array
    {
        $base = adminUrl('MetricDefinitions');

        return ['list' => $base, 'edit' => $base . '/edit', 'save' => $base . '/save', 'active' => $base . '/active', 'delete' => $base . '/delete'];
    }

    /** `status=paid` lines, one a line, into `[{column, value}]`. */
    private static function conditionsFromLines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            if (($line = trim($line)) === '' || !str_contains($line, '=')) {
                continue;
            }
            [$column, $value] = array_map('trim', explode('=', $line, 2));
            $out[] = ['column' => $column, 'value' => str_contains($value, ',') ? array_map('trim', explode(',', $value)) : $value];
        }

        return $out;
    }

    /** `[{column, value}]` back into `status=paid` lines. */
    private static function linesFromConditions(array $conditions): string
    {
        return implode("\n", array_map(static fn (array $c): string => $c['column'] . '=' . (is_array($c['value']) ? implode(',', $c['value']) : (string) $c['value']), $conditions));
    }

    /** `region=region_column` lines, one a line, into `[{name, column}]`. */
    private static function dimensionsFromLines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            if (($line = trim($line)) === '' || !str_contains($line, '=')) {
                continue;
            }
            [$name, $column] = array_map('trim', explode('=', $line, 2));
            $out[] = ['name' => $name, 'column' => $column];
        }

        return $out;
    }

    /** `[{name, column}]` back into `region=region_column` lines. */
    private static function linesFromDimensions(array $dimensions): string
    {
        return implode("\n", array_map(static fn (array $d): string => $d['name'] . '=' . $d['column'], $dimensions));
    }
}
