<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

use Pramnos\Llm\Speech\Speech;

/**
 * `speak`: a page's answers read aloud by the application's speech server
 * ({@see \Pramnos\Llm\Speech\Speech}), for `pf-chat.js` — the session's own way to what the API's
 * `POST {chat}/speak` is for a token.
 *
 * A controller using it registers `speak` as an auth action and a write action: it is a POST with
 * the session's token, since every call is synthesised, and some servers bill by the character.
 */
trait ReadsAloud
{
    /**
     * Read the posted `text` aloud: the audio itself, under the speech server's own type; or JSON
     * `{status, error}` — 501 with no server, 422 for an empty or overlong text, 502 when the
     * server fails.
     */
    public function speak(): mixed
    {
        $spoken = Speech::speak((string) ($_POST['text'] ?? ''));
        if (!$spoken['ok']) {
            \Pramnos\Framework\Factory::getDocument('json');
            http_response_code($spoken['status']);

            return json_encode(['status' => $spoken['status'], 'error' => $spoken['says']], JSON_UNESCAPED_UNICODE);
        }
        \Pramnos\Framework\Factory::getDocument('raw');
        if (!headers_sent()) {
            header('Content-Type: ' . $spoken['type']);
            header('X-Content-Type-Options: nosniff');
        }

        return $spoken['audio'];
    }

    /** Whether the page reads its answers through the speech server: there is one set up. */
    public static function readsAloudOnServer(): bool
    {
        return Speech::config() !== null;
    }
}
