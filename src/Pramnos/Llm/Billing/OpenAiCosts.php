<?php

declare(strict_types=1);

namespace Pramnos\Llm\Billing;

use Pramnos\Application\Settings;
use Pramnos\Llm\CallLog;

/**
 * What OpenAI says it billed, and what that leaves.
 *
 * OpenAI publishes no remaining balance, but it does publish its costs: the Costs API
 * (`/v1/organization/costs`) answers the USD billed per day, by project. It takes an **Admin
 * key** (`sk-admin-…`), not the key calls are made with. The balance is then arithmetic, and is
 * shown as such: the top-ups an administrator records, less what was billed since the first.
 *
 * With the installation's calls in a project of their own, the project's figure is its exact
 * spend, which checks the estimate in `llm_calls`.
 */
final class OpenAiCosts
{
    /** The Admin key, encrypted. */
    public const ADMIN_KEY = 'llm_openai_admin_key';

    /** One top-up a line: `2026-10-01 50` — a date and dollars. */
    public const TOPUPS = 'llm_openai_topups';

    /** The installation's own OpenAI project (`proj_…`), to tell its spend from the organisation's. */
    public const PROJECT = 'llm_openai_project';

    private const URL = 'https://api.openai.com/v1/organization/costs';

    private const CACHE_SECONDS = 600;

    /** @var array<string, array{at: int, value: array<string, mixed>}> */
    private static array $cached = [];

    /**
     * @param (callable(string): array{0: int, 1: ?array})|null $fetch A GET with the admin key, for tests
     * @param list<string> $projects Other projects to report on their own, by the setting naming each
     */
    public function __construct(private readonly mixed $fetch = null, private readonly array $projects = [])
    {
    }

    public static function configured(): bool
    {
        return self::adminKey() !== '';
    }

    /** @return list<array{date: string, usd: float}> Oldest first; a line that is not a date and an amount is skipped */
    public static function topups(?string $text = null): array
    {
        $out = [];

        foreach (preg_split('/\R/', $text ?? (string) Settings::getSetting(self::TOPUPS, '')) ?: [] as $line) {
            if (preg_match('/^\s*(\d{4}-\d{2}-\d{2})\s+\$?\s*(\d+(?:\.\d+)?)\s*$/', $line, $m) === 1 && strtotime($m[1] . ' UTC') !== false) {
                $out[] = ['date' => $m[1], 'usd' => (float) $m[2]];
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $out;
    }

    /**
     * Spend and balance, or why there are none.
     *
     * `projects` holds the month's spend of each project named in the constructor, by setting.
     *
     * @return array<string, mixed>
     */
    public function summary(?int $now = null, bool $fresh = false): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'says' => 'No Admin key is saved.'];
        }

        $now    = $now ?? time();
        $topups = self::topups();
        $month  = (int) strtotime(gmdate('Y-m-01 00:00:00', $now) . ' UTC');
        $since  = $topups === [] ? null : (int) strtotime($topups[0]['date'] . ' UTC');
        $from   = min($month, $since ?? $month);
        $key    = $from . ':' . self::project() . ':' . implode(',', array_map([self::class, 'project'], $this->projects));

        if (!$fresh && isset(self::$cached[$key]) && self::$cached[$key]['at'] > $now - self::CACHE_SECONDS) {
            return self::$cached[$key]['value'];
        }

        $days = $this->days($from);

        if ($days === null) {
            return ['ok' => false, 'says' => 'OpenAI did not answer with its costs. The Admin key may be refused.'];
        }

        $sum = static function (int $start, ?string $project = null) use ($days): float {
            $total = 0.0;

            foreach ($days as $day) {
                if ($day['start'] >= $start && ($project === null || $day['project'] === $project)) {
                    $total += $day['usd'];
                }
            }

            return round($total, 4);
        };

        $projects = [];

        foreach ($this->projects as $setting) {
            $projects[$setting] = self::project($setting) === '' ? null : $sum($month, self::project($setting));
        }

        $toppedUp = array_sum(array_column($topups, 'usd'));
        $spent    = $since === null ? null : $sum($since);
        $value    = [
            'ok'                => true,
            'says'              => '',
            'month_usd'         => $sum($month),
            'project_month_usd' => self::project() === '' ? null : $sum($month, self::project()),
            'projects'          => $projects,
            'meter_month_usd'   => (new CallLog())->spentOn('openai', $month),
            'since'             => $topups[0]['date'] ?? null,
            'spent_since_usd'   => $spent,
            'topped_up_usd'     => round($toppedUp, 2),
            'balance_usd'       => $spent === null ? null : round($toppedUp - $spent, 2),
        ];

        self::$cached[$key] = ['at' => $now, 'value' => $value];

        return $value;
    }

    /** Forget what was read, after the settings change. */
    public static function forget(): void
    {
        self::$cached = [];
    }

    /**
     * Every day's billed USD since `$from`, by project, through every page.
     *
     * @return list<array{start: int, project: string, usd: float}>|null
     */
    private function days(int $from): ?array
    {
        $out  = [];
        $page = null;

        // ponytail: 20 pages of 31 days is over a year and a half; past that it stops rather than loops.
        for ($i = 0; $i < 20; $i++) {
            $query = http_build_query(['start_time' => $from, 'bucket_width' => '1d', 'limit' => 31, 'group_by' => ['project_id']]
                + ($page === null ? [] : ['page' => $page]));
            [$status, $body] = $this->get(self::URL . '?' . preg_replace('/%5B\d+%5D/', '', $query));

            if ($status !== 200 || !is_array($body)) {
                return null;
            }

            foreach ((array) ($body['data'] ?? []) as $bucket) {
                foreach ((array) ($bucket['results'] ?? []) as $result) {
                    $out[] = [
                        'start'   => (int) ($bucket['start_time'] ?? 0),
                        'project' => (string) ($result['project_id'] ?? ''),
                        'usd'     => (float) ($result['amount']['value'] ?? 0),
                    ];
                }
            }

            $page = $body['next_page'] ?? null;

            if (empty($body['has_more']) || $page === null || $page === '') {
                break;
            }
        }

        return $out;
    }

    /** @return array{0: int, 1: ?array<mixed>} */
    private function get(string $url): array
    {
        if ($this->fetch !== null) {
            return ($this->fetch)($url);
        }

        try {
            $response = \Pramnos\Http\Client::get($url)->bearerToken(self::adminKey())
                ->header('Accept', 'application/json')->timeout(30)->send();
            $decoded  = json_decode($response->body(), true);

            return [$response->status(), is_array($decoded) ? $decoded : null];
        } catch (\Throwable) {
            return [0, null];
        }
    }

    private static function adminKey(): string
    {
        try {
            return \Pramnos\Security\Encrypter::maybeDecrypt(trim((string) Settings::getSetting(self::ADMIN_KEY, '')));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function project(string $setting = self::PROJECT): string
    {
        return trim((string) Settings::getSetting($setting, ''));
    }
}
