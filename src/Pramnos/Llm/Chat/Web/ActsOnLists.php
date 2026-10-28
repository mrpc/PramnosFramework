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

    /** Download artifact `{id}` as CSV — a table, a list or a chart's numbers. */
    public function download(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope    = $this->taskScope();
        $artifact = $scope === null ? null : (new Artifacts($scope))->find((int) ($id ?: \Pramnos\Http\Request::staticGetOption()));
        $csv      = $artifact === null ? null : Artifacts::csv($artifact);
        if ($csv === null) {
            $this->addError('There is nothing to download here.');
            $this->redirect($this->shareReturnUrl(0));

            return null;
        }

        return $this->sendFile(Artifacts::filename($artifact, 'csv'), 'text/csv; charset=utf-8', $csv);
    }

    /** Artifact `{id}` on a page of its own, to print or save as PDF. */
    public function printable(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope    = $this->taskScope();
        $artifact = $scope === null ? null : (new Artifacts($scope))->find((int) ($id ?: \Pramnos\Http\Request::staticGetOption()));
        if ($artifact === null) {
            $this->addError('There is no such artifact.');
            $this->redirect($this->shareReturnUrl(0));

            return null;
        }

        return $this->sendFile('', 'text/html; charset=utf-8', ArtifactTools::printable($artifact));
    }

    /** Pin artifact `{id}` to the dashboard, or take it off with `pinned=0`. */
    public function pin(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope = $this->taskScope();
        $on    = (string) ($_POST['pinned'] ?? '1') !== '0';
        $why   = $scope === null ? 'There is no such artifact.' : (new Artifacts($scope))->pin((int) ($id ?: \Pramnos\Http\Request::staticGetOption()), $on);
        $why === null ? $this->addMessage($on ? 'Pinned to your dashboard.' : 'Taken off your dashboard.') : $this->addError($why);
        $this->redirect($this->shareReturnUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }

    /**
     * Send a file, or a page, and stop: a CSV as an attachment, a printable page as it is. A seam
     * for tests, which read what would have been sent.
     */
    protected function sendFile(string $name, string $type, string $body): mixed
    {
        \Pramnos\Framework\Factory::getDocument('raw');
        if (!headers_sent()) {
            header('Content-Type: ' . $type);
            if ($name !== '') {
                header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
            }
            header('X-Content-Type-Options: nosniff');
        }

        return $body;
    }

    /** Approve proposal `{id}`: the change runs now, as the person approving. */
    public function approve(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope  = $this->taskScope();
        $result = $scope === null ? 'There is no such proposal.' : (new \Pramnos\Llm\Chat\Proposals($scope))->approve((int) ($id ?: \Pramnos\Http\Request::staticGetOption()));
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

    /** Reject proposal `{id}`: nothing changes, and it stays marked so. */
    public function reject(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope = $this->taskScope();
        $why   = $scope === null ? 'There is no such proposal.' : (new \Pramnos\Llm\Chat\Proposals($scope))->reject((int) ($id ?: \Pramnos\Http\Request::staticGetOption()));
        $why === null ? $this->addMessage('Rejected; nothing was changed.') : $this->addError($why);
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
