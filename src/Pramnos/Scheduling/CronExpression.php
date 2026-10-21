<?php

namespace Pramnos\Scheduling;

/**
 * Parses and evaluates standard 5-field cron expressions.
 *
 * Field order: minute hour day-of-month month day-of-week
 *
 * Supports:
 *   - `*`        — any value
 *   - `5`        — specific value
 *   - `1-5`      — inclusive range
 *   - `*\/5`     — step over the full range
 *   - `1-15\/3`  — step within a range
 *   - `1,3,5`    — comma-separated list (each item may itself be a range or step)
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class CronExpression
{
    /** Minimum and maximum for each cron field index. */
    private const FIELD_RANGES = [
        0 => [0, 59],   // minute
        1 => [0, 23],   // hour
        2 => [1, 31],   // day-of-month
        3 => [1, 12],   // month
        4 => [0, 6],    // day-of-week (0 = Sunday)
    ];

    private readonly string $expression;

    /** @var string[] The five raw field strings. */
    private readonly array $fields;

    /**
     * @param string $expression A 5-field cron expression, e.g. '0 2 * * *'.
     * @throws \InvalidArgumentException When the expression does not have exactly 5 fields.
     */
    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException(
                "Cron expression must have exactly 5 fields, got: '{$expression}'"
            );
        }

        $this->expression = $expression;
        $this->fields     = $parts;
    }

    /**
     * Returns the original expression string.
     */
    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * Returns a new CronExpression with the minute and hour fields replaced by
     * the given time string ('HH:MM' or 'H:MM').
     *
     * Used to implement fluent ->at('02:30') chaining on daily/weekly tasks.
     *
     * @param string $time 'HH:MM' or 'H:MM'
     */
    public function withTime(string $time): self
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            throw new \InvalidArgumentException("Invalid time string '{$time}'. Expected HH:MM.");
        }

        $hour   = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            throw new \InvalidArgumentException("Time '{$time}' is out of range (00:00–23:59).");
        }

        $fields    = $this->fields;
        $fields[0] = (string) $minute;
        $fields[1] = (string) $hour;

        return new self(implode(' ', $fields));
    }

    /**
     * Returns true when the expression is due at the given moment.
     *
     * Evaluation is based on the date/time components of $time, rounded to the
     * nearest minute (seconds are ignored).
     */
    public function isDue(\DateTimeInterface $time): bool
    {
        $values = [
            0 => (int) $time->format('i'),  // minute
            1 => (int) $time->format('G'),  // hour (0-23)
            2 => (int) $time->format('j'),  // day of month
            3 => (int) $time->format('n'),  // month
            4 => (int) $time->format('w'),  // day of week (0=Sun)
        ];

        foreach ($this->fields as $index => $field) {
            [$min, $max] = self::FIELD_RANGES[$index];
            if (!$this->fieldMatches($field, $values[$index], $min, $max)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first minute after `$after` the expression is due, or null when none falls within
     * five years (an expression such as `0 0 31 2 *`, which never matches).
     *
     * Day by day, then the hours and minutes the expression allows on a matching day — not
     * minute by minute, which for a yearly task is half a million checks.
     */
    public function nextRunAfter(\DateTimeInterface $after): ?\DateTimeImmutable
    {
        return $this->scan($after, 1);
    }

    /**
     * The last minute before `$before` the expression was due, or null when none falls within
     * five years — when a run was last expected, for telling a task that is late.
     */
    public function previousRunBefore(\DateTimeInterface $before): ?\DateTimeImmutable
    {
        return $this->scan($before, -1);
    }

    /**
     * The expression in words, for a screen: "Every 5 minutes", "Daily at 02:30",
     * "At :05, :20, :35, :50 every hour". The expression itself for a shape with no wording.
     * Through `t()` where it exists.
     */
    public function describe(): string
    {
        [$minute, $hour, $dom, $month, $dow] = $this->fields;
        $t    = static fn (string $words): string => function_exists('t') ? (string) t($words) : $words;
        $time = static fn (string $h, string $m): string => sprintf('%02d:%02d', (int) $h, (int) $m);
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $num  = '/^\d+$/';

        if ($month !== '*') {
            return $this->expression;
        }
        if ($minute === '*' && $hour === '*' && $dom === '*' && $dow === '*') {
            return $t('Every minute');
        }
        if (preg_match('/^\*\/(\d+)$/', $minute, $m) && $hour === '*' && $dom === '*' && $dow === '*') {
            return sprintf($t('Every %d minutes'), (int) $m[1]);
        }
        if (preg_match('/^\d+(,\d+)*$/', $minute) && $hour === '*' && $dom === '*' && $dow === '*') {
            $at = implode(', ', array_map(static fn (string $v): string => sprintf(':%02d', (int) $v), explode(',', $minute)));

            return sprintf($t('At %s every hour'), $at);
        }
        if (preg_match($num, $minute) && preg_match('/^\*\/(\d+)$/', $hour, $m) && $dom === '*' && $dow === '*') {
            return sprintf($t('Every %d hours at :%02d'), (int) $m[1], (int) $minute);
        }
        if (!preg_match($num, $minute) || !preg_match($num, $hour)) {
            return $this->expression;
        }
        if ($dom === '*' && $dow === '*') {
            return sprintf($t('Daily at %s'), $time($hour, $minute));
        }
        if ($dom === '*' && preg_match('/^[0-6]$/', $dow)) {
            return sprintf($t('Every %s at %s'), $t($days[(int) $dow]), $time($hour, $minute));
        }
        if ($dow === '*' && preg_match($num, $dom)) {
            return sprintf($t('Monthly on day %d at %s'), (int) $dom, $time($hour, $minute));
        }

        return $this->expression;
    }

    /**
     * The nearest due minute strictly after (`$direction` 1) or before (-1) `$from`.
     */
    private function scan(\DateTimeInterface $from, int $direction): ?\DateTimeImmutable
    {
        $allowed = [];
        foreach ($this->fields as $index => $field) {
            [$min, $max]     = self::FIELD_RANGES[$index];
            $allowed[$index] = array_values(array_filter(
                range($min, $max),
                fn (int $value): bool => $this->fieldMatches($field, $value, $min, $max)
            ));
        }
        $hours   = $direction > 0 ? $allowed[1] : array_reverse($allowed[1]);
        $minutes = $direction > 0 ? $allowed[0] : array_reverse($allowed[0]);
        $start   = \DateTimeImmutable::createFromInterface($from);
        $start   = $start->setTime((int) $start->format('G'), (int) $start->format('i'));
        $midnight = $start->setTime(0, 0);

        for ($day = 0; $day <= 366 * 5; $day++) {
            $date = $midnight->modify(($direction * $day) . ' days');
            if (!in_array((int) $date->format('j'), $allowed[2], true)
                || !in_array((int) $date->format('n'), $allowed[3], true)
                || !in_array((int) $date->format('w'), $allowed[4], true)) {
                continue;
            }
            foreach ($hours as $hour) {
                foreach ($minutes as $minute) {
                    $at = $date->setTime($hour, $minute);
                    if ($direction > 0 ? $at > $start : $at < $start) {
                        return $at;
                    }
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Internal parsing
    // -------------------------------------------------------------------------

    private function fieldMatches(string $field, int $value, int $min, int $max): bool
    {
        // Comma-separated list: each part is evaluated independently
        if (str_contains($field, ',')) {
            foreach (explode(',', $field) as $part) {
                if ($this->fieldMatches(trim($part), $value, $min, $max)) {
                    return true;
                }
            }
            return false;
        }

        // Step: */N  or  start-end/N
        if (str_contains($field, '/')) {
            [$rangeStr, $stepStr] = explode('/', $field, 2);
            $step = (int) $stepStr;
            if ($step < 1) {
                return false;
            }

            if ($rangeStr === '*') {
                $start = $min;
                $end   = $max;
            } elseif (str_contains($rangeStr, '-')) {
                [$start, $end] = $this->parseRange($rangeStr);
            } else {
                $start = (int) $rangeStr;
                $end   = $max;
            }

            for ($v = $start; $v <= $end; $v += $step) {
                if ($v === $value) {
                    return true;
                }
            }
            return false;
        }

        // Range: N-M
        if (str_contains($field, '-')) {
            [$start, $end] = $this->parseRange($field);
            return $value >= $start && $value <= $end;
        }

        // Wildcard
        if ($field === '*') {
            return true;
        }

        // Exact value
        return (int) $field === $value;
    }

    /** @return int[] [start, end] */
    private function parseRange(string $range): array
    {
        [$start, $end] = explode('-', $range, 2);
        return [(int) $start, (int) $end];
    }
}
