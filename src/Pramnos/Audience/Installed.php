<?php

declare(strict_types=1);

namespace Pramnos\Audience;

/**
 * Whether the audience tables are there — so an application that has not run their migrations
 * yet is untouched by them: nothing recorded, nothing queued, no screen in the menu, no error.
 *
 * Asked only where something would read or write them, and remembered for a few minutes — per
 * process, and across requests where a cache is configured — so a page that asks on every view
 * does not ask the database, and a worker notices a `migrate` within minutes.
 */
final class Installed
{
    /** How long an answer is remembered, in seconds. */
    public const REMEMBER = 300;

    /** @var (\Closure(): \Pramnos\Cache\Cache)|null Where answers are kept across requests; tests set their own */
    public static ?\Closure $cache = null;

    /** @var array<string, array{0: bool, 1: int}> By table list: the answer and when it was asked */
    private static array $known = [];

    /** Whether every one of these tables exists. */
    public static function has(string ...$tables): bool
    {
        $key = self::database() . ':' . implode(',', $tables);
        if (isset(self::$known[$key]) && self::$known[$key][1] > time() - self::REMEMBER) {
            return self::$known[$key][0];
        }
        // '' when the database cannot answer: not installed for now, and not remembered as such.
        $ask = static function () use ($tables): string {
            try {
                $schema = \Pramnos\Database\Database::getInstance()->schema();
                foreach ($tables as $table) {
                    if (!$schema->hasTable($table)) {
                        return 'no';
                    }
                }

                return 'yes';
            } catch (\Throwable) {
                return '';
            }
        };
        // Across requests too, where a cache is configured: a page asks this on every view.
        try {
            $said = (string) self::cache()->remember('installed:' . $key, self::REMEMBER, $ask);
        } catch (\Throwable) {
            $said = $ask();
        }
        $answer = $said === 'yes';
        self::$known[$key] = [$answer, time()];

        return $answer;
    }

    /** Segments and their members. */
    public static function segments(): bool
    {
        return self::has('segments', 'segment_members');
    }

    /** What people did, summed. */
    public static function personEvents(): bool
    {
        return self::has('person_events');
    }

    /** Each occurrence of what people did: the change log's application events. */
    public static function eventLog(): bool
    {
        return self::has(PersonEvents::LOG);
    }

    /** Consent lists. */
    public static function consentLists(): bool
    {
        return self::has('consent_lists');
    }

    /** Forget the answers, here and in the cache: tests, and a migration run in the same process. */
    public static function reset(): void
    {
        try {
            self::cache()->clear('audience');
        } catch (\Throwable) {
            // nothing remembered
        }
        self::$known = [];
    }

    /** The cache the answers are kept in. */
    private static function cache(): \Pramnos\Cache\Cache
    {
        return self::$cache !== null ? (self::$cache)() : \Pramnos\Cache\Cache::getInstance('audience');
    }

    /** Which database the answer is about: the same names mean different tables elsewhere. */
    private static function database(): string
    {
        $db = \Pramnos\Database\Database::getInstance();

        return md5($db->type . '|' . $db->server . '|' . $db->port . '|' . $db->database . '|' . $db->prefix);
    }
}
