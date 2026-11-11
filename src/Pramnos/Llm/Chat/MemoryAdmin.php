<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Database\Database;

/**
 * Everybody's Ask memory, for the people who read everybody's Ask conversations
 * ({@see \Pramnos\Llm\Analyst\Review::readsAll()}): who has memories and how many, one person's —
 * or what an organisation shares — and correcting or deleting one.
 *
 * It is personal data, read on somebody else's behalf, so each reading of a person's memories, each
 * correction and each deletion is written to the audit log (`ai.memory_viewed`,
 * `ai.memory_corrected`, `ai.memory_deleted`), with the person it was about. Who may read it is
 * the caller's to check; this class reads and writes for whoever it is given.
 */
final class MemoryAdmin
{
    public function __construct(private readonly ?Database $db = null)
    {
    }

    /**
     * Who has memories: each person, and each organisation's shared memory (`userid` 0), with how
     * many and when the latest changed, newest first.
     *
     * @return list<array{userid: int, username: string, organization_id: ?int, space: string, count: int, latest: string}>
     */
    public function people(): array
    {
        try {
            $query  = $this->db()->queryBuilder()->table('assist.chat_memory');
            $result = $query->select(['userid', 'organization_id', 'space', $query->raw('COUNT(*) AS n'), $query->raw('MAX(updated_at) AS latest')])
                ->groupBy('userid', 'organization_id', 'space')->get();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Ask memories unavailable: ' . $e->getMessage(), 'llm');

            return [];
        }
        $rows = [];
        while ($result && ($row = $result->fetch()) !== null) {
            $rows[] = ['userid' => (int) $row['userid'], 'organization_id' => $row['organization_id'] === null ? null : (int) $row['organization_id'],
                'space' => (string) ($row['space'] ?? Scope::CHAT), 'count' => (int) $row['n'], 'latest' => (string) ($row['latest'] ?? '')];
        }
        $names = $this->usernames(array_values(array_filter(array_column($rows, 'userid'))));
        foreach ($rows as &$row) {
            $row['username'] = $row['userid'] === Memory::SHARED ? '' : ($names[$row['userid']] ?? '#' . $row['userid']);
        }
        unset($row);
        usort($rows, static fn (array $a, array $b): int => strcmp($b['latest'], $a['latest']));

        return $rows;
    }

    /**
     * One person's memories — or with `$userId` 0 what the organisation shares — in a space, the
     * reading recorded as done by `$reader`.
     *
     * @return list<array{memory_id: int, kind: string, content: string, source: ?string, topic: string, at: string}>
     */
    public function of(object $reader, int $userId, ?int $organizationId, string $space = Scope::CHAT): array
    {
        try {
            $query = $this->db()->queryBuilder()->table('assist.chat_memory')->where('userid', $userId)->where('space', $space);
            $organizationId === null ? $query->whereNull('organization_id') : $query->where('organization_id', $organizationId);
            $rows = $query->orderBy('memory_id')->getAllOrFail();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Ask memories unavailable: ' . $e->getMessage(), 'llm');

            return [];
        }
        \Pramnos\Auth\AuditLog::record('ai.memory_viewed', (int) ($reader->userid ?? 0), $userId > 0 ? $userId : null,
            ['organization_id' => $organizationId, 'space' => $space, 'count' => count($rows)], 'chat_memory');

        return array_map(static fn (array $row): array => [
            'memory_id' => (int) $row['memory_id'], 'kind' => (string) $row['kind'], 'content' => (string) $row['content'],
            'source' => ($row['source'] ?? null) === null ? null : (string) $row['source'],
            'topic' => Memory::topicOf($row['topic'] ?? null),
            'at' => (string) ($row['updated_at'] ?? $row['created_at'] ?? ''),
        ], array_values($rows));
    }

    /** Correct one memory's words, up to {@see Memory::chars()}; whether it was there to correct. */
    public function correct(object $reader, int $memoryId, string $content): bool
    {
        return $this->correctOn($reader, $memoryId, $content, null);
    }

    /**
     * Correct one memory's words and what it is about — `$topic`, one of {@see Memory::TOPICS},
     * null to leave it; whether it was there to correct.
     */
    public function correctOn(object $reader, int $memoryId, string $content, ?string $topic): bool
    {
        $content = trim($content);
        $row     = $this->row($memoryId);
        if ($row === null || $content === '') {
            return false;
        }
        try {
            $this->db()->queryBuilder()->table('assist.chat_memory')->where('memory_id', $memoryId)
                ->update(['content' => mb_substr($content, 0, Memory::chars()), 'updated_at' => \Pramnos\Llm\CallLog::instant(time())]
                    + ($topic !== null && $this->db()->schema()->hasColumn('assist.chat_memory', 'topic') ? ['topic' => Memory::topicOf($topic)] : []));
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Ask memory not corrected: ' . $e->getMessage(), 'llm');

            return false;
        }
        \Pramnos\Auth\AuditLog::record('ai.memory_corrected', (int) ($reader->userid ?? 0), (int) $row['userid'] > 0 ? (int) $row['userid'] : null,
            ['was' => (string) $row['content']], 'chat_memory', $memoryId);

        return true;
    }

    /** Delete one memory; whether it was there to delete. */
    public function delete(object $reader, int $memoryId): bool
    {
        $row = $this->row($memoryId);
        if ($row === null) {
            return false;
        }
        try {
            $this->db()->queryBuilder()->table('assist.chat_memory')->where('memory_id', $memoryId)->delete();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Ask memory not deleted: ' . $e->getMessage(), 'llm');

            return false;
        }
        \Pramnos\Auth\AuditLog::record('ai.memory_deleted', (int) ($reader->userid ?? 0), (int) $row['userid'] > 0 ? (int) $row['userid'] : null,
            ['was' => (string) $row['content']], 'chat_memory', $memoryId);

        return true;
    }

    /** @return array<string, mixed>|null One memory's row */
    private function row(int $memoryId): ?array
    {
        try {
            $rows = $this->db()->queryBuilder()->table('assist.chat_memory')->where('memory_id', $memoryId)->getAllOrFail();
        } catch (\Throwable) {
            return null;
        }

        return $rows === [] ? null : array_values($rows)[0];
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function usernames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            $rows = $this->db()->queryBuilder()->table('#PREFIX#users')->select(['userid', 'username'])->whereIn('userid', $ids)->getAllOrFail();
        } catch (\Throwable) {
            return [];
        }

        return array_column(array_values($rows), 'username', 'userid');
    }

    private function db(): Database
    {
        return $this->db ?? Database::getInstance();
    }
}
