<?php

declare(strict_types=1);

namespace Pramnos\Moderation;

/**
 * Moderation: who may not be somewhere (a ban), who may be there but not write (a mute), who is
 * put out for now (a kick), a scope's slow mode, and every one of these written down (the log). The
 * `moderation` feature's tables.
 *
 * ```php
 * $who = [Subject::account(48211), Subject::ip($ip)];
 * Moderation::mute(Subject::account(48211), 'conversation:12', 600, 'Ύβρεις', $me);  // ten minutes
 * Moderation::ban(Subject::ip('203.0.113.4'), '', null, 'Spam');                  // everywhere, for good
 * Moderation::restriction($who, 'conversation:12');   // ['effect' => 'mute', 'until' => …] or null
 * Moderation::slowMode('conversation:12', 30);         // one message per person every 30 seconds
 * ```
 *
 * A scope is the application's word for where — `conversation:12`, `comments:article:9`; the empty
 * scope is everywhere, and a restriction there holds in every scope. What a person writes is let
 * through or refused by {@see Gate}; reports are {@see Reports}.
 *
 * The rules are `moderation` in `app.php`, each with its default:
 *
 * ```php
 * 'moderation' => [
 *     'rate'   => ['messages' => 10, 'per' => 60],   // a burst of ten, then one each six seconds
 *     'filter' => [...],                             // see Filter
 * ],
 * ```
 */
final class Moderation
{
    /** The defaults of `moderation` in `app.php`. */
    private const DEFAULTS = [
        'rate'   => ['messages' => 10, 'per' => 60],
        'filter' => [],
    ];

    /** One of the rules: the application's `moderation.$name`, over its defaults. */
    public static function rule(string $name): array
    {
        $given = \Pramnos\Application\Application::currentInstance()?->applicationInfo['moderation'][$name] ?? [];

        return (is_array($given) ? $given : []) + (self::DEFAULTS[$name] ?? []);
    }

    /**
     * Keep a subject out of a scope — everywhere, with the empty scope — for `$seconds`, or for good.
     *
     * @return int The restriction
     */
    public static function ban(Subject $subject, string $scope = '', ?int $seconds = null, string $reason = '', ?int $by = null): int
    {
        return self::restrict('ban', $subject, $scope, $seconds, $reason, $by);
    }

    /**
     * Let a subject be in a scope but not write there — a timeout — for `$seconds`, or for good.
     *
     * @return int The restriction
     */
    public static function mute(Subject $subject, string $scope = '', ?int $seconds = null, string $reason = '', ?int $by = null): int
    {
        return self::restrict('mute', $subject, $scope, $seconds, $reason, $by);
    }

    /**
     * Lift a subject's restrictions of an effect in a scope — `ban`, `mute`, or both with null.
     *
     * @return int How many were lifted
     */
    public static function lift(Subject $subject, string $scope = '', ?string $effect = null, ?int $by = null): int
    {
        $now   = time();
        $query = self::table('moderation_restrictions')->where('subjectkind', $subject->kind)->where('subject', $subject->value)
            ->where('scope', $scope)->whereNull('liftedat')
            ->where(static function ($q) use ($now): void {
                $q->whereNull('expiresat')->orWhere('expiresat', '>', $now);
            });
        if ($effect !== null) {
            $query->where('effect', $effect);
        }
        $ids = array_map('intval', array_column($query->select(['id'])->getAllOrFail(), 'id'));
        if ($ids !== []) {
            self::table('moderation_restrictions')->whereIn('id', $ids)->update(['liftedat' => $now, 'liftedby' => $by]);
        }
        $lifted = count($ids);
        if ($lifted > 0) {
            self::log('lift', $subject, $scope, $by, '', ['effect' => $effect]);
        }

        return $lifted;
    }

    /**
     * The strongest restriction in force on any of these subjects in a scope — a ban over a mute,
     * then the one that lasts longest — or null. A restriction everywhere holds in every scope.
     *
     * @param list<Subject> $subjects Who is asking: their account or visitor key, their address, their nickname
     * @return array{id: int, effect: string, scope: string, until: ?int, reason: string}|null
     */
    public static function restriction(array $subjects, string $scope = '', ?int $now = null): ?array
    {
        $now = $now ?? time();
        $subjects = array_values(array_filter($subjects, static fn ($s): bool => $s instanceof Subject));
        if ($subjects === []) {
            return null;
        }
        $rows = self::table('moderation_restrictions')->whereIn('scope', array_values(array_unique(['', $scope])))->whereNull('liftedat')
            ->where(static function ($q) use ($now): void {
                $q->whereNull('expiresat')->orWhere('expiresat', '>', $now);
            })
            ->where(static function ($q) use ($subjects): void {
                foreach ($subjects as $subject) {
                    $q->orWhere(static function ($one) use ($subject): void {
                        $one->where('subjectkind', $subject->kind)->where('subject', $subject->value);
                    });
                }
            })->getAllOrFail();
        if ($rows === []) {
            return null;
        }
        usort($rows, static fn (array $a, array $b): int => [$b['effect'] === 'ban', $b['expiresat'] === null, (int) $b['expiresat']]
            <=> [$a['effect'] === 'ban', $a['expiresat'] === null, (int) $a['expiresat']]);
        $row = $rows[0];

        return ['id' => (int) $row['id'], 'effect' => (string) $row['effect'], 'scope' => (string) $row['scope'],
            'until' => $row['expiresat'] === null ? null : (int) $row['expiresat'], 'reason' => (string) $row['reason']];
    }

    /**
     * The restrictions in force, newest first — everywhere and in a scope, or only one subject's.
     *
     * @return list<array{id: int, effect: string, scope: string, subjectKind: string, subject: string, reason: string, by: ?int, at: int, until: ?int}>
     */
    public static function restrictions(?string $scope = null, ?Subject $subject = null, int $limit = 100): array
    {
        $now   = time();
        $query = self::table('moderation_restrictions')->whereNull('liftedat')
            ->where(static function ($q) use ($now): void {
                $q->whereNull('expiresat')->orWhere('expiresat', '>', $now);
            });
        if ($scope !== null) {
            $query->where('scope', $scope);
        }
        if ($subject !== null) {
            $query->where('subjectkind', $subject->kind)->where('subject', $subject->value);
        }

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'effect' => (string) $r['effect'], 'scope' => (string) $r['scope'],
            'subjectKind' => (string) $r['subjectkind'], 'subject' => (string) $r['subject'], 'reason' => (string) $r['reason'],
            'by' => $r['createdby'] === null ? null : (int) $r['createdby'], 'at' => (int) $r['createdat'],
            'until' => $r['expiresat'] === null ? null : (int) $r['expiresat']],
            $query->orderBy('id', 'desc')->limit(max(1, min(500, $limit)))->getAllOrFail());
    }

    /**
     * Put a subject out of a scope now — they may come back. Told as `moderation.kicked`, for the
     * realtime layer to close what they have open there.
     */
    public static function kick(Subject $subject, string $scope = '', string $reason = '', ?int $by = null): void
    {
        self::log('kick', $subject, $scope, $by, $reason);
        \Pramnos\Event\Event::dispatch(new Events\Kicked($subject, $scope, $reason));
    }

    /** Set a scope's slow mode: one message per person every `$seconds`; 0 turns it off. */
    public static function slowMode(string $scope, int $seconds, ?int $by = null): void
    {
        $seconds = max(0, $seconds);
        self::table('moderation_scopes')->upsert(['scope' => $scope, 'slowmode' => $seconds, 'updatedat' => time()], ['scope'], ['slowmode' => $seconds, 'updatedat' => time()]);
        self::log('slowmode', null, $scope, $by, '', ['seconds' => $seconds]);
    }

    /** A scope's slow mode, in seconds; 0 when it has none. */
    public static function slowModeOf(string $scope): int
    {
        $row = self::table('moderation_scopes')->where('scope', $scope)->limit(1)->getAllOrFail()[0] ?? null;

        return $row === null ? 0 : (int) $row['slowmode'];
    }

    /**
     * Write down what was done.
     *
     * @param array<string, mixed> $details
     */
    public static function log(string $action, ?Subject $subject, string $scope = '', ?int $by = null, string $reason = '', array $details = []): void
    {
        self::table('moderation_log')->insert(['scope' => $scope, 'action' => $action, 'subjectkind' => $subject?->kind ?? '',
            'subject' => $subject?->value ?? '', 'actor' => $by, 'reason' => mb_substr($reason, 0, 255),
            'details' => $details === [] ? null : (string) json_encode($details, JSON_UNESCAPED_UNICODE), 'createdat' => time()]);
    }

    /**
     * What was done, newest first — in a scope, about a subject, or everything.
     *
     * @return list<array{id: int, scope: string, action: string, subjectKind: string, subject: string, by: ?int, reason: string, details: array<string, mixed>, at: int}>
     */
    public static function history(?string $scope = null, ?Subject $subject = null, int $limit = 100): array
    {
        $query = self::table('moderation_log');
        if ($scope !== null) {
            $query->where('scope', $scope);
        }
        if ($subject !== null) {
            $query->where('subjectkind', $subject->kind)->where('subject', $subject->value);
        }

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'scope' => (string) $r['scope'], 'action' => (string) $r['action'],
            'subjectKind' => (string) $r['subjectkind'], 'subject' => (string) $r['subject'], 'by' => $r['actor'] === null ? null : (int) $r['actor'],
            'reason' => (string) $r['reason'], 'details' => (array) json_decode((string) ($r['details'] ?? ''), true), 'at' => (int) $r['createdat']],
            $query->orderBy('id', 'desc')->limit(max(1, min(500, $limit)))->getAllOrFail());
    }

    /** A restriction written down, and logged. */
    private static function restrict(string $effect, Subject $subject, string $scope, ?int $seconds, string $reason, ?int $by): int
    {
        $now = time();
        $db  = \Pramnos\Framework\Factory::getDatabase();
        self::table('moderation_restrictions')->insert(['scope' => $scope, 'effect' => $effect, 'subjectkind' => $subject->kind,
            'subject' => $subject->value, 'reason' => mb_substr($reason, 0, 255), 'createdby' => $by, 'createdat' => $now,
            'expiresat' => $seconds === null ? null : $now + max(1, $seconds)]);
        $id = (int) $db->getInsertId();
        self::log($effect, $subject, $scope, $by, $reason, $seconds === null ? [] : ['seconds' => $seconds]);

        return $id;
    }

    /** A moderation table, on the application's connection. */
    private static function table(string $name): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#' . $name);
    }
}
