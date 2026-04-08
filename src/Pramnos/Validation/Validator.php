<?php

namespace Pramnos\Validation;

/**
 * Modern Validator class for form and data validation.
 */
class Validator
{
    /**
     * Rules that must be validated even if the field is missing from the data array.
     * @var array<int, string>
     */
    protected static array $implicitRules = ['csrf'];

    /**
     * Validates an array of data against a set of rules.
     *
     * @param array<string, mixed> $data The data to validate
     * @param array<string, string|array<int, string>> $rules The validation rules
     * @param array<string, string> $messages Custom error messages
     * @param array<string, string> $attributes Custom attribute names for error messages
     * @return array<string, mixed> The validated data
     *
     * @throws ValidationException If validation fails
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
                // Handle missing fields: check for 'required' or implicit rules (like CSRF)
                $mustRun = [];
                foreach ($parsedRules as $rule) {
                    [$ruleName] = self::parseRule($rule);
                    if (in_array($ruleName, self::$implicitRules, true)) {
                        $mustRun[] = $rule;
                    }
                }

                if ($required) {
                    self::addError(
                        $errors,
                        $field,
                        self::message($field, 'required', [], $messages, $attributes)
                    );
                }

                if (empty($mustRun)) {
                    continue;
                }
                
                // If we have implicit rules, we continue into the rules loop even if $valueExists is false
            }

            if ($valueExists && self::isEmptyValue($value)) {
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

                // Skip non-implicit rules if the value doesn't exist
                if (!$valueExists && !in_array($ruleName, self::$implicitRules, true)) {
                    continue;
                }

                $passed = self::applyRule($ruleName, $value, $parameters, $field);

                if (!$passed) {
                    self::addError(
                        $errors,
                        $field,
                        self::message($field, $ruleName, $parameters, $messages, $attributes)
                    );
                }
            }

            if ($valueExists && !isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    /**
     * Parses the rules into an array of individual rules.
     *
     * @param string|array<int, string> $rules The raw rules
     * @return array<int, string> The parsed rules
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
     * Parses a single rule into its name and parameters.
     *
     * @param string $rule The rule string (e.g., "min:3")
     * @return array{0:string,1:array<int,string>} An array containing [ruleName, parameters]
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
     * Checks if a value is empty (null or empty string).
     *
     * @param mixed $value The value to check
     * @return bool True if empty, false otherwise
     */
    protected static function isEmptyValue($value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Adds an error message for a specific field.
     *
     * @param array<string, array<int, string>> $errors The errors array (passed by reference)
     * @param string $field The field name
     * @param string $message The error message
     */
    protected static function addError(array &$errors, string $field, string $message): void
    {
        if (!isset($errors[$field])) {
            $errors[$field] = [];
        }

        $errors[$field][] = $message;
    }

    /**
     * Applies a specific validation rule to a value.
     * Support for value transformation (sanitization) is enabled by passing $value by reference.
     *
     * @param string $ruleName The name of the rule
     * @param mixed &$value The value to validate (passed by reference for transformation)
     * @param array<int, string> $parameters Rule parameters
     * @param string $field The name of the field being validated
     * @return bool True if passed, false otherwise
     */
    protected static function applyRule(string $ruleName, &$value, array $parameters, string $field = ''): bool
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

            case 'csrf':
                // CSRF must match the session token and the field value must be strictly '1'
                return $value === '1' && $field === \Pramnos\Http\Session::getInstance()->getToken();

            case 'url':
                $normalized = self::checkLink($value);
                if ($normalized !== false) {
                    $value = $normalized;
                    return true;
                }
                return false;

            case 'json':
                return self::isJson($value);

            case 'between':
                return isset($parameters[0], $parameters[1])
                    && self::validateMin($value, [$parameters[0]])
                    && self::validateMax($value, [$parameters[1]]);

            default:
                throw new \InvalidArgumentException('Unknown validation rule: ' . $ruleName);
        }
    }

    /**
     * Validates the minimum value/length.
     *
     * @param mixed $value The value to check
     * @param array<int, string> $parameters Rule parameters (parameters[0] is the min limit)
     * @return bool True if valid
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
     * Validates the maximum value/length.
     *
     * @param mixed $value The value to check
     * @param array<int, string> $parameters Rule parameters (parameters[0] is the max limit)
     * @return bool True if valid
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
     * Generates an error message for a failed validation rule.
     *
     * @param string $field The field name
     * @param string $ruleName The rule name
     * @param array<int, string> $parameters Rule parameters
     * @param array<string, string> $messages Custom messages
     * @param array<string, string> $attributes Custom attributes
     * @return string The formatted error message
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
            'csrf' => 'The security token is invalid or expired.',
            'url' => 'The :attribute format is invalid.',
            'json' => 'The :attribute must be a valid JSON string.',
            'between' => 'The :attribute must be between :min and :max.',
        ];

        $message = $messages[$ruleName] ?? ($defaultMessages[$ruleName] ?? 'The :attribute field is invalid.');

        return self::replacePlaceholders($message, $attribute, $parameters);
    }

    /**
     * Replaces placeholders in an error message with actual values.
     *
     * @param string $message The message template
     * @param string $attribute The attribute name
     * @param array<int, string> $parameters Rule parameters
     * @return string The formatted message
     */
    protected static function replacePlaceholders(
        string $message,
        string $attribute,
        array $parameters
    ): string {
        $replacements = [
            ':attribute' => $attribute,
            ':min' => $parameters[0] ?? '',
            ':max' => $parameters[1] ?? ($parameters[0] ?? ''),
        ];

        return strtr($message, $replacements);
    }

    /**
     * Check if an email address is valid and sanitize it.
     *
     * @param string $email The email address to check
     * @return string|false The sanitized email or false if invalid
     */
    public static function checkEmail($email)
    {
        $emailFixed = filter_var(strtolower(trim($email)), FILTER_SANITIZE_EMAIL);
        return filter_var($emailFixed, FILTER_VALIDATE_EMAIL) ? $emailFixed : false;
    }

    /**
     * Check if a string is a valid JSON.
     *
     * @param string $string The string to check
     * @return bool True if valid JSON
     */
    public static function isJson($string): bool
    {
        if (function_exists('json_validate')) {
            return json_validate($string);
        }
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    /**
     * Check if a link is valid and normalize it.
     *
     * @param string $url The URL to check
     * @return string|false The normalized URL or false if invalid
     */
    public static function checkLink($url)
    {
        $urlToCheck = trim($url);
        $reg_exUrl = "/^(http|https|ftp|ftps)\:\/\/(.*)/i";
        if (!preg_match($reg_exUrl, $urlToCheck)) {
            $urlToCheck = "http://" . $urlToCheck;
        }
        $finalUrl = filter_var($urlToCheck, FILTER_SANITIZE_URL);
        if (strpos($finalUrl, ".") === false) {
            return false;
        }
        return filter_var($finalUrl, FILTER_VALIDATE_URL) ? $finalUrl : false;
    }

    /**
     * Factory function for backward compatibility.
     *
     * @return static
     */
    public static function &getInstance()
    {
        static $instance;
        if (!is_object($instance)) {
            $instance = new static();
        }
        return $instance;
    }
}