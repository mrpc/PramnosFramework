<?php

declare(strict_types=1);

namespace Pramnos\Moderation;

/**
 * Somebody reporting somebody: what they wrote, kept as it was when it was reported, and why. A
 * moderator then resolves or dismisses each.
 *
 * ```php
 * Reports::file(Subject::visitor($key), Subject::account($author), 'conversation:12', 'message:' . $id, $message['body'], 'Ύβρεις');
 * Reports::open('conversation:12');               // what waits
 * Reports::close($report, 'resolved', $me);       // or 'dismissed'
 * ```
 */
final class Reports
{
    /** What a report may end as. */
    public const CLOSED = ['resolved', 'dismissed'];

    /**
     * File a report — once per reporter and content: the same report again is the first one.
     *
     * @return int The report
     */
    public static function file(Subject $reporter, Subject $subject, string $scope, string $content, string $snapshot, string $reason = ''): int
    {
        if ($reporter->is($subject)) {
            throw new \InvalidArgumentException(t('Nobody reports themselves.'));
        }
        $same = self::table()->where('reporterkind', $reporter->kind)->where('reporter', $reporter->value)->where('subjectkind', $subject->kind)
            ->where('subject', $subject->value)->where('content', $content)->limit(1)->getAllOrFail()[0] ?? null;
        if ($same !== null) {
            return (int) $same['id'];
        }
        self::table()->insert(['scope' => $scope, 'reporterkind' => $reporter->kind, 'reporter' => $reporter->value,
            'subjectkind' => $subject->kind, 'subject' => $subject->value, 'content' => mb_substr($content, 0, 100), 'snapshot' => $snapshot,
            'reason' => mb_substr($reason, 0, 255), 'status' => 'open', 'createdat' => time()]);
        $id = (int) \Pramnos\Framework\Factory::getDatabase()->getInsertId();
        Moderation::log('report', $subject, $scope, null, $reason, ['report' => $id, 'by' => $reporter->key(), 'content' => $content]);

        return $id;
    }

    /**
     * The reports still waiting, oldest first — in a scope, or everywhere.
     *
     * @return list<array{id: int, scope: string, reporterKind: string, reporter: string, subjectKind: string, subject: string, content: string, snapshot: string, reason: string, at: int}>
     */
    public static function open(?string $scope = null, int $limit = 100): array
    {
        $query = self::table()->where('status', 'open');
        if ($scope !== null) {
            $query->where('scope', $scope);
        }

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'scope' => (string) $r['scope'], 'reporterKind' => (string) $r['reporterkind'],
            'reporter' => (string) $r['reporter'], 'subjectKind' => (string) $r['subjectkind'], 'subject' => (string) $r['subject'],
            'content' => (string) $r['content'], 'snapshot' => (string) ($r['snapshot'] ?? ''), 'reason' => (string) $r['reason'], 'at' => (int) $r['createdat']],
            $query->orderBy('id')->limit(max(1, min(500, $limit)))->getAllOrFail());
    }

    /** Close a report — `resolved` (something was done) or `dismissed` (nothing to do). */
    public static function close(int $id, string $status, ?int $by = null): void
    {
        if (!in_array($status, self::CLOSED, true)) {
            throw new \InvalidArgumentException(t('A report is resolved or dismissed.'));
        }
        $report = self::table()->where('id', $id)->limit(1)->getAllOrFail()[0] ?? throw new \DomainException(t('That report no longer exists.'));
        self::table()->where('id', $id)->update(['status' => $status, 'resolvedat' => time(), 'resolvedby' => $by]);
        Moderation::log('resolve', Subject::of((string) $report['subjectkind'], (string) $report['subject']), (string) $report['scope'], $by, $status, ['report' => $id]);
    }

    /** The reports' table. */
    private static function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#moderation_reports');
    }
}
