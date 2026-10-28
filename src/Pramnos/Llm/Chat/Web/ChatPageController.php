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
    use SchedulesTasks;
    use SharesConversations;
    use ActsOnLists;
    use RatesAnswers;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'ask', 'asked', 'delete', 'forget', 'consent', 'report',
            'scheduled', 'schedule', 'scheduledupdate', 'scheduleddelete', 'scheduledrun', 'scheduledsubscribe', 'share', 'unshare', 'shared', 'act', 'approve', 'reject', 'rate', 'download', 'printable', 'pin']);
        // POST with the session's token: each of these spends, forgets or changes a schedule.
        $this->addWriteAction(['ask', 'delete', 'forget', 'consent',
            'schedule', 'scheduledupdate', 'scheduleddelete', 'scheduledrun', 'scheduledsubscribe', 'share', 'unshare', 'act', 'approve', 'reject', 'rate', 'pin']);
        parent::__construct($application);
    }

    /** The conversations, one of them open, and a question being answered. */
    public function display(): mixed
    {
        $scope = $this->scope();

        if ($scope === null) {
            $this->redirect(\Pramnos\Auth\SecurityPolicy::loginUrl('chat'));

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
        // How it looks as the application's own (ChatLook): its greeting, placeholder, colour, `/` commands.
        $view->greeting = \Pramnos\Llm\Chat\Web\ChatLook::greeting(is_array($chat) ? $chat : []);
        foreach (['placeholder', 'accent'] as $key) {
            $view->{$key} = is_string($chat[$key] ?? null) ? $chat[$key] : '';
        }
        $view->commands = is_array($chat['commands'] ?? null) ? $chat['commands'] : [];
        foreach ($this->sharing($scope, $open) as $name => $value) {
            $view->{$name} = $value;
        }
        $view->bulkActions = $this->listActions($scope, $view->conversation);
        $view->pinned      = \Pramnos\Llm\Chat\Artifacts::pinnedIds(new \Pramnos\User\User($scope->userId));

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
        $made  = $scope === null ? null : $this->taskService()->continueRun($scope, $runId);

        if ($made === null) {
            $this->addError('There is no such report you may read.');
        }
        $this->redirect($this->pageUrl((int) $made));

        return null;
    }

    /** The person whose tasks the scheduled page shows. */
    protected function taskScope(): ?Scope
    {
        return $this->scope();
    }

    /** Ask AI's conversations, as that person. */
    protected function taskConversations(): \Pramnos\Llm\Chat\Conversations
    {
        return $this->conversations();
    }

    /** `chat/scheduled`, or `chat/<action>`. */
    protected function scheduledUrl(string $action = ''): string
    {
        return sURL . 'chat/' . ($action === '' ? 'scheduled' : $action);
    }

    /** `chat/report/<run>`. */
    protected function taskReportUrl(int $runId): string
    {
        return sURL . 'chat/report/' . ($runId > 0 ? $runId : '');
    }

    /** The conversations page. */
    protected function taskChatUrl(): string
    {
        return $this->pageUrl();
    }

    /** Sign in, and come back to the scheduled page. */
    protected function taskSignInUrl(): string
    {
        return \Pramnos\Auth\SecurityPolicy::loginUrl('chat/scheduled');
    }

    /** The page's address, open at a conversation and following a question. */
    protected function shareReturnUrl(int $conversationId): string
    {
        return $this->pageUrl($conversationId);
    }

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
