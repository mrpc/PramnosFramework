<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

use Pramnos\Database\Instant;

/**
 * The assistant's manners when it reaches out on its own, by default: no ping in quiet hours, a
 * few a day at most, and none while the person is busy. Applied once the application sets
 * `'llm' => ['proactivity' => …]` — to a person's own scheduled questions and to calendar
 * reminders.
 *
 * ```php
 * 'llm' => ['proactivity' => ['quiet' => '22:30-07:30', 'daily' => 3]],
 * ```
 *
 * | Rule | Then |
 * |---|---|
 * | inside `quiet` (22:30–07:30), on the person's clock | held until it ends |
 * | `daily` (3) pings already today — scheduled questions it asked and appointment reminders | not this time |
 * | in an appointment now, or busy by their word (`set_busy`, the setting {@see BUSY}) | not this time |
 *
 * An alarm the person set (`critical`) goes whatever the rules say.
 */
class QuietProactivity implements Proactivity
{
    /** The person's own setting: busy until this Unix time. */
    public const BUSY = 'ai_busy_until';

    public const QUIET = '22:30-07:30';
    public const DAILY = 3;

    public function when(int $userId, string $timezone, int $now, bool $critical = false): int
    {
        if ($critical) {
            return $now;
        }
        $zone  = new \DateTimeZone($timezone);
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);

        if (($until = $this->quietUntil($local)) !== null) {
            return $until;
        }
        if ($this->sentToday($userId, $local) >= $this->daily() || $this->busy($userId, $timezone, $now)) {
            return 0;
        }

        return $now;
    }

    /** How many pings a day; from `'llm' => ['proactivity' => ['daily' => …]]`. */
    protected function daily(): int
    {
        $config = \Pramnos\Llm\Llm::config('proactivity', []);

        return is_array($config) && isset($config['daily']) ? max(0, (int) $config['daily']) : self::DAILY;
    }

    /** The end of quiet hours, when `$local` is inside them; null otherwise. */
    protected function quietUntil(\DateTimeImmutable $local): ?int
    {
        $config = \Pramnos\Llm\Llm::config('proactivity', []);
        $span   = is_array($config) && isset($config['quiet']) ? (string) $config['quiet'] : self::QUIET;
        if ($span === '' || preg_match('/^(\d\d):(\d\d)-(\d\d):(\d\d)$/', $span, $m) !== 1) {
            return null;
        }
        $minute = (int) $local->format('G') * 60 + (int) $local->format('i');
        $from   = (int) $m[1] * 60 + (int) $m[2];
        $to     = (int) $m[3] * 60 + (int) $m[4];
        $inside = $from > $to ? ($minute >= $from || $minute < $to) : ($minute >= $from && $minute < $to);
        if (!$inside) {
            return null;
        }
        $end = $local->setTime((int) $m[3], (int) $m[4]);

        return ($end <= $local ? $end->modify('+1 day') : $end)->getTimestamp();
    }

    /**
     * Pings already sent today, on the person's clock: questions it asked, and reminders. The
     * tables are the calendar's; a failure here reaches the caller, which lets
     * the person's schedule go rather than silence it.
     */
    protected function sentToday(int $userId, \DateTimeImmutable $local): int
    {
        $midnight = Instant::utc($local->setTime(0, 0)->getTimestamp());
        $db       = \Pramnos\Framework\Factory::getDatabase();
        $asks     = $db->queryBuilder()->table('pramnos.ai_routines')->where('userid', $userId)->where('kind', 'ask')->pluck('routine_id');
        $asked    = $asks === [] ? 0 : $db->queryBuilder()->table('pramnos.ai_task_runs')
            ->whereIn('routine_id', array_map('intval', $asks))->where('status', 'ok')
            ->where('started_at', '>=', $midnight)->count();

        return $asked + $db->queryBuilder()->table('#PREFIX#chat_calendar')
            ->where('userid', $userId)->where('alerted_at', '>=', $midnight)->count();
    }

    /** In an appointment now, or busy by their own word. */
    protected function busy(int $userId, string $timezone, int $now): bool
    {
        return (int) (new \Pramnos\User\User($userId))->getSetting(self::BUSY, 0) > $now
            || (new \Pramnos\Llm\Chat\Calendar(new \Pramnos\Llm\Chat\Scope($userId, null), $timezone))->busyAt($now);
    }
}
