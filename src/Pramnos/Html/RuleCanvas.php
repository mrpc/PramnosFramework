<?php

declare(strict_types=1);

namespace Pramnos\Html;

use Pramnos\Audience\Rules\Actions;

/**
 * A rule drawn as boxes: when, who, each step in order, branches side by side, how many went
 * through each — the rule's page.
 *
 * ```php
 * echo RuleCanvas::render($rule['definition'], RuleCanvas::counts($rule['id']));
 * ```
 *
 * A step two branches lead to is drawn once, where it is first reached; the other way says "go
 * to". Neutral `pf-rule-*` markup the theme's stylesheet dresses.
 */
final class RuleCanvas
{
    /**
     * How many runs reached each step, and what they came to.
     *
     * @return array<string, array{reached: int, outcomes: array<string, int>}> By step
     */
    public static function counts(int $rule): array
    {
        $rows = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#rule_log')
            ->select(['step', 'outcome', new \Pramnos\Database\Expression('COUNT(DISTINCT runid) AS n')])
            ->where('ruleid', $rule)->whereNotNull('step')->groupBy(['step', 'outcome'])->getAllOrFail();
        $counts = [];
        foreach ($rows as $row) {
            $step = (string) $row['step'];
            $counts[$step]['outcomes'][(string) $row['outcome']] = (int) $row['n'];
        }
        foreach ($counts as $step => $count) {
            // Each run logs a step at least once; the largest outcome is a floor for how many reached it.
            $counts[$step]['reached'] = max($count['outcomes']);
        }

        return $counts;
    }

    /**
     * The boxes.
     *
     * @param array<string, mixed>                                              $definition Checked
     * @param array<string, array{reached: int, outcomes: array<string, int>}> $counts
     */
    public static function render(array $definition, array $counts = []): string
    {
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $drawn = [];
        $html  = '<div class="pf-rule-canvas">'
            . '<div class="pf-rule-box pf-rule-when"><b>' . $e(t('When')) . '</b>' . $e(self::trigger($definition['trigger'])) . '</div>'
            . ($definition['who']['conditions'] === [] ? '' : '<div class="pf-rule-box"><b>' . $e(t('Who')) . '</b>'
                . $e(t('%s conditions', (string) count($definition['who']['conditions']))) . '</div>')
            . self::chain($definition['steps'], $definition['start'], $counts, $drawn);
        if ($definition['exit']['conditions'] !== [] || $definition['goal'] !== null) {
            $html .= '<div class="pf-rule-box pf-rule-until"><b>' . $e(t('Until')) . '</b>'
                . ($definition['exit']['conditions'] !== [] ? $e(t('leaves when the exit matches')) : '')
                . ($definition['goal'] !== null ? ' ' . $e(t('goal: %s within %s days', $definition['goal']['event'], (string) $definition['goal']['days'])) : '')
                . '</div>';
        }

        return $html . '</div>';
    }

    /**
     * A step and what follows it, until the end or a step already drawn.
     *
     * @param array<string, array<string, mixed>>                                $steps
     * @param array<string, array{reached: int, outcomes: array<string, int>}> $counts
     * @param array<string, true>                                                $drawn
     */
    private static function chain(array $steps, ?string $id, array $counts, array &$drawn): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        if ($id === null) {
            return '<div class="pf-rule-end">' . $e(t('End')) . '</div>';
        }
        if (isset($drawn[$id])) {
            return '<div class="pf-rule-goto">→ ' . $e(t('go to %s', $id)) . '</div>';
        }
        $drawn[$id] = true;
        $step  = $steps[$id];
        $count = $counts[$id] ?? ['reached' => 0, 'outcomes' => []];
        $box   = '<div class="pf-rule-box pf-rule-' . $e($step['type']) . '" data-step="' . $e($id) . '"><b>' . $e(self::title($step)) . '</b>'
            . $e(self::detail($step))
            . '<span class="pf-rule-count">' . $e(t('%s passed', number_format($count['reached'])))
            . ($count['outcomes'] === [] ? '' : ' · ' . $e(implode(', ', array_map(static fn ($k, $n): string => $k . ' ' . $n, array_keys($count['outcomes']), $count['outcomes']))))
            . '</span></div>';
        $ways = match ($step['type']) {
            'branch'     => [t('Yes') => $step['yes'], t('No') => $step['no']],
            'wait_event' => [t('It happened') => $step['next'], t('Time ran out') => $step['timeout']],
            'split'      => array_combine(array_map(static fn (array $v): string => $v['key'] . ' · ' . $v['share'] . '%', $step['variants']), array_column($step['variants'], 'next')),
            default      => null,
        };
        if ($ways === null) {
            return $box . '<div class="pf-rule-down">↓</div>' . self::chain($steps, $step['next'], $counts, $drawn);
        }
        $branches = '';
        foreach ($ways as $label => $next) {
            $branches .= '<div class="pf-rule-way"><span class="pf-rule-way-label">' . $e((string) $label) . '</span>' . self::chain($steps, $next, $counts, $drawn) . '</div>';
        }

        return $box . '<div class="pf-rule-branches">' . $branches . '</div>';
    }

    /** @param array<string, mixed> $trigger */
    public static function trigger(array $trigger): string
    {
        return match ($trigger['type']) {
            'event'           => t('%s happens', \Pramnos\Audience\PersonEvents::label($trigger['event'])),
            'segment_entered' => t('enters the segment %s', self::segment((int) $trigger['segment'])),
            'segment_left'    => t('leaves the segment %s', self::segment((int) $trigger['segment'])),
            'schedule'        => t('on schedule: %s', (new \Pramnos\Scheduling\CronExpression($trigger['cron']))->describe()),
            default           => t('once, when switched on'),
        };
    }

    /** @param array<string, mixed> $step */
    private static function title(array $step): string
    {
        return match ($step['type']) {
            'action'     => Actions::get($step['action'])['label'] ?? $step['action'],
            'wait'       => t('Wait'),
            'wait_event' => t('Wait for %s', \Pramnos\Audience\PersonEvents::label($step['event'])),
            'branch'     => t('If'),
            default      => t('A/B split'),
        };
    }

    /** @param array<string, mixed> $step */
    private static function detail(array $step): string
    {
        return match ($step['type']) {
            'action'     => implode(' · ', array_filter(array_map(static fn ($v): string => is_scalar($v) ? mb_strimwidth((string) $v, 0, 60, '…') : '', $step['params']))),
            'wait'       => self::duration($step['seconds']),
            'wait_event' => t('up to %s', self::duration($step['seconds'])),
            'branch'     => isset($step['message']) ? t('the message of %s was %s', $step['message']['step'], $step['message']['status'])
                : t('%s conditions', (string) count($step['filter']['conditions'])),
            default      => '',
        };
    }

    /** Seconds as days, hours or minutes. */
    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds % 86400 === 0 => t('%s days', (string) intdiv($seconds, 86400)),
            $seconds % 3600 === 0  => t('%s hours', (string) intdiv($seconds, 3600)),
            default                => t('%s minutes', (string) intdiv($seconds, 60)),
        };
    }

    private static function segment(int $id): string
    {
        try {
            return \Pramnos\Audience\Segments::get($id)['name'] ?? '#' . $id;
        } catch (\Throwable) {
            return '#' . $id;
        }
    }
}
