<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\Ratings;

/**
 * The conversation page's rating of an answer — `rate/{message}` with `rating` (1, -1, or 0 to take
 * it back) and an optional `note` — for the page that asks and the analyst's alike.
 *
 * Needs the controller's `taskScope()`, `taskDenied()` and `shareReturnUrl()` — and nothing else:
 * the JSON answer is the trait's own, because `AiController` uses it too and is not a
 * `ChatPageController`, so a call to that class's `wantsJson()` was a fatal on every rating there,
 * the plain form included.
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
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            $words = \Pramnos\Translator\Language::getInstance();

            return $this->ratingAnswer($why === null
                ? ['ok' => true, 'rating' => (int) ($_POST['rating'] ?? 0), 'message' => $words->_('Thank you — noted.')]
                : ['ok' => false, 'error' => $words->_($why)], $why === null ? 200 : 422);
        }
        $why === null ? $this->addMessage('Thank you — noted.') : $this->addError($why);
        $this->redirect($this->shareReturnUrl((int) ($_POST['conversation_id'] ?? 0)));

        return null;
    }

    /** A rating's answer to `pf-chat.js`, as JSON with its status. Named for the trait, so it meets no controller's own method. */
    private function ratingAnswer(array $answer, int $status): string
    {
        \Pramnos\Framework\Factory::getDocument('json');
        http_response_code($status);

        return (string) json_encode($answer, JSON_UNESCAPED_UNICODE);
    }
}
