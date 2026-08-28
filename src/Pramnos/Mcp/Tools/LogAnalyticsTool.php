<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Logs\LogAnalytics;
use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: what is going wrong on this installation.
 *
 * The same numbers `/admin/logs` draws — the trend, the level breakdown, the top errors with
 * their counts, and each file's error rate — for a caller with no browser and no session.
 *
 * That asymmetry was the reason for it. «What is going wrong here» is the first question anybody
 * asks, and the answer existed in one place that only a human with an administrator's session
 * could reach. An assistant asked to look at a problem had to be handed a pasted log file, which
 * is both more work and less information: a paste has no counts, no rates, and no idea what it
 * left out.
 *
 * Counts rather than lines, deliberately — {@see LogErrorsTool} is the one that returns text. A
 * summary answers "is something wrong and how much"; a hundred stack traces answer nothing until
 * somebody has read them.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class LogAnalyticsTool implements McpToolInterface
{
    public function name(): string
    {
        return 'log-analytics';
    }

    public function description(): string
    {
        return 'Summarise this installation\'s logs: entry trend, counts per level, the most '
            . 'frequent errors with how often each occurred, and per-file error rates. The same '
            . 'figures the /admin/logs dashboard shows. Ask for a timespan of 1h, 6h, 24h, 7d or 30d.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'timespan' => [
                    'type' => 'string',
                    'enum' => array_keys(LogAnalytics::TIMESPANS),
                    'description' => 'How far back to look. Defaults to 24h.',
                ],
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Log file names to include. Omit for all of them.',
                ],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        $timespan = is_string($input['timespan'] ?? null) ? $input['timespan'] : '24h';
        $files    = is_array($input['files'] ?? null)
            ? array_values(array_filter(array_map('strval', $input['files'])))
            : [];

        try {
            $summary = LogAnalytics::summary($timespan, $files);
        } catch (\Throwable $exception) {
            // Reported rather than thrown: an MCP client gets a JSON answer either way, and
            // "the logs could not be read" is a useful answer.
            return ['error' => 'Could not read the logs: ' . $exception->getMessage()];
        }

        // Said out loud, because a summary that quietly analysed the last few megabytes of a
        // large file reads as a complete picture.
        if ($summary['truncated']) {
            $summary['note'] = 'At least one log file was too large to scan in full; only its '
                . 'most recent entries were analysed.';
        }

        if ($summary['files'] === []) {
            $summary['note'] = 'No log files were readable. Check that ' . ($files !== []
                ? 'the named files exist'
                : 'this installation writes logs and that the directory is readable') . '.';
        }

        return $summary;
    }
}
