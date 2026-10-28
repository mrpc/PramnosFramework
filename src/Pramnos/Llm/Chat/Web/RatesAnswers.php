<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\Ratings;

/**
 * The conversation page's rating of an answer — `rate/{message}` with `rating` (1, -1, or 0 to take
 * it back) and an optional `note` — for the page that asks and the analyst's alike.
 *
 * Needs the controller's `taskScope()` and `taskDenied()` and `shareReturnUrl()`.
 */
trait RatesAnswers
{
    /** Rate answer `{id}` in one of this person's conversations. */
    public function rate(mixed $id = null): mixed
    {
        if ($this->taskDenied()) {
            return null;
        }
        $scope = $this->taskScope();
        $why   = $scope === null ? 'There is no such answer.'
            : (new Ratings())->rate($scope, (int) ($id ?: \Pramnos\Http\Request::staticGetOption()), (int) ($_POST['rating'] ?? 0), (string) ($_POST['note'] ?? ''));
        $why === null ? $this->addMessage('Thank you — noted.') : $this->addError($why);
        $this->redirect($this->shareReturnUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }
}
