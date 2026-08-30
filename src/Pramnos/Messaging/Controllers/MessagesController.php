<?php

declare(strict_types=1);

namespace Pramnos\Messaging\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Framework\Factory;
use Pramnos\Messaging\Message;

/**
 * `/messages` — where an account reads what was sent to it.
 *
 * The other end of a dead end. `messages` has been in the schema since the messaging feature
 * shipped, `MassMessageDispatcher` writes a row per recipient when a broadcast goes out as an
 * internal message, and `Message::countUnread()` counts them — and **nothing displayed any of
 * it**. An operator could compose a message, choose "internal message", watch the progress
 * screen report every recipient delivered, and no recipient could read a word of it. Delivered,
 * in that arrangement, meant "written to a table nobody looks at".
 *
 * That is the failure this screen exists to end, and it is worth being precise about why it
 * survived: every part of the machinery worked. The insert succeeded, the count was right, the
 * admin screen was honest about what it had done. Only the reader was missing, and no test can
 * notice a reader that was never written.
 *
 * ## What it shows
 *
 * The signed-in account's own messages, newest first, unread marked — and one message on its
 * own page, which is where reading it marks it read. Nothing else: no compose, no reply, no
 * folders. An authentication server tells people things; it is not a mail client, and the
 * screens it does not have are screens nobody has to maintain.
 *
 * ## What "unread" is
 *
 * `messages.type` carries the state, and it is overloaded — the same column distinguishes an
 * inbox item from a sent one, an archived one and a deleted one. So the listing names the
 * states it wants rather than excluding the ones it does not: a state added later must not
 * appear in somebody's inbox because a `NOT IN` list was not updated.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class MessagesController extends Controller
{
    /**
     * The states that belong in an inbox listing.
     *
     * Named rather than excluded. `type` also holds sent, archived and deleted, and a
     * `NOT IN (deleted, sent)` would show a state added next year to everybody.
     *
     * @var array<int, int>
     */
    public const INBOX_TYPES = [
        Message::TYPE_READ,
        Message::TYPE_NEW,
        Message::TYPE_UNREAD,
        Message::TYPE_MARKED_READ,
        Message::TYPE_NOTIFICATION_NEW,
        Message::TYPE_NOTIFICATION_READ,
    ];

    /** The states that count as unread. */
    public const UNREAD_TYPES = [
        Message::TYPE_NEW,
        Message::TYPE_UNREAD,
        Message::TYPE_NOTIFICATION_NEW,
    ];

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'show', 'readall']);
        parent::__construct($application);
    }

    /**
     * The account's messages, newest first.
     */
    public function display(): mixed
    {
        $userId = $this->currentUserId();

        if ($userId < 1) {
            $this->redirect(sURL . 'login');

            return null;
        }

        $doc        = Factory::getDocument();
        $doc->title = t('Messages');

        $view           = $this->getView('messages');
        $view->messages = $this->listFor($userId);
        $view->unread   = static::unreadCount($userId);

        return $view->display();
    }

    /**
     * One message — and reading it is what marks it read.
     */
    public function show(mixed $id = null): mixed
    {
        $userId = $this->currentUserId();

        if ($userId < 1) {
            $this->redirect(sURL . 'login');

            return null;
        }

        $messageId = (int) ($id ?: \Pramnos\Http\Request::staticGetOption());
        $message   = $this->loadFor($userId, $messageId);

        if ($message === null) {
            /*
             * Somebody else's message, or none. Answered as "not found" rather than
             * "forbidden", and the ownership check is a `where` rather than a comparison after
             * loading: a message id is a small integer in a URL, and the difference between
             * the two answers is a way to count how many messages exist.
             */
            $this->addError(t('That message is not in your inbox.'));
            $this->redirect(sURL . 'messages');

            return null;
        }

        $this->markRead($userId, $messageId, (int) ($message['type'] ?? 0));

        $doc        = Factory::getDocument();
        $doc->title = (string) ($message['subject'] ?: t('Message'));

        $view          = $this->getView('messages');
        $view->message = $message;

        return $view->display('show');
    }

    /**
     * Mark everything read, for somebody who has just read the list.
     */
    public function readall(): mixed
    {
        $userId = $this->currentUserId();

        if ($userId < 1) {
            $this->redirect(sURL . 'login');

            return null;
        }

        try {
            $this->database()->queryBuilder()
                ->table('#PREFIX#messages')
                ->where('touserid', $userId)
                ->whereIn('type', self::UNREAD_TYPES)
                ->update(['type' => Message::TYPE_READ]);
        } catch (\Throwable $exception) {
            $this->addError(t('Could not mark your messages as read.'));
            \Pramnos\Logs\Logger::log(
                'Marking messages read failed: ' . $exception->getMessage(),
                'messaging'
            );
        }

        $this->redirect(sURL . 'messages');

        return null;
    }

    /**
     * How many unread messages an account has, for a badge in the navigation.
     *
     * Static and cheap, because the navigation asks on every page. Zero when it cannot tell:
     * a badge is not worth an exception on an unrelated screen.
     */
    public static function unreadCount(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        try {
            return (int) \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('#PREFIX#messages')
                ->where('touserid', $userId)
                ->whereIn('type', self::UNREAD_TYPES)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function listFor(int $userId): array
    {
        try {
            $result = $this->database()->queryBuilder()
                ->table('#PREFIX#messages')
                // `excerpt`, not `text`: the body may be in a file, and a listing that
                // opened one per row would decompress two hundred to draw one page.
                ->select(['messageid', 'subject', 'excerpt', 'text', 'type', 'date',
                          'fromuserid', 'html'])
                ->where('touserid', $userId)
                ->whereIn('type', self::INBOX_TYPES)
                ->orderBy('date', 'desc')
                ->limit(200)
                ->get();
        } catch (\Throwable $exception) {
            $this->addError(t('Could not read your messages.'));
            \Pramnos\Logs\Logger::log(
                'Reading the message list failed: ' . $exception->getMessage(),
                'messaging'
            );

            return [];
        }

        $messages = [];

        while (($row = $result->fetch()) !== null) {
            $row = (array) $row;
            $row['isUnread'] = in_array((int) ($row['type'] ?? 0), self::UNREAD_TYPES, true);
            $messages[] = $row;
        }

        return $messages;
    }

    /**
     * One message, if it belongs to this account.
     *
     * @return ?array<string, mixed>
     */
    protected function loadFor(int $userId, int $messageId): ?array
    {
        if ($messageId < 1) {
            return null;
        }

        try {
            $result = $this->database()->queryBuilder()
                ->table('#PREFIX#messages')
                ->where('messageid', $messageId)
                ->where('touserid', $userId)
                ->whereIn('type', self::INBOX_TYPES)
                ->limit(1)
                ->get();
        } catch (\Throwable) {
            return null;
        }

        $row = $result->fetch();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;

        // Here the body *is* wanted — this is the screen that shows it — so it comes back from
        // wherever it was put. One read, for one message somebody asked to open.
        $row['text'] = \Pramnos\Storage\BodyStore::bodyOf($row);

        return $row;
    }

    /**
     * Move an unread message to its read state, leaving everything else alone.
     */
    protected function markRead(int $userId, int $messageId, int $type): void
    {
        if (!in_array($type, self::UNREAD_TYPES, true)) {
            return;
        }

        // A notification stays a notification: the pair of states exists so a screen can tell
        // "something we told you" from "something somebody sent you", and collapsing both into
        // TYPE_READ would lose that on first read.
        $readState = $type === Message::TYPE_NOTIFICATION_NEW
            ? Message::TYPE_NOTIFICATION_READ
            : Message::TYPE_READ;

        try {
            $this->database()->queryBuilder()
                ->table('#PREFIX#messages')
                ->where('messageid', $messageId)
                ->where('touserid', $userId)
                ->update(['type' => $readState]);
        } catch (\Throwable $exception) {
            // Not worth failing the read over. The message is on the screen either way, and
            // the worst outcome is a badge that still counts it.
            \Pramnos\Logs\Logger::log(
                'Marking a message read failed: ' . $exception->getMessage(),
                'messaging'
            );
        }
    }

    protected function currentUserId(): int
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return \Pramnos\Http\Session::staticIsLogged() ? (int) ($user->userid ?? 0) : 0;
    }

    protected function database(): \Pramnos\Database\Database
    {
        return \Pramnos\Framework\Factory::getDatabase();
    }
}
