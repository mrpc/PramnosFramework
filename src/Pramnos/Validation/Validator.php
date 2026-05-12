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
     * Rule names that are handled in the outer loop and must be skipped
     * inside the per-rule applyRule() loop.
     * @var array<int, string>
     */
    protected static array $metaRules = [
        'required',
        'nullable',
        'sometimes',
        'required_if',
        'required_unless',
        'required_with',
        'required_without',
    ];

    /**
     * Custom rules registered via Validator::extend().
     * @var array<string, callable|RuleInterface>
     */
    protected static array $customRules = [];

    // =========================================================================
    // Registration
    // =========================================================================

    /**
     * Register a custom validation rule.
     *
     * $rule may be:
     *  - A RuleInterface instance.
     *  - A callable with signature: fn(string $attribute, mixed $value, array $parameters): bool
     *
     * @param string              $name  The rule name used in rule strings, e.g. 'strong_password'.
     * @param callable|RuleInterface $rule
     */
    public static function extend(string $name, callable|RuleInterface $rule): void
    {
        static::$customRules[strtolower($name)] = $rule;
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Validates an array of data against a set of rules.
     *
     * Rules may be a pipe-delimited string ("required|email|min:3") or an
     * array. RuleInterface objects may be included directly in array rules.
     *
     * @param array<string, mixed>                           $data
     * @param array<string, string|array<int, string|RuleInterface>> $rules
     * @param array<string, string>                          $messages   Custom error messages.
     * @param array<string, string>                          $attributes Custom attribute labels.
     * @return array<string, mixed> The validated (and whitelisted) data.
     *
     * @throws ValidationException If any rule fails.
     */
    public static function validate(
        array $data,
        array $rules,
        array $messages = [],
        array $attributes = []
    ): array {
        $errors    = [];
        $validated = [];

        foreach ($rules as $field => $fieldRules) {
            $parsedRules = self::parseRules($fieldRules);
            $valueExists = array_key_exists($field, $data);
            $value       = $valueExists ? $data[$field] : null;

            // --- 'sometimes': skip everything if the field is absent ---
            if (in_array('sometimes', $parsedRules, true) && !$valueExists) {
                continue;
            }

            $nullable = in_array('nullable', $parsedRules, true);
            $required = self::resolveRequired($field, $parsedRules, $data);

            // --- Required check ---
            if ($required) {
                if (!$valueExists || self::isEmptyValue($value)) {
                    self::addError(
                        $errors,
                        $field,
                        self::message($field, 'required', [], $messages, $attributes)
                    );
                    continue;
                }
            }

            // --- Missing optional field ---
            if (!$valueExists) {
                $mustRun = [];
                foreach ($parsedRules as $rule) {
                    if ($rule instanceof RuleInterface) {
                        continue;
                    }
                    [$ruleName] = self::parseRule($rule);
                    if (in_array($ruleName, self::$implicitRules, true)) {
                        $mustRun[] = $rule;
                    }
                }

                if (empty($mustRun)) {
                    continue;
                }
            }

            // --- Empty optional value ---
            if ($valueExists && self::isEmptyValue($value)) {
                if ($nullable) {
                    $validated[$field] = $value;
                    continue;
                }
            }

            // --- Per-rule validation ---
            foreach ($parsedRules as $rule) {
                // Inline RuleInterface object
                if ($rule instanceof RuleInterface) {
                    if ($valueExists && !$rule->passes($field, $value)) {
                        $msg = str_replace(':attribute', $attributes[$field] ?? $field, $rule->message());
                        self::addError($errors, $field, $msg);
                    }
                    continue;
                }

                [$ruleName, $parameters] = self::parseRule($rule);

                // Meta-rules are resolved above; skip here
                if (in_array($ruleName, self::$metaRules, true)) {
                    continue;
                }

                // Non-implicit rules do not run on absent fields
                if (!$valueExists && !in_array($ruleName, self::$implicitRules, true)) {
                    continue;
                }

                $passed = self::applyRule($ruleName, $value, $parameters, $field, $data);

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

    // =========================================================================
    // Required resolution
    // =========================================================================

    /**
     * Determine whether a field is effectively required given its rules and data.
     *
     * Handles: required, required_if, required_unless, required_with, required_without.
     *
     * @param string                          $field
     * @param array<int, string|RuleInterface> $parsedRules
     * @param array<string, mixed>            $data
     */
    protected static function resolveRequired(string $field, array $parsedRules, array $data): bool
    {
        foreach ($parsedRules as $rule) {
            if ($rule instanceof RuleInterface) {
                continue;
            }

            [$ruleName, $parameters] = self::parseRule($rule);

            switch ($ruleName) {
                case 'required':
                    return true;

                case 'required_if':
                    // required_if:other_field,expected_value
                    if (isset($parameters[0], $parameters[1])
                        && array_key_exists($parameters[0], $data)
                        && (string) $data[$parameters[0]] === $parameters[1]
                    ) {
                        return true;
                    }
                    break;

                case 'required_unless':
                    // required_unless:other_field,exempt_value — required when other ≠ exempt
                    if (isset($parameters[0], $parameters[1])) {
                        $otherPresent = array_key_exists($parameters[0], $data);
                        $otherMatches = $otherPresent && (string) $data[$parameters[0]] === $parameters[1];
                        if (!$otherMatches) {
                            return true;
                        }
                    }
                    break;

                case 'required_with':
                    // required_with:field1,field2 — required if ANY listed field is non-empty
                    foreach ($parameters as $otherField) {
                        if (array_key_exists($otherField, $data) && !self::isEmptyValue($data[$otherField])) {
                            return true;
                        }
                    }
                    break;

                case 'required_without':
                    // required_without:field1,field2 — required if ANY listed field is absent/empty
                    foreach ($parameters as $otherField) {
                        if (!array_key_exists($otherField, $data) || self::isEmptyValue($data[$otherField])) {
                            return true;
                        }
                    }
                    break;
            }
        }

        return false;
    }

    // =========================================================================
    // Rule parsing
    // =========================================================================

    /**
     * Parses a mixed rules value into a flat array of string rules and RuleInterface objects.
     *
     * @param string|array<int, string|RuleInterface> $rules
     * @return array<int, string|RuleInterface>
     */
    protected static function parseRules($rules): array
    {
        if (is_array($rules)) {
            return array_values(array_filter($rules, function ($r) {
                return $r instanceof RuleInterface || (is_string($r) && trim($r) !== '');
            }));
        }

        $parts = explode('|', $rules);
        return array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
    }

    /**
     * Parses a single rule string into its name and parameters.
     *
     * @param string $rule e.g. "min:3" or "in:a,b,c"
     * @return array{0:string,1:array<int,string>}
     */
    protected static function parseRule(string $rule): array
    {
        $segments   = explode(':', $rule, 2);
        $name       = strtolower(trim($segments[0]));
        $parameters = [];

        if (isset($segments[1])) {
            $parameters = array_map('trim', explode(',', $segments[1]));
        }

        return [$name, $parameters];
    }

    // =========================================================================
    // Core rule engine
    // =========================================================================

    /**
     * Checks if a value is empty (null or empty string).
     *
     * @param mixed $value
     */
    protected static function isEmptyValue($value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Adds an error message for a specific field.
     *
     * @param array<string, array<int, string>> $errors
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
     *
     * $value is passed by reference to allow transformation rules (e.g. 'url'
     * normalises the value by prepending the scheme).
     *
     * @param string               $ruleName
     * @param mixed               &$value      Value being validated (may be transformed).
     * @param array<int, string>   $parameters  Rule parameters after the colon.
     * @param string               $field       Field name (needed for 'confirmed').
     * @param array<string, mixed> $data        Full input data (needed for cross-field rules).
     * @return bool True if the value passes.
     */
    protected static function applyRule(
        string $ruleName,
        &$value,
        array $parameters,
        string $field = '',
        array $data = []
    ): bool {
        switch ($ruleName) {
            // -----------------------------------------------------------------
            // Type checks
            // -----------------------------------------------------------------
            case 'string':
                return is_string($value);

            case 'integer':
                return filter_var($value, FILTER_VALIDATE_INT) !== false;

            case 'numeric':
                return is_numeric($value);

            case 'boolean':
                return in_array($value, [true, false, 0, 1, '0', '1'], true);

            case 'array':
                return is_array($value);

            // -----------------------------------------------------------------
            // String content
            // -----------------------------------------------------------------
            case 'alpha':
                return is_string($value) && (bool) preg_match('/^[\pL\pM]+$/u', $value);

            case 'alpha_num':
                return is_string($value) && (bool) preg_match('/^[\pL\pM\pN]+$/u', $value);

            case 'digits':
                $n = isset($parameters[0]) ? (int) $parameters[0] : 0;
                return ctype_digit((string) $value) && strlen((string) $value) === $n;

            case 'regex':
                $pattern = $parameters[0] ?? '';
                return $pattern !== '' && (bool) preg_match($pattern, (string) $value);

            case 'starts_with':
                return isset($parameters[0]) && str_starts_with((string) $value, $parameters[0]);

            case 'ends_with':
                return isset($parameters[0]) && str_ends_with((string) $value, $parameters[0]);

            // -----------------------------------------------------------------
            // Network / format
            // -----------------------------------------------------------------
            case 'email':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

            case 'ip':
                return filter_var($value, FILTER_VALIDATE_IP) !== false;

            case 'uuid':
                return (bool) preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                    (string) $value
                );

            case 'url':
                $normalized = self::checkLink($value);
                if ($normalized !== false) {
                    $value = $normalized;
                    return true;
                }
                return false;

            case 'json':
                return self::isJson($value);

            // -----------------------------------------------------------------
            // Size / range
            // -----------------------------------------------------------------
            case 'min':
                return self::validateMin($value, $parameters);

            case 'max':
                return self::validateMax($value, $parameters);

            case 'size':
                return self::validateSize($value, $parameters);

            case 'between':
                return isset($parameters[0], $parameters[1])
                    && self::validateMin($value, [$parameters[0]])
                    && self::validateMax($value, [$parameters[1]]);

            // -----------------------------------------------------------------
            // Inclusion
            // -----------------------------------------------------------------
            case 'in':
                return in_array((string) $value, $parameters, true);

            case 'not_in':
                return !in_array((string) $value, $parameters, true);

            // -----------------------------------------------------------------
            // Cross-field
            // -----------------------------------------------------------------
            case 'confirmed':
                // Expects a corresponding <field>_confirmation key in $data
                $confirmKey = $field . '_confirmation';
                return array_key_exists($confirmKey, $data) && $data[$confirmKey] === $value;

            // -----------------------------------------------------------------
            // Dates
            // -----------------------------------------------------------------
            case 'date':
                return is_string($value) && strtotime($value) !== false;

            case 'date_format':
                $format = $parameters[0] ?? 'Y-m-d';
                $d = \DateTime::createFromFormat($format, (string) $value);
                return $d !== false && $d->format($format) === (string) $value;

            case 'before':
                return self::compareDates($value, $parameters[0] ?? null, '<');

            case 'before_or_equal':
                return self::compareDates($value, $parameters[0] ?? null, '<=');

            case 'after':
                return self::compareDates($value, $parameters[0] ?? null, '>');

            case 'after_or_equal':
                return self::compareDates($value, $parameters[0] ?? null, '>=');

            // -----------------------------------------------------------------
            // Security
            // -----------------------------------------------------------------
            case 'csrf':
                $session = \Pramnos\Http\Session::getInstance();
                return $field === $session->getToken() && $session->checkTokenValue($value);

            // -----------------------------------------------------------------
            // Custom rules registered via extend()
            // -----------------------------------------------------------------
            default:
                if (isset(static::$customRules[$ruleName])) {
                    $custom = static::$customRules[$ruleName];
                    if ($custom instanceof RuleInterface) {
                        return $custom->passes($field, $value);
                    }
                    return (bool) ($custom)($field, $value, $parameters);
                }
                throw new \InvalidArgumentException('Unknown validation rule: ' . $ruleName);
        }
    }

    // =========================================================================
    // Size helpers
    // =========================================================================

    /**
     * Validates the minimum value/length/count.
     *
     * @param mixed             $value
     * @param array<int,string> $parameters
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
     * Validates the maximum value/length/count.
     *
     * @param mixed             $value
     * @param array<int,string> $parameters
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
     * Validates the exact size (string length, array count, or numeric value).
     *
     * @param mixed             $value
     * @param array<int,string> $parameters
     */
    protected static function validateSize($value, array $parameters): bool
    {
        if (!isset($parameters[0]) || !is_numeric($parameters[0])) {
            return false;
        }
        $size = (float) $parameters[0];

        if (is_string($value)) {
            return mb_strlen($value) === (int) $size;
        }
        if (is_array($value)) {
            return count($value) === (int) $size;
        }
        if (is_numeric($value)) {
            return (float) $value === $size;
        }
        return false;
    }

    // =========================================================================
    // Date helper
    // =========================================================================

    /**
     * Compare two date strings using the given operator.
     *
     * @param mixed       $value     The value being validated.
     * @param string|null $threshold The date to compare against (e.g. "2025-01-01" or "today").
     * @param string      $operator  One of '<', '<=', '>', '>='.
     */
    protected static function compareDates($value, ?string $threshold, string $operator): bool
    {
        if ($threshold === null) {
            return false;
        }
        $ts        = is_string($value) ? strtotime($value) : false;
        $tsLimit   = strtotime($threshold);

        if ($ts === false || $tsLimit === false) {
            return false;
        }

        switch ($operator) {
            case '<':  return $ts < $tsLimit;
            case '<=': return $ts <= $tsLimit;
            case '>':  return $ts > $tsLimit;
            case '>=': return $ts >= $tsLimit;
        }
        return false;
    }

    // =========================================================================
    // Error messages
    // =========================================================================

    /**
     * Generates an error message for a failed validation rule.
     *
     * @param string            $field
     * @param string            $ruleName
     * @param array<int,string> $parameters
     * @param array<string,string> $messages
     * @param array<string,string> $attributes
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
            // Core
            'required'         => 'The :attribute field is required.',
            'string'           => 'The :attribute must be a string.',
            'integer'          => 'The :attribute must be an integer.',
            'numeric'          => 'The :attribute must be a number.',
            'boolean'          => 'The :attribute field must be true or false.',
            'array'            => 'The :attribute must be an array.',
            // String content
            'alpha'            => 'The :attribute may only contain letters.',
            'alpha_num'        => 'The :attribute may only contain letters and numbers.',
            'digits'           => 'The :attribute must be :digits digits.',
            'regex'            => 'The :attribute format is invalid.',
            'starts_with'      => 'The :attribute must start with :values.',
            'ends_with'        => 'The :attribute must end with :values.',
            // Network / format
            'email'            => 'The :attribute must be a valid email address.',
            'ip'               => 'The :attribute must be a valid IP address.',
            'uuid'             => 'The :attribute must be a valid UUID.',
            'url'              => 'The :attribute format is invalid.',
            'json'             => 'The :attribute must be a valid JSON string.',
            // Size
            'min'              => 'The :attribute must be at least :min.',
            'max'              => 'The :attribute must not be greater than :max.',
            'size'             => 'The :attribute must be :size.',
            'between'          => 'The :attribute must be between :min and :max.',
            // Inclusion
            'in'               => 'The selected :attribute is invalid.',
            'not_in'           => 'The selected :attribute is invalid.',
            // Cross-field
            'confirmed'        => 'The :attribute confirmation does not match.',
            // Dates
            'date'             => 'The :attribute must be a valid date.',
            'date_format'      => 'The :attribute must match the format :format.',
            'before'           => 'The :attribute must be a date before :date.',
            'before_or_equal'  => 'The :attribute must be a date on or before :date.',
            'after'            => 'The :attribute must be a date after :date.',
            'after_or_equal'   => 'The :attribute must be a date on or after :date.',
            // Security
            'csrf'             => 'The security token is invalid or expired.',
            // Conditional required (message key when we add error from resolveRequired)
            'required_if'      => 'The :attribute field is required.',
            'required_unless'  => 'The :attribute field is required.',
            'required_with'    => 'The :attribute field is required.',
            'required_without' => 'The :attribute field is required.',
        ];

        $message = $messages[$ruleName] ?? ($defaultMessages[$ruleName] ?? 'The :attribute field is invalid.');

        return self::replacePlaceholders($message, $attribute, $parameters);
    }

    /**
     * Replaces placeholders in an error message with actual values.
     *
     * @param string            $message
     * @param string            $attribute
     * @param array<int,string> $parameters
     */
    protected static function replacePlaceholders(
        string $message,
        string $attribute,
        array $parameters
    ): string {
        $replacements = [
            ':attribute' => $attribute,
            ':min'       => $parameters[0] ?? '',
            ':max'       => $parameters[1] ?? ($parameters[0] ?? ''),
            ':digits'    => $parameters[0] ?? '',
            ':format'    => $parameters[0] ?? '',
            ':date'      => $parameters[0] ?? '',
            ':size'      => $parameters[0] ?? '',
            ':values'    => implode(', ', $parameters),
        ];

        return strtr($message, $replacements);
    }

    // =========================================================================
    // Static helper methods (public API, used directly and via legacy wrapper)
    // =========================================================================

    /**
     * Check if an email address is valid and sanitize it.
     *
     * @param string $email
     * @return string|false The sanitized email or false if invalid.
     */
    public static function checkEmail($email)
    {
        $emailFixed = filter_var(strtolower(trim($email)), FILTER_SANITIZE_EMAIL);
        return filter_var($emailFixed, FILTER_VALIDATE_EMAIL) ? $emailFixed : false;
    }

    /**
     * Check if a string is valid JSON.
     *
     * @param string $string
     */
    public static function isJson($string): bool
    {
        if (function_exists('json_validate')) {
            return json_validate($string);
        }
        // @codeCoverageIgnoreStart
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Check if a URL is valid and normalize it (prepend http:// if scheme is absent).
     *
     * @param string $url
     * @return string|false The normalized URL or false if invalid.
     */
    public static function checkLink($url)
    {
        $urlToCheck = trim($url);
        $reg_exUrl  = "/^(http|https|ftp|ftps)\:\/\/(.*)/i";
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
