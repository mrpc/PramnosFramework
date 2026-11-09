<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Llm\Analyst\Tools\GoalsTool;
use Pramnos\Metrics\Achievements;
use Pramnos\Metrics\GoalJudge;
use Pramnos\Metrics\Goals as Store;
use Pramnos\Metrics\Metrics;

/**
 * `/admin/Goals` — the goals on the application's metrics ({@see Store}) and what was reached
 * ({@see Achievements}).
 *
 * Actions:
 *   - display() — the goals, each with its state, sentence, past periods and streak; the form to
 *                 add one, or to change one (`?goal=<id>`); the latest achievements
 *   - preview() — POST: the form's goal judged without keeping it, and what to try, as JSON
 *   - save()    — POST: add or change
 *   - delete()  — POST: take one away; what it reached stays
 *
 * Only goals on metrics, organisations and dimension values the person may read.
 */
class Goals extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.goals';

    /** The fields the form sends that a goal takes. */
    private const FIELDS = ['metric', 'kind', 'target', 'repeats', 'starts', 'due', 'note'];

    /** Registers the actions: all behind sign-in, the writes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'preview', 'save', 'delete']);
        $this->addWriteAction(['save', 'delete']);
        parent::__construct($application);
    }

    /** The goals, the form, the achievements. */
    public function display(): mixed
    {
        if ($this->refused()) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = t('Goals');
        $user  = $this->user();
        $goals = [];
        foreach (GoalsTool::readable($user) as $goal) {
            try {
                $judged = Store::judge($goal) ?? [];
            } catch (\Throwable $e) {
                $judged = ['state' => 'unknown', 'says' => $e->getMessage()];
            }
            $on = array_map(static fn ($d, $v): string => Metrics::dimensionOf((string) $d)->labelOf((string) $v), array_keys($goal['filters']), $goal['filters']);
            $goals[] = ['goal' => $goal, 'label' => Metrics::get($goal['metric'])->label(), 'on' => implode(', ', $on), 'judged' => $judged];
        }
        $editing = (int) ($_GET['goal'] ?? 0);
        $view               = $this->getView('goals', 'tpl');
        $view->goals        = $goals;
        $view->editing      = array_values(array_filter($goals, static fn (array $g): bool => $g['goal']['id'] === $editing))[0]['goal'] ?? null;
        $view->metrics      = array_map(static fn ($m): array => ['name' => $m->name, 'label' => $m->label(), 'level' => $m->kind === \Pramnos\Metrics\Metric::LEVEL,
            'dims' => self::dimensions($m, $user)], array_values(Metrics::all($user, false)));
        $view->kinds        = ['total' => GoalJudge::KINDS_TOTAL, 'level' => GoalJudge::KINDS_LEVEL];
        $tenants            = Metrics::tenantsOf($user);
        $view->achievements = $tenants === null || count($tenants) === 1 ? Achievements::list($tenants[0] ?? null, 20) : [];
        $view->csrfField    = \Pramnos\Http\Middleware\CsrfMiddleware::tokenField();
        $view->urls         = $this->urls();

        return $view->display();
    }

    /** The form's goal judged, not kept: its sentence, and what to try. */
    public function preview(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        try {
            $goal = $this->posted() + ['tenant' => $this->tenant($this->user())];
            if (!isset($goal['target'])) {
                // No target typed yet: only the last period's figure, and what to try.
                return $this->json(['says' => ''] + Store::preview(['target' => 1] + $goal));
            }

            return $this->json(Store::preview($goal));
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Add a goal, or change one (`goalid`). */
    public function save(): void
    {
        if ($this->refused()) {
            return;
        }
        $user = $this->user();
        $id   = (int) ($_POST['goalid'] ?? 0);
        try {
            $goal = $this->posted();
            if ($id > 0) {
                $this->readable($id);
                unset($goal['metric'], $goal['filters']);   // its metric, organisation and dimension values stay
                Store::update($id, $goal);
            } else {
                if (!Metrics::withinScope((string) ($goal['metric'] ?? ''), $goal['filters'] ?? [], $user)) {
                    throw new \DomainException(t('Those figures are not yours to set a goal on.'));
                }
                Store::create($goal + ['tenant' => $this->tenant($user), 'owner' => (int) $user->userid, 'by' => (int) $user->userid]);
            }
        } catch (\InvalidArgumentException|\DomainException $e) {
            $this->addError($e->getMessage());
            $this->redirect($this->urls()['list'] . ($id > 0 ? '?goal=' . $id : ''));

            return;
        }
        $this->addMessage(t('Saved.'));
        $this->redirect($this->urls()['list']);
    }

    /** Take a goal away. */
    public function delete(): void
    {
        if ($this->refused()) {
            return;
        }
        $id = (int) ($_POST['goalid'] ?? 0);
        try {
            $this->readable($id);
            Store::delete($id);
            $this->addMessage(t('Deleted.'));
        } catch (\DomainException $e) {
            $this->addError($e->getMessage());
        }
        $this->redirect($this->urls()['list']);
    }

    /** Below the administration floor, or no metrics declared: the request stops here. */
    protected function refused(): bool
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return true;
        }
        if (Metrics::all() === []) {
            $this->addError(t('No metrics are declared here, so there are no goals.'));
            $this->redirect(adminUrl(''));

            return true;
        }

        return false;
    }

    /** The person signed in. */
    protected function user(): object
    {
        return \Pramnos\User\User::current() ?? (object) ['userid' => 0, 'usertype' => 0];
    }

    /** @return array<string, string> The screen's addresses */
    protected function urls(): array
    {
        $base = adminUrl('Goals');

        return ['list' => $base, 'preview' => $base . '/preview', 'save' => $base . '/save', 'delete' => $base . '/delete'];
    }

    /** A JSON answer. @param array<string, mixed> $data */
    protected function json(array $data, int $status = 200): \Pramnos\Http\Response
    {
        \Pramnos\Framework\Factory::getDocument('json');

        return \Pramnos\Http\Response::json($data, $status);
    }

    /**
     * The form's goal: a metric the person may read; an empty field left out, so a change keeps it.
     *
     * @return array<string, mixed>
     * @throws \DomainException For a metric that is not theirs
     */
    private function posted(): array
    {
        // `repeats` kept when empty: it says "once", over the period a changed goal had.
        $goal = array_filter(array_intersect_key($_POST, array_flip(self::FIELDS)), static fn ($v, $k): bool => is_string($v) && (trim($v) !== '' || $k === 'repeats'), ARRAY_FILTER_USE_BOTH);
        $goal = array_map('trim', $goal);
        if (isset($goal['metric']) && !isset(Metrics::all($this->user(), false)[$goal['metric']])) {
            throw new \DomainException(t('There is no such metric.'));
        }
        // Dimension values chosen: only the chosen metric's, only values its dimension has.
        $metric = isset($goal['metric']) ? Metrics::get($goal['metric']) : null;
        $filters = [];
        foreach ((array) ($_POST['filters'][$goal['metric'] ?? ''] ?? []) as $dimension => $value) {
            $known = $metric !== null ? (self::dimensions($metric, $this->user())[(string) $dimension]['values'] ?? []) : [];
            if (is_string($value) && $value !== '' && isset($known[$value])) {
                $filters[(string) $dimension] = $value;
            }
        }
        if ($filters !== []) {
            $goal['filters'] = $filters;
        }
        // A recurring goal has no due date, a one-off one no period: the form shows both.
        if (($goal['repeats'] ?? '') !== '') {
            unset($goal['due'], $goal['starts']);
        }

        return $goal;
    }

    /**
     * The dimensions of a metric a goal can be narrowed by: those the person may read, with listed values.
     *
     * @return array<string, array{label: string, values: array<string, string>}>
     */
    private static function dimensions(\Pramnos\Metrics\Metric $metric, object $user): array
    {
        $out = [];
        foreach ($metric->getDimensions() as $name) {
            $dimension = Metrics::dimensionOf($name);
            $values    = $dimension->allows($user) ? $dimension->all() : [];
            if ($values !== []) {
                $out[$name] = ['label' => $dimension->label, 'values' => $values];
            }
        }

        return $out;
    }

    /** @throws \DomainException For a goal that is not there, or not the person's to read */
    private function readable(int $id): void
    {
        if (!in_array($id, array_column(GoalsTool::readable($this->user()), 'id'), true)) {
            throw new \DomainException(t('There is no such goal.'));
        }
    }

    /** The one organisation the person reads, or none. */
    private function tenant(object $user): int
    {
        $tenants = Metrics::tenantsOf($user);

        return $tenants !== null && count($tenants) === 1 ? $tenants[0] : 0;
    }
}
