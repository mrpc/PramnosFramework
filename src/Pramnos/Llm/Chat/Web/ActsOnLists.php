<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Bulk\BulkActions;
use Pramnos\Llm\Chat\Artifacts;
use Pramnos\Llm\Chat\Scope;

/**
 * The conversation page's bulk actions on a list artifact — `act/{artifact}` with `action` and
 * `params[...]` — for the page that asks and the analyst's alike: what {@see BulkActions} offers
 * for the list's kind of record, run as the person on the page.
 *
 * Needs the controller's `taskScope()` and `taskDenied()` and {@see shareReturnUrl()}, as
 * {@see SharesConversations} does.
 */
trait ActsOnLists
{
    /** Run `action` on the records of list artifact `{id}`. */
    public function act(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope    = $this->taskScope();
        $artifact = $scope === null ? null : (new Artifacts($scope))->find((int) ($id ?: \Pramnos\Http\Request::staticGetOption()));
        $result   = $artifact === null || $artifact['kind'] !== 'list'
            ? 'There is no such list.'
            : BulkActions::run((string) $artifact['entity'], (string) ($_POST['action'] ?? ''), (array) ($artifact['content']['ids'] ?? []),
                is_array($_POST['params'] ?? null) ? $_POST['params'] : [], $scope->userId);

        if (is_string($result)) {
            $this->addError($result);
        } elseif ($result['queued'] > 0) {
            $this->addMessage(sprintf('%d queued; they will be done shortly.', $result['queued']));
        } else {
            $this->addMessage($result['says'] !== '' ? $result['says'] : sprintf('Done for %d.', $result['done']));
        }
        $this->redirect($this->shareReturnUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }

    /**
     * The actions the open conversation's lists offer this person, by kind of record.
     *
     * @param array<string, mixed>|null $conversation As Conversations::find() answers
     * @return array<string, array<string, array{label: string, params: array<string, array<string, mixed>>}>>
     */
    protected function listActions(?Scope $scope, ?array $conversation): array
    {
        if ($scope === null || $conversation === null) {
            return [];
        }
        $out = [];
        foreach ((array) ($conversation['messages'] ?? []) as $message) {
            foreach ((array) ($message['artifacts'] ?? []) as $artifact) {
                $entity = (string) ($artifact['entity'] ?? '');
                if ($artifact['kind'] === 'list' && $entity !== '' && !isset($out[$entity])) {
                    $out[$entity] = BulkActions::available($entity, $scope->userId);
                }
            }
        }

        return $out;
    }
}
