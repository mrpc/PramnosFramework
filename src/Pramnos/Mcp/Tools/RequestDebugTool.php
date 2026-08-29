<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Debug\RequestLog;
use Pramnos\Mcp\McpToolInterface;

/**
 * MCP tool: what a request did, from the log, by id — or which requests went wrong.
 *
 * The debug toolbar answers this for a response somebody is looking at. A request that *died*
 * carried almost nothing back: an error page is not a JSON payload, and the header that still
 * gets through has room for a count and never for a message. The lines are on disk, tagged with
 * the request id, and until now the only way to read them was the DevPanel — a browser, a
 * session, and a person clicking.
 *
 * Two shapes, and the second is the one worth having:
 *
 * - `{"request": "a1b2c3d4…"}` — every line that request wrote, oldest first.
 * - `{}` — the requests the log knows about, worst first. Because the question is almost never
 *   "show me request a1b2c3d4"; it is "what blew up", and an id is something you only have
 *   *after* somebody has read an error page and copied it out.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class RequestDebugTool implements McpToolInterface
{
    public function name(): string
    {
        return 'request-debug';
    }

    public function description(): string
    {
        return 'What one request logged, by id — queries, errors and context, oldest first. '
            . 'Called with no id it lists the requests that went wrong, most recent first, '
            . 'which is where you start when something just broke and there is no id to quote.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'request' => [
                    'type' => 'string',
                    'description' => 'A request id — sixteen hex characters, from the debug bar, '
                        . 'the `X-Request-Id` header, or a previous call to this tool.',
                ],
                'level' => [
                    'type' => 'string',
                    'description' => 'When listing: only requests that logged at this level or '
                        . 'worse. Defaults to `error`. Pass `""` for every request.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many requests to list, or how many lines to return.',
                ],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        $id    = trim((string) ($input['request'] ?? ''));
        $limit = (int) ($input['limit'] ?? 0);

        if ($id === '') {
            return $this->list(
                array_key_exists('level', $input) ? (string) $input['level'] : 'error',
                $limit > 0 ? $limit : 20
            );
        }

        if (!RequestLog::isValidId($id)) {
            return [
                'error' => 'Not a request id. They are sixteen hex characters.',
                'note'  => 'Call this with no `request` to list the ones the log knows about.',
            ];
        }

        $lines = RequestLog::forRequest($id, $limit > 0 ? $limit : 500);

        if ($lines === []) {
            return [
                'request' => $id,
                'lines'   => [],
                'note'    => 'Nothing in the log carries that id. Lines are only tagged while '
                    . 'the debug toolbar is active for the visitor who made the request, so a '
                    . 'request made without it leaves none — which is deliberate: another '
                    . "visitor's lines are not a developer's to read.",
            ];
        }

        return [
            'request'  => $id,
            'lines'    => $lines,
            'counts'   => $this->countLevels($lines),
            'timespan' => [
                'from' => $lines[0]['timestamp'] ?? '',
                'to'   => $lines[count($lines) - 1]['timestamp'] ?? '',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function list(string $level, int $limit): array
    {
        $requests = RequestLog::recent($limit, $level);

        if ($requests === []) {
            return [
                'requests' => [],
                'level'    => $level,
                'note'     => $level === ''
                    ? 'The log has no request-tagged lines at all. They are written only while '
                        . 'the debug toolbar is active for a visitor.'
                    : 'No request logged at `' . $level . '` or worse. Pass `"level": ""` for '
                        . 'every request the log knows about.',
            ];
        }

        return [
            'requests' => $requests,
            'level'    => $level,
            'note'     => 'Ask again with one of these ids for its lines.',
        ];
    }

    /**
     * @param  list<array<string, mixed>> $lines
     * @return array<string, int>
     */
    private function countLevels(array $lines): array
    {
        $counts = [];

        foreach ($lines as $line) {
            $level = (string) ($line['level'] ?? 'info');
            $counts[$level] = ($counts[$level] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }
}
