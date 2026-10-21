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
            . self::box('pf-rule-when pf-rule-tone-when', 'rule', t('When'), self::trigger($definition['trigger']))
            . ($definition['who']['conditions'] === [] ? '' : '<div class="pf-rule-down"></div>'
                . self::box('pf-rule-tone-who', 'members', t('Who'), self::plural(count($definition['who']['conditions']), '%s condition', '%s conditions')))
            . '<div class="pf-rule-down"></div>'
            . self::chain($definition['steps'], $definition['start'], $counts, $drawn);
        if ($definition['exit']['conditions'] !== [] || $definition['goal'] !== null) {
            $html .= '<div class="pf-rule-down"></div>' . self::box('pf-rule-until pf-rule-tone-until', 'goal', t('Until'), trim(
                ($definition['exit']['conditions'] !== [] ? t('leaves when the exit matches') : '')
                . ($definition['goal'] !== null ? ' ' . t('goal: %s within %s days', \Pramnos\Audience\PersonEvents::label($definition['goal']['event']), (string) $definition['goal']['days']) : '')
            ));
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
            return '<div class="pf-rule-goto">→ ' . $e(t('go to %s', self::stepLabels($steps)[$id] ?? $id)) . '</div>';
        }
        $drawn[$id] = true;
        $step  = $steps[$id];
        $count = $counts[$id] ?? ['reached' => 0, 'outcomes' => []];
        $kind  = self::kind($step);
        $box   = '<div class="pf-rule-box pf-rule-' . $e($step['type']) . ' pf-rule-step-' . $kind . '" data-step="' . $e($id) . '">'
            . '<div class="pf-rule-step-head"><span class="pf-rule-icon">' . Icon::svg(self::ICONS[$kind], '') . '</span>'
            . '<span class="pf-rule-num">' . (array_search($id, array_keys($steps), true) + 1) . '</span><span class="pf-rule-step-title">' . $e(self::title($step)) . '</span>'
            . '<span class="pf-badge pf-tone-accent pf-rule-count">' . $e(t('%s passed', number_format($count['reached']))) . '</span></div>'
            . (self::detail($step, $steps) === '' ? '' : '<span class="pf-muted">' . $e(self::detail($step, $steps)) . '</span>')
            . ($count['outcomes'] === [] ? '' : '<span class="pf-rule-outcomes">' . $e(implode(' · ', array_map(static fn ($k, $n): string => t((string) $k) . ' ' . number_format($n),
                array_keys($count['outcomes']), $count['outcomes']))) . '</span>')
            . '</div>';
        $ways = match ($step['type']) {
            'branch'     => [t('Yes') => $step['yes'], t('No') => $step['no']],
            'wait_event' => [t('It happened') => $step['next'], t('Time ran out') => $step['timeout']],
            'split'      => array_combine(array_map(static fn (array $v): string => $v['key'] . ' · ' . $v['share'] . '%', $step['variants']), array_column($step['variants'], 'next')),
            default      => null,
        };
        if ($ways === null) {
            return $box . '<div class="pf-rule-down"></div>' . self::chain($steps, $step['next'], $counts, $drawn);
        }
        $branches = '';
        foreach ($ways as $label => $next) {
            $branches .= '<div class="pf-rule-way"><span class="pf-rule-way-label">' . $e((string) $label) . '</span>' . self::chain($steps, $next, $counts, $drawn) . '</div>';
        }

        return $box . '<div class="pf-rule-branches">' . $branches . '</div>';
    }

    /** The icon of each kind of step. */
    private const ICONS = ['message' => 'send', 'action' => 'action', 'wait' => 'clock', 'wait_event' => 'rule', 'branch' => 'branch', 'split' => 'split'];

    /**
     * A step's kind as drawn: an action on a channel is a message.
     *
     * @param array<string, mixed> $step
     */
    private static function kind(array $step): string
    {
        if ($step['type'] === 'action') {
            return (Actions::get($step['action'])['channel'] ?? null) !== null ? 'message' : 'action';
        }

        return $step['type'];
    }

    /** One box: its icon, its title, and a line. */
    private static function box(string $class, string $icon, string $title, string $line): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        return '<div class="pf-rule-box ' . $class . '"><div class="pf-rule-step-head"><span class="pf-rule-icon">' . Icon::svg($icon, '') . '</span>'
            . '<span class="pf-rule-step-title">' . $e($title) . '</span></div><span class="pf-muted">' . $e($line) . '</span></div>';
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

    /**
     * @param array<string, mixed>                $step
     * @param array<string, array<string, mixed>> $steps
     */
    private static function detail(array $step, array $steps = []): string
    {
        return match ($step['type']) {
            'action'     => implode(' · ', array_filter(array_map(static fn ($v): string => is_scalar($v) ? mb_strimwidth((string) $v, 0, 60, '…') : '', $step['params']))),
            'wait'       => self::duration($step['seconds']),
            'wait_event' => t('up to %s', self::duration($step['seconds'])),
            'branch'     => isset($step['message']) ? t('the message of %s was %s', self::stepLabels($steps)[$step['message']['step']] ?? (string) $step['message']['step'], (string) $step['message']['status'])
                : self::plural(count($step['filter']['conditions']), '%s condition', '%s conditions'),
            default      => '',
        };
    }

    /** Seconds as days, hours or minutes. */
    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds % 86400 === 0 => self::plural(intdiv($seconds, 86400), '%s day', '%s days'),
            $seconds % 3600 === 0  => self::plural(intdiv($seconds, 3600), '%s hour', '%s hours'),
            default                => self::plural(intdiv($seconds, 60), '%s minute', '%s minutes'),
        };
    }

    /**
     * Each step named as an administrator reads it — its place and what it does, "2. Send an
     * email" — never its id.
     *
     * @param  array<string, array<string, mixed>> $steps
     * @return array<string, string> By step id
     */
    public static function stepLabels(array $steps): array
    {
        $labels = [];
        foreach (array_keys($steps) as $i => $id) {
            $labels[$id] = ($i + 1) . '. ' . self::title($steps[$id]);
        }

        return $labels;
    }

    /** A count with its noun, one or many. */
    private static function plural(int $n, string $one, string $many): string
    {
        return t($n === 1 ? $one : $many, number_format($n));
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
