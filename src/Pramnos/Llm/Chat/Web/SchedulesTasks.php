<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;

/**
 * The scheduled page — a person's tasks, the reports shared with them, a task's runs, a form to
 * schedule — and its POST actions, for a controller to serve where it likes.
 *
 * {@see ChatPageController} serves it at `chat/scheduled` for Ask AI, and
 * {@see \Pramnos\Application\Controllers\AiController} at `Ai/scheduled` for the analyst. A
 * controller using it answers where its people are and where its addresses go:
 *
 * | hook | |
 * |---|---|
 * | `taskScope()` | whose tasks, or null for nobody signed in |
 * | `taskConversations()` | the conversations they are made over, as that person |
 * | `scheduledUrl($action)` | the page (`''`), or one of its actions (`schedule`, …) |
 * | `taskReportUrl($runId)` | where a report's run opens as a conversation |
 * | `taskChatUrl()` | the conversations |
 * | `taskSignInUrl()` | where somebody not signed in is sent |
 * | `taskDenied()` | true when this person may not reach the page — it has answered already; false by default |
 * | `scheduledExtras($task)` | markup of its own: `form`, fields inside the form; `task`, on the open task's card |
 * | `afterScheduled($task, $input)` | what it does with a task just made, and the form's whole input |
 * | `afterAudienceChanged($task, $before)` | what it does when a report's readers were changed from its card |
 * | `audienceRoles()` | the roles readers are chosen by, `role => usertype` — static, so an override is read |
 *
 * Its actions are `scheduled`, `schedule`, `scheduledupdate`, `scheduleddelete`, `scheduledrun`
 * and `scheduledsubscribe`; the controller declares them as auth actions, and all but the first
 * as write actions.
 */
trait SchedulesTasks
{
    /**
     * `chat/scheduled` — this person's scheduled tasks (questions asked again, reminders,
     * reports), the reports shared with them to receive, a form to schedule one, and with
     * `?task=` one task's runs, each opened in Ask from here.
     */
    public function scheduled(): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope = $this->taskScope();
        if ($scope === null) {
            $this->redirect($this->taskSignInUrl());

            return null;
        }

        $tasks  = $this->taskService();
        $policy = $tasks->policy();
        $open   = (int) ($_GET['task'] ?? 0);
        $task   = $open > 0 ? ($tasks->find($scope, $open) ?? $tasks->byId($open)) : null;
        if ($task !== null && !$policy->canSee($scope->userId, $task)) {
            $task = null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Scheduled';
        $this->scheduledCrumbs($task);

        $view            = $this->getView('chat');
        $view->tasks     = $tasks->mine($scope);
        $view->reports   = $tasks->reports($scope);
        $view->task      = $task;
        $view->runs      = $task === null ? [] : $tasks->runs((int) $task['routine_id'], 20);
        $view->templates = \Pramnos\Llm\Chat\Routines::templates();
        $view->cadences  = \Pramnos\Llm\Chat\Routines::CADENCES;
        $view->kinds     = \Pramnos\Llm\Chat\Routines::KINDS;
        $view->owners    = array_values(array_filter(\Pramnos\Llm\Chat\Routines::OWNERS, static fn (string $owner): bool => $policy->canManage($scope, $owner)));
        $view->timezone  = $this->taskConversations()->assistant()->timezone($scope);
        $view->pageUrl   = $this->scheduledUrl();
        $view->chatUrl   = $this->taskChatUrl();
        // What the open task cost is the framework's own; an application's `task` markup replaces it.
        $view->extras    = $this->scheduledExtras($task) + ['form' => '', 'task' => $task === null ? '' : \Pramnos\Llm\Chat\TaskCosts::forTask($task, $view->runs)];
        // Who may receive a shared report: the roles to choose from, and the open one's readers by name.
        // Only roles that may open the space's screen: a report is not offered to those it is closed to.
        $view->audienceRoles = array_filter(static::audienceRoles(), fn (int $level): bool => $level >= $this->audienceFloor());
        $view->audienceUsers = is_array($task['audience'] ?? null) ? $policy->audienceNames($task['audience']['users']) : [];
        $view->mayEdit       = $task !== null && (int) $task['userid'] === $scope->userId;
        // Where the readers' field asks who matches what is typed: only where somebody is offered.
        $view->peopleUrl     = $scope->space === \Pramnos\Llm\Chat\Scope::ADMIN ? $this->scheduledUrl('people') : '';
        $view->urls      = [
            'page'   => $this->scheduledUrl(),
            'action' => $this->scheduledUrl('%s'),
            'report' => $this->taskReportUrl(0),
            'chat'   => $this->taskChatUrl(),
        ];

        return $view->display('scheduled');
    }

    /**
     * The scheduled page's breadcrumbs: the conversations, Scheduled, and the open task's title.
     * An application with breadcrumbs of its own overrides it, and may add none.
     *
     * @param array<string, mixed>|null $task The open task, or null
     */
    protected function scheduledCrumbs(?array $task): void
    {
        $app = $this->application ?? \Pramnos\Application\Application::currentInstance();
        if (!$app instanceof \Pramnos\Application\Application) {
            return;
        }
        $app->addbreadcrumb($this->taskChatName(), $this->taskChatUrl());
        $app->addbreadcrumb(t('Scheduled'), $task === null ? '' : $this->scheduledUrl());
        if ($task !== null) {
            $app->addbreadcrumb((string) ($task['title'] ?? ''), '');
        }
    }

    /** What the conversations are called in the breadcrumbs: "Ask AI", or the analyst's name. */
    protected function taskChatName(): string
    {
        return t('Ask AI');
    }

    /** Schedule a task from the form: kind, title, prompt, cadence and its fields, owner, notify. */
    public function schedule(): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope = $this->taskScope();
        $input = array_intersect_key($_POST, array_flip(['kind', 'template', 'title', 'prompt', 'cadence', 'at', 'day', 'nth', 'run_at', 'owner', 'notify', 'audience_role', 'audience_users']));
        // The form asks the day twice — a weekday, a day of the month — and sends the one the cadence reads.
        if (!isset($input['day']) && ($_POST['weekday'] ?? $_POST['monthday'] ?? null) !== null) {
            $input['day'] = ($input['cadence'] ?? '') === 'monthly' ? ($_POST['monthday'] ?? null) : ($_POST['weekday'] ?? null);
        }
        // A field left empty is not an answer: a template fills it.
        $input = array_filter($input, static fn ($value): bool => $value !== '' && $value !== null);
        $made  = $scope === null ? 'This needs somebody signed in.'
            : $this->taskService()->create($scope, $input + ['timezone' => $this->taskConversations()->assistant()->timezone($scope)]);
        if (is_string($made)) {
            $this->addError($made);
        } else {
            $this->addMessage(t('Scheduled. Next: %s.', (string) $made['next_run_local']));
            // "Email the report to me too", ticked on the form: its maker is subscribed by email.
            if (!empty($_POST['email']) && ($made['kind'] ?? '') === Routines::REPORT) {
                $this->taskService()->subscribe($scope->userId, (int) $made['routine_id'], true);
            }
            $this->afterScheduled($made, $_POST);
        }
        $this->redirect($this->scheduledUrl());

        return null;
    }

    /**
     * Pause or resume one of this person's tasks — `active` in the form; or, with `audience` in
     * the form, choose who may receive a shared report: `audience_role` and `audience_users`.
     */
    public function scheduledupdate(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope    = $this->taskScope();
        $id       = (int) ($id ?: ($_POST['routine_id'] ?? 0));
        $audience = isset($_POST['audience']);
        $changes  = $audience
            ? ['audience_role' => (string) ($_POST['audience_role'] ?? ''), 'audience_users' => (string) ($_POST['audience_users'] ?? '')]
            : ['active' => !empty($_POST['active'])];
        $before  = $audience && $scope !== null ? $this->taskService()->find($scope, $id) : null;
        $changed = $scope === null ? 'This needs somebody signed in.' : $this->taskService()->update($scope, $id, $changes);
        if (is_string($changed)) {
            $this->addError($changed);
        } else {
            $this->addMessage($audience ? 'Its readers are saved.' : ($changed['active'] ? 'Resumed.' : 'Paused.'));
            if ($audience && $before !== null) {
                $this->afterAudienceChanged($changed, $before);
            }
        }
        $this->redirect($this->scheduledUrl() . ($audience ? '?task=' . $id : ''));

        return null;
    }

    /** Delete one of this person's tasks. Its conversation and its runs' record stay as they are. */
    public function scheduleddelete(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope = $this->taskScope();
        $scope !== null && $this->taskService()->delete($scope, (int) ($id ?: ($_POST['routine_id'] ?? 0)))
            ? $this->addMessage('Deleted.')
            : $this->addError('There is no such task.');
        $this->redirect($this->scheduledUrl());

        return null;
    }

    /** Run one of this person's tasks now, whatever its schedule, and show its runs. */
    public function scheduledrun(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope = $this->taskScope();
        $id    = (int) ($id ?: ($_POST['routine_id'] ?? 0));
        $found = $scope === null ? null : $this->taskService()->find($scope, $id);
        if ($found === null) {
            $this->addError('There is no such task.');
            $this->redirect($this->scheduledUrl());

            return null;
        }
        $ran = $this->taskService()->runNow($found, time());
        if (!$ran['ok']) {
            $this->addError((string) $ran['says']);
        } else {
            $this->addMessage($ran['queued'] ? 'It is running: its result will be under its runs, and the bell will tell you when it is ready.' : 'It ran.');
        }
        $this->redirect($this->scheduledUrl() . '?task=' . $id);

        return null;
    }

    /** Receive a shared report, or stop — `on` in the form; `email` to receive the report itself by email too. */
    public function scheduledsubscribe(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope = $this->taskScope();
        $id    = (int) ($id ?: ($_POST['routine_id'] ?? 0));
        if ($scope === null) {
            $this->addError('This needs somebody signed in.');
        } elseif (!empty($_POST['on'])) {
            ($why = $this->taskService()->subscribe($scope->userId, $id, !empty($_POST['email']))) === null
                ? $this->addMessage(!empty($_POST['email']) ? 'You will receive it, by email too.' : 'You will receive it.') : $this->addError($why);
        } else {
            $this->taskService()->unsubscribe($scope->userId, $id) ? $this->addMessage('You will no longer receive it.') : $this->addError('You do not receive it.');
        }
        $this->redirect($this->scheduledUrl());

        return null;
    }

    /**
     * The roles a report's readers may be chosen by, lowest first: `role => usertype`, as app.php
     * sets them.
     *
     * @return array<string, int>
     */
    protected static function audienceRoles(): array
    {
        $roles = [];
        foreach (array_keys(\Pramnos\User\Roles::DEFAULTS) as $role) {
            $roles[$role] = \Pramnos\User\Roles::level($role);
        }
        asort($roles);

        return $roles;
    }

    /** The lowest role a shared report may be offered to: nobody's, by default; the analyst's screen, its floor. */
    protected function audienceFloor(): int
    {
        return 0;
    }

    /** The scheduled tasks, over this person's conversations. */
    protected function taskService(): Routines
    {
        return new Routines($this->taskConversations());
    }

    /**
     * Whether the request stops here — the controller has already answered it, with its sign-in
     * or a refusal. Every action asks first. Nobody is stopped by default; a controller behind a
     * floor answers with its usertype check, as `AiController` does.
     */
    protected function taskDenied(): bool
    {
        return false;
    }

    /**
     * Markup of the controller's own for the page: `form`, printed inside the form before its
     * button — fields its {@see afterScheduled()} reads; `task`, printed on the open task's card in
     * place of the framework's cost summary ({@see \Pramnos\Llm\Chat\TaskCosts::forTask()}).
     * Printed as given: escape what goes in. Nothing by default.
     *
     * @param array<string, mixed>|null $task The task whose card is open, or null
     * @return array{form?: string, task?: string}
     */
    protected function scheduledExtras(?array $task): array
    {
        return [];
    }

    /**
     * A task was just made from the form: `$task` as {@see Routines::create()} answered, `$input`
     * the whole form, the controller's own fields among it. Called before the redirect. Nothing
     * by default.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $input
     */
    protected function afterScheduled(array $task, array $input): void
    {
    }

    /**
     * A shared report's readers were just changed from its card: `$task` as it is now, `$before`
     * as it was — to subscribe the people newly named, say. Called before the redirect. Nothing
     * by default.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $before
     */
    protected function afterAudienceChanged(array $task, array $before): void
    {
    }

    /** Whose tasks: the person signed in, or null. */
    abstract protected function taskScope(): ?Scope;

    /** The conversations the tasks are made over, as that person. */
    abstract protected function taskConversations(): Conversations;

    /** The page (`''`), or one of its actions. */
    abstract protected function scheduledUrl(string $action = ''): string;

    /** Where a report's run opens as a conversation; `0` for the address before the number. */
    abstract protected function taskReportUrl(int $runId): string;

    /** The conversations. */
    abstract protected function taskChatUrl(): string;

    /** Where somebody not signed in is sent. */
    abstract protected function taskSignInUrl(): string;
}
