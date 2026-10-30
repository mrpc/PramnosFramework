<?php

declare(strict_types=1);

namespace Pramnos\Llm;

/**
 * What a value breaks of a JSON schema — the part of it tools and structured answers use:
 * `type` (one, or a list), `required`, `properties`, `items`, `enum`, `minimum`/`maximum`,
 * `minLength`/`maxLength`, `minItems`/`maxItems`. Anything else in a schema is not checked.
 *
 * Lenient where a model is honestly ambiguous: a number written as text (`"5"`) is a number, and
 * an integer is a number. Strict where a call would go wrong: a missing required field, a value
 * outside its `enum`, a list where an object belongs.
 *
 * ```php
 * JsonSchema::errors(['to' => 5], ['type' => 'object', 'properties' => ['to' => ['type' => 'string']], 'required' => ['to', 'subject']]);
 * // ['to: must be a string', 'subject: is required']
 * ```
 */
final class JsonSchema
{
    /**
     * Each way the value breaks the schema, with where; none when it fits.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    public static function errors(mixed $value, array $schema, string $path = ''): array
    {
        $at    = $path === '' ? '' : $path . ': ';
        $types = (array) ($schema['type'] ?? []);
        if ($types !== [] && !in_array(true, array_map(static fn ($t): bool => self::is($value, (string) $t), $types), true)) {
            return [$at . 'must be ' . implode(' or ', array_map(static fn ($t): string => self::article((string) $t), $types))];
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], false)) {
            return [$at . 'must be one of ' . implode(', ', array_map(static fn ($v): string => (string) json_encode($v), $schema['enum']))];
        }

        $errors = [];
        if (is_array($value) && !array_is_list($value) || $value instanceof \stdClass || (is_array($value) && $value === [] && in_array('object', $types, true))) {
            $object = (array) $value;
            foreach ((array) ($schema['required'] ?? []) as $name) {
                if (!array_key_exists((string) $name, $object)) {
                    $errors[] = ($path === '' ? '' : $path . '.') . $name . ': is required';
                }
            }
            foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
                if (array_key_exists((string) $name, $object) && is_array($property)) {
                    $errors = [...$errors, ...self::errors($object[$name], $property, ($path === '' ? '' : $path . '.') . $name)];
                }
            }
        } elseif (is_array($value)) {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                $errors[] = $at . 'needs at least ' . (int) $schema['minItems'] . ' items';
            }
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                $errors[] = $at . 'takes at most ' . (int) $schema['maxItems'] . ' items';
            }
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $i => $item) {
                    $errors = [...$errors, ...self::errors($item, $schema['items'], $path . '[' . $i . ']')];
                }
            }
        } elseif (is_string($value) && !is_numeric($value) || is_string($value) && in_array('string', $types, true)) {
            if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
                $errors[] = $at . 'needs at least ' . (int) $schema['minLength'] . ' characters';
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                $errors[] = $at . 'takes at most ' . (int) $schema['maxLength'] . ' characters';
            }
        } elseif (is_int($value) || is_float($value) || is_numeric($value)) {
            if (isset($schema['minimum']) && (float) $value < (float) $schema['minimum']) {
                $errors[] = $at . 'must be at least ' . $schema['minimum'];
            }
            if (isset($schema['maximum']) && (float) $value > (float) $schema['maximum']) {
                $errors[] = $at . 'must be at most ' . $schema['maximum'];
            }
        }

        return $errors;
    }

    /** Whether a value is of a JSON type, read leniently for numbers. */
    private static function is(mixed $value, string $type): bool
    {
        return match ($type) {
            'string'  => is_string($value),
            'number'  => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'boolean' => is_bool($value),
            'array'   => is_array($value) && array_is_list($value),
            'object'  => $value instanceof \stdClass || (is_array($value) && (!array_is_list($value) || $value === [])),
            'null'    => $value === null,
            default   => true,
        };
    }

    private static function article(string $type): string
    {
        return in_array($type, ['array', 'object', 'integer'], true) ? 'an ' . $type : ($type === 'null' ? 'null' : 'a ' . $type);
    }
}
