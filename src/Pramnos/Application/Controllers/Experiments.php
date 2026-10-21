<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Audience\Experiments as Store;
use Pramnos\Audience\Installed;

/**
 * `/admin/Experiments` — the A/B tests: each with its variants and how they are doing, how sure
 * the difference is and how many more people it needs, and the button that makes a variant the
 * winner.
 *
 * Behind its own ability, `admin.experiments`: a test changes what people see on the site. Every
 * change is audited by the store.
 *
 * Actions:
 *   - display()       — every test, its status and its leading variant
 *   - view($id)       — the results; start, stop, make a winner
 *   - edit($id)       — create (no id) or edit a draft
 *   - save()          — POST
 *   - start($id), stop($id) — POST
 *   - decide($id)     — POST `variant`: everybody gets it from now on
 */
class Experiments extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.experiments';

    /** How many variants the form offers. */
    public const FORM_VARIANTS = 4;

    /** Registers the actions: all behind sign-in, the writes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'view', 'edit', 'save', 'start', 'stop', 'decide']);
        $this->addWriteAction(['save', 'start', 'stop', 'decide']);
        parent::__construct($application);
    }

    /** Every test, with its leading variant. */
    public function display(): mixed
    {
        if ($this->refused()) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = t('A/B tests');
        $rows = [];
        foreach (Store::all() as $test) {
            $results = Store::results($test['id']);
            $leading = null;
            foreach ($results['variants'] as $variant) {
                if ($variant['rate'] !== null && ($leading === null || $variant['rate'] > $leading['rate'])) {
                    $leading = $variant;
                }
            }
            $rows[] = $test + ['people' => array_sum(array_column($results['variants'], 'people')), 'leading' => $leading];
        }
        $view        = $this->getView('experiments');
        $view->tests = $rows;

        return $view->display();
    }

    /** A test's results, and what can be done with it. */
    public function view(mixed $id = null): mixed
    {
        if ($this->refused()) {
            return null;
        }
        $test = Store::get((int) \Pramnos\Http\Request::staticGetOption());
        if ($test === null) {
            return $this->gone();
        }
        \Pramnos\Framework\Factory::getDocument()->title = $test['name'];
        $view          = $this->getView('experiments');
        $view->test    = $test;
        $view->results = Store::results($test['id']);
        $view->segment = $test['segment'] === null ? null : (\Pramnos\Audience\Segments::get($test['segment'])['name'] ?? '#' . $test['segment']);

        return $view->display('view');
    }

    /** The form: name, slug, variants with shares, segment, goal. */
    public function edit(mixed $id = null): mixed
    {
        if ($this->refused()) {
            return null;
        }
        $id   = (int) \Pramnos\Http\Request::staticGetOption();
        $test = $id > 0 ? Store::get($id) : null;
        if ($id > 0 && $test === null) {
            return $this->gone();
        }
        \Pramnos\Framework\Factory::getDocument()->title = $test === null ? t('New A/B test') : t('Edit A/B test');
        $view           = $this->getView('experiments');
        $view->test     = $test;
        $view->segments = Installed::segments() ? \Pramnos\Audience\Segments::all() : [];
        $view->events   = \Pramnos\Audience\PersonEvents::all();

        return $view->display('edit');
    }

    /** Create a test as a draft, or change a draft. */
    public function save(): void
    {
        if ($this->refused()) {
            return;
        }
        $id       = (int) ($_POST['experimentid'] ?? 0);
        $variants = [];
        foreach ((array) ($_POST['variants'] ?? []) as $variant) {
            if (trim((string) ($variant['key'] ?? '')) !== '') {
                $variants[] = ['key' => (string) $variant['key'], 'label' => (string) ($variant['label'] ?? ''), 'share' => (int) ($variant['share'] ?? 0)];
            }
        }
        $segment = (int) ($_POST['segment'] ?? 0) ?: null;
        $goal    = trim((string) ($_POST['goalevent'] ?? ''));
        $days    = (int) ($_POST['goaldays'] ?? 7);
        try {
            if ($id > 0) {
                Store::update($id, ['name' => (string) ($_POST['name'] ?? ''), 'variants' => $variants, 'segment' => $segment,
                    'goalEvent' => $goal, 'goalDays' => $days], $this->me() ?: null);
            } else {
                $id = Store::create((string) ($_POST['slug'] ?? ''), (string) ($_POST['name'] ?? ''), $variants, $goal, $days, $segment, $this->me() ?: null);
            }
        } catch (\InvalidArgumentException $e) {
            $this->addError($e->getMessage());
            $this->redirect(adminUrl('Experiments/edit/') . ($id > 0 ? $id : ''));

            return;
        } catch (\Throwable) {
            // The unique slug: the one way an insert of a checked row is refused.
            $this->addError(t('Another test already has that name for the code.'));
            $this->redirect(adminUrl('Experiments/edit/'));

            return;
        }
        $this->addMessage(t('Saved.'));
        $this->redirect(adminUrl('Experiments/view/') . $id);
    }

    /** Start giving people variants. */
    public function start(mixed $id = null): void
    {
        $this->move(static fn (int $test, ?int $by) => Store::start($test, $by), t('The test has started.'));
    }

    /** Stop giving variants, without a winner. */
    public function stop(mixed $id = null): void
    {
        $this->move(static fn (int $test, ?int $by) => Store::stop($test, $by), t('The test has stopped.'));
    }

    /** Make a variant the winner. */
    public function decide(mixed $id = null): void
    {
        $variant = (string) ($_POST['variant'] ?? '');
        $this->move(static fn (int $test, ?int $by) => Store::decide($test, $variant, $by), t('Everybody gets %s from now on.', $variant));
    }

    /**
     * A change of status, said in the flash.
     *
     * @param callable(int, ?int): void $change
     */
    private function move(callable $change, string $said): void
    {
        if ($this->refused()) {
            return;
        }
        $id = (int) \Pramnos\Http\Request::staticGetOption();
        try {
            $change($id, $this->me() ?: null);
            $this->addMessage($said);
        } catch (\InvalidArgumentException $e) {
            $this->addError($e->getMessage());
        }
        $this->redirect(adminUrl('Experiments/view/') . $id);
    }

    /** Below the floor, or before the tables are installed. */
    protected function refused(): bool
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return true;
        }
        if (!Installed::has('experiments', 'experiment_assignments')) {
            $this->addError(t('A/B tests are not installed here yet: run migrate.'));
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

    /** A test that is not there: back to the list. */
    protected function gone(): mixed
    {
        $this->addError(t('That test no longer exists.'));
        $this->redirect(adminUrl('Experiments'));

        return null;
    }
}
