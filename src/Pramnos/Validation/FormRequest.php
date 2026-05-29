<?php

declare(strict_types=1);

namespace Pramnos\Validation;

/**
 * Base class for form-level validation requests.
 *
 * Subclasses declare validation rules, custom messages, and attribute labels.
 * Calling validated() runs the validation against the current request input:
 *
 *   - On success it returns the whitelisted, validated data array.
 *   - On failure it stores the errors + old input in the session, then
 *     redirects the browser back (HTTP_REFERER or $redirectTo) and exits.
 *
 * Usage in a controller action:
 *
 *   $data = (new StoreUserRequest())->validated();
 *   // if we reach here, $data is guaranteed valid
 *
 * Viewing errors in a template:
 *
 *   if (FormRequest::hasErrors()):
 *       foreach (FormRequest::errors() as $field => $msgs):
 *           foreach ($msgs as $msg): echo htmlspecialchars($msg); endforeach;
 *       endforeach;
 *   endif;
 *   $email = FormRequest::old('email');
 *
 * Override $redirectTo (or getRedirectUrl()) to redirect somewhere other than
 * HTTP_REFERER on validation failure.
 *
 */
abstract class FormRequest
{
    /** Session key used to store field errors across redirects. */
    protected string $errorsSessionKey = '_form_errors';

    /** Session key used to store old input values for form repopulation. */
    protected string $oldInputSessionKey = '_form_old_input';

    /**
     * Fixed redirect target on failure. Empty string = use HTTP_REFERER (or /).
     */
    protected string $redirectTo = '';

    // =========================================================================
    // Abstract / overridable contract
    // =========================================================================

    /**
     * Validation rules for this request.
     *
     * Same format as Validator::validate() — see Pramnos\Validation\Validator.
     *
     * @return array<string, string|array<int, string|RuleInterface>>
     */
    abstract public function rules(): array;

    /**
     * Custom error messages.  Keyed by "field.rule" (e.g. "email.required").
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Human-readable attribute labels used in error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * Data to validate. By default merges $_GET + $_POST (POST wins).
     * Override to use a custom data source (e.g. JSON body).
     *
     * @return array<string, mixed>
     */
    protected function input(): array
    {
        return array_merge($_GET ?? [], $_POST ?? []);
    }

    // =========================================================================
    // Validation entry point
    // =========================================================================

    /**
     * Run validation against the current request input.
     *
     * Returns the whitelisted, validated data on success. On failure stores
     * errors + old input in session and redirects — never returns to the
     * caller on failure.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validateData($this->input());
    }

    /**
     * Validate $data against this request's rules.
     *
     * Extracted so that test subclasses can call it directly without
     * going through the HTTP input layer.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function validateData(array $data): array
    {
        try {
            return Validator::validate(
                $data,
                $this->rules(),
                $this->messages(),
                $this->attributes()
            );
        } catch (ValidationException $e) {
            $this->failWith($e->errors(), $data);
        }
    }

    // =========================================================================
    // Static helpers (read errors / old input from session)
    // =========================================================================

    /**
     * Return true if the session contains validation errors from a previous
     * failed FormRequest submission.
     */
    public static function hasErrors(string $key = '_form_errors'): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            return false;
        }
        return !empty($_SESSION[$key]);
    }

    /**
     * Get all validation errors (keyed by field) or errors for one field.
     *
     * @param  string|null $field  Field name; null = all errors.
     * @param  string      $key   Session key (default: '_form_errors').
     * @return array<string, array<int, string>>|array<int, string>
     */
    public static function errors(?string $field = null, string $key = '_form_errors'): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            return [];
        }
        $errors = (array) ($_SESSION[$key] ?? []);
        if ($field !== null) {
            return (array) ($errors[$field] ?? []);
        }
        return $errors;
    }

    /**
     * Retrieve a value from the previous request's input (for form repopulation).
     *
     * @param  string $field   Input field name.
     * @param  mixed  $default Returned when the field was absent.
     * @param  string $key     Session key (default: '_form_old_input').
     */
    public static function old(string $field, mixed $default = '', string $key = '_form_old_input'): mixed
    {
        if (session_status() === PHP_SESSION_NONE) {
            return $default;
        }
        $old = (array) ($_SESSION[$key] ?? []);
        return array_key_exists($field, $old) ? $old[$field] : $default;
    }

    /**
     * Remove stored form errors and old input from the session.
     * Call this after successfully processing a form to prevent stale messages.
     */
    public static function clearErrors(
        string $errorsKey  = '_form_errors',
        string $oldKey     = '_form_old_input'
    ): void {
        unset($_SESSION[$errorsKey], $_SESSION[$oldKey]);
    }

    // =========================================================================
    // Redirect URL resolution
    // =========================================================================

    /**
     * Return the URL to redirect to on validation failure.
     *
     * Override this method in a subclass to use a custom URL.
     * Default: $redirectTo property, then HTTP_REFERER, then '/'.
     */
    protected function getRedirectUrl(): string
    {
        if ($this->redirectTo !== '') {
            return $this->redirectTo;
        }
        return $_SERVER['HTTP_REFERER'] ?? '/';
    }

    // =========================================================================
    // Failure handler
    // =========================================================================

    /**
     * Store errors + old input in session and redirect.  Never returns.
     *
     * This method is protected so test subclasses can override it to capture
     * the redirect instead of actually calling header()/exit.
     *
     * @param array<string, array<int, string>> $errors
     * @param array<string, mixed>              $oldInput
     */
    protected function failWith(array $errors, array $oldInput = []): never
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION[$this->errorsSessionKey]   = $errors;
        $_SESSION[$this->oldInputSessionKey] = $oldInput;

        header('Location: ' . $this->getRedirectUrl(), true, 302);
        exit;
    }
}
