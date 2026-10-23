<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Audience\Installed;
use Pramnos\Audience\Rules\Rules as Store;
use Pramnos\Audience\Rules\Tester;

/**
 * `/admin/Rules` — the rules: the list with how each is doing, a rule's page with its boxes and
 * how many went through each, its versions and log, and the tools to try it — why one person
 * would or would not come in, a simulation, a test send — before it is switched on.
 *
 * Behind its own ability, `admin.rules`, which an administrator is given or not: rules send to
 * people on their own. Every change is audited by the store.
 *
 * Actions:
 *   - display()        — every rule: status, trigger, entered in seven days, under way, goals reached
 *   - view($id)        — the canvas with counts, versions, the log; `?person=`, `?simulate=7|30`, `?diff=1,2`
 *   - edit($id)        — create (no id) or edit, in the editor
 *   - save()           — POST: create, or a new version
 *   - status($id)      — POST `status`: draft, shadow, active, paused
 *   - testsend($id)    — POST `step`, `person`: the step's action to me, with their details
 *   - delete($id)      — POST: refused while it runs
 */
class Rules extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.rules';

    /** How many log rows a rule's page shows. */
    public const LOG_ROWS = 50;

    /** Registers the actions: all behind sign-in, the writes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'view', 'edit', 'save', 'status', 'testsend', 'delete']);
        $this->addWriteAction(['save', 'status', 'testsend', 'delete']);
        parent::__construct($application);
    }

    /** Every rule, with how it is doing. */
    public function display(): mixed
    {
        if ($this->refused()) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = t('Rules');
        $rows = [];
        foreach (Store::all() as $rule) {
            $rows[] = $rule + ['trigger' => \Pramnos\Html\RuleCanvas::trigger($rule['definition']['trigger'])] + Store::figures($rule['id']);
        }
        $view        = $this->getView('rules');
        $view->rules = $rows;

        return $view->display();
    }

    /** A rule: its boxes and counts, versions, log, and the testing tools' answers. */
    public function view(mixed $id = null): mixed
    {
        if ($this->refused()) {
            return null;
        }
        $rule = Store::get((int) \Pramnos\Http\Request::staticGetOption());
        if ($rule === null) {
            return $this->gone();
        }
        \Pramnos\Framework\Factory::getDocument()->title = $rule['name'];
        $get  = static fn (string $key): string => (string) \Pramnos\Http\Request::staticGet($key, '', 'get');
        $view = $this->getView('rules');
        $view->rule     = $rule;
        $view->canvas   = \Pramnos\Html\RuleCanvas::render($rule['definition'], \Pramnos\Html\RuleCanvas::counts($rule['id']));
        $view->versions = Store::versions($rule['id']);
        $view->log      = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('pramnos.rule_log')->where('ruleid', $rule['id'])
            ->orderBy('id', 'desc')->limit(self::LOG_ROWS)->getAllOrFail();
        $view->person   = (int) $get('person');
        $view->why      = $view->person > 0 ? Tester::why($rule['id'], $view->person) : null;
        $view->simulated = in_array($get('simulate'), ['7', '30'], true) ? Tester::simulate($rule['id'], (int) $get('simulate')) + ['days' => (int) $get('simulate')] : null;
        $diff = array_map('intval', explode(',', $get('diff')));
        $view->diff = count($diff) === 2 && $diff[0] > 0 && $diff[1] > 0 ? self::quietly(fn () => Tester::diff($rule['id'], $diff[0], $diff[1]) + ['from' => $diff[0], 'to' => $diff[1]]) : null;
        // The steps a test can be sent of: messages, not changes to data.
        $view->actionSteps = array_keys(array_filter($rule['definition']['steps'], static fn (array $s): bool => $s['type'] === 'action'
            && (\Pramnos\Audience\Rules\Actions::get($s['action'])['channel'] ?? null) !== null));

        return $view->display('view');
    }

    /** The editor: the name and the definition. */
    public function edit(mixed $id = null): mixed
    {
        if ($this->refused()) {
            return null;
        }
        $id   = (int) \Pramnos\Http\Request::staticGetOption();
        $rule = $id > 0 ? Store::get($id) : null;
        if ($id > 0 && $rule === null) {
            return $this->gone();
        }
        \Pramnos\Framework\Factory::getDocument()->title = $rule === null ? t('New rule') : t('Edit rule');
        $view       = $this->getView('rules');
        $view->rule = $rule;

        return $view->display('edit');
    }

    /** Create a rule as a draft, or save a new version. */
    public function save(): void
    {
        if ($this->refused()) {
            return;
        }
        $id   = (int) ($_POST['ruleid'] ?? 0);
        $name = (string) ($_POST['name'] ?? '');
        $definition = \Pramnos\Html\RuleEditor::combine($_POST);
        if ($id > 0 && Store::get($id) === null) {
            $this->gone();

            return;
        }
        try {
            if ($id > 0) {
                $version = Store::update($id, ['name' => $name, 'definition' => $definition], $this->me() ?: null);
                $this->addMessage(t('Saved as version %s.', (string) $version));
            } else {
                $id = Store::create($name, $definition, $this->me() ?: null);
                $this->addMessage(t('Saved as a draft.'));
            }
        } catch (\InvalidArgumentException $e) {
            $this->addError($e->getMessage());
            $this->redirect(adminUrl('Rules/edit/') . ($id > 0 ? $id : ''));

            return;
        }
        $this->redirect(adminUrl('Rules/view/') . $id);
    }

    /** Draft, shadow, active or paused. */
    public function status(mixed $id = null): void
    {
        if ($this->refused()) {
            return;
        }
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        try {
            Store::setStatus($id, (string) ($_POST['status'] ?? ''), $this->me() ?: null);
            $this->addMessage(t('The rule is %s.', t((string) $_POST['status'])));
        } catch (\InvalidArgumentException $e) {
            $this->addError($e->getMessage());
        }
        $this->redirect(adminUrl('Rules/view/') . $id);
    }

    /** A step's action, to the signed-in account, with a person's details. */
    public function testsend(mixed $id = null): void
    {
        if ($this->refused()) {
            return;
        }
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        try {
            $result = Tester::testSend($id, (string) ($_POST['step'] ?? ''), (int) ($_POST['person'] ?? 0), $this->me());
            $status = (string) ($result['status'] ?? '');
            if ($status === 'sent' || $status === 'done') {
                $this->addMessage(t('Test sent to you.'));
            } else {
                $this->addError(t('Not sent: %s', (string) json_encode($result['detail'] ?? [], JSON_UNESCAPED_UNICODE)));
            }
        } catch (\Throwable $e) {
            $this->addError($e->getMessage());
        }
        $this->redirect(adminUrl('Rules/view/') . $id);
    }

    /** Delete a rule that is not running. */
    public function delete(mixed $id = null): void
    {
        if ($this->refused()) {
            return;
        }
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        try {
            Store::delete($id, $this->me() ?: null);
            $this->addMessage(t('Rule deleted.'));
            $this->redirect(adminUrl('Rules'));
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $this->addError($e->getMessage());
            $this->redirect(adminUrl('Rules/view/') . $id);
        }
    }

    /**
     * Whether this request stops here: below the floor, or before the tables are installed.
     */
    protected function refused(): bool
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return true;
        }
        if (!Installed::has('rules', 'rule_versions', 'pramnos.rule_runs', 'pramnos.rule_log')) {
            $this->addError(t('Rules are not installed here yet: run migrate.'));
            $this->redirect(adminUrl(''));

            return true;
        }

        return false;
    }

    /** The signed-in account's id, or 0. */
    protected function me(): int
    {
        $user = \Pramnos\User\User::current();

        return $user ? (int) $user->userid : 0;
    }

    /** A rule that is not there: back to the list. */
    protected function gone(): mixed
    {
        $this->addError(t('That rule no longer exists.'));
        $this->redirect(adminUrl('Rules'));

        return null;
    }

    /**
     * An answer, or null where it cannot be given.
     *
     * @template T
     * @param  callable(): T $answer
     * @return T|null
     */
    private static function quietly(callable $answer): mixed
    {
        try {
            return $answer();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
