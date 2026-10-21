<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Html\MessageComposer;
use Pramnos\Html\SafeHtml;

/**
 * What the message composer asks the server ({@see MessageComposer}): saved messages, writing
 * with AI, and the signature.
 *
 * Reachable at `/admin/Composer` as the framework's own controller. Every action is behind the
 * administration floor; every change is a POST with the session's token — the composer sends it
 * as `X-CSRF-Token`.
 *
 * - `saved?scope=` — the account's saved messages and the shared ones, as JSON
 * - `save` (POST `name`, `subject`, `body`, `scope`, `shared`) — kept; the same name in the same
 *   scope is replaced. Shared needs the `messages.share` ability where the application defines
 *   it; where it does not, the administration floor that opens this controller is enough
 * - `forget` (POST `id`) — an account's own; a shared one with the same right as sharing
 * - `write` (POST `instruction`, `subject`, `body`, `language`, `fields`) — a subject and a body
 *   from the configured language model, placeholders kept, the cost logged as every call is
 * - `signature` — the account's signature: the page that edits it, and its POST
 */
class Composer extends Controller
{
    /** The ability that lets an account share a saved message with everybody. */
    public const SHARE_ABILITY = 'messages.share';

    /** Registers the actions: all behind sign-in, the changes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['saved', 'save', 'forget', 'write', 'signature']);
        $this->addWriteAction(['save', 'forget', 'write']);
        parent::__construct($application);
    }

    /** The saved messages a form of `?scope=` offers: the account's own, then the shared. */
    public function saved(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $scope = $this->scope((string) \Pramnos\Http\Request::staticGet('scope', '', 'get'));
        $me    = $this->me();
        $rows  = $this->table()->where('scope', $scope)
            ->where(static function ($q) use ($me): void {
                $q->where('userid', $me);
                $q->whereRaw('userid IS NULL', [], 'or');
            })
            ->orderBy('name')->getAll();

        return $this->json(['messages' => array_map(fn (array $row): array => [
            'id'      => (int) $row['id'],
            'name'    => (string) $row['name'],
            'subject' => (string) $row['subject'],
            'body'    => (string) $row['body'],
            'shared'  => $row['userid'] === null,
            'mine'    => (int) $row['userid'] === $me,
            'canForget' => $row['userid'] === null ? $this->mayShare() : true,
        ], $rows), 'canShare' => $this->mayShare()]);
    }

    /** Keep a message — the account's, or shared; the same name in the same scope is replaced. */
    public function save(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $name   = trim(strip_tags((string) ($_POST['name'] ?? '')));
        $scope  = $this->scope((string) ($_POST['scope'] ?? ''));
        $shared = !empty($_POST['shared']);
        if ($name === '') {
            return $this->json(['error' => t('A saved message needs a name.')], 422);
        }
        if ($shared && !$this->mayShare()) {
            return $this->json(['error' => t('You may not share messages with everybody.')], 403);
        }
        $owner = $shared ? null : $this->me();
        $row   = [
            'name'       => mb_substr($name, 0, 150),
            'subject'    => mb_substr(trim(strip_tags((string) ($_POST['subject'] ?? ''))), 0, 255),
            'body'       => SafeHtml::clean((string) ($_POST['body'] ?? '')),
            'updated_at' => time(),
        ];
        $same = $this->table()->where('scope', $scope)->where('name', $row['name']);
        $same = $owner === null ? $same->whereRaw('userid IS NULL') : $same->where('userid', $owner);
        $existing = $same->first();
        if ($existing && $existing->numRows > 0) {
            $this->table()->where('id', (int) $existing->fields['id'])->update($row);
            $id = (int) $existing->fields['id'];
        } else {
            $this->table()->insert($row + ['userid' => $owner, 'scope' => $scope, 'created_at' => time()]);
            $id = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();
        }

        return $this->json(['saved' => $id, 'shared' => $owner === null]);
    }

    /** Forget a saved message: the account's own, or a shared one with the right to share. */
    public function forget(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $id  = (int) ($_POST['id'] ?? 0);
        $row = $id > 0 ? $this->table()->where('id', $id)->first() : null;
        if (!$row || $row->numRows === 0) {
            return $this->json(['error' => t('That saved message no longer exists.')], 404);
        }
        $owner = $row->fields['userid'];
        $may   = $owner === null ? $this->mayShare() : (int) $owner === $this->me();
        if (!$may) {
            return $this->json(['error' => t('You may not forget this message.')], 403);
        }
        $this->table()->where('id', $id)->delete();

        return $this->json(['forgotten' => $id]);
    }

    /**
     * A subject and a body from the language model: written from an instruction, or the current
     * text improved. Every placeholder kept as written; in `language` when given.
     */
    public function write(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $client = $this->client();
        if (!$client->isConfigured()) {
            return $this->json(['error' => t('No language model is configured.')], 503);
        }
        $instruction = trim((string) ($_POST['instruction'] ?? ''));
        $subject     = trim((string) ($_POST['subject'] ?? ''));
        $body        = trim((string) ($_POST['body'] ?? ''));
        if ($instruction === '' && $subject === '' && strip_tags($body) === '') {
            return $this->json(['error' => t('Say what to write, or write something to improve.')], 422);
        }
        $fields   = array_values(array_filter(array_map('strval', (array) json_decode((string) ($_POST['fields'] ?? '[]'), true))));
        $language = trim((string) ($_POST['language'] ?? ''));

        $answer = $client->about(['purpose' => 'compose', 'userid' => $this->me()])->ask(
            self::prompt($fields, $language),
            [['role' => 'user', 'content' => (string) json_encode(
                ['instruction' => $instruction, 'subject' => $subject, 'body' => $body],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )]]
        );
        if (empty($answer['ok'])) {
            return $this->json(['error' => (string) ($answer['says'] ?? t('The language model did not answer.'))], 502);
        }
        $written = self::parse((string) $answer['reply']);
        if ($written === null) {
            return $this->json(['error' => t('The language model did not answer with a message.')], 502);
        }

        return $this->json($written);
    }

    /** The account's signature: the page that edits it, and its POST. */
    public function signature(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }
        $user = \Pramnos\User\User::current();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->isVerifiedWrite()) {
                $this->addError(t('The form expired. Try again.'));
            } elseif ($user instanceof \Pramnos\User\User) {
                $user->setSetting(MessageComposer::SIGNATURE_SETTING, SafeHtml::clean((string) ($_POST['signature'] ?? '')), (int) $user->userid);
                $this->addMessage(t('Signature saved.'));
            }
            $this->redirect(adminUrl('Composer/signature'));

            return null;
        }
        \Pramnos\Framework\Factory::getDocument()->title = t('My signature');
        $view            = $this->getView('composer');
        $view->signature = MessageComposer::signature();

        return $view->display('signature');
    }

    /**
     * What the model is told. Placeholders are the reason for this prompt: a message written for
     * many people is a template, and a model that "fills in" `{firstname}` sends one person's
     * name to everybody.
     *
     * @param list<string> $fields The placeholders the form offers
     */
    public static function prompt(array $fields, string $language): string
    {
        return 'You write e-mail messages for an administrator of a web application. '
            . 'You get JSON with "instruction", "subject" and "body" (HTML). If there is an instruction, write the message it asks for; '
            . 'otherwise improve the subject and the body as written — clearer, correct, the same meaning and tone. '
            . 'Placeholders in curly braces, such as ' . ($fields === [] ? '{firstname}' : implode(', ', $fields)) . ', are filled for each recipient later: '
            . 'keep every placeholder exactly as written, never fill one in, and use only those placeholders. '
            . ($language !== '' ? 'Write in the language "' . $language . '". ' : 'Write in the language of the existing text, or of the instruction. ')
            . 'Answer with JSON only: {"subject": "...", "body": "..."}, the body as HTML with only p, strong, em, ul, ol, li and a.';
    }

    /**
     * The model's answer as a subject and a clean body, or null when it is not one.
     *
     * @return array{subject: string, body: string}|null
     */
    public static function parse(string $reply): ?array
    {
        $start = strpos($reply, '{');
        $end   = strrpos($reply, '}');
        $data  = $start !== false && $end !== false ? json_decode(substr($reply, $start, $end - $start + 1), true) : null;
        if (!is_array($data) || !isset($data['subject'], $data['body'])) {
            return null;
        }

        return ['subject' => trim(strip_tags((string) $data['subject'])), 'body' => SafeHtml::clean((string) $data['body'])];
    }

    /** The model client — a seam, so a test can give it a log of its own. */
    protected function client(): \Pramnos\Llm\Client
    {
        return new \Pramnos\Llm\Client();
    }

    /** Whether the signed-in account may share a message with everybody. */
    protected function mayShare(): bool
    {
        return \Pramnos\Auth\Gate::has(self::SHARE_ABILITY) ? $this->can(self::SHARE_ABILITY) : true;
    }

    /** The signed-in account's id. */
    protected function me(): int
    {
        $user = \Pramnos\User\User::current();

        return $user ? (int) $user->userid : 0;
    }

    /** A scope as stored: letters, digits, dot, dash, underscore. */
    private function scope(string $scope): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $scope), 0, 60);
    }

    /** A builder on the table. */
    private function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#savedmessages');
    }

    /**
     * A JSON answer.
     *
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $status = 200): \Pramnos\Http\Response
    {
        \Pramnos\Framework\Factory::getDocument('json');

        return \Pramnos\Http\Response::json($data, $status);
    }
}
