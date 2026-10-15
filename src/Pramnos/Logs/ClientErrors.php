<?php

declare(strict_types=1);

namespace Pramnos\Logs;

use Pramnos\Database\Database;

/**
 * Errors the application's pages hit in the browser, kept one row per distinct error.
 *
 * ```php
 * $errors = new ClientErrors();
 * $errors->record(['message' => 'x is undefined', 'source' => '/assets/js/app.js', 'line' => 12], $agent);
 * $errors->list();          // newest first: hash, error, count, first and last seen, …
 * $errors->delete($hash);
 * ```
 *
 * What reports them: `pf-utils.js` on every server-rendered page, and the service worker for its
 * own errors, both posting to `ClientErrorsController`. A second report of the same error raises
 * `seentimes` and `lastseen` rather than adding a row — the same message from the same place is
 * one error however many browsers hit it, and the table stays the size of the problem list.
 *
 * ## The older shape
 *
 * A report of `['error' => …]` is the shape applications posted before this class existed: one
 * line of text and the agent. It is stored as it was, under the same hash those applications
 * computed (`md5(error . agent)`), so their existing rows keep counting.
 */
final class ClientErrors
{
    /** How long a stored text may be. The one-line `error` and the short ones are 255. */
    private const MESSAGE_MAX = 2000;
    private const STACK_MAX   = 8000;

    /** What a report may say it is. */
    public const KINDS = ['error', 'rejection', 'worker'];

    public function __construct(private readonly ?Database $db = null)
    {
    }

    /**
     * Store one report: a new row, or one more sighting of a known error.
     *
     * @param array<string, mixed> $report `message`, `source`, `line`, `column`, `stack`, `page`,
     *        `kind` and `release` — or the older `error`
     * @return string|null The error's hash, or null for a report with nothing in it
     */
    public function record(array $report, string $agent = '', ?int $userId = null): ?string
    {
        $row = self::normalise($report, $agent);
        if ($row === null) {
            return null;
        }
        $row['userid'] = $userId !== null && $userId > 1 ? $userId : null;
        $now = time();

        // A known error first: the common case, and an UPDATE does not race an INSERT's unique key.
        if ($this->sighted($row, $now) > 0) {
            return $row['idhash'];
        }
        $inserted = $this->table()->insertOrIgnore($row + ['firstseen' => $now, 'lastseen' => $now, 'seentimes' => 1]);
        // Inserted by another report between the two: count this one on it.
        if (!is_object($inserted) || (int) $inserted->getAffectedRows() === 0) {
            $this->sighted($row, $now);
        }

        return $row['idhash'];
    }

    /**
     * One page of errors, the most recently seen first.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min(200, $perPage));
        $total   = (int) $this->table()->count();
        $result  = $this->table()->orderBy('lastseen', 'desc')
            ->limit($perPage)->offset((max(1, $page) - 1) * $perPage)->get();

        $items = [];
        while ($result && $result->fetch()) {
            $items[] = $result->fields;
        }

        return ['items' => $items, 'total' => $total];
    }

    /** Forget one error. False when there was no such error. */
    public function delete(string $hash): bool
    {
        if ((int) $this->table()->where('idhash', $hash)->count() === 0) {
            return false;
        }
        $this->table()->where('idhash', $hash)->delete();

        return true;
    }

    /** Forget every error. Returns how many there were. */
    public function clear(): int
    {
        $count = (int) $this->table()->count();
        if ($count > 0) {
            $this->table()->where('seentimes', '>=', 0)->delete();
        }

        return $count;
    }

    /**
     * A report as a row, or null when it says nothing.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>|null
     */
    public static function normalise(array $report, string $agent = ''): ?array
    {
        $agent = self::cut($agent, 255);

        // The older shape: one line, hashed with the agent as it always was.
        if (!isset($report['message']) && isset($report['error'])) {
            $error = self::cut((string) $report['error'], 255);
            if (trim($error) === '') {
                return null;
            }

            return ['idhash' => md5((string) $report['error'] . $agent), 'error' => $error, 'agent' => $agent];
        }

        $message = trim(self::cut((string) ($report['message'] ?? ''), self::MESSAGE_MAX));
        if ($message === '') {
            return null;
        }
        $kind   = in_array($report['kind'] ?? 'error', self::KINDS, true) ? (string) ($report['kind'] ?? 'error') : 'error';
        $source = self::withoutQuery((string) ($report['source'] ?? ''));
        $page   = self::withoutQuery((string) ($report['page'] ?? ''));
        $line   = isset($report['line']) && is_numeric($report['line']) ? (int) $report['line'] : null;
        $column = isset($report['column']) && is_numeric($report['column']) ? (int) $report['column'] : null;

        return [
            // The same message from the same place is the same error, in any browser and on any page.
            'idhash'  => md5($kind . "\n" . $message . "\n" . $source . "\n" . $line),
            // The one line the older readers show: where, what, and on which page.
            'error'   => self::cut(($source !== '' ? $source . ':' . $line . "\n\n" : '') . $message . ($page !== '' ? ': ' . $page : ''), 255),
            'agent'   => $agent,
            'kind'    => $kind,
            'message' => $message,
            'source'  => $source === '' ? null : $source,
            'line'    => $line,
            'col'     => $column,
            'stack'   => isset($report['stack']) && trim((string) $report['stack']) !== '' ? self::cut((string) $report['stack'], self::STACK_MAX) : null,
            'page'    => $page === '' ? null : $page,
            'release' => isset($report['release']) && (string) $report['release'] !== '' ? self::cut((string) $report['release'], 64) : null,
        ];
    }

    /**
     * One more sighting of a known error: the count, the time, and the latest of everything else.
     *
     * @param array<string, mixed> $row
     */
    private function sighted(array $row, int $now): int
    {
        $set = ['lastseen' => $now, 'agent' => $row['agent']];
        foreach (['page', 'release', 'userid', 'stack'] as $latest) {
            if (array_key_exists($latest, $row) && $row[$latest] !== null) {
                $set[$latest] = $row[$latest];
            }
        }
        $query = $this->table()->where('idhash', $row['idhash']);
        $query->update($set);

        return $this->table()->where('idhash', $row['idhash'])->increment('seentimes');
    }

    /** A URL without its query and fragment: tokens, ad ids and search terms are not an error's address. */
    private static function withoutQuery(string $url): string
    {
        return self::cut((string) preg_replace('/[?#].*$/s', '', trim($url)), 255);
    }

    private static function cut(string $text, int $length): string
    {
        return mb_substr($text, 0, $length);
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return ($this->db ?? Database::getInstance())->queryBuilder()->table('#PREFIX#javascripterrors');
    }
}
