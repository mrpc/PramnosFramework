<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

use Pramnos\Database\Database;

/**
 * {@see BroadcastEventStore} backed by a framework database connection.
 *
 * Stores events in a `broadcast_events` table (see the shipped migration
 * CreateBroadcastEventsTable) and range-scans it by ascending id. Works on both
 * MySQL and PostgreSQL via the framework's Database abstraction. Values are
 * escaped through Database::prepareInput; ids are integers.
 *
 * A row's payload is stored as a JSON string and decoded back to an array on
 * read, so it round-trips through the same envelope the Redis driver uses.
 */
class DatabaseEventStore implements BroadcastEventStore
{
    public function __construct(
        private readonly Database $db,
        private readonly string $table = 'broadcast_events',
    ) {
    }

    public function append(string $channel, string $event, array $payload): void
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sql  = 'INSERT INTO ' . $this->table . ' (channel, event, payload, created_at) VALUES ('
            . "'" . $this->db->prepareInput($channel) . "', "
            . "'" . $this->db->prepareInput($event) . "', "
            . "'" . $this->db->prepareInput($json) . "', "
            . 'NOW())';
        $this->db->query($sql);
    }

    public function latestId(): int
    {
        $row = $this->db->selectOne('SELECT MAX(id) AS max_id FROM ' . $this->table);
        return (int) ($row['max_id'] ?? 0);
    }

    public function fetchSince(int $lastId, array $channels): array
    {
        if ($channels === []) {
            return [];
        }

        $escaped = array_map(
            fn (string $c): string => "'" . $this->db->prepareInput($c) . "'",
            array_values($channels)
        );

        $sql = 'SELECT id, channel, event, payload FROM ' . $this->table
            . ' WHERE id > ' . (int) $lastId
            . ' AND channel IN (' . implode(', ', $escaped) . ')'
            . ' ORDER BY id ASC';

        $result = $this->db->query($sql);
        $rows   = [];
        if ($result) {
            while ($result->fetch()) {
                $payload = json_decode((string) $result->fields['payload'], true);
                $rows[] = [
                    'id'      => (int) $result->fields['id'],
                    'channel' => (string) $result->fields['channel'],
                    'event'   => (string) $result->fields['event'],
                    'payload' => is_array($payload) ? $payload : [],
                ];
            }
        }
        return $rows;
    }
}
