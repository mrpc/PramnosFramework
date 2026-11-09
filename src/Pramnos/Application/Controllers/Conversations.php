<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Conversations\Access;
use Pramnos\Conversations\Images;
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
 * | `messages/{id}` | GET | `before`, `after`, `thread`, `limit` | `{conversation, handles, members, formers, messages, pinned, saved, draft}` — `draft`, what the reader has begun writing there (in `thread`, when asked for one) |
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
 * | `post/{id}` | POST | `body`, `thread`, `broadcast`, `files[]` | `{id, message, paused}` — `broadcast` with `thread`: the reply is also sent to the conversation; `paused` who it addresses with their notifications paused |
 * | `nudge/{id}` | POST | `person` | `{ok}`: "Notify anyway" — the message's writer tells one of `paused`, past the pause, once |
 * | `schedule/{id}` | POST | `body`, `at` (Unix seconds), `thread`, `broadcast`, `files[]` | `{id, scheduled}`: a message to send later, as its writer |
 * | `scheduled` | GET | — | `{scheduled}`: what one is waiting to send, and what could not be sent with its `error`, soonest first |
 * | `reschedule/{id}` | POST | `body`, `at` | `{scheduled}`: one's own scheduled message changed; one that failed is tried again |
 * | `unschedule/{id}` | POST | — | `{ok}` |
 * | `drafts` | GET | — | `{drafts}`: what one has begun writing, everywhere, newest first — `{conversationId, thread, body, updatedAt}` |
 * | `draft/{id}` | POST | `body`, `thread` | `{ok}`: what one has written so far there, kept for one's other devices; an empty body lets it go |
 * | `edit/{id}` | POST | `body` | `{message}` |
 * | `delete/{id}` | POST | — | `{ok}` |
 * | `react/{id}` | POST | `emoji`, `on` | `{ok}` |
 * | `pin/{id}` | POST | `on` | `{ok}` |
 * | `unfurl/{id}` | POST | `url` | `{ok}`: the writer takes that link's preview card off the message |
 * | `gifs` | GET | `q` | `{gifs}`: GIFs for a search, or the trending ones — `{id, title, preview: {url, width, height}, url}` — asked with the application's key |
 * | `gif/{id}` | POST | `url`, `title` | `{id, name, size, mime}`: a GIF the picker offered, kept as a file of the conversation for the message about to be written |
 * | `preview` | GET | `name` | a preview card's picture, kept here so a reader's browser never asks the other site |
 * | `read/{id}` | POST | `message` | `{ok}` |
 * | `unread/{id}` | POST | `message` | `{ok}`: unread from that message on (a reply: from its root) |
 * | `notify/{id}` | POST | `notify` (all, mentions, none), `muted` | `{ok}` |
 * | `star/{id}` | POST | `on`, `before` | `{ok}` — `before`, a starred conversation it is placed before, in the reader's own order |
 * | `starorder` | POST | `ids[]` | `{ok}`: the reader's own order of their starred conversations, all at once; ids not theirs are let be |
 * | `active` | POST | `client`, `idle`, `front`, `locked` | `{ok}`: this client is where the person is, so a message is notified here alone |
 * | `rename/{id}` | POST | `name` | `{ok}`: a group named, or its name taken away (empty); any member may |
 * | `away` | POST | `minutes` (0, 5, 10, 30) | `{minutes, choices}`: away this long, every client is notified; read without `minutes` |
 * | `save/{id}` | POST | `on` | `{ok}`: a message kept for later, or let go |
 * | `upload/{id}` | POST | `file` (multipart) | `{id, name, size, mime}` |
 * | `file/{id}` | GET | `thumbnail` | the file, to a reader of its conversation |
 */
class Conversations extends Controller
{
    /** The writes: each a POST with the session's token. */
    private const WRITES = ['create', 'join', 'leave', 'add', 'post', 'edit', 'delete', 'react', 'pin', 'unfurl', 'gif', 'read', 'notify', 'star', 'save', 'upload', 'command', 'withask', 'unread', 'nudge', 'schedule', 'reschedule', 'unschedule', 'draft', 'starorder', 'active', 'away', 'rename', 'setimage', 'clearimage'];

    /** The JSON body, decoded once. @var array<string, mixed>|null */
    private ?array $json = null;

    /** Registers the actions: all behind sign-in, the writes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(array_merge(['display', 'conversations', 'messages', 'people', 'person', 'search', 'threads', 'activity', 'unreads', 'sent', 'saved', 'files', 'file', 'emoji', 'preview', 'gifs', 'refs', 'emergencykey', 'reach', 'scheduled', 'drafts'], self::WRITES));
        $this->addWriteAction(self::WRITES);
        // Every read action here — including `conversations`, polled often from an open tab —
        // only reads $_SESSION (through User::current()/Session::staticIsLogged()), never writes
        // it, so the session lock has nothing to protect and releasing it early stops these
        // requests queueing behind each other and behind any write action this session makes
        // elsewhere, same as the other screens already opted into this.
        $this->releaseSessionOnRead();
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
            $gone   = Store::formers(array_column($mine, 'id'));
            $ids    = array_merge([], ...array_map(static fn (array $c): array => $c['members'] ?? [], $mine));
            $people = \Pramnos\Conversations\People::names($ids);
            $asleep = \Pramnos\Conversations\People::inactive($ids);
            foreach ($mine as &$conversation) {
                // The others in a direct or group conversation who are inactive: when each last signed in.
                $conversation['inactive'] = (object) array_intersect_key($asleep, array_flip(array_diff($conversation['members'] ?? [], [(int) $me->userid])));
                // Who has no account here any more, by name: a deleted profile, in the title too.
                $conversation['formers'] = $gone[$conversation['id']] ?? [];
                $others = array_merge(array_map(static fn (int $id): string => $people[$id] ?? '#' . $id,
                    array_values(array_diff($conversation['members'] ?? [], [(int) $me->userid]))), array_column($conversation['formers'], 'name'));
                // The members, for a group with a name too: a search finds it by either.
                $conversation['membersTitle'] = implode(', ', $others);
                $conversation['title'] = $conversation['kind'] === 'bot' ? \Pramnos\Conversations\Ask::displayName((string) $conversation['name'])
                    : ($conversation['kind'] === 'group' && (string) ($conversation['name'] ?? '') !== '' ? (string) $conversation['name']
                    : ($conversation['members'] === null ? '#' . $conversation['slug']
                    // Nobody else: one's own dm, one's notes.
                    : ($others === [] ? ($people[(int) $me->userid] ?? '#' . $me->userid) . ' ' . t('(you)') : implode(', ', $others))));
                if (\Pramnos\Conversations\Ask::isOwn($conversation)) {
                    $conversation['avatar'] = \Pramnos\Llm\Analyst\Analyst::avatar();
                }
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

    /**
     * How many people a message's handles would tell in a conversation the reader may write in — for
     * a composer to ask before `@channel` or `@everyone` tells many.
     */
    public function reach(): mixed
    {
        return $this->answer(function (object $me): array {
            if (!\Pramnos\Conversations\Conversations::canRead($this->id(), $me)) {
                throw new \DomainException(t('There is no such conversation.'));
            }

            return ['reach' => \Pramnos\Conversations\Handles::reach($this->id(), (int) $me->userid, (string) ($this->in('body') ?? ''))];
        });
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
                // The handles its composer offers the reader: `@here`, `@channel`, `@everyone` in the general one, the groups — those they may use.
                'handles' => \Pramnos\Conversations\Handles::offered($id, (int) $me->userid),
                'members' => array_map(static fn (int $person): array => ['id' => $person, 'name' => $names[$person] ?? '#' . $person,
                    'inactive' => $asleep[$person] ?? null, 'avatar' => $faces[$person] ?? null], $members),
                'formers' => Store::formers([$id])[$id] ?? [],
                'messages' => $messages, 'pinned' => array_column(Messages::pinned($id), 'id'),
                'saved' => Inbox::savedIds((int) $me->userid, array_column($messages, 'id')),
                'draft' => \Pramnos\Conversations\Drafts::get((int) $me->userid, $id, isset($options['thread']) ? (int) $options['thread'] : null)];
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
                // A reply also sent to the conversation.
                'broadcast' => $this->flag('broadcast', false) ?: null,
            ]));

            // `@Ask`, or a question to Ask in one's conversation with it: the analyst answers.
            \Pramnos\Conversations\Ask::start($id, $me);

            // Who it addresses with their notifications paused: the composer offers "Notify anyway".
            return ['id' => $id, 'message' => Messages::present([(array) Messages::get($id)])[0], 'paused' => \Pramnos\Conversations\Notify::paused($id)];
        });
    }

    /** What one has begun writing, everywhere. */
    public function drafts(): mixed
    {
        return $this->answer(fn (object $me): array => ['drafts' => \Pramnos\Conversations\Drafts::mine((int) $me->userid)]);
    }

    /** Keep what one has written so far in a conversation, or a thread of it; nothing lets it go. */
    public function draft(): mixed
    {
        return $this->answer(function (object $me): array {
            \Pramnos\Conversations\Drafts::save((int) $me->userid, $this->id(),
                $this->in('thread') !== null && $this->in('thread') !== '' ? (int) $this->in('thread') : null, (string) ($this->in('body') ?? ''));

            return ['ok' => true];
        });
    }

    /** Keep a message to send later, as its writer. */
    public function schedule(): mixed
    {
        return $this->answer(function (object $me): array {
            $id = \Pramnos\Conversations\Scheduled::create($this->id(), (int) $me->userid, (string) $this->in('body'), (int) $this->in('at'), [
                'thread' => $this->in('thread') !== null && $this->in('thread') !== '' ? (int) $this->in('thread') : null,
                'files'  => array_map('intval', (array) ($this->in('files') ?? [])),
                'broadcast' => $this->flag('broadcast', false),
            ]);

            return ['id' => $id, 'scheduled' => $this->scheduledOne((int) $me->userid, $id)];
        });
    }

    /** What one is waiting to send, and what could not be sent. */
    public function scheduled(): mixed
    {
        return $this->answer(fn (object $me): array => ['scheduled' => \Pramnos\Conversations\Scheduled::mine((int) $me->userid)]);
    }

    /** Change one's own scheduled message: its text, its time. */
    public function reschedule(): mixed
    {
        return $this->answer(function (object $me): array {
            \Pramnos\Conversations\Scheduled::change($this->id(), (int) $me->userid, $this->in('body') === null ? null : (string) $this->in('body'),
                $this->in('at') === null || $this->in('at') === '' ? null : (int) $this->in('at'));

            return ['scheduled' => $this->scheduledOne((int) $me->userid, $this->id())];
        });
    }

    /** Let go of one's own scheduled message. */
    public function unschedule(): mixed
    {
        return $this->answer(function (object $me): array {
            \Pramnos\Conversations\Scheduled::cancel($this->id(), (int) $me->userid);

            return ['ok' => true];
        });
    }

    /** @return array<string, mixed>|null One of one's own scheduled messages */
    private function scheduledOne(int $person, int $id): ?array
    {
        foreach (\Pramnos\Conversations\Scheduled::mine($person) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** "Notify anyway": the writer of a message tells one it addressed past their pause, once. */
    public function nudge(): mixed
    {
        return $this->answer(function (object $me): array {
            $this->readable($me);
            \Pramnos\Conversations\Notify::nudge($this->id(), (int) $me->userid, (int) $this->in('person'));

            return ['ok' => true];
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

    /** Take a link's preview card off one's own message. */
    public function unfurl(): mixed
    {
        return $this->answer(function (object $me): array {
            $this->readable($me);
            \Pramnos\Conversations\Unfurls::hide($this->id(), (string) $this->in('url'), (int) $me->userid);

            return ['ok' => true];
        });
    }

    /** GIFs for a search, or the trending ones, asked by this server with the application's key. */
    public function gifs(): mixed
    {
        return $this->answer(fn (object $me): array => ['gifs' => \Pramnos\Conversations\Gifs::search((string) ($this->in('q') ?? ''))]);
    }

    /** A GIF from the picker, kept as a file of the conversation for the message about to be written. */
    public function gif(): mixed
    {
        return $this->answer(function (object $me): array {
            $id   = \Pramnos\Conversations\Gifs::keep($this->id(), (int) $me->userid, (string) $this->in('url'), (string) ($this->in('title') ?? ''));
            $kept = (array) Files::get($id);

            return ['id' => $id, 'name' => $kept['name'], 'size' => $kept['size'], 'mime' => $kept['mime']];
        });
    }

    /** A preview card's picture, to somebody who may use the conversations; a 404 to anybody else. */
    public function preview(): mixed
    {
        if (!Access::canUse($this->me()) || !\Pramnos\Conversations\Unfurls::sendImage((string) $this->in('name'))) {
            return Response::json(['error' => t('That file is not here.')], 404);
        }
        $this->terminate();

        return null;
    }

    /** Mark a conversation read up to a message. */
    public function read(): mixed
    {
        return $this->answer(function (object $me): array {
            Store::markRead($this->id(), (int) $me->userid, (int) $this->in('message'));
            \Pramnos\Conversations\Realtime::read($this->id(), (int) $me->userid);
            // Its notifications in the bell are read too.
            \Pramnos\Conversations\Notify::settle($this->id(), (int) $me->userid);

            return ['ok' => true];
        });
    }

    /** Mark a conversation one is in unread from a message on. */
    public function unread(): mixed
    {
        return $this->answer(function (object $me): array {
            if (Store::member($this->id(), (int) $me->userid) === null) {
                throw new \DomainException(t('That conversation no longer exists.'));
            }
            Store::markUnread($this->id(), (int) $me->userid, (int) $this->in('message'));

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

    /**
     * "I did something", from a client: its id (`client`), and where it can tell, the system's idle
     * seconds (`idle`), whether it is in front (`front`) and whether the screen is locked
     * (`locked`). Where the person is decides which client is notified ({@see \Pramnos\Conversations\Clients}).
     */
    public function active(): mixed
    {
        return $this->answer(function (object $me): array {
            \Pramnos\Conversations\Clients::active((int) $me->userid, (string) $this->in('client'),
                $this->in('idle') !== null && $this->in('idle') !== '' ? (int) $this->in('idle') : null,
                $this->flag('front'), $this->flag('locked', false));

            return ['ok' => true];
        });
    }

    /** How many minutes away before every client is told: read, or set with `minutes`. */
    public function away(): mixed
    {
        return $this->answer(function (object $me): array {
            $minutes = $this->in('minutes');

            return ['minutes' => \Pramnos\Conversations\Clients::awayAfter((int) $me->userid, $minutes === null || $minutes === '' ? null : (int) $minutes),
                'choices' => \Pramnos\Conversations\Clients::CHOICES];
        });
    }

    /** Name a group one is in, or take its name away (`name` empty). */
    public function rename(): mixed
    {
        $me = $this->me();
        // Somebody not in it may not rename it: refused as such, not as a group that is not there.
        if ($me !== null && Store::get($this->id()) !== null && Store::member($this->id(), (int) $me->userid) === null) {
            return Response::json(['error' => t('Only its members rename a group.')], 403);
        }

        return $this->answer(function (object $me): array {
            Store::rename($this->id(), $this->in('name') === null ? null : (string) $this->in('name'), (int) $me->userid);

            return ['ok' => true];
        });
    }

    /** Star one of one's conversations, or not. */
    public function star(): mixed
    {
        return $this->answer(function (object $me): array {
            Store::star($this->id(), (int) $me->userid, $this->flag('on'),
                $this->in('before') !== null && $this->in('before') !== '' ? (int) $this->in('before') : null);

            return ['ok' => true];
        });
    }

    /** The reader's own order of their starred conversations, all at once. */
    public function starorder(): mixed
    {
        return $this->answer(function (object $me): array {
            Store::starOrder((int) $me->userid, array_map('intval', (array) ($this->in('ids') ?? [])));

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

    /** Give a channel or a group a picture: the upload `image`, kept square ({@see Images}). */
    public function setimage(): mixed
    {
        return $this->answer(function (object $me): array {
            $file = $_FILES['image'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException(t('The file did not arrive.'));
            }
            Images::set($this->id(), (string) $file['tmp_name'], (int) $me->userid);

            return ['image' => Store::get($this->id())['image'] ?? null];
        });
    }

    /** Take a channel's or a group's picture away. */
    public function clearimage(): mixed
    {
        return $this->answer(function (object $me): array {
            Images::clear($this->id(), (int) $me->userid);

            return ['image' => Store::get($this->id())['image'] ?? null];
        });
    }

    /**
     * A channel's or a group's picture (`?id=`): for whoever may use the conversations, or for
     * anybody where `conversations.images_public` says so — a desktop application has no cookie.
     */
    public function image(): mixed
    {
        if ((!Images::public() && !Access::canUse($this->me())) || !Images::send((int) ($_GET['id'] ?? 0))) {
            return Response::json(['error' => t('That picture is not here.')], 404);
        }
        $this->terminate();

        return null;
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

    /** One of the team's own emoji, its picture (`?name=`), for whoever may use the conversations. */
    public function emoji(): mixed
    {
        if (!Access::canUse($this->me()) || !\Pramnos\Conversations\CustomEmoji::send((string) ($_GET['name'] ?? ''))) {
            return Response::json(['error' => t('That emoji is not here.')], 404);
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
