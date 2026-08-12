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
        // @codeCoverageIgnoreStart — Logger creates this directory on its first
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
        // @codeCoverageIgnoreStart — a file glob() just listed and filesize()
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

        // @codeCoverageIgnoreStart — an open handle whose read fails is a
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
