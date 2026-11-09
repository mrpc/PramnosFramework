<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * What a conversation does by itself, as Slack's workflows: a message sent again on a schedule, a
 * welcome to whoever comes in, a question put to the analyst on a schedule.
 *
 * ```php
 * Automations::add($general, $me, ['kind' => 'repeat', 'body' => 'Stand-up!', 'every' => 'weekdays', 'time' => '09:30']);
 * Automations::add($general, $me, ['kind' => 'repeat', 'body' => '…', 'every' => 'week', 'days' => [1, 4], 'time' => '17:00', 'sender' => 'creator']);
 * Automations::add($general, $me, ['kind' => 'repeat', 'body' => '…', 'every' => 'month', 'day' => 1, 'time' => '10:00']);
 * Automations::add($general, $me, ['kind' => 'welcome', 'body' => 'Καλώς ήρθες!', 'to' => 'person']);   // or 'channel'
 * Automations::add($general, $me, ['kind' => 'ask', 'body' => 'Πόσες εγγραφές χθες;', 'every' => 'day', 'time' => '08:00']);
 * Automations::of($general);  Automations::change($id, $me, [...]);  Automations::pause($id, $me);  Automations::delete($id, $me);
 * Automations::due();         // `conversations:send-scheduled`, every minute
 * ```
 *
 * - **Who:** whoever manages the conversation's tabs ({@see Tabs::manages()}) — its admins, or
 *   every member where `tabsBy` is `everyone`.
 * - **Times** are on the installation's clock (PHP's zone), so 09:30 stays 09:30 across a change
 *   of summer time. A run missed while the server was down is not made up: the next is the next
 *   one after now.
 * - **From whom:** `sender` `automation` (the default) posts as the conversation's "Automation";
 *   `creator` as whoever set it up, while they are in the conversation. A question is always
 *   asked as them, with the rights `@Ask` has there, and costed to them.
 * - **A welcome** is sent each time somebody comes in ({@see Events\MemberJoined}): to them alone,
 *   in their conversation with the system (or a direct message from its creator), or in the
 *   conversation, mentioning them.
 * - Changes are `conversation.automation_*` events in the changelog; the members' pages are told
 *   (`conversation.changed`). Deleting the conversation deletes its automations.
 */
final class Automations
{
    /** The kinds. */
    public const KINDS = ['repeat', 'welcome', 'ask'];

    /** How often. */
    public const EVERY = ['day', 'weekdays', 'week', 'month'];

    /**
     * The automations of a conversation, in the order made.
     *
     * @return list<array<string, mixed>>
     */
    public static function of(int $conversationId): array
    {
        if (!self::installed()) {
            return [];
        }
        $rows  = self::table()->where('conversationid', $conversationId)->orderBy('automationid')->getAllOrFail();
        $names = People::names(array_map('intval', array_column($rows, 'createdby')));

        return array_map(static fn (array $r): array => self::row($r) + ['createdByName' => $names[(int) $r['createdby']] ?? null], $rows);
    }

    /** One automation, or null. @return array<string, mixed>|null */
    public static function get(int $id): ?array
    {
        $row = self::installed() ? (self::table()->where('automationid', $id)->limit(1)->getAllOrFail()[0] ?? null) : null;

        return $row === null ? null : self::row($row);
    }

    /**
     * Set one up.
     *
     * @param array<string, mixed> $fields `kind`, `body`, `sender`; `every`, `days`, `day`, `time` for a schedule; `to` for a welcome
     * @return int The automation
     * @throws \DomainException          For somebody who may not
     * @throws \InvalidArgumentException With what is wrong
     */
    public static function add(int $conversationId, int $by, array $fields): int
    {
        self::mayManage($conversationId, $by);
        $kind = (string) ($fields['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(t('An automation repeats a message, welcomes, or asks the analyst.'));
        }
        $row = self::checked(['kind' => $kind, 'createdby' => $by], $fields);
        $db  = Conversations::db();
        $insert = self::table();
        if ($db->type === 'postgresql') {
            $insert->returning('automationid');
        }
        $result = $insert->insert($row + ['conversationid' => $conversationId, 'kind' => $kind, 'createdby' => $by, 'createdat' => time(), 'paused' => 0]);
        $id     = $db->type === 'postgresql' ? (int) ($result->fields['automationid'] ?? 0) : (int) $db->getInsertId();
        self::changed($conversationId, $by, 'conversation.automation_added', ['automation' => $id, 'kind' => $kind]);

        return $id;
    }

    /**
     * Change one: any of the fields `add()` takes, but its kind. One that could not send is tried
     * again.
     *
     * @param array<string, mixed> $fields
     */
    public static function change(int $id, int $by, array $fields): void
    {
        $current = self::managed($id, $by);
        $merged  = array_merge(['body' => $current['body'], 'sender' => $current['sender']], $current['schedule'] ?? [],
            $current['to'] !== null ? ['to' => $current['to']] : [], $fields);
        $row = self::checked(['kind' => $current['kind'], 'createdby' => $current['createdBy']], $merged);
        self::table()->where('automationid', $id)->update($row + ['error' => null]);
        self::changed($current['conversationId'], $by, 'conversation.automation_changed', ['automation' => $id, 'fields' => array_keys($fields)]);
    }

    /** Pause one, or let it go on: a scheduled one goes on from its next time after now. */
    public static function pause(int $id, int $by, bool $paused = true): void
    {
        $current = self::managed($id, $by);
        $update  = ['paused' => $paused ? 1 : 0];
        if (!$paused && $current['schedule'] !== null) {
            $update['nextat'] = self::next($current['schedule'], time());
        }
        self::table()->where('automationid', $id)->update($update);
        self::changed($current['conversationId'], $by, $paused ? 'conversation.automation_paused' : 'conversation.automation_resumed', ['automation' => $id]);
    }

    /** Delete one. */
    public static function delete(int $id, int $by): void
    {
        $current = self::managed($id, $by);
        self::table()->where('automationid', $id)->delete();
        self::changed($current['conversationId'], $by, 'conversation.automation_deleted', ['automation' => $id, 'kind' => $current['kind']]);
    }

    /**
     * Run the scheduled ones that are due, each moved on to its next time first — so one that
     * fails is not run again every minute. One that cannot send keeps why, for its managers.
     *
     * @return int How many ran
     */
    public static function due(?int $now = null): int
    {
        if (!self::installed()) {
            return 0;
        }
        $now ??= time();
        $ran = 0;
        foreach (self::table()->where('paused', 0)->whereNotNull('nextat')->where('nextat', '<=', $now)->orderBy('nextat')->limit(200)->getAllOrFail() as $raw) {
            $a = self::row($raw);
            self::table()->where('automationid', $a['id'])->update(['nextat' => self::next($a['schedule'], $now)]);
            try {
                $conversation = Conversations::get($a['conversationId']);
                if ($conversation === null || $conversation['archivedAt'] !== null) {
                    continue;
                }
                $a['kind'] === 'ask' ? self::ask($a) : self::send($a, $a['body']);
                self::table()->where('automationid', $a['id'])->update(['error' => null]);
                $ran++;
            } catch (\Throwable $e) {
                self::table()->where('automationid', $a['id'])->update(['error' => mb_substr($e instanceof \InvalidArgumentException ? $e->getMessage() : t('It could not be sent.'), 0, 255)]);
                if (!$e instanceof \InvalidArgumentException) {
                    \Pramnos\Logs\Logger::log('Conversations: automation ' . $a['id'] . ' did not run: ' . $e->getMessage(), 'queue');
                }
            }
        }

        return $ran;
    }

    /**
     * Welcome somebody who came in, with each welcome the conversation has that is not paused.
     * A welcome that cannot be sent does not stop them coming in.
     */
    public static function welcome(int $conversationId, int $person): void
    {
        if (!self::installed()) {
            return;
        }
        foreach (self::table()->where('conversationid', $conversationId)->where('kind', 'welcome')->where('paused', 0)->getAllOrFail() as $raw) {
            $a = self::row($raw);
            if ($a['createdBy'] === $person) {
                continue;
            }
            try {
                if ($a['to'] === 'channel') {
                    self::send($a, '<@' . $person . '> ' . $a['body']);
                } elseif ($a['sender'] === 'creator') {
                    $dm = Conversations::dmBetween($a['createdBy'], $person) ?? Conversations::create('dm', null, [$a['createdBy'], $person], $a['createdBy']);
                    Messages::post($dm, $a['createdBy'], $a['body']);
                } else {
                    // In their conversation with the system, signed with the conversation's name.
                    $own = SystemPost::conversation($person);
                    if ($own !== null) {
                        Messages::post($own, null, $a['body'], ['card' => ['from' => Conversations::names([$conversationId])[$conversationId] ?? t('Automation')]]);
                    }
                }
            } catch (\Throwable $e) {
                self::table()->where('automationid', $a['id'])->update(['error' => mb_substr($e instanceof \InvalidArgumentException ? $e->getMessage() : t('It could not be sent.'), 0, 255)]);
            }
        }
    }

    /**
     * The next time a schedule falls after `$after`, on the installation's clock.
     *
     * @param array{every: string, days?: list<int>, day?: int, time: string} $schedule
     */
    public static function next(array $schedule, int $after, ?\DateTimeZone $zone = null): int
    {
        $zone ??= new \DateTimeZone(date_default_timezone_get());
        [$hour, $minute] = array_map('intval', explode(':', $schedule['time']));
        $midnight = (new \DateTimeImmutable('@' . $after))->setTimezone($zone)->setTime(0, 0);
        for ($i = 0; $i <= 62; $i++) {
            $day     = $midnight->modify('+' . $i . ' day');
            $weekday = (int) $day->format('N');
            $falls   = match ($schedule['every']) {
                'weekdays' => $weekday <= 5,
                'week'     => in_array($weekday, $schedule['days'] ?? [], true),
                'month'    => (int) $day->format('j') === min((int) ($schedule['day'] ?? 1), (int) $day->format('t')),
                default    => true,
            };
            $at = $day->setTime($hour, $minute)->getTimestamp();
            if ($falls && $at > $after) {
                return $at;
            }
        }

        // @codeCoverageIgnoreStart
        throw new \LogicException('A schedule with no day in two months.');
        // @codeCoverageIgnoreEnd
    }

    /** Whether the table is there. */
    public static function installed(): bool
    {
        // Known once per connection — a welcome asks on every join — and only once it is there.
        static $installed = null;
        $installed ??= new \WeakMap();
        $db = Conversations::db();
        if (!isset($installed[$db]) && $db->schema()->hasTable('chat.conversation_automations')) {
            $installed[$db] = true;
        }

        return isset($installed[$db]);
    }

    /** Post a message as the automation, or as its creator while they are in the conversation. @param array<string, mixed> $a */
    private static function send(array $a, string $body): void
    {
        if ($a['sender'] === 'creator') {
            if (Conversations::member($a['conversationId'], $a['createdBy']) === null) {
                throw new \InvalidArgumentException(t('Its creator is no longer in the conversation.'));
            }
            Messages::post($a['conversationId'], $a['createdBy'], $body);

            return;
        }
        Messages::post($a['conversationId'], null, $body, ['card' => ['from' => t('Automation')]]);
    }

    /** Put the question to the analyst as its creator, answered in the message's thread. @param array<string, mixed> $a */
    private static function ask(array $a): void
    {
        $person = new \Pramnos\User\User($a['createdBy']);
        if (!Ask::canAsk($person) || Conversations::member($a['conversationId'], $a['createdBy']) === null) {
            throw new \InvalidArgumentException(t('Its creator may no longer ask the analyst here.'));
        }
        Ask::start(Messages::post($a['conversationId'], $a['createdBy'], '@' . Ask::NAME . ' ' . $a['body']), $person);
    }

    /**
     * The columns of a valid automation, or why not.
     *
     * @param array{kind: string, createdby: int} $is
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function checked(array $is, array $fields): array
    {
        $body = trim((string) ($fields['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > Messages::MAX_LENGTH) {
            throw new \InvalidArgumentException($is['kind'] === 'ask' ? t('An automation asks a question.') : t('An automation needs a message.'));
        }
        $sender = $is['kind'] === 'ask' ? 'creator' : (string) ($fields['sender'] ?? 'automation');
        if (!in_array($sender, ['automation', 'creator'], true)) {
            throw new \InvalidArgumentException(t('A message is from the automation or from whoever set it up.'));
        }
        if ($is['kind'] === 'welcome') {
            $to = (string) ($fields['to'] ?? 'person');
            if (!in_array($to, ['person', 'channel'], true)) {
                throw new \InvalidArgumentException(t('A welcome goes to the newcomer alone or to the conversation.'));
            }

            return ['body' => $body, 'sender' => $sender, 'options' => (string) json_encode(['to' => $to]), 'nextat' => null];
        }
        if ($is['kind'] === 'ask' && !Ask::canAsk(new \Pramnos\User\User($is['createdby']))) {
            throw new \InvalidArgumentException(t('Only somebody who may ask the analyst sets it a question.'));
        }
        $every = (string) ($fields['every'] ?? '');
        $time  = (string) ($fields['time'] ?? '');
        $days  = array_values(array_unique(array_map('intval', (array) ($fields['days'] ?? []))));
        $day   = (int) ($fields['day'] ?? 0);
        if (!in_array($every, self::EVERY, true)) {
            throw new \InvalidArgumentException(t('It repeats every day, on weekdays, every week or every month.'));
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new \InvalidArgumentException(t('A time is written as 09:30.'));
        }
        if ($every === 'week' && ($days === [] || array_diff($days, range(1, 7)) !== [])) {
            throw new \InvalidArgumentException(t('Every week, on which days?'));
        }
        if ($every === 'month' && ($day < 1 || $day > 31)) {
            throw new \InvalidArgumentException(t('Every month, on which day?'));
        }
        sort($days);
        $schedule = ['every' => $every, 'time' => $time] + ($every === 'week' ? ['days' => $days] : []) + ($every === 'month' ? ['day' => $day] : []);

        return ['body' => $body, 'sender' => $sender, 'options' => (string) json_encode($schedule), 'nextat' => self::next($schedule, time())];
    }

    /** @return array<string, mixed> One the person manages, or why not */
    private static function managed(int $id, int $by): array
    {
        $current = self::get($id) ?? throw new \DomainException(t('That automation no longer exists.'));
        self::mayManage($current['conversationId'], $by);

        return $current;
    }

    /** @throws \DomainException For somebody who may not */
    private static function mayManage(int $conversationId, int $by): void
    {
        if (!self::installed()) {
            throw new \DomainException(t('Automations are not installed here yet: run migrate.'));
        }
        if (!Tabs::manages($conversationId, $by)) {
            throw new \DomainException(t('Only its admins manage this conversation\'s automations.'));
        }
    }

    /** Logged and told. @param array<string, mixed> $data */
    private static function changed(int $conversationId, int $by, string $event, array $data): void
    {
        \Pramnos\Changelog\ChangelogWriter::event('conversation', $conversationId, $event, $data, by: $by);
        Realtime::conversationChanged($conversationId, Conversations::members($conversationId));
    }

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    private static function row(array $raw): array
    {
        $options  = (array) json_decode((string) ($raw['options'] ?? ''), true);
        $schedule = $raw['kind'] === 'welcome' ? null : ['every' => (string) ($options['every'] ?? 'day'), 'time' => (string) ($options['time'] ?? '09:00')]
            + (isset($options['days']) ? ['days' => array_map('intval', (array) $options['days'])] : []) + (isset($options['day']) ? ['day' => (int) $options['day']] : []);

        return ['id' => (int) $raw['automationid'], 'conversationId' => (int) $raw['conversationid'], 'kind' => (string) $raw['kind'],
            'body' => (string) $raw['body'], 'sender' => (string) $raw['sender'], 'schedule' => $schedule,
            'to' => $raw['kind'] === 'welcome' ? (string) ($options['to'] ?? 'person') : null, 'createdBy' => (int) $raw['createdby'],
            'createdAt' => (int) $raw['createdat'], 'nextAt' => $raw['nextat'] === null ? null : (int) $raw['nextat'],
            'paused' => (bool) $raw['paused'], 'error' => $raw['error'] === null ? null : (string) $raw['error']];
    }

    /** The table. */
    private static function table(): \Pramnos\Database\QueryBuilder
    {
        return Conversations::db()->queryBuilder()->table('chat.conversation_automations');
    }
}
