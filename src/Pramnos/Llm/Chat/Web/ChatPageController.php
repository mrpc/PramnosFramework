<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Application\Controller;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Llm;

/**
 * `/chat` — conversations with a model, for an application without a SPA.
 *
 * The page lists the person's conversations, shows one, and asks: the question is queued and
 * the page follows it — with `pf-chat.js` it waits in place, and without JavaScript it refreshes
 * itself until the answer is there. Answers are drawn with {@see \Pramnos\Llm\Chat\Markdown},
 * escaped first.
 *
 * The same hooks as the JSON API (`Concerns\TakesQuestions`): `beforeAsk()`, `storePicture()`,
 * `extra()`, `scope()`, `conversations()`, `asks()` — override them in the application's
 * `Chat` controller, which `init --features=…,llm` writes.
 */
class ChatPageController extends Controller
{
    use \Pramnos\Llm\Chat\Concerns\TakesQuestions;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'ask', 'asked', 'delete', 'forget', 'consent', 'report',
            'scheduled', 'schedule', 'scheduledupdate', 'scheduleddelete', 'scheduledrun', 'scheduledsubscribe']);
        // POST with the session's token: each of these spends, forgets or changes a schedule.
        $this->addWriteAction(['ask', 'delete', 'forget', 'consent',
            'schedule', 'scheduledupdate', 'scheduleddelete', 'scheduledrun', 'scheduledsubscribe']);
        parent::__construct($application);
    }

    /** The conversations, one of them open, and a question being answered. */
    public function display(): mixed
    {
        $scope = $this->scope();

        if ($scope === null) {
            $this->redirect(sURL . 'login?return=' . rawurlencode('chat'));

            return null;
        }

        $request = new \Pramnos\Http\Request();
        $open    = (int) $request->get('c', 0, 'get', 'int');
        $askId   = (int) $request->get('ask', 0, 'get', 'int');
        $chat    = $this->conversations();
        $ask     = $askId > 0 ? $this->asks()->find($scope, $askId) : null;

        // Answered since the page was drawn: open the conversation it went into.
        if ($ask !== null && $ask['status'] === 'answered' && $open === 0) {
            $open = (int) $ask['conversation_id'];
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Ask AI';

        $view                = $this->getView('chat');
        $view->conversations = $chat->list();
        $view->conversation  = $open > 0 ? $chat->find($open) : null;
        $view->ask           = $ask;
        $view->memories      = $chat->memory()?->all() ?? [];
        $view->limits        = $chat->limits();
        $view->consent       = $this->consentState($scope);
        $chat                = Llm::config('chat', []);
        $view->waitingLines  = is_array($chat['waiting_lines'] ?? null) ? $chat['waiting_lines'] : [];
        $view->suggestions   = is_array($chat['suggestions'] ?? null) ? $chat['suggestions'] : [];
        $view->assistantName = is_string($chat['name'] ?? null) ? $chat['name'] : '';

        return $view->display();
    }

    /**
     * Ask. Answered with a redirect — to the conversation, following the queued question — or,
     * for `pf-chat.js` (`Accept: application/json`), with the JSON the API answers.
     */
    public function ask(): mixed
    {
        $input  = $_POST;
        $result = $this->takeQuestion($input, $this->uploaded());

        if ($this->wantsJson()) {
            return $this->json($result);
        }

        $conversationId = (int) ($result['data']['conversation_id'] ?? ($input['conversation_id'] ?? 0));

        if ($result['status'] >= 400) {
            $this->addError((string) $result['error']);
            $this->redirect($this->pageUrl($conversationId));

            return null;
        }

        $askId = (int) ($result['data']['ask_id'] ?? 0);
        $this->redirect($this->pageUrl($conversationId, $askId));

        return null;
    }

    /** A queued question's state, for `pf-chat.js`. */
    public function asked(mixed $id = null): mixed
    {
        $scope = $this->scope();
        $found = $scope === null ? null : $this->asks()->find($scope, (int) ($id ?: \Pramnos\Http\Request::staticGetOption()));

        return $this->json($found === null ? ['status' => 404, 'error' => 'There is no such question.'] : ['status' => 200, 'data' => $found]);
    }

    /** Forget a conversation. */
    public function delete(mixed $id = null): mixed
    {
        $deleted = $this->conversations()->delete((int) ($id ?: \Pramnos\Http\Request::staticGetOption()));
        $deleted ? $this->addMessage('The conversation is forgotten.') : $this->addError('There is no such conversation.');
        $this->redirect($this->pageUrl());

        return null;
    }

    /** Forget one memory. */
    public function forget(mixed $id = null): mixed
    {
        $forgotten = (bool) $this->conversations()->memory()?->forget((int) ($id ?: \Pramnos\Http\Request::staticGetOption()));
        $forgotten ? $this->addMessage('Forgotten.') : $this->addError('There is no such memory.');
        $this->redirect($this->pageUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }

    /** Agree to the provider in use, or withdraw — `give` in the form. */
    public function consent(): mixed
    {
        $scope = $this->scope();

        if ($scope !== null) {
            $this->recordConsent($scope, !empty($_POST['give']));
        }

        $this->redirect($this->pageUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }

    /**
     * `chat/report/{run}` — where a report's notification leads: the run, opened as a
     * conversation of the reader's own to ask about it, the same one each time. A GET, because a
     * notification's link is one; opening it again creates nothing new.
     */
    public function report(mixed $id = null): mixed
    {
        $scope = $this->scope();
        $runId = (int) ($id ?: \Pramnos\Http\Request::staticGetOption());
        $made  = $scope === null ? null : $this->routines()->continueRun($scope, $runId);

        if ($made === null) {
            $this->addError('There is no such report you may read.');
        }
        $this->redirect($this->pageUrl((int) $made));

        return null;
    }

    /**
     * `chat/scheduled` — this person's scheduled tasks (questions asked again, reminders,
     * reports), the reports shared with them to receive, a form to schedule one, and with
     * `?task=` one task's runs, each opened in Ask from here.
     */
    public function scheduled(): mixed
    {
        $scope = $this->scope();
        if ($scope === null) {
            $this->redirect(sURL . 'login?return=' . rawurlencode('chat/scheduled'));

            return null;
        }

        $tasks  = $this->routines();
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
        $view->timezone  = $this->conversations()->assistant()->timezone($scope);
        $view->pageUrl   = sURL . 'chat/scheduled';
        $view->chatUrl   = $this->pageUrl();

        return $view->display('scheduled');
    }

    /** Schedule a task from the form: kind, title, prompt, cadence and its fields, owner, notify. */
    public function schedule(): mixed
    {
        $scope = $this->scope();
        $input = array_intersect_key($_POST, array_flip(['kind', 'template', 'title', 'prompt', 'cadence', 'at', 'day', 'nth', 'run_at', 'owner', 'notify']));
        // The form asks the day twice — a weekday, a day of the month — and sends the one the cadence reads.
        if (!isset($input['day']) && ($_POST['weekday'] ?? $_POST['monthday'] ?? null) !== null) {
            $input['day'] = ($input['cadence'] ?? '') === 'monthly' ? ($_POST['monthday'] ?? null) : ($_POST['weekday'] ?? null);
        }
        // A field left empty is not an answer: a template fills it.
        $input = array_filter($input, static fn ($value): bool => $value !== '' && $value !== null);
        $made  = $scope === null ? 'This needs somebody signed in.'
            : $this->routines()->create($scope, $input + ['timezone' => $this->conversations()->assistant()->timezone($scope)]);
        is_string($made) ? $this->addError($made) : $this->addMessage('Scheduled. Next: ' . $made['next_run_local'] . '.');
        $this->redirect(sURL . 'chat/scheduled');

        return null;
    }

    /** Pause or resume one of this person's tasks — `active` in the form. */
    public function scheduledupdate(mixed $id = null): mixed
    {
        $scope   = $this->scope();
        $changed = $scope === null ? 'This needs somebody signed in.'
            : $this->routines()->update($scope, (int) ($id ?: ($_POST['routine_id'] ?? 0)), ['active' => !empty($_POST['active'])]);
        is_string($changed) ? $this->addError($changed) : $this->addMessage($changed['active'] ? 'Resumed.' : 'Paused.');
        $this->redirect(sURL . 'chat/scheduled');

        return null;
    }

    /** Delete one of this person's tasks. Its conversation and its runs' record stay as they are. */
    public function scheduleddelete(mixed $id = null): mixed
    {
        $scope = $this->scope();
        $scope !== null && $this->routines()->delete($scope, (int) ($id ?: ($_POST['routine_id'] ?? 0)))
            ? $this->addMessage('Deleted.')
            : $this->addError('There is no such task.');
        $this->redirect(sURL . 'chat/scheduled');

        return null;
    }

    /** Run one of this person's tasks now, whatever its schedule, and show its runs. */
    public function scheduledrun(mixed $id = null): mixed
    {
        $scope = $this->scope();
        $id    = (int) ($id ?: ($_POST['routine_id'] ?? 0));
        $found = $scope === null ? null : $this->routines()->find($scope, $id);
        if ($found === null) {
            $this->addError('There is no such task.');
            $this->redirect(sURL . 'chat/scheduled');

            return null;
        }
        $ran = $this->routines()->run($found, time());
        $ran['ok'] ? $this->addMessage('It ran.') : $this->addError((string) $ran['says']);
        $this->redirect(sURL . 'chat/scheduled?task=' . $id);

        return null;
    }

    /** Receive a shared report, or stop — `on` in the form. */
    public function scheduledsubscribe(mixed $id = null): mixed
    {
        $scope = $this->scope();
        $id    = (int) ($id ?: ($_POST['routine_id'] ?? 0));
        if ($scope === null) {
            $this->addError('This needs somebody signed in.');
        } elseif (!empty($_POST['on'])) {
            ($why = $this->routines()->subscribe($scope->userId, $id)) === null ? $this->addMessage('You will receive it.') : $this->addError($why);
        } else {
            $this->routines()->unsubscribe($scope->userId, $id) ? $this->addMessage('You will no longer receive it.') : $this->addError('You do not receive it.');
        }
        $this->redirect(sURL . 'chat/scheduled');

        return null;
    }

    /** The scheduled tasks, over this person's conversations. */
    protected function routines(): \Pramnos\Llm\Chat\Routines
    {
        return new \Pramnos\Llm\Chat\Routines($this->conversations());
    }

    /** The page's address, open at a conversation and following a question. */
    protected function pageUrl(int $conversationId = 0, int $askId = 0): string
    {
        $query = array_filter(['c' => $conversationId, 'ask' => $askId]);

        return sURL . 'chat' . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * The files uploaded with the question, `files[]`.
     *
     * @return list<array{name: string, bytes: string}>
     */
    protected function uploaded(): array
    {
        return \Pramnos\Llm\Chat\Attachments::uploaded();
    }

    /** Whether the caller is `pf-chat.js`, which wants JSON rather than a page. */
    protected function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** Answer JSON and stop. A seam for tests. */
    protected function json(array $answer): mixed
    {
        \Pramnos\Framework\Factory::getDocument('json');
        http_response_code((int) ($answer['status'] ?? 200));

        return json_encode($answer, JSON_UNESCAPED_UNICODE);
    }
}
