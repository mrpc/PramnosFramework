<?php

declare(strict_types=1);

namespace Pramnos\Changelog;

/**
 * Turns a stored change into a sentence, at read time.
 *
 * Nothing in the log stores prose. The feed stores a diff, an application event stores a
 * machine code, and both are rendered here — so the wording can change without a
 * migration and without rewriting history, and so it can be translated.
 *
 * The reference application renders from a `switch` returning hardcoded English keyed on
 * two magic numbers, which is the same idea frozen in PHP: untranslatable, and every
 * correction is a code change that silently reinterprets rows written years ago.
 *
 * ## Labels
 *
 * Register human names for an entity's columns and events, or get the raw identifiers:
 *
 * ```php
 * ChangelogRenderer::label('wcm-device', [
 *     'status'                        => 'Status',
 *     'device.assigned_on_finalize'   => 'Assigned on finalize',
 * ]);
 * ```
 *
 * Lookups go through {@see \Pramnos\Translator\Language} first, so an application with a
 * language file needs none of this.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ChangelogRenderer
{
    /**
     * Registered labels, keyed by entity then by field or event code.
     *
     * @var array<string, array<string, string>>
     */
    protected static array $labels = [];

    /**
     * Register labels for one entity. Merges with anything already registered.
     *
     * @param array<string, string> $labels
     */
    public static function label(string $entity, array $labels): void
    {
        static::$labels[$entity] = $labels + (static::$labels[$entity] ?? []);
    }

    /** Forget every registered label. Tests only. */
    public static function reset(): void
    {
        static::$labels = [];
    }

    /**
     * Describe one row from {@see ChangelogReader::history()}.
     *
     * @param  array<string, mixed> $row
     */
    public static function describe(array $row): string
    {
        $entity = (string) ($row['entity'] ?? '');

        // An application event says what it is. Free text wins over a code, because the
        // only reason to write free text is that no code described the thing.
        if (($row['origin'] ?? '') === 'events') {
            $description = trim((string) ($row['description'] ?? ''));
            if ($description !== '') {
                return $description;
            }

            return static::translate($entity, (string) ($row['event'] ?? ''));
        }

        $op = (string) ($row['op'] ?? '');
        if ($op === \Pramnos\Event\ModelChange::CREATED) {
            return static::translate($entity, $entity) . ' created';
        }
        if ($op === \Pramnos\Event\ModelChange::DELETED) {
            return static::translate($entity, $entity) . ' deleted';
        }

        $changes = $row['changes'] ?? [];
        if (!is_array($changes) || $changes === []) {
            // An update with no readable diff. Saying "updated" is honest; inventing a
            // field list from the row would not be.
            return static::translate($entity, $entity) . ' updated';
        }

        $parts = [];
        foreach ($changes as $field => $change) {
            $parts[] = static::translate($entity, (string) $field) . ': '
                . static::value($change['old'] ?? null) . ' → '
                . static::value($change['new'] ?? null);
        }

        return implode(', ', $parts);
    }

    /**
     * A human name for a field or event code.
     *
     * Language file first, registered labels second, the identifier itself last. Falling
     * back to the identifier is deliberate: a missing label shows `conditionid` rather
     * than an empty string, which is ugly and tells the reader exactly what to register.
     */
    protected static function translate(string $entity, string $key): string
    {
        if ($key === '') {
            return '';
        }

        try {
            $language = \Pramnos\Translator\Language::getInstance();
            $tag      = 'changelog.' . $entity . '.' . $key;
            $result   = $language->_($tag);

            if ($result !== '' && $result !== $tag) {
                return $result;
            }
        } catch (\Throwable) {
            // No language configured — the registered labels below still apply.
        }

        return static::$labels[$entity][$key] ?? $key;
    }

    /**
     * A stored value, as something readable.
     *
     * Null becomes the word rather than an empty gap, because "status: → 3" reads as a
     * rendering bug while "status: (none) → 3" reads as what happened.
     */
    protected static function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '(none)';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '(unreadable)';
    }
}
