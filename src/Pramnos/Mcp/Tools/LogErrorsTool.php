<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Logs\LogAnalytics;
use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: the log entries themselves, newest first.
 *
 * {@see LogAnalyticsTool} answers "is something wrong and how much". This one answers "what does
 * it say", which is the next question and the one a paste into a chat window is usually trying to
 * answer — badly, because a paste has no filter, no bound and no idea what it cut off.
 *
 * The default levels are the ones somebody means by "the error log": emergency, alert, critical
 * and error. `info` and `debug` are available by asking, and are not what anybody wants first.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class LogErrorsTool implements McpToolInterface
{
    /** A bound, because an MCP response is a message and a log file is not. */
    private const MAX_LIMIT = 200;

    public function name(): string
    {
        return 'log-errors';
    }

    public function description(): string
    {
        return 'Read this installation\'s recent log entries, newest first — by default the '
            . 'error-level ones (emergency, alert, critical, error). Optionally filter by level, '
            . 'file, timespan or a search string. Use log-analytics first for counts and rates.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'levels' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => [
                            'emergency', 'alert', 'critical', 'error',
                            'warning', 'notice', 'info', 'debug',
                        ],
                    ],
                    'description' => 'Which levels to include. Omit for the error-level ones.',
                ],
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Log file names. Omit for all of them.',
                ],
                'timespan' => [
                    'type' => 'string',
                    'enum' => array_keys(LogAnalytics::TIMESPANS),
                    'description' => 'How far back to look. Defaults to 24h.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Only entries containing this text.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many entries at most (up to ' . self::MAX_LIMIT . ').',
                ],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        $levels = is_array($input['levels'] ?? null)
            ? array_values(array_filter(array_map('strval', $input['levels'])))
            : [];
        $files = is_array($input['files'] ?? null)
            ? array_values(array_filter(array_map('strval', $input['files'])))
            : [];
        $timespan = is_string($input['timespan'] ?? null) ? $input['timespan'] : '24h';
        $query    = is_string($input['query'] ?? null) ? $input['query'] : '';
        $limit    = (int) ($input['limit'] ?? 50);
        $limit    = max(1, min(self::MAX_LIMIT, $limit));

        try {
            $entries = LogAnalytics::entries($levels, $files, $timespan, $limit, $query);
        } catch (\Throwable $exception) {
            return ['error' => 'Could not read the logs: ' . $exception->getMessage()];
        }

        return [
            'timespan' => $timespan,
            'levels'   => $levels !== [] ? $levels : ['emergency', 'alert', 'critical', 'error'],
            'count'    => count($entries),
            // Said out loud: an answer that stopped at the limit looks like "that is all there
            // is", and the difference matters when somebody is deciding whether it is fixed.
            'complete' => count($entries) < $limit,
            'entries'  => $entries,
        ];
    }
}
