<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * What is watched on a metric, and who is told when it happens.
 *
 * ```php
 * // Payments at nought for two hours, between nine and eleven at night: a gateway in trouble.
 * Alerts::create(['metric' => 'payments.count', 'kind' => Alerts::STOPPED, 'value' => 7200, 'hours' => '9-23',
 *     'notify' => ['users' => [1], 'conversation' => $opsChannel]]);
 * Alerts::create(['metric' => 'registrations', 'grain' => 'day', 'kind' => Alerts::CHANGE, 'op' => 'below',
 *     'value' => -0.3, 'against' => Metrics::YEAR_EARLIER, 'notify' => ['users' => [1]]]);
 * Alerts::evaluate();   // after each metrics:compute
 * ```
 *
 * - **threshold**: the last completed period's value above (or below) `value`;
 * - **change**: its change against the period before, or a year earlier, above (or below) `value`,
 *   a fraction (-0.3 is a fall of 30 %);
 * - **stopped**: the metric at nought — nothing came — for the last `value` seconds of completed
 *   hours. It reads the metric by the hour.
 *
 * `hours` keeps a rule to those hours of the day, in the metrics' zone (`9-23`). A rule that fires
 * tells its users (the bell, and a push) and posts in its conversation, then waits `every` seconds
 * before it may fire again. Each firing is kept in the changelog (`alert.fired`), not a table.
 */
final class Alerts
{
    public const TABLE = 'stats.alert_rules';

    public const THRESHOLD = 'threshold';
    public const CHANGE    = 'change';
    public const STOPPED   = 'stopped';

    /**
     * A rule; its id.
     *
     * @param array{metric: string, kind: string, grain?: string, filters?: array<string, mixed>, tenant?: int, op?: string, value?: float|int, against?: string, hours?: string, notify?: array{users?: list<int>, conversation?: int}, every?: int, name?: string, by?: int} $rule
     */
    public static function create(array $rule): int
    {
        $metric = Metrics::get((string) ($rule['metric'] ?? ''));
        $kind   = (string) ($rule['kind'] ?? '');
        $grain  = $kind === self::STOPPED ? Window::HOUR : (string) ($rule['grain'] ?? Window::DAY);
        if (!in_array($kind, [self::THRESHOLD, self::CHANGE, self::STOPPED], true)) {
            throw new \InvalidArgumentException(t('An alert is a threshold, a change or data that stopped.'));
        }
        if (!in_array($grain, Window::GRAINS, true) || !in_array($rule['op'] ?? 'above', ['above', 'below'], true)) {
            throw new \InvalidArgumentException(t('An alert needs a grain and above or below.'));
        }
        $hours = (string) ($rule['hours'] ?? '');
        if ($hours !== '' && preg_match('/^([01]?\d|2[0-3])-([01]?\d|2[0-4])$/', $hours) !== 1) {
            throw new \InvalidArgumentException(t('Hours are from-to, e.g. 9-23.'));
        }
        $db = Metrics::connection();
        $db->queryBuilder()->table(self::TABLE)->insert([
            'name' => mb_substr((string) ($rule['name'] ?? ''), 0, 150), 'metric' => $metric->name, 'grain' => $grain,
            'filters' => empty($rule['filters']) ? null : json_encode($rule['filters'], JSON_UNESCAPED_UNICODE), 'tenant' => (int) ($rule['tenant'] ?? 0),
            'kind' => $kind, 'op' => (string) ($rule['op'] ?? 'above'), 'value' => (float) ($rule['value'] ?? 0),
            'against' => (string) ($rule['against'] ?? Metrics::PREVIOUS), 'hours' => $hours,
            'notify' => json_encode((object) ($rule['notify'] ?? []), JSON_UNESCAPED_UNICODE), 'every' => max(60, (int) ($rule['every'] ?? 3600)),
            'lastfired' => 0, 'enabled' => 1, 'createdby' => $rule['by'] ?? null, 'createdat' => time(),
        ]);

        return (int) $db->getInsertId();
    }

    /** Switch a rule on or off. */
    public static function enable(int $id, bool $on = true): void
    {
        Metrics::connection()->queryBuilder()->table(self::TABLE)->where('ruleid', $id)->update(['enabled' => $on ? 1 : 0]);
    }

    /** Take a rule away. */
    public static function delete(int $id): void
    {
        Metrics::connection()->queryBuilder()->table(self::TABLE)->where('ruleid', $id)->delete();
    }

    /**
     * Check every rule that is on; tell of each that fires and may fire again. Run after
     * `metrics:compute`.
     *
     * @return array<int, string> The rules that fired, with what they said
     */
    public static function evaluate(?int $now = null): array
    {
        $now   = $now ?? time();
        $fired = [];
        $rules = Metrics::connection()->queryBuilder()->table(self::TABLE)->where('enabled', 1)->getAllOrFail();
        foreach ($rules as $rule) {
            try {
                if ($now - (int) $rule['lastfired'] < (int) $rule['every'] || !self::inHours((string) $rule['hours'], $now)) {
                    continue;
                }
                $said = self::check($rule, $now);
                if ($said !== null) {
                    self::fire($rule, $said, $now);
                    $fired[(int) $rule['ruleid']] = $said;
                }
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('Alert ' . $rule['ruleid'] . ' not checked: ' . $e->getMessage(), 'metrics');
            }
        }

        return $fired;
    }

    /**
     * What a rule says now, or null when it does not fire.
     *
     * @param array<string, mixed> $rule
     */
    private static function check(array $rule, int $now): ?string
    {
        $metric  = Metrics::get((string) $rule['metric']);
        $filters = (array) json_decode((string) ($rule['filters'] ?? ''), true);
        $label   = (string) $rule['name'] !== '' ? (string) $rule['name'] : $metric->label();
        $above   = $rule['op'] === 'above';
        $value   = (float) $rule['value'];
        $query   = Metrics::query($metric->name)->where($filters);
        if ((int) $rule['tenant'] > 0) {
            $query->tenant((int) $rule['tenant']);
        }
        if ($rule['kind'] === self::STOPPED) {
            // The completed hours of the last `value` seconds: nought in every one of them.
            $last  = Window::of(Window::HOUR, $now)->previous();
            $first = Window::of(Window::HOUR, $last->startsAt() - max(3600, (int) $value) + 3600);
            $hours = $query->grain(Window::HOUR)->between($first->key(), $last->key())->series()['values'][''] ?? [];
            $sum   = array_sum(array_map(static fn (?float $v): float => (float) $v, $hours));

            return $sum == 0.0 ? sprintf(t('%s: nothing for %s hours, since %s.'), $label, (string) count($hours), $first->label()) : null;
        }
        $period = Window::of((string) $rule['grain'], $now)->previous();
        $query->grain($period->grain)->at($period->key());
        if ($rule['kind'] === self::THRESHOLD) {
            $current = $query->value();
            if ($current === null || ($above ? $current <= $value : $current >= $value)) {
                return null;
            }

            return sprintf(t('%s was %s in %s: %s %s.'), $label, (string) round($current, 2), $period->label(), $above ? t('above') : t('below'), (string) $value);
        }
        $compared = $query->compare((string) $rule['against'])->compared();
        $change   = $compared['change'];
        if ($change === null || ($above ? $change <= $value : $change >= $value)) {
            return null;
        }

        return sprintf(t('%s in %s: %s%% against %s.'), $label, $compared['period'], sprintf('%+.1f', $change * 100), $compared['against']);
    }

    /**
     * Tell of a rule that fired: its users, its conversation; kept in the changelog.
     *
     * @param array<string, mixed> $rule
     */
    private static function fire(array $rule, string $said, int $now): void
    {
        $id     = (int) $rule['ruleid'];
        $notify = (array) json_decode((string) ($rule['notify'] ?? ''), true);
        foreach (array_map('intval', (array) ($notify['users'] ?? [])) as $person) {
            try {
                $user = new \Pramnos\User\User($person);
                if ((int) ($user->userid ?? 0) === $person) {
                    $user->notify(new AlertNotification($id, $said));
                }
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('Alert ' . $id . ': ' . $person . ' not told: ' . $e->getMessage(), 'metrics');
            }
        }
        if ((int) ($notify['conversation'] ?? 0) > 0) {
            try {
                \Pramnos\Conversations\Messages::post((int) $notify['conversation'], null, $said);
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('Alert ' . $id . ': not posted: ' . $e->getMessage(), 'metrics');
            }
        }
        \Pramnos\Changelog\ChangelogWriter::event('alert', $id, 'alert.fired', ['metric' => $rule['metric'], 'said' => $said], at: $now);
        Metrics::connection()->queryBuilder()->table(self::TABLE)->where('ruleid', $id)->update(['lastfired' => $now]);
    }

    /** Whether now is within a rule's hours of the day (`9-23`), in the metrics' zone; empty: always. */
    private static function inHours(string $hours, int $now): bool
    {
        if ($hours === '') {
            return true;
        }
        [$from, $to] = array_map('intval', explode('-', $hours));
        $hour = (int) (new \DateTimeImmutable('@' . $now))->setTimezone(Window::zone())->format('G');

        return $hour >= $from && $hour < $to;
    }
}
