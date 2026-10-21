<?php

declare(strict_types=1);

namespace Pramnos\Audience;

/**
 * A condition of an explanation ({@see Filter::explain()}) in words: what it asks about, how, the
 * value it asks for, and what the person has — values by their labels, as the builder shows them.
 *
 * ```php
 * Explained::condition($why['conditions'][0]);
 * // ['what' => 'Account type', 'how' => 'is', 'value' => 'Professional', 'actual' => 'Parent']
 * ```
 */
final class Explained
{
    /**
     * @param  array<string, mixed> $condition One condition of an explanation, not a group
     * @return array{what: string, how: string, value: ?string, actual: string}
     */
    public static function condition(array $condition): array
    {
        $words = \Pramnos\Application\Controllers\Audience::operatorWords();
        $how   = $words[$condition['op']] ?? (string) $condition['op'];
        if (isset($condition['segment'])) {
            return ['what' => (string) ($condition['label'] ?? '#' . $condition['segment']), 'how' => $how, 'value' => null,
                'actual' => !empty($condition['actual']) ? t('in it') : t('not in it')];
        }
        if (isset($condition['experiment'])) {
            return ['what' => (string) ($condition['label'] ?? $condition['experiment']),
                'how' => $condition['op'] === 'in' ? t('was given') : t('was not given'), 'value' => (string) $condition['variant'],
                'actual' => $condition['actual'] ?? null ? t('variant %s', (string) $condition['actual']) : t('not in the test')];
        }
        if (isset($condition['event'])) {
            $did = (array) ($condition['actual'] ?? []);
            $actual = (int) ($did['count'] ?? 0) === 0 ? t('never')
                : t('%s times, last %s', (string) $did['count'], date('Y-m-d', (int) $did['last']))
                    . (isset($did['inWindow']) ? ' · ' . t('%s in the window', (string) $did['inWindow']) : '');

            return ['what' => PersonEvents::label((string) $condition['event']), 'how' => $how,
                'value' => array_key_exists('value', $condition) ? (string) $condition['value'] . (isset($condition['days']) ? ' / ' . t('%s days', (string) $condition['days']) : '') : null,
                'actual' => $actual];
        }
        $attribute = Attributes::get((string) $condition['attribute']);

        return [
            'what'   => $attribute?->labelText() ?? (string) $condition['attribute'],
            'how'    => $how,
            'value'  => array_key_exists('value', $condition) ? self::value($attribute, $condition['value']) : null,
            'actual' => self::value($attribute, $condition['actual'] ?? null),
        ];
    }

    /** A value as the person reads it: by its label, yes or no, or a list of them. */
    public static function value(?Attribute $attribute, mixed $value): string
    {
        if (is_array($value)) {
            return $value === [] ? '—' : implode(', ', array_map(static fn ($v): string => self::value($attribute, $v), $value));
        }
        if ($value === null || $value === '') {
            return '—';
        }
        if ($attribute?->type() === Attribute::BOOLEAN) {
            return (int) $value === 1 ? t('yes') : t('no');
        }
        if ($attribute?->type() === Attribute::DATE && $attribute->dateFormat() === 'unix' && is_numeric($value)) {
            return (int) $value === 0 ? '—' : date('Y-m-d', (int) $value);
        }
        $labels = $attribute?->valueLabels() ?? [];

        return (string) ($labels[$value] ?? $value);
    }
}
