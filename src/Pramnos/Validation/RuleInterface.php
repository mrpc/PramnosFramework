<?php

namespace Pramnos\Validation;

/**
 * Contract for custom validation rule objects.
 *
 * Implement this interface to encapsulate reusable validation logic that does
 * not fit neatly into a simple callable. Pass instances directly inside the
 * rules array:
 *
 *   $rules = ['password' => ['required', new StrongPasswordRule()]];
 *
 * Or register the rule globally with Validator::extend():
 *
 *   Validator::extend('strong_password', new StrongPasswordRule());
 *
 */
interface RuleInterface
{
    /**
     * Determine if the given value passes the rule.
     *
     * @param string $attribute The name of the field being validated.
     * @param mixed  $value     The value to validate.
     * @return bool True if the value passes, false otherwise.
     */
    public function passes(string $attribute, mixed $value): bool;

    /**
     * Return the validation error message for this rule.
     *
     * Use :attribute as a placeholder for the field name:
     *   "The :attribute must be a strong password."
     *
     * @return string
     */
    public function message(): string;
}
