<?php

declare(strict_types=1);

namespace Pramnos\Messaging;

use Pramnos\Database\Database;

/**
 * Turns a mass message into deliveries, a batch at a time.
 *
 * `massmessages` and `massmessagerecipients` have been in the schema since the messaging
 * feature shipped, with models for both. Nothing wrote to them, nothing read them, and
 * nothing sent anything: an application that wanted to mail its users wrote its own loop.
 *
 * ## Two steps, because they fail differently
 *
 * {@see queue()} resolves an audience into one recipient row per account. It runs once, in
 * the request that composes the message, and it is the step that must not be repeated —
 * a second call would double every delivery, so it refuses a message that already has
 * recipients.
 *
 * {@see dispatch()} takes pending recipients in batches and delivers them. It runs from a
 * command, over and over, and every row it touches is marked before the next is attempted.
 * That is the whole design: a mass send is interrupted — by a timeout, a deploy, a mail
 * server that stops answering halfway — and the only question that matters afterwards is
 * whether it can be resumed without sending anything twice. One row, one status, and the
 * next run picks up whatever is still pending.
 *
 * ## What "delivered" means
 *
 * | Type | Delivery | Failure |
 * | --- | --- | --- |
 * | `TYPE_EMAIL` | one email per recipient, through the configured wrapper | the mailer refused it |
 * | `TYPE_MESSAGE` | a row in `messages`, which is the account's own inbox | the write failed |
 * | `TYPE_PUSH` | nothing — the framework has no push transport | every recipient, with a reason |
 *
 * Push is refused rather than silently skipped. An operator who chose it is owed the
 * answer "there is no transport for this", not a message that reports itself sent to
 * nobody.
 *
 * ## Scheduling
 *
 * A message with `scheduled` in the future is not picked up until then. `scheduled = 0`
 * means now. Nothing else about the two paths differs, which is why there is no separate
 * scheduler: the dispatcher runs on a timer anyway, and a scheduled message is one that is
 * not yet its turn.
 */
class MassMessageDispatcher
{
    /** How many recipients one `dispatch()` call attempts. */
    public const DEFAULT_BATCH = 100;

    private Database $database;

    public function __construct(?Database $database = null)
    {
        $this->database = $database ?? Database::getInstance();
    }

    /**
     * Write one recipient row per account, and record the total.
     *
     * Refuses a message that already has recipients: `queue()` is the step that cannot be
     * repeated, and "run it twice by accident" is the failure that reaches every person on
     * the list rather than a log.
     *
     * @param  int       $messageId The `massmessages` row.
     * @param  iterable<int> $userIds   The audience, already resolved.
     * @return int       Rows written.
     */
    public function queue(int $messageId, iterable $userIds): int
    {
        if ($messageId < 1) {
            return 0;
        }

        if ($this->recipientCount($messageId) > 0) {
            return 0;
        }

        $written = 0;
        $seen    = [];

        foreach ($userIds as $userId) {
            $userId = (int) $userId;

            // A duplicate in the audience is one person mailed twice, and an audience is
            // assembled from queries somebody wrote — a join that fans out is the usual
            // way this happens.
            if ($userId < 1 || isset($seen[$userId])) {
                continue;
            }

            $seen[$userId] = true;

            $this->database->queryBuilder()
                ->table('#PREFIX#massmessagerecipients')
                ->insert([
                    'messageid' => $messageId,
                    'userid'    => $userId,
                    'status'    => MassMessageRecipient::STATUS_PENDING,
                ]);

            $written++;
        }

        $this->database->queryBuilder()
            ->table('#PREFIX#massmessages')
            ->where('messageid', $messageId)
            ->update(['totalrecipients' => $written]);

        return $written;
    }

    /**
     * Deliver up to `$limit` pending recipients across all due messages.
     *
     * @return array{attempted:int,delivered:int,failed:int}
     */
    public function dispatch(int $limit = self::DEFAULT_BATCH): array
    {
        $stats = ['attempted' => 0, 'delivered' => 0, 'failed' => 0];

        foreach ($this->dueMessages() as $message) {
            if ($stats['attempted'] >= $limit) {
                break;
            }

            $remaining = $limit - $stats['attempted'];
            $batch     = $this->pendingRecipients((int) $message['messageid'], $remaining);

            foreach ($batch as $recipient) {
                $stats['attempted']++;

                if ($this->deliver($message, (int) $recipient['userid'])) {
                    $this->markRecipient(
                        (int) $recipient['recipientid'],
                        MassMessageRecipient::STATUS_DELIVERED
                    );
                    $stats['delivered']++;
                } else {
                    $this->markRecipient(
                        (int) $recipient['recipientid'],
                        MassMessageRecipient::STATUS_FAILED
                    );
                    $stats['failed']++;
                }
            }

            // Sent when nothing is pending — including when everything failed. The header's
            // status answers "is this still going", and the counts answer "did it work";
            // conflating them would leave a message that can never finish.
            if ($this->pendingCount((int) $message['messageid']) === 0) {
                $this->database->queryBuilder()
                    ->table('#PREFIX#massmessages')
                    ->where('messageid', (int) $message['messageid'])
                    ->update(['status' => MassMessage::STATUS_SENT]);
            }
        }

        return $stats;
    }

    /**
     * How a message stands: its recipients by status.
     *
     * For the screen, and for a test that wants the answer without parsing one.
     *
     * @return array{total:int,pending:int,delivered:int,failed:int}
     */
    public function progress(int $messageId): array
    {
        $counts = [
            'total'     => 0,
            'pending'   => 0,
            'delivered' => 0,
            'failed'    => 0,
        ];

        try {
            $result = $this->database->queryBuilder()
                ->table('#PREFIX#massmessagerecipients')
                ->select(['status', 'COUNT(*) AS total'])
                ->where('messageid', $messageId)
                ->groupBy('status')
                ->get();
        } catch (\Throwable) {
            return $counts;
        }

        while (($row = $result->fetch()) !== null) {
            $count = (int) ($row['total'] ?? 0);
            $counts['total'] += $count;

            $counts[match ((int) ($row['status'] ?? 0)) {
                MassMessageRecipient::STATUS_DELIVERED => 'delivered',
                MassMessageRecipient::STATUS_FAILED    => 'failed',
                default                                => 'pending',
            }] += $count;
        }

        return $counts;
    }

    // ── Delivery ─────────────────────────────────────────────────────────────

    /**
     * One recipient, by the message's type.
     */
    protected function deliver(array $message, int $userId): bool
    {
        $type = (int) ($message['type'] ?? MassMessage::TYPE_EMAIL);

        return match ($type) {
            MassMessage::TYPE_MESSAGE => $this->deliverAsMessage($message, $userId),
            MassMessage::TYPE_EMAIL   => $this->deliverAsEmail($message, $userId),
            // No transport, so no pretending. See the class docblock.
            default                   => false,
        };
    }

    /**
     * Mail it, in the recipient's own language and the installation's wrapper.
     */
    protected function deliverAsEmail(array $message, int $userId): bool
    {
        $user = new \Pramnos\User\User($userId);

        if ((int) $user->userid !== $userId || trim((string) $user->email) === '') {
            return false;
        }

        return (bool) \Pramnos\Translator\Language::using(
            trim((string) $user->language),
            function () use ($message, $user): bool {
                $mailer = new \Pramnos\Email\Email();
                $mailer->subject = (string) ($message['subject'] ?? '');
                $mailer->body    = (string) ($message['message'] ?? '');
                $mailer->to      = (string) $user->email;
                $mailer->module  = 'massmessage';

                return (bool) $mailer->send();
            }
        );
    }

    /**
     * Put it in the account's own inbox.
     */
    protected function deliverAsMessage(array $message, int $userId): bool
    {
        try {
            $this->database->queryBuilder()
                ->table('#PREFIX#messages')
                ->insert([
                    'massid'     => (int) $message['messageid'],
                    'type'       => Message::TYPE_NEW,
                    'subject'    => (string) ($message['subject'] ?? ''),
                    'text'       => (string) ($message['message'] ?? ''),
                    'fromuserid' => (int) ($message['sender'] ?? 0) ?: null,
                    'touserid'   => $userId,
                    'date'       => time(),
                    'html'       => 1,
                    // `messages.attachmenttext` is TEXT with no default, which under MySQL's
                    // strict mode is NOT NULL with nothing to fall back on: omitting it makes
                    // the insert fail, and the failure is caught here — so every recipient
                    // would be recorded as failed with the reason only in a log.
                    'attachmenttext' => '',
                ]);

            return true;
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'MassMessageDispatcher could not write a message for ' . $userId . ': '
                . $exception->getMessage(),
                'messaging'
            );

            return false;
        }
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * Messages that are due: pending or scheduled, and not scheduled for later.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function dueMessages(): array
    {
        $messages = [];

        try {
            $result = $this->database->queryBuilder()
                ->table('#PREFIX#massmessages')
                ->whereIn('status', [MassMessage::STATUS_PENDING, MassMessage::STATUS_SCHEDULED])
                ->where('scheduled', '<=', time())
                ->orderBy('messageid', 'asc')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        while (($row = $result->fetch()) !== null) {
            $messages[] = (array) $row;
        }

        return $messages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function pendingRecipients(int $messageId, int $limit): array
    {
        $rows = [];

        try {
            $result = $this->database->queryBuilder()
                ->table('#PREFIX#massmessagerecipients')
                ->where('messageid', $messageId)
                ->where('status', MassMessageRecipient::STATUS_PENDING)
                ->orderBy('recipientid', 'asc')
                ->limit(max(1, $limit))
                ->get();
        } catch (\Throwable) {
            return [];
        }

        while (($row = $result->fetch()) !== null) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    protected function pendingCount(int $messageId): int
    {
        try {
            return (int) $this->database->queryBuilder()
                ->table('#PREFIX#massmessagerecipients')
                ->where('messageid', $messageId)
                ->where('status', MassMessageRecipient::STATUS_PENDING)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function recipientCount(int $messageId): int
    {
        try {
            return (int) $this->database->queryBuilder()
                ->table('#PREFIX#massmessagerecipients')
                ->where('messageid', $messageId)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function markRecipient(int $recipientId, int $status): void
    {
        try {
            $this->database->queryBuilder()
                ->table('#PREFIX#massmessagerecipients')
                ->where('recipientid', $recipientId)
                ->update(['status' => $status]);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'MassMessageDispatcher could not mark recipient ' . $recipientId . ': '
                . $exception->getMessage(),
                'messaging'
            );
        }
    }
}
