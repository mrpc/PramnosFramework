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
        $view->extras    = $this->scheduledExtras($task) + ['form' => '', 'task' => ''];
        $view->urls      = [
            'page'   => $this->scheduledUrl(),
            'action' => $this->scheduledUrl('%s'),
            'report' => $this->taskReportUrl(0),
            'chat'   => $this->taskChatUrl(),
        ];

        return $view->display('scheduled');
    }

    /** Schedule a task from the form: kind, title, prompt, cadence and its fields, owner, notify. */
    public function schedule(): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope = $this->taskScope();
        $input = array_intersect_key($_POST, array_flip(['kind', 'template', 'title', 'prompt', 'cadence', 'at', 'day', 'nth', 'run_at', 'owner', 'notify']));
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
            $this->afterScheduled($made, $_POST);
        }
        $this->redirect($this->scheduledUrl());

        return null;
    }

    /** Pause or resume one of this person's tasks — `active` in the form. */
    public function scheduledupdate(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope   = $this->taskScope();
        $changed = $scope === null ? 'This needs somebody signed in.'
            : $this->taskService()->update($scope, (int) ($id ?: ($_POST['routine_id'] ?? 0)), ['active' => !empty($_POST['active'])]);
        is_string($changed) ? $this->addError($changed) : $this->addMessage($changed['active'] ? 'Resumed.' : 'Paused.');
        $this->redirect($this->scheduledUrl());

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
        $ran = $this->taskService()->run($found, time());
        $ran['ok'] ? $this->addMessage('It ran.') : $this->addError((string) $ran['says']);
        $this->redirect($this->scheduledUrl() . '?task=' . $id);

        return null;
    }

    /** Receive a shared report, or stop — `on` in the form. */
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
            ($why = $this->taskService()->subscribe($scope->userId, $id)) === null ? $this->addMessage('You will receive it.') : $this->addError($why);
        } else {
            $this->taskService()->unsubscribe($scope->userId, $id) ? $this->addMessage('You will no longer receive it.') : $this->addError('You do not receive it.');
        }
        $this->redirect($this->scheduledUrl());

        return null;
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
     * button — fields its {@see afterScheduled()} reads; `task`, printed on the open task's card.
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
