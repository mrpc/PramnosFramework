<?php

declare(strict_types=1);

namespace Pramnos\Audience;

/**
 * Whether the audience tables are there — so an application that has not run their migrations
 * yet is untouched by them: nothing recorded, nothing queued, no screen in the menu, no error.
 *
 * Asked only where something would read or write them, never on every request, and remembered
 * for a few minutes per process, so a long-running worker notices a `migrate` without asking the
 * database on every event.
 */
final class Installed
{
    /** How long an answer is remembered, in seconds. */
    public const REMEMBER = 300;

    /** @var array<string, array{0: bool, 1: int}> By table list: the answer and when it was asked */
    private static array $known = [];

    /** Whether every one of these tables exists. */
    public static function has(string ...$tables): bool
    {
        $key = implode(',', $tables);
        if (isset(self::$known[$key]) && self::$known[$key][1] > time() - self::REMEMBER) {
            return self::$known[$key][0];
        }
        try {
            $schema = \Pramnos\Database\Database::getInstance()->schema();
            $answer = true;
            foreach ($tables as $table) {
                $answer = $answer && $schema->hasTable($table);
            }
        } catch (\Throwable) {
            $answer = false;
        }
        self::$known[$key] = [$answer, time()];

        return $answer;
    }

    /** Segments and their members. */
    public static function segments(): bool
    {
        return self::has('segments', 'segment_members');
    }

    /** What people did. */
    public static function personEvents(): bool
    {
        return self::has('person_events', 'person_event_log');
    }

    /** Consent lists. */
    public static function consentLists(): bool
    {
        return self::has('consent_lists');
    }

    /** Forget the answers: tests, and a migration run in the same process. */
    public static function reset(): void
    {
        self::$known = [];
    }
}
