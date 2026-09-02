<?php

declare(strict_types=1);

namespace Pramnos\Debug;

use Pramnos\Logs\Logger;

/**
 * The log lines one request wrote, read back out of the log files.
 *
 * This is the other half of {@see RequestId}. The toolbar shows what a response
 * carried, and a response that died carried almost nothing — an error page is
 * not a JSON object, and the header that still gets through has room for a count
 * but never for a message. The lines are on disk; this finds them.
 *
 * **By id, never by time.** The obvious implementation — everything logged
 * between the start of the request and its response — is wrong on any server
 * with more than one visitor: the toolbar is open for one browser, by grant,
 * while everyone else writes into the same seconds. Their lines are not the
 * developer's to read. So a line qualifies only by carrying the id, which
 * Logger writes while the toolbar is active.
 *
 * Reading is deliberately narrow: files inside the log directory, the tail of
 * each, a capped number of matches. Nothing here takes a path from a caller.
 */
final class RequestLog
{
    /**
     * How much of each file's tail is read, in bytes.
     *
     * The request being asked about has just happened, so its lines are at the
     * end. Reading a 400MB log from the front to find eight lines written a
     * second ago is how a debugging aid becomes an outage.
     */
    private const TAIL_BYTES = 2097152;

    /**
     * How many matching lines are returned at most.
     */
    private const MAX_LINES = 500;

    /**
     * The shape a request id must have to be looked up at all.
     *
     * {@see RequestId::current()} emits sixteen hex characters. Anything else
     * did not come from there, and a pattern this strict means the value can
     * never carry a path, a glob or a regex into the code below.
     */
    private const ID_PATTERN = '/^[a-f0-9]{16}$/';

    /**
     * Is this a well-formed request id?
     *
     * @param  string $id
     * @return bool
     */
    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Every log line this request wrote, oldest first.
     *
     * @param  string $id    A request id, as issued by {@see RequestId}
     * @param  int    $limit Maximum lines to return, capped at {@see MAX_LINES}
     * @return list<array{timestamp: string, level: string, message: string, file: string, context: array<string, mixed>}>
     */
    public static function forRequest(string $id, int $limit = self::MAX_LINES): array
    {
        if (!self::isValidId($id)) {
            return [];
        }

        $limit = max(1, min($limit, self::MAX_LINES));
        $found = [];

        foreach (self::logFiles() as $path) {
            foreach (self::tailLines($path) as $line) {
                // Cheap rejection before the decode: the overwhelming majority
                // of lines in a busy log belong to some other request.
                if (strpos($line, $id) === false) {
                    continue;
                }

                $entry = json_decode($line, true);
                if (!is_array($entry) || ($entry['request'] ?? null) !== $id) {
                    continue;
                }

                $found[] = [
                    'timestamp' => (string) ($entry['timestamp'] ?? ''),
                    'level'     => (string) ($entry['level'] ?? 'info'),
                    'message'   => (string) ($entry['message'] ?? ''),
                    'file'      => basename($path),
                    'context'   => is_array($entry['context'] ?? null) ? $entry['context'] : [],
                ];

                if (count($found) >= $limit) {
                    return $found;
                }
            }
        }

        return $found;
    }

    /**
     * The requests the log knows about, most recent first.
     *
     * The other half of {@see forRequest()}, and the half a person actually starts from: the
     * question is almost never "show me request a1b2c3d4", it is "what blew up". An id is
     * something you have *after* somebody has read an error page and copied it out — which, on
     * a request that died before rendering one, never happens.
     *
     * @param  int    $limit How many requests to describe
     * @param  string $level Only requests that logged at this level or worse — `error` to see
     *                       the ones that went wrong, `''` for everything
     * @return list<array{request: string, started: string, ended: string, lines: int, worst: string, message: string, files: list<string>}>
     */
    public static function recent(int $limit = 20, string $level = ''): array
    {
        $limit    = max(1, min($limit, 200));
        $requests = [];
        $order    = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5];
        $floor    = $order[strtolower(trim($level))] ?? -1;

        foreach (self::logFiles() as $path) {
            foreach (self::tailLines($path) as $line) {
                $entry = json_decode($line, true);

                if (!is_array($entry)) {
                    continue;
                }

                $id = (string) ($entry['request'] ?? '');

                if (!self::isValidId($id)) {
                    continue;
                }

                $severity  = strtolower((string) ($entry['level'] ?? 'info'));
                $rank      = $order[$severity] ?? 1;
                $timestamp = (string) ($entry['timestamp'] ?? '');

                /*
                 * Compared as a number, never as a string.
                 *
                 * The log writes `d/m/Y H:i:s`, and `01/09/2026` sorts before `29/08/2026`
                 * alphabetically — so "most recent" would flip to "oldest" every time a month
                 * rolls over, and only then.
                 */
                $when = Logger::timestampOf($timestamp);

                if (!isset($requests[$id])) {
                    $requests[$id] = [
                        'request' => $id,
                        'started' => $timestamp,
                        'ended'   => $timestamp,
                        'first'   => $when,
                        'last'    => $when,
                        'lines'   => 0,
                        'worst'   => $severity,
                        'rank'    => $rank,
                        'message' => '',
                        'files'   => [],
                    ];
                }

                $requests[$id]['lines']++;
                $requests[$id]['files'][basename($path)] = true;

                if ($when > 0) {
                    if ($requests[$id]['first'] === 0 || $when < $requests[$id]['first']) {
                        $requests[$id]['first']   = $when;
                        $requests[$id]['started'] = $timestamp;
                    }

                    if ($when > $requests[$id]['last']) {
                        $requests[$id]['last']  = $when;
                        $requests[$id]['ended'] = $timestamp;
                    }
                }

                if ($rank >= $requests[$id]['rank']) {
                    /*
                     * The worst line, and its message.
                     *
                     * `>=` rather than `>` so the *last* line at the worst level wins: a
                     * request that failed twice is usually explained by the second one, and
                     * the first is what led to it.
                     */
                    $requests[$id]['rank']    = $rank;
                    $requests[$id]['worst']   = $severity;
                    $requests[$id]['message'] = (string) ($entry['message'] ?? '');
                }
            }
        }

        $requests = array_filter(
            $requests,
            static fn (array $request): bool => $request['rank'] >= $floor
        );

        // Most recent first: the request somebody is asking about is the one that just happened.
        uasort(
            $requests,
            static fn (array $a, array $b): int => $b['last'] <=> $a['last']
        );

        $out = [];

        foreach (array_slice($requests, 0, $limit) as $request) {
            unset($request['rank'], $request['first'], $request['last']);
            $request['files'] = array_keys($request['files']);
            $out[] = $request;
        }

        return $out;
    }

    /**
     * The log files worth reading.
     *
     * Only `*.log` directly inside the log directory, and only real files —
     * a symlink out of it, or a name a caller chose, never gets this far,
     * because no caller supplies a name at all.
     *
     * @return list<string>
     */
    private static function logFiles(): array
    {
        $dir = Logger::logDirectory();
        // Logger creates this directory on its first
        // @codeCoverageIgnoreStart
        // write, so reaching here means an installation with no logs at all.
        if (!is_dir($dir)) {
            return [];
        }
        // @codeCoverageIgnoreEnd

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.log') ?: [];

        return array_values(array_filter($files, static function (string $path): bool {
            return is_file($path) && !is_link($path);
        }));
    }

    /**
     * The last {@see TAIL_BYTES} of a file, as lines.
     *
     * The first line of the chunk is dropped when the file is larger than the
     * window, because it is almost certainly cut in half — half a JSON line
     * would simply fail to decode, but dropping it says so on purpose.
     *
     * @param  string $path
     * @return list<string>
     */
    private static function tailLines(string $path): array
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            return [];
        }

        $handle = @fopen($path, 'rb');
        // a file glob() just listed and filesize()
        // @codeCoverageIgnoreStart
        // just measured, refusing to open: a permissions change mid-read.
        if ($handle === false) {
            return [];
        }
        // @codeCoverageIgnoreEnd

        $truncated = $size > self::TAIL_BYTES;
        if ($truncated) {
            fseek($handle, -self::TAIL_BYTES, SEEK_END);
        }

        $chunk = stream_get_contents($handle);
        fclose($handle);

        // an open handle whose read fails is a
        // @codeCoverageIgnoreStart
        // disk-level failure, not a case a test can set up.
        if ($chunk === false) {
            return [];
        }
        // @codeCoverageIgnoreEnd

        $lines = explode("\n", $chunk);
        if ($truncated) {
            array_shift($lines);
        }

        return array_values(array_filter($lines, static fn(string $l): bool => trim($l) !== ''));
    }
}
