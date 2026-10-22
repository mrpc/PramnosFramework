<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Moderation\Moderation;
use Pramnos\Moderation\Subject;

/**
 * The moderators' `/commands` in a conversation, where the application has the `moderation` feature:
 *
 * | command | does |
 * |---|---|
 * | `/mute @Name [10m] [why]` | they read, but do not write here — ten minutes unless said |
 * | `/unmute @Name` | they write again |
 * | `/ban @Name [1d] [why]` | they do not write here — for good unless said |
 * | `/unban @Name` | lifted |
 * | `/kick @Name [why]` | put out now; they may come back |
 * | `/slow 30s` · `/slow off` | one message per person every so often |
 *
 * A duration is `45s`, `10m`, `2h` or `3d`. Only whoever moderates the conversation
 * ({@see Access::moderates()}) runs them; the answer is theirs alone, and the log keeps it.
 */
final class ModeratorCommands
{
    /** The commands, registered — the conversations' service provider does it. */
    public static function register(): void
    {
        foreach ([
            'mute'   => [t('Stop somebody writing here for a while'), '@Name [10m] [why]'],
            'unmute' => [t('Let somebody write here again'), '@Name'],
            'ban'    => [t('Stop somebody writing here, for good or for a while'), '@Name [1d] [why]'],
            'unban'  => [t('Lift a ban here'), '@Name'],
            'kick'   => [t('Put somebody out of here now'), '@Name [why]'],
            'slow'   => [t('One message per person every so often'), '30s | off'],
        ] as $name => [$description, $usage]) {
            Commands::register($name, $description, static fn (string $args, int $conversation, object $person): string
                => self::run($name, $args, $conversation, $person), $usage);
        }
    }

    /**
     * Run one of them.
     *
     * @throws \InvalidArgumentException With what was wrong, for the moderator
     */
    public static function run(string $name, string $args, int $conversation, object $person): string
    {
        if (!Access::moderates($person, $conversation)) {
            throw new \InvalidArgumentException(t('Only a moderator of this conversation does that.'));
        }
        $scope = 'conversation:' . $conversation;
        $by    = (int) $person->userid;
        if ($name === 'slow') {
            $seconds = strtolower(trim($args)) === 'off' ? 0 : (self::duration(trim($args)) ?? throw new \InvalidArgumentException(t('Say how often: 30s, 2m, or off.')));
            Moderation::slowMode($scope, $seconds, $by);

            return $seconds === 0 ? t('Slow mode is off.') : t('Slow mode: one message per person every %s seconds.', (string) $seconds);
        }
        if (!preg_match('/^<@(\d+)>\s*(?:(\d+[smhd])\b\s*)?(.*)$/is', trim($args), $m)) {
            throw new \InvalidArgumentException(t('Name somebody with @.'));
        }
        $who    = (int) $m[1];
        $named  = People::names([$who])[$who] ?? ('#' . $who);
        $for    = $m[2] !== '' ? self::duration($m[2]) : null;
        $reason = trim($m[3]);
        $whom   = Subject::account($who);
        if ($who === $by) {
            throw new \InvalidArgumentException(t('You cannot moderate yourself.'));
        }

        switch ($name) {
            case 'mute':
                Moderation::mute($whom, $scope, $for ?? 600, $reason, $by);

                return t('%s cannot write here for %s seconds.', $named, (string) ($for ?? 600));
            case 'ban':
                Moderation::ban($whom, $scope, $for, $reason, $by);

                return $for === null ? t('%s is banned here.', $named) : t('%s is banned here for %s seconds.', $named, (string) $for);
            case 'unmute':
                return Moderation::lift($whom, $scope, 'mute', $by) > 0 ? t('%s can write here again.', $named) : t('%s was not muted here.', $named);
            case 'unban':
                return Moderation::lift($whom, $scope, 'ban', $by) > 0 ? t('%s is no longer banned here.', $named) : t('%s was not banned here.', $named);
            default:
                Moderation::kick($whom, $scope, $reason, $by);

                return t('%s was put out of here.', $named);
        }
    }

    /** `45s`, `10m`, `2h`, `3d` as seconds; null for anything else. */
    public static function duration(string $text): ?int
    {
        if (!preg_match('/^(\d+)([smhd])$/i', trim($text), $m) || (int) $m[1] === 0) {
            return null;
        }

        return (int) $m[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][strtolower($m[2])];
    }
}
