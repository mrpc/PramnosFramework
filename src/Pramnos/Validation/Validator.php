<?php

namespace Pramnos\Validation;

class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $rules
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(
        array $data,
        array $rules,
        array $messages = [],
        array $attributes = []
    ): array {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $fieldRules) {
            $parsedRules = self::parseRules($fieldRules);
            $valueExists = array_key_exists($field, $data);
            $value = $valueExists ? $data[$field] : null;

            $nullable = in_array('nullable', $parsedRules, true);
            $required = in_array('required', $parsedRules, true);

            if (!$valueExists) {
                if ($required) {
                    self::addError(
                        $errors,
                        $field,
                        self::message($field, 'required', [], $messages, $attributes)
                    );
                }
                continue;
            }

            if (self::isEmptyValue($value)) {
                if ($required && !$nullable) {
                    self::addError(
                        $errors,
                        $field,
                        self::message($field, 'required', [], $messages, $attributes)
                    );
                    continue;
                }

                if ($nullable) {
                    $validated[$field] = $value;
                    continue;
                }
            }

            foreach ($parsedRules as $rule) {
                [$ruleName, $parameters] = self::parseRule($rule);

                if ($ruleName === 'required' || $ruleName === 'nullable') {
                    continue;
                }

                $passed = self::applyRule($ruleName, $value, $parameters);

                if (!$passed) {
                    self::addError(
                        $errors,
                        $field,
                        self::message($field, $ruleName, $parameters, $messages, $attributes)
                    );
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    /**
     * @param string|array<int, string> $rules
     * @return array<int, string>
     */
    protected static function parseRules($rules): array
    {
        if (is_array($rules)) {
            return $rules;
        }

        $parts = explode('|', $rules);
        $parts = array_map('trim', $parts);

        return array_values(array_filter($parts, function ($part) {
            return $part !== '';
        }));
    }

    /**
     * @param string $rule
     * @return array{0:string,1:array<int,string>}
     */
    protected static function parseRule(string $rule): array
    {
        $segments = explode(':', $rule, 2);
        $name = strtolower(trim($segments[0]));
        $parameters = [];

        if (isset($segments[1])) {
            $parameters = array_map('trim', explode(',', $segments[1]));
        }

        return [$name, $parameters];
    }

    /**
     * @param $value
     * @return bool
     */
    protected static function isEmptyValue($value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * @param array<string, array<int, string>> $errors
     * @param string $field
     * @param string $message
     */
    protected static function addError(array &$errors, string $field, string $message): void
    {
        if (!isset($errors[$field])) {
            $errors[$field] = [];
        }

        $errors[$field][] = $message;
    }

    /**
     * @param string $ruleName
     * @param $value
     * @param array<int, string> $parameters
     * @return bool
     */
    protected static function applyRule(string $ruleName, $value, array $parameters): bool
    {
        switch ($ruleName) {
            case 'string':
                return is_string($value);

            case 'integer':
                return filter_var($value, FILTER_VALIDATE_INT) !== false;

            case 'numeric':
                return is_numeric($value);

            case 'boolean':
                return in_array($value, [true, false, 0, 1, '0', '1'], true);

            case 'email':
                return is_string($value)
                    && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

            case 'min':
                return self::validateMin($value, $parameters);

            case 'max':
                return self::validateMax($value, $parameters);

            case 'in':
                return in_array((string) $value, $parameters, true);

            default:
                throw new \InvalidArgumentException('Unknown validation rule: ' . $ruleName);
        }
    }

    /**
     * @param $value
     * @param array<int, string> $parameters
     * @return bool
     */
    protected static function validateMin($value, array $parameters): bool
    {
        if (!isset($parameters[0]) || !is_numeric($parameters[0])) {
            return false;
        }

        $min = (float) $parameters[0];

        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }

        if (is_array($value)) {
            return count($value) >= $min;
        }

        if (is_numeric($value)) {
            return (float) $value >= $min;
        }

        return false;
    }

    /**
     * @param $value
     * @param array<int, string> $parameters
     * @return bool
     */
    protected static function validateMax($value, array $parameters): bool
    {
        if (!isset($parameters[0]) || !is_numeric($parameters[0])) {
            return false;
        }

        $max = (float) $parameters[0];

        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }

        if (is_array($value)) {
            return count($value) <= $max;
        }

        if (is_numeric($value)) {
            return (float) $value <= $max;
        }

        return false;
    }

    /**
     * @param string $field
     * @param string $ruleName
     * @param array<int, string> $parameters
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     * @return string
     */
    protected static function message(
        string $field,
        string $ruleName,
        array $parameters,
        array $messages,
        array $attributes
    ): string {
        $attribute = $attributes[$field] ?? $field;

        $specificKey = $field . '.' . $ruleName;
        if (isset($messages[$specificKey])) {
            return self::replacePlaceholders($messages[$specificKey], $attribute, $parameters);
        }

        $defaultMessages = [
            'required' => 'The :attribute field is required.',
            'string' => 'The :attribute must be a string.',
            'integer' => 'The :attribute must be an integer.',
            'numeric' => 'The :attribute must be a number.',
            'boolean' => 'The :attribute field must be true or false.',
            'email' => 'The :attribute must be a valid email address.',
            'min' => 'The :attribute must be at least :min.',
            'max' => 'The :attribute must not be greater than :max.',
            'in' => 'The selected :attribute is invalid.',
        ];

        $message = $messages[$ruleName] ?? ($defaultMessages[$ruleName] ?? 'The :attribute field is invalid.');

        return self::replacePlaceholders($message, $attribute, $parameters);
    }

    /**
     * @param string $message
     * @param string $attribute
     * @param array<int, string> $parameters
     * @return string
     */
    protected static function replacePlaceholders(
        string $message,
        string $attribute,
        array $parameters
    ): string {
        $replacements = [
            ':attribute' => $attribute,
            ':min' => $parameters[0] ?? '',
            ':max' => $parameters[0] ?? '',
        ];

        return strtr($message, $replacements);
    }
}