<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Chat\ModelChoices;

/**
 * `modelchoice`: keep the model a person chose in the Ask composer ({@see ModelChoices}), for
 * `pf-chat.js`.
 *
 * A controller using it registers `modelchoice` as an auth action and a write action: a POST with
 * the session's token.
 */
trait ChoosesModel
{
    /**
     * Keep the posted `choice` — a key among the models this person may choose, or empty for
     * Automatic. Answers `{status, data: {choice}}`; 401 signed out, 422 for a model they may not
     * have.
     */
    public function modelchoice(): mixed
    {
        $user = \Pramnos\User\User::getCurrentUser();
        \Pramnos\Framework\Factory::getDocument('json');
        if (!is_object($user) || (int) ($user->userid ?? 0) < 1) {
            http_response_code(401);

            return json_encode(['status' => 401, 'error' => 'This needs somebody signed in.']);
        }
        $choice = trim((string) ($_POST['choice'] ?? ''));
        if (!ModelChoices::choose($user, $choice)) {
            http_response_code(422);

            return json_encode(['status' => 422, 'error' => 'That model is not one you may choose.']);
        }

        return json_encode(['status' => 200, 'data' => ['choice' => $choice]]);
    }
}
