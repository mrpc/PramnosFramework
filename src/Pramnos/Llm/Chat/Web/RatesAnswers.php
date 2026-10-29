<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\Ratings;

/**
 * The conversation page's rating of an answer — `rate/{message}` with `rating` (1, -1, or 0 to take
 * it back) and an optional `note` — for the page that asks and the analyst's alike.
 *
 * Needs the controller's `taskScope()`, `taskDenied()`, `shareReturnUrl()`, `wantsJson()` and
 * `json()`.
 *
 * `pf-chat.js` sends the rating with `Accept: application/json` and is answered
 * `{ok: true, rating, message}` or `{ok: false, error}` with a 422, so the buttons change in place
 * and the conversation keeps its scroll position. Without the script the form posts, the message is
 * flashed and the page comes back, as it always has.
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
        if ($this->wantsJson()) {
            $words = \Pramnos\Translator\Language::getInstance();

            return $this->json($why === null
                ? ['ok' => true, 'rating' => (int) ($_POST['rating'] ?? 0), 'message' => $words->_('Thank you — noted.')]
                : ['ok' => false, 'error' => $words->_($why), 'status' => 422]);
        }
        $why === null ? $this->addMessage('Thank you — noted.') : $this->addError($why);
        $this->redirect($this->shareReturnUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }
}
