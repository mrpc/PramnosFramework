<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Database\QueryBuilder;

/**
 * The dates a question is about, and the periods its series are counted in — in the analyst's
 * time zone, {@see \Pramnos\Llm\Analyst\Analyst::timezone()}: "yesterday" and "per day" are the
 * administrator's days. The window's ends are moments, so a Unix column compares as it is and a
 * timestamp column is compared in UTC.
 */
final class Window
{
    private function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly string $group,
        private readonly \DateTimeZone $zone = new \DateTimeZone('UTC'),
    ) {
    }

    /**
     * `days` (30 by default), or `from`/`to` as YYYY-MM-DD; `group` day, week or month,
     * chosen from the length when not given.
     *
     * @param array<string, mixed> $input
     */
    public static function from(array $input, ?int $now = null, ?\DateTimeZone $zone = null): self
    {
        $zone = $zone ?? new \DateTimeZone(\Pramnos\Llm\Analyst\Analyst::timezone());
        $now  = $now ?? time();
        $to   = isset($input['to']) && $input['to'] !== '' ? self::date((string) $input['to'], $zone, true) : $now;

        if (isset($input['from']) && $input['from'] !== '') {
            $from = self::date((string) $input['from'], $zone, false);
        } else {
            $days = max(1, min(AdminTool::MAX_DAYS, (int) ($input['days'] ?? 30)));
            $from = $to - $days * 86400;
        }

        if ($from > $to) {
            throw new \InvalidArgumentException('the window starts after it ends.');
        }
        if ($to - $from > AdminTool::MAX_DAYS * 86400) {
            $from = $to - AdminTool::MAX_DAYS * 86400;
        }

        $days  = ($to - $from) / 86400;
        $group = in_array($input['group'] ?? '', ['day', 'week', 'month'], true)
            ? (string) $input['group']
            : ($days <= 31 ? 'day' : ($days <= 180 ? 'week' : 'month'));

        return new self($from, $to, $group, $zone);
    }

    /** The window as the tool reports it. @return array{from: string, to: string, group: string} */
    public function describe(): array
    {
        return ['from' => $this->format('Y-m-d', $this->from), 'to' => $this->format('Y-m-d', $this->to), 'group' => $this->group, 'timezone' => $this->zone->getName()];
    }

    /** Limit a query to rows whose time column falls in the window. */
    public function apply(QueryBuilder $query, string $column, bool $unix): QueryBuilder
    {
        return $unix
            ? $query->where($column, '>=', $this->from)->where($column, '<=', $this->to)
            : $query->where($column, '>=', gmdate('Y-m-d H:i:s', $this->from))->where($column, '<=', gmdate('Y-m-d H:i:s', $this->to));
    }

    /** @return array<string, int> Every period of the window, at zero */
    public function buckets(): array
    {
        $out = [];
        for ($at = $this->from; $at <= $this->to; $at += 86400) {
            $out[(string) $this->label($at)] = 0;
        }
        $out[(string) $this->label($this->to)] = 0;

        return $out;
    }

    /** The period a moment falls in, or null outside the window. */
    public function label(int $at): ?string
    {
        if ($at < $this->from || $at > $this->to) {
            return null;
        }

        return $this->format(match ($this->group) {
            'week'  => 'o-\WW',
            'month' => 'Y-m',
            default => 'Y-m-d',
        }, $at);
    }

    private function format(string $format, int $at): string
    {
        return (new \DateTimeImmutable('@' . $at))->setTimezone($this->zone)->format($format);
    }

    /** A day's first moment, or its last, in the zone. */
    private static function date(string $value, \DateTimeZone $zone, bool $end): int
    {
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);

        if ($at === false) {
            throw new \InvalidArgumentException('a date is YYYY-MM-DD: "' . $value . '" is not.');
        }

        return $end ? $at->modify('+1 day')->getTimestamp() - 1 : $at->getTimestamp();
    }
}
