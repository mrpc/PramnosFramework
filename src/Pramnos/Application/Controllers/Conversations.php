<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Conversations\Access;
use Pramnos\Conversations\Conversations as Store;
use Pramnos\Conversations\Files;
use Pramnos\Conversations\Inbox;
use Pramnos\Conversations\Messages;
use Pramnos\Http\Response;

/**
 * `/admin/Conversations/…` — the team's conversations: the JSON a client reads and writes, and a
 * basic page drawn by `pf-conversations-basic.js` from it. Behind sign-in and the `admin.chat` ability, where the
 * application has the `conversations` feature; every write a POST carrying the session's token
 * (`X-CSRF-Token`) — without it 403 and JSON, never a redirect. A body is a form or JSON. A refusal
 * is `{"error": "…"}` with 403, a mistake 422, something missing 404.
 *
 * | action | method | in | out |
 * |---|---|---|---|
 * | `display` | GET | `#/c/{id}` | the basic page: the list, one conversation, a box to write in |
 * | `conversations` | GET | — | `{me, conversations, browse, people, avatars}`: one's own with unread and mentions and a title, the open ones to join, the names of the people in direct and group ones and their faces (`conversations.avatars`) |
 * | `messages/{id}` | GET | `before`, `after`, `thread`, `limit` | `{conversation, members, messages, pinned, saved}` |
 * | `threads` | GET | — | `{threads}`: the threads one takes part in, newest reply first, with replies new since one's own |
 * | `activity` | GET | — | `{activity}`: mentions of one, reactions to what one wrote, replies in one's threads |
 * | `unreads` | GET | — | `{unreads}`: every unread message, conversation by conversation |
 * | `sent` | GET | — | `{messages}`: what one wrote, newest first |
 * | `saved` | GET | — | `{messages}`: what one kept for later |
 * | `files/{id}` | GET | — | `{files}`: the files sent in a conversation and still kept, newest first |
 * | `people` | GET | `q`, `inactive` | `{people}`: who may use the conversations, by name — inactive ones only when asked for or named whole |
 * | `person/{id}` | GET | `show` (messages, mentions, files), `q` | `{person}`: a name's card and profile — and what they wrote, what mentions them, or what they sent |
 * | `emergencykey` | GET | `device` | `{key, exp, urls}`: this device's key to the beacon, where there is one |
 * | `refs` | GET | `keys` (`user:5,invoice:9`) | `{cards}`: each reference as the application draws it for the reader, or null |
 * | `command/{id}` | POST | `text` (`/name args`), `thread` | `{reply: {text, card}}`: the command's answer, for the writer alone |
 * | `search` | GET | `q`, `conversation` | `{messages}` |
 * | `create` | POST | `kind`, `name`, `topic`, `members[]` | `{id, conversation}`, as `messages` gives it |
 * | `withask` | POST | — | `{id, conversation}`: one's conversation with Ask, made the first time; 404 to whoever may not ask it |
 * | `join/{id}`, `leave/{id}` | POST | — | `{ok}` |
 * | `add/{id}` | POST | `person` | `{ok}` |
 * | `post/{id}` | POST | `body`, `thread`, `files[]` | `{id, message}` |
 * | `edit/{id}` | POST | `body` | `{message}` |
 * | `delete/{id}` | POST | — | `{ok}` |
 * | `react/{id}` | POST | `emoji`, `on` | `{ok}` |
 * | `pin/{id}` | POST | `on` | `{ok}` |
 * | `read/{id}` | POST | `message` | `{ok}` |
 * | `notify/{id}` | POST | `notify` (all, mentions, none), `muted` | `{ok}` |
 * | `star/{id}` | POST | `on` | `{ok}` |
 * | `save/{id}` | POST | `on` | `{ok}`: a message kept for later, or let go |
 * | `upload/{id}` | POST | `file` (multipart) | `{id, name, size, mime}` |
 * | `file/{id}` | GET | `thumbnail` | the file, to a reader of its conversation |
 */
class Conversations extends Controller
{
    /** The writes: each a POST with the session's token. */
    private const WRITES = ['create', 'join', 'leave', 'add', 'post', 'edit', 'delete', 'react', 'pin', 'read', 'notify', 'star', 'save', 'upload', 'command', 'withask'];

    /** The JSON body, decoded once. @var array<string, mixed>|null */
    private ?array $json = null;

    /** Registers the actions: all behind sign-in, the writes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(array_merge(['display', 'conversations', 'messages', 'people', 'person', 'search', 'threads', 'activity', 'unreads', 'sent', 'saved', 'files', 'file', 'refs', 'emergencykey'], self::WRITES));
        $this->addWriteAction(self::WRITES);
        parent::__construct($application);
    }

    /**
     * The basic page: the theme around it, `pf-conversations-basic.js` in it. Somebody who may not
     * use the conversations is sent back with a message.
     */
    public function display(): mixed
    {
        $me = $this->me();
        if (!$this->enabled() || !Access::canUse($me)) {
            $this->addError(t('You do not have access to that screen.'));
            $this->redirect(defined('sURL') ? \sURL : '/');

            return null;
        }
        $document = \Pramnos\Framework\Factory::getDocument();
        $document->title = t('Conversations');
        // The page is the conversations: the title is theirs to draw, and they fill what is left.
        $document->bodyclasses[] = 'pf-titled';
        $document->bodyclasses[] = 'pf-conv-page';
        $view        = $this->getView('conversations');
        $view->props = \Pramnos\Conversations\Client::basic($me);

        return $view->display();
    }

    /** One's own conversations, each with a title; the open ones to join; the people's names. */
    public function conversations(): mixed
    {
        return $this->answer(function (object $me): array {
            $mine   = Store::forPerson((int) $me->userid);
            $ids    = array_merge([], ...array_map(static fn (array $c): array => $c['members'] ?? [], $mine));
            $people = \Pramnos\Conversations\People::names($ids);
            $asleep = \Pramnos\Conversations\People::inactive($ids);
            foreach ($mine as &$conversation) {
                // The others in a direct or group conversation who are inactive: when each last signed in.
                $conversation['inactive'] = (object) array_intersect_key($asleep, array_flip(array_diff($conversation['members'] ?? [], [(int) $me->userid])));
                $conversation['title'] = $conversation['kind'] === 'bot' ? (string) $conversation['name']
                    : ($conversation['members'] === null ? '#' . $conversation['slug']
                    : implode(', ', array_map(static fn (int $id): string => $people[$id] ?? '#' . $id,
                        array_values(array_diff($conversation['members'], [(int) $me->userid])) ?: [(int) $me->userid])));
            }
            unset($conversation);

            return ['me' => (int) $me->userid, 'conversations' => $mine, 'browse' => Store::browse((int) $me->userid), 'people' => $people,
                'avatars' => (object) \Pramnos\Conversations\People::avatars(array_merge($ids, [(int) $me->userid]), $me)];
        });
    }

    /** This device's key to the beacon, for when this server does not answer; renewed every day it does. */
    public function emergencykey(): mixed
    {
        return $this->answer(function (object $me): array {
            // Not a primary, or one without its signing key: no beacon to hand keys for — 404, not a 500.
            if (!\Pramnos\Conversations\Beacon\Config::ready()) {
                throw new \DomainException(t('There is no beacon here.'));
            }
            $name = \Pramnos\Conversations\People::names([(int) $me->userid])[(int) $me->userid] ?? '';
            $key  = \Pramnos\Conversations\Beacon\EmergencyKey::mint((int) $me->userid, $name, (string) ($this->in('device') ?? ''));

            return ['key' => $key, 'exp' => time() + \Pramnos\Conversations\Beacon\EmergencyKey::LIFETIME,
                'urls' => array_values(array_map('strval', (array) \Pramnos\Conversations\Beacon\Config::value('urls', [])))];
        });
    }

    /** The references' cards, as the application draws them for this reader. */
    public function refs(): mixed
    {
        return $this->answer(fn (object $me): array => ['cards' => (object) \Pramnos\Conversations\References::resolve(
            array_filter(explode(',', (string) ($this->in('keys') ?? ''))), $me)]);
    }

    /** Run a `/command`: its answer is for the writer alone. */
    public function command(): mixed
    {
        return $this->answer(fn (object $me): array => ['reply' => \Pramnos\Conversations\Commands::run((string) $this->in('text'), $this->id(), $me,
            $this->in('thread') !== null && $this->in('thread') !== '' ? (int) $this->in('thread') : null)]);
    }

    /** Who may use the conversations whose name holds `q`. */
    public function people(): mixed
    {
        return $this->answer(fn (): array => ['people' => \Pramnos\Conversations\People::search((string) ($this->in('q') ?? ''), 20, $this->flag('inactive', false))]);
    }

    /**
     * Somebody, as a name's card and profile show them; with `show` = `messages`, `mentions` or
     * `files`, what they wrote (holding every word of `q`), what mentions them, or what they sent.
     */
    public function person(): mixed
    {
        return $this->answer(function (object $me): array {
            $person = \Pramnos\Conversations\People::profile($this->id(), $me) ?? throw new \DomainException(t('That person is not in the conversations.'));

            return ['person' => $person] + match ((string) ($this->in('show') ?? '')) {
                'messages' => ['messages' => Messages::search($me, (string) ($this->in('q') ?? ''), null, 30, $person['id'])],
                'mentions' => ['messages' => Messages::search($me, (string) ($this->in('q') ?? ''), null, 30, null, $person['id'])],
                'files'    => ['files' => array_map(static fn (array $f): array => ['id' => $f['id'], 'conversationId' => $f['conversationId'],
                    'name' => $f['name'], 'size' => $f['size'], 'createdAt' => $f['createdAt']], Files::sentBy($person['id'], $me, 30))],
                default    => [],
            };
        });
    }

    /** A conversation's messages: the newest, further back, what came after, or a thread. */
    public function messages(): mixed
    {
        return $this->answer(function (object $me): array {
            $id = $this->id();
            if (!Store::canRead($id, $me)) {
                throw new \DomainException(t('That conversation no longer exists.'));
            }
            $options = array_filter(['before' => $this->in('before'), 'after' => $this->in('after'), 'thread' => $this->in('thread'),
                'limit' => $this->in('limit')], static fn ($v): bool => $v !== null && $v !== '');

            $members = Store::members($id);
            $names   = \Pramnos\Conversations\People::names($members);
            $asleep  = \Pramnos\Conversations\People::inactive($members);
            $faces   = \Pramnos\Conversations\People::avatars($members, $me);

            $messages = Messages::list($id, array_map('intval', $options));

            return ['conversation' => Store::get($id) + ['member' => Store::member($id, (int) $me->userid)],
                'members' => array_map(static fn (int $person): array => ['id' => $person, 'name' => $names[$person] ?? '#' . $person,
                    'inactive' => $asleep[$person] ?? null, 'avatar' => $faces[$person] ?? null], $members),
                'messages' => $messages, 'pinned' => array_column(Messages::pinned($id), 'id'),
                'saved' => Inbox::savedIds((int) $me->userid, array_column($messages, 'id'))];
        });
    }

    /** The files sent in a conversation one reads, and still kept. */
    public function files(): mixed
    {
        return $this->answer(function (object $me): array {
            if (!Store::canRead($this->id(), $me)) {
                throw new \DomainException(t('That conversation no longer exists.'));
            }

            return ['files' => array_map(static fn (array $f): array => ['id' => $f['id'], 'messageId' => $f['messageId'], 'name' => $f['name'],
                'mime' => $f['mime'], 'size' => $f['size'], 'uploadedBy' => $f['uploadedBy'], 'createdAt' => $f['createdAt']],
                Files::inConversation($this->id()))];
        });
    }

    /** The threads one takes part in. */
    public function threads(): mixed
    {
        return $this->answer(static fn (object $me): array => ['threads' => Inbox::threads((int) $me->userid)]);
    }

    /** What happened to one: mentions, reactions, replies. */
    public function activity(): mixed
    {
        return $this->answer(static fn (object $me): array => ['activity' => Inbox::activity((int) $me->userid)]);
    }

    /** Every unread message, conversation by conversation. */
    public function unreads(): mixed
    {
        return $this->answer(static fn (object $me): array => ['unreads' => Inbox::unreads((int) $me->userid)]);
    }

    /** What one wrote. */
    public function sent(): mixed
    {
        return $this->answer(static fn (object $me): array => ['messages' => Inbox::sent((int) $me->userid)]);
    }

    /** What one kept for later. */
    public function saved(): mixed
    {
        return $this->answer(static fn (object $me): array => ['messages' => Inbox::saved((int) $me->userid)]);
    }

    /** Keep a message one can read for later, or let it go. */
    public function save(): mixed
    {
        return $this->answer(function (object $me): array {
            $message = Messages::get($this->id());
            if ($message === null || !Store::canRead($message['conversationId'], $me)) {
                throw new \DomainException(t('That message no longer exists.'));
            }
            Inbox::save($message['id'], (int) $me->userid, $this->flag('on'));

            return ['ok' => true];
        });
    }

    /** The messages one reads holding every word of `q`. */
    public function search(): mixed
    {
        return $this->answer(fn (object $me): array => ['messages' => Messages::search($me, (string) $this->in('q'),
            $this->in('conversation') !== null && $this->in('conversation') !== '' ? (int) $this->in('conversation') : null)]);
    }

    /** A new conversation. */
    public function create(): mixed
    {
        $name = $this->in('name');

        return $this->answer(function (object $me) use ($name): array {
            $id = Store::create((string) $this->in('kind'), $name === null || $name === '' ? null : (string) $name,
                array_map('intval', (array) ($this->in('members') ?? [])), (int) $me->userid, (string) ($this->in('topic') ?? ''));

            return ['id' => $id, 'conversation' => Store::get($id) + ['member' => Store::member($id, (int) $me->userid)]];
        });
    }

    /** One's conversation with Ask: every message a question, answered there. */
    public function withask(): mixed
    {
        return $this->answer(function (object $me): array {
            $id = \Pramnos\Conversations\Ask::conversationFor($me) ?? throw new \DomainException(t('Ask is not here for you.'));

            return ['id' => $id, 'conversation' => Store::get($id) + ['member' => Store::member($id, (int) $me->userid)]];
        });
    }

    /** Join an open conversation. */
    public function join(): mixed
    {
        return $this->answer(function (object $me): array {
            Store::join($this->id(), (int) $me->userid);

            return ['ok' => true];
        });
    }

    /** Leave a conversation. */
    public function leave(): mixed
    {
        return $this->answer(function (object $me): array {
            Store::leave($this->id(), (int) $me->userid);

            return ['ok' => true];
        });
    }

    /** Add somebody to a conversation one is in. */
    public function add(): mixed
    {
        return $this->answer(function (object $me): array {
            Store::add($this->id(), (int) $this->in('person'), (int) $me->userid);

            return ['ok' => true];
        });
    }

    /** Write a message, or a reply in a thread, with the files uploaded for it. */
    public function post(): mixed
    {
        return $this->answer(function (object $me): array {
            $id = Messages::post($this->id(), (int) $me->userid, (string) $this->in('body'), array_filter([
                'thread' => $this->in('thread') !== null && $this->in('thread') !== '' ? (int) $this->in('thread') : null,
                'files'  => array_map('intval', (array) ($this->in('files') ?? [])),
            ]));

            // `@Ask`, or a question to Ask in one's conversation with it: the analyst answers.
            \Pramnos\Conversations\Ask::start($id, $me);

            return ['id' => $id, 'message' => Messages::present([(array) Messages::get($id)])[0]];
        });
    }

    /** Change one's own message. */
    public function edit(): mixed
    {
        return $this->answer(function (object $me): array {
            Messages::edit($this->id(), (int) $me->userid, (string) $this->in('body'));

            return ['message' => Messages::present([(array) Messages::get($this->id())])[0]];
        });
    }

    /** Delete a message: one's own, or as a conversation's owner, or as root. */
    public function delete(): mixed
    {
        return $this->answer(function (object $me): array {
            Messages::delete($this->id(), $me);

            return ['ok' => true];
        });
    }

    /** React to a message, or take it back with `on=0`. */
    public function react(): mixed
    {
        return $this->answer(function (object $me): array {
            $this->readable($me);
            Messages::react($this->id(), (int) $me->userid, (string) $this->in('emoji'), $this->flag('on'));

            return ['ok' => true];
        });
    }

    /** Pin a message, or unpin it with `on=0`. */
    public function pin(): mixed
    {
        return $this->answer(function (object $me): array {
            $this->readable($me);
            Messages::pin($this->id(), (int) $me->userid, $this->flag('on'));

            return ['ok' => true];
        });
    }

    /** Mark a conversation read up to a message. */
    public function read(): mixed
    {
        return $this->answer(function (object $me): array {
            Store::markRead($this->id(), (int) $me->userid, (int) $this->in('message'));

            return ['ok' => true];
        });
    }

    /** What one is told of a conversation, and whether it is muted. */
    public function notify(): mixed
    {
        return $this->answer(function (object $me): array {
            if ($this->in('notify') !== null) {
                Store::notify($this->id(), (int) $me->userid, (string) $this->in('notify'));
            }
            if ($this->in('muted') !== null) {
                Store::mute($this->id(), (int) $me->userid, $this->flag('muted'));
            }

            return ['ok' => true];
        });
    }

    /** Star one of one's conversations, or not. */
    public function star(): mixed
    {
        return $this->answer(function (object $me): array {
            Store::star($this->id(), (int) $me->userid, $this->flag('on'));

            return ['ok' => true];
        });
    }

    /** Keep a file for a message about to be written. */
    public function upload(): mixed
    {
        return $this->answer(function (object $me): array {
            $file = $_FILES['file'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException(in_array((int) ($file['error'] ?? 0), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? t('A file is at most %s MB.', (string) Files::maxMegabytes()) : t('The file did not arrive.'));
            }
            $id   = Files::store($this->id(), (int) $me->userid, (string) $file['tmp_name'], (string) $file['name']);
            $kept = (array) Files::get($id);

            return ['id' => $id, 'name' => $kept['name'], 'size' => $kept['size'], 'mime' => $kept['mime']];
        });
    }

    /** A file, to a reader of its conversation; a 404 to anybody else. */
    public function file(): mixed
    {
        $me = $this->me();
        if (!Access::canUse($me) || !Files::send($this->id(), $me, $this->flag('thumbnail', false))) {
            return Response::json(['error' => t('That file is not here.')], 404);
        }
        $this->terminate();

        return null;
    }

    /**
     * Run an action for somebody who may use the conversations, as JSON: a refusal 403, a mistake
     * 422, something gone 404.
     *
     * @param callable(object): array<string, mixed> $action
     */
    protected function answer(callable $action): Response
    {
        \Pramnos\Framework\Factory::getDocument('json');
        $me = $this->me();
        if (!$this->enabled() || !Access::canUse($me)) {
            return Response::json(['error' => t('You do not have access to that screen.')], 403);
        }
        try {
            return Response::json($action($me));
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * A write without the session's token: 403 and `{"error": …}`, whatever the request says it
     * accepts — this is an API, and a redirect to the site is no answer a browser's code can read.
     */
    protected function refuseUnverifiedWrite(): void
    {
        \Pramnos\Framework\Factory::getDocument('raw')->setContent((string) json_encode(
            ['error' => t('That request could not be verified, so nothing was changed. Try again from the page.')], JSON_UNESCAPED_UNICODE));
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
        }
    }

    /** Who is asking: the signed-in account. */
    protected function me(): ?object
    {
        return \Pramnos\User\User::current();
    }

    /** Whether the application has the conversations. */
    protected function enabled(): bool
    {
        return in_array('conversations', (array) ($this->application->applicationInfo['features'] ?? []), true);
    }

    /** The id in the path: `/admin/Chat/post/12`. */
    protected function id(): int
    {
        return (int) \Pramnos\Http\Request::staticGetOption();
    }

    /** A value of the request: the query for a GET, the form or the JSON body for a POST. */
    protected function in(string $name): mixed
    {
        if (array_key_exists($name, $_POST)) {
            return $_POST[$name];
        }
        $this->json ??= (array) json_decode(\Pramnos\Http\Request::rawBody(), true);

        return $this->json[$name] ?? $_GET[$name] ?? null;
    }

    /** A yes/no value of the request; yes when absent unless said otherwise. */
    private function flag(string $name, bool $absent = true): bool
    {
        $value = $this->in($name);

        return $value === null ? $absent : !in_array($value, [false, 0, '0', 'false', ''], true);
    }

    /** The message in the path must be in a conversation one reads. */
    private function readable(object $me): void
    {
        $message = Messages::get($this->id());
        if ($message === null || !Store::canRead($message['conversationId'], $me)) {
            throw new \DomainException(t('That message no longer exists.'));
        }
    }
}
