<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Shares;

/**
 * The conversation page's sharing actions, for the page that asks and the analyst's alike:
 * `share/{id}` (a conversation, or an artifact with `kind=artifact`), `unshare/{share}`, and
 * `shared/{share}` — where the recipient's notification leads, which opens the share as a
 * conversation of their own.
 *
 * Needs the controller's `taskScope()`, `taskConversations()` and `taskDenied()` (from
 * {@see SchedulesTasks}) and {@see shareReturnUrl()}.
 */
trait SharesConversations
{
    /** Share the conversation or artifact `{id}` with the people named in `names`. */
    public function share(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope    = $this->taskScope();
        $kind     = ($_POST['kind'] ?? '') === Shares::ARTIFACT ? Shares::ARTIFACT : Shares::CONVERSATION;
        $names    = preg_split('/[\s,;]+/', (string) ($_POST['names'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result   = $scope === null ? 'This needs somebody signed in.'
            : (new Shares())->share($scope, $kind, (int) ($id ?: \Pramnos\Http\Request::staticGetOption()), $names);

        if (is_string($result)) {
            $this->addError($result);
        } else {
            if ($result['shared'] !== []) {
                $this->addMessage(sprintf('Shared with %s.', implode(', ', $result['shared'])));
            }
            if ($result['unknown'] !== []) {
                $this->addError(sprintf('Nobody here is called %s.', implode(', ', $result['unknown'])));
            }
        }
        $this->redirect($this->shareReturnUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }

    /** Stop sharing — the share `{id}`. A copy the recipient already took stays theirs. */
    public function unshare(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope = $this->taskScope();
        $scope !== null && (new Shares())->revoke($scope, (int) ($id ?: \Pramnos\Http\Request::staticGetOption()))
            ? $this->addMessage('No longer shared.') : $this->addError('There is no such share.');
        $this->redirect($this->shareReturnUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }

    /** Open a share as its recipient: their own copy, made the first time. */
    public function shared(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $made = (new Shares())->open((int) ($id ?: \Pramnos\Http\Request::staticGetOption()), $this->taskConversations());
        if ($made === null) {
            $this->addError('There is nothing shared with you here.');
        }
        $this->redirect($this->shareReturnUrl((int) $made));

        return null;
    }

    /**
     * What the page shows of sharing: what others shared with the reader, and who the open
     * conversation is shared with.
     *
     * @return array{sharedWithMe: list<array<string, mixed>>, sharePeople: list<array{value: string, label: string}>, shares: list<array<string, mixed>>}
     */
    protected function sharing(?Scope $scope, int $openConversationId): array
    {
        if ($scope === null) {
            return ['sharedWithMe' => [], 'sharePeople' => [], 'shares' => []];
        }
        $shares = new Shares();

        return [
            'sharedWithMe' => $shares->withMe($scope->userId),
            'sharePeople'  => \Pramnos\Llm\Chat\SharePolicy::current()->candidates($scope),
            'shares'       => $openConversationId > 0 ? $shares->sharesOf($scope, Shares::CONVERSATION, $openConversationId) : [],
        ];
    }

    /** Where a sharing action returns: the conversation page, with that conversation open. */
    abstract protected function shareReturnUrl(int $conversationId): string;

    abstract protected function taskScope(): ?Scope;

    abstract protected function taskConversations(): Conversations;
}
