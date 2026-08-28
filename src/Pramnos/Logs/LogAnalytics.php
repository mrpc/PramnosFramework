<?php

declare(strict_types=1);

namespace Pramnos\Logs;

/**
 * The numbers the log dashboard shows, as something other than a screen can ask for.
 *
 * `LogController::dashboard()` computed all of this inline: a hundred lines that walk every
 * whitelisted file, merge the per-file analytics, add up the levels, deduplicate the errors by
 * message and sort them. Useful numbers, reachable only by a human with a browser and an
 * administrator's session.
 *
 * Which is the wrong shape for the thing most likely to want them. «What is going wrong on this
 * installation» is the first question anybody asks — a person, a monitor, an assistant with an
 * MCP connection — and the answer existed in one place that could not be called.
 *
 * So it is a service, and the screen is one of its callers. The other is
 * {@see \Pramnos\Mcp\Tools\LogAnalyticsTool}. One implementation on purpose: two copies of an
 * aggregation drift, and the day they disagree the screen and the tool each look right on their
 * own.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class LogAnalytics
{
    /**
     * Files that carry no structured entries, so counting levels in them means nothing.
     *
     * @var list<string>
     */
    public const SKIP = ['GitDeploy', 'GitWebhookDebug'];

    /**
     * How far back each timespan reaches, and how its trend is grouped.
     *
     * @var array<string, array{seconds: int, group: string, format: string}>
     */
    public const TIMESPANS = [
        '1h'  => ['seconds' => 3600,    'group' => 'minute', 'format' => 'H:i'],
        // 6h was on the screen's own selector before this service existed. Dropping it here
        // would have quietly turned that option into 24h.
        '6h'  => ['seconds' => 21600,   'group' => 'minute', 'format' => 'H:i'],
        '24h' => ['seconds' => 86400,   'group' => 'hour',   'format' => 'H:i'],
        '7d'  => ['seconds' => 604800,  'group' => 'day',    'format' => 'M d'],
        '30d' => ['seconds' => 2592000, 'group' => 'day',    'format' => 'M d'],
    ];

    /** How many distinct errors are worth reporting. */
    public const TOP_ERRORS = 10;

    /**
     * Everything the dashboard draws, for one timespan.
     *
     * @param  string      $timespan One of {@see TIMESPANS}; anything else means `24h`
     * @param  list<string> $files   Log file names; empty means every whitelisted one
     * @return array{
     *     timespan: string, from: int, to: int, group: string,
     *     trends: array<string, int>, levels: array<string, int>,
     *     topErrors: list<array{message: string, count: int, file: string, last_seen: string}>,
     *     files: array<string, array{last_entry: ?string, error_rate: float, total_entries: int}>,
     *     truncated: bool
     * }
     */
    public static function summary(string $timespan = '24h', array $files = []): array
    {
        $window = self::TIMESPANS[$timespan] ?? self::TIMESPANS['24h'];
        $timespan = isset(self::TIMESPANS[$timespan]) ? $timespan : '24h';

        $endTime   = time();
        $startTime = $endTime - $window['seconds'];

        $trends    = [];
        $levels    = [];
        $errors    = [];
        $perFile   = [];
        $truncated = false;

        foreach (($files !== [] ? $files : self::files()) as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);

            if (in_array($name, self::SKIP, true)) {
                continue;
            }

            $extension = pathinfo($file, PATHINFO_EXTENSION) ?: 'log';
            $analytics = LogManager::getLogAnalytics(
                $name,
                $extension,
                $startTime,
                $endTime,
                $window['group']
            );

            if ($analytics === []) {
                continue;
            }

            if (!empty($analytics['truncated'])) {
                $truncated = true;
            }

            foreach ((array) ($analytics['trends'] ?? []) as $at => $count) {
                $trends[$at] = ($trends[$at] ?? 0) + (int) $count;
            }

            foreach ((array) ($analytics['levels'] ?? []) as $level => $count) {
                $levels[$level] = ($levels[$level] ?? 0) + (int) $count;
            }

            foreach ((array) ($analytics['topErrors'] ?? []) as $error) {
                // Keyed by the message, so the same error in three files is one row with the
                // total — which is the number somebody acts on.
                $key = md5((string) ($error['message'] ?? ''));

                if (!isset($errors[$key])) {
                    $errors[$key] = [
                        'message'   => (string) ($error['message'] ?? ''),
                        'count'     => 0,
                        'file'      => $file,
                        'last_seen' => (string) ($error['timestamp'] ?? ''),
                    ];
                }

                $errors[$key]['count'] += (int) ($error['count'] ?? 0);
            }

            $perFile[$file] = [
                'last_entry'    => $analytics['lastEntry'] ?? null,
                'error_rate'    => (float) ($analytics['errorRate'] ?? 0),
                'total_entries' => (int) ($analytics['totalEntries'] ?? 0),
            ];
        }

        uasort($errors, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        ksort($trends);

        $labelled = [];

        foreach ($trends as $at => $count) {
            $labelled[date($window['format'], (int) $at)] = $count;
        }

        return [
            'timespan'  => $timespan,
            'from'      => $startTime,
            'to'        => $endTime,
            'group'     => $window['group'],
            'trends'    => $labelled,
            'levels'    => $levels,
            'topErrors' => array_values(array_slice($errors, 0, self::TOP_ERRORS)),
            'files'     => $perFile,
            'truncated' => $truncated,
        ];
    }

    /**
     * The recent entries at or above a level, newest first.
     *
     * The other half of the question. A count says something is wrong; the lines say what — and
     * pasting a log file into a chat window is what people do when they cannot ask for this.
     *
     * @param  list<string> $levels One or more of emergency…debug; empty means the error-ish ones
     * @param  list<string> $files  Empty means every whitelisted file
     * @return list<array{file: string, level: string, timestamp: ?int, message: string}>
     */
    public static function entries(
        array $levels = [],
        array $files = [],
        string $timespan = '24h',
        int $limit = 100,
        string $query = ''
    ): array {
        $window    = self::TIMESPANS[$timespan] ?? self::TIMESPANS['24h'];
        $endTime   = time();
        $startTime = $endTime - $window['seconds'];

        // The default is what somebody means by "the error log": everything worth waking up for.
        $levels = $levels !== []
            ? array_map('strtolower', $levels)
            : ['emergency', 'alert', 'critical', 'error'];

        $entries = [];

        foreach (($files !== [] ? $files : self::files()) as $file) {
            $name      = pathinfo($file, PATHINFO_FILENAME);
            $extension = pathinfo($file, PATHINFO_EXTENSION) ?: 'log';

            foreach (LogManager::getFilteredLogEntries(
                $name,
                $extension,
                $levels,
                $startTime,
                $endTime,
                $query,
                $limit
            ) as $entry) {
                $entry = (array) $entry;
                $entry['file'] = $file;
                $entries[] = $entry;
            }
        }

        usort(
            $entries,
            static fn (array $a, array $b): int => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0)
        );

        return array_slice($entries, 0, $limit);
    }

    /**
     * The log files this installation has.
     *
     * `LogManager::getLogFiles()` rather than the controller's whitelist: a caller with no
     * controller — a command, an MCP tool — has no whitelist to consult, and the directory is
     * the honest answer to "what logs are there".
     *
     * @return list<string>
     */
    public static function files(): array
    {
        try {
            return array_values(array_map('strval', LogManager::getLogFiles()));
        } catch (\Throwable) {
            return [];
        }
    }
}
