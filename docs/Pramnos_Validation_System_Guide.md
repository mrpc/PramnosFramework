---
use_cases:
  - Validating request input in a controller or API endpoint
  - Choosing a validation rule or writing a custom one
  - Showing validation errors back in a view, or returning them from an API
  - Understanding flash validation state between requests
---

# Pramnos Validation System Guide

The Pramnos Framework includes a lightweight validation system for validating incoming data in a centralized and reusable way.

This feature was designed to reduce manual validation code inside controllers and provide a cleaner, more maintainable flow for both WWW and API applications.

---

## Overview

Validation is primarily performed through the `Request` object.

```php
$request = new \Pramnos\Http\Request();

$data = $request->validate([
    'name' => 'required|string|min:3|max:255',
]);
```

If validation succeeds, the validated input is returned as an array.

If validation fails, a `Pramnos\Validation\ValidationException` is thrown.

---

## Main Components

The validation system is built around the following classes:

- `Pramnos\Validation\Validator`
- `Pramnos\Validation\ValidationException`
- `Pramnos\Validation\RuleInterface` — a custom rule
- `Pramnos\Validation\FormRequest` — rules in their own class
- `Pramnos\Http\Request`
- `Pramnos\Http\Middleware\ValidationRedirectMiddleware` — the redirect for router-dispatched applications

It is also integrated into:

- `Pramnos\Application\Application`
- `Pramnos\Application\Api`
- `Pramnos\Application\View`

---

## Basic Usage

### Validating Request Data

```php
$request = new \Pramnos\Http\Request();

$data = $request->validate([
    'name' => 'required|string|min:3|max:255',
    'type' => 'nullable|integer|in:0,1',
    'value' => 'nullable|numeric',
], [], [], 'POST');
```

### Returned Value

If validation passes, `validate()` returns an array containing the validated input values.

```php
[
    'name' => 'Main metric',
    'type' => '1',
    'value' => '12.5'
]
```

---

## Available Request Helper Methods

The following helper methods are available on `Pramnos\Http\Request`.

### `all()`

Returns all input data from a specific input source.

```php
$request->all('POST');
$request->all('GET');
$request->all('PUT');
$request->all('DELETE');
```

### `allCurrent()`

Returns all input data from the current request method.

```php
$data = $request->allCurrent();
```

### `only()`

Returns only selected keys from the request input.

```php
$data = $request->only(['name', 'email'], 'POST');
```

### `validate()`

Validates request input using the validation rules.

```php
$data = $request->validate([
    'name' => 'required|string|max:255',
]);
```

### `errors()`

Returns flashed validation errors for the current request.

```php
$errors = $request->errors();
```

### `old()`

Returns old input values stored after validation failure.

```php
$request->old('name');
$request->old('name', 'default value');
```

### `clearValidationState()`

Clears validation errors and old input values.

```php
$request->clearValidationState();
```

---

## Validation Rules

Rules for a field are given either as one string separated by `|`, or as an array whose
entries are rule strings or rule objects:

```php
'name'  => 'required|string|max:255',
'code'  => ['required', 'regex:/^[A-Z]{3}$/', new UppercaseRule()],
```

Parameters follow a `:` and are split on `,` — `in:a,b,c`, `between:1,5`.

### Presence

| Rule | Meaning |
|------|---------|
| `required` | Present, and not `null` or `''` |
| `required_if:other,value` | Required when `other` equals `value` |
| `required_unless:other,value` | Required unless `other` equals `value` |
| `required_with:a,b` | Required when any of the listed fields is present and non-empty |
| `required_without:a,b` | Required when any of the listed fields is absent or empty |
| `nullable` | `null` or `''` is accepted and skips the remaining rules |
| `sometimes` | When the field is absent, none of its rules run |

A field that is absent and not required is skipped — its other rules do not run.

### Type and format

| Rule | Passes when |
|------|-------------|
| `string` | the value is a string |
| `integer` | `filter_var(FILTER_VALIDATE_INT)` accepts it (`"12"` passes) |
| `numeric` | `is_numeric()` |
| `boolean` | one of `true`, `false`, `0`, `1`, `'0'`, `'1'` |
| `array` | the value is an array |
| `alpha` | letters only (Unicode) |
| `alpha_num` | letters and digits only (Unicode) |
| `digits:n` | exactly `n` digits |
| `regex:/pattern/` | `preg_match()` matches |
| `starts_with:x` | the value starts with `x` |
| `ends_with:x` | the value ends with `x` |
| `email` | `FILTER_VALIDATE_EMAIL` |
| `ip` | a valid IPv4 or IPv6 address |
| `uuid` | an 8-4-4-4-12 hex UUID |
| `url` | a valid URL; see below |
| `json` | a valid JSON string |

### Size and inclusion

| Rule | Meaning |
|------|---------|
| `min:x` | at least `x` |
| `max:x` | at most `x` |
| `size:x` | exactly `x` |
| `between:min,max` | from `min` to `max`, inclusive |
| `in:a,b,c` | one of the listed values (compared as strings) |
| `not_in:a,b,c` | none of the listed values |

### Dates and cross-field

| Rule | Meaning |
|------|---------|
| `date` | `strtotime()` can parse it |
| `date_format:Y-m-d` | matches that `DateTime` format exactly |
| `before:date` / `before_or_equal:date` | earlier than (or equal to) `date` |
| `after:date` / `after_or_equal:date` | later than (or equal to) `date` |
| `confirmed` | a `<field>_confirmation` key holds the identical value |

The `date` parameter of the comparison rules is anything `strtotime()` reads — `2025-01-01`,
`today`, `+1 week`. It is a date, not the name of another field.

### Security

| Rule | Meaning |
|------|---------|
| `csrf` | the session's form token is valid; see below |

### Rule Details

#### `min`, `max`, `size` and `between`
These rules are type-aware:
- **Strings**: the **number of characters** (`mb_strlen`).
- **Numbers**: the **numeric value**.
- **Arrays**: the **number of elements**.

A field that also carries `numeric` or `integer` is compared **by value** even when it arrives
as a string, so `"25"` from a form passes `integer|min:18` as the number 25 rather than as a
two-character string.

| Rule | String Example | Numeric Example | Array Example |
|------|----------------|-----------------|---------------|
| `min:3` | At least 3 chars | Value >= 3 | At least 3 items |
| `max:10` | At most 10 chars | Value <= 10 | At most 10 items |
| `between:1,5` | 1 to 5 chars | Value from 1 to 5 | 1 to 5 items |

#### `regex`, `starts_with` and `ends_with`
Parameters are split on `,`, so a `regex` pattern containing a comma (`{2,4}`) is cut at the
comma and no longer means what was written; express such a pattern as a custom rule. A
pattern containing `|` must be given in the array form, since the string form splits on `|`. `starts_with` and `ends_with` check only the **first** listed value.

#### `csrf`
Verifies the form token that `Session::getTokenField()` emits. That token's field **name** is
the session's token and its **value** is a per-session fingerprint, so the rule checks both:
1. The field name equals the current session token (`Session::getToken()`).
2. The submitted value matches the session's fingerprint (`Session::checkTokenValue()`).

Unlike other rules it runs even when the field is absent, so a missing token fails.

*Note: This rule requires an active session.*

#### `url`
Validates that the input is a valid URL. A missing scheme is assumed to be `http://` **when
checking**, so `google.com` passes; the value returned by `validate()` is the input as
submitted, not the normalised URL. A host without a dot fails.

#### `json`
Validates that the input is a valid JSON string, with `json_validate()`.

### Custom messages and attribute names

`validate()` takes two more arrays after the rules:

```php
$data = $request->validate(
    ['email' => 'required|email'],
    [
        'email.required' => 'We need an address to reply to.',   // this field, this rule
        'required'       => 'Please fill in :attribute.',        // any field, this rule
    ],
    ['email' => 'email address'],                                 // :attribute
    'POST'
);
```

A message is looked up as `field.rule`, then as `rule`, then falls back to the built-in
text. Placeholders: `:attribute`, `:min`, `:max`, `:size`, `:digits`, `:format`, `:date`,
`:values`.

### Custom rules

A rule object implements `Pramnos\Validation\RuleInterface`:

```php
use Pramnos\Validation\RuleInterface;

class UppercaseRule implements RuleInterface
{
    public function passes(string $attribute, mixed $value): bool
    {
        return is_string($value) && strtoupper($value) === $value;
    }

    public function message(): string
    {
        return 'The :attribute must be upper case.';
    }
}

$request->validate(['code' => ['required', new UppercaseRule()]]);
```

To use it by name in rule strings, register it once with `Validator::extend()`. A closure
works too, and receives the parameters after the `:`:

```php
use Pramnos\Validation\Validator;

Validator::extend('uppercase', new UppercaseRule());
Validator::extend('multiple_of', fn (string $attribute, mixed $value, array $parameters): bool =>
    is_numeric($value) && fmod((float) $value, (float) ($parameters[0] ?? 1)) == 0
);

$request->validate(['qty' => 'required|integer|multiple_of:6']);
```

A rule registered by name takes its message from the `$messages` array (`'multiple_of' => …`)
or the generic *"The :attribute field is invalid."*. An unknown rule name throws
`InvalidArgumentException` rather than passing silently.

### Example

```php
$session = \Pramnos\Http\Session::getInstance();
$token = $session->getToken();

$request->validate([
    $token => 'csrf', // Dynamic CSRF validation
    'name' => 'required|string|min:3|max:255',
    'type' => 'required|integer|in:0,1',
    'interval' => 'nullable|integer|min:0',
    'value' => 'nullable|numeric|between:0,100',
    'website' => 'nullable|url',
]);
```

---

## How Validation Failures Work

When validation fails, the validator throws:

```php
\Pramnos\Validation\ValidationException
```

### Example

```php
try {
    $data = $request->validate([
        'name' => 'required|string|min:3|max:255',
    ]);
} catch (\Pramnos\Validation\ValidationException $e) {
    $errors = $e->errors();
}
```

### Error Structure

The exception stores validation errors as an array grouped by field name.

```php
[
    'name' => [
        'The name field is required.'
    ]
]
```

### Who catches it, and where that leaves your application

The flash-and-redirect this guide describes — errors and submitted input into the
session, browser sent back to the form — is written **inside `Application::exec()`**.

That is the whole MVC request cycle. An application that dispatches with
`Router::dispatch()` never calls it, so it gets an **uncaught `ValidationException`**
where this page promises a redirect. One line restores it:

```php
$router->addGlobalMiddleware(new \Pramnos\Http\Middleware\ValidationRedirectMiddleware());
```

It catches only `ValidationException` — anything else passes through, because turning
a real fault into a redirect back to the form is the most confusing outcome available:
the visitor sees the page again with nothing wrong on it. Pass a path to the
constructor for a form that should always return to one place; otherwise it uses
`HTTP_REFERER`, exactly as `Application::exec()` does, falling back to the site root
when there is none (some privacy tooling strips the header).

### Two session conventions, and they do not interoperate

This is the sharp edge in this system, and it is invisible from either side:

| Written by | Keys | Read by |
| --- | --- | --- |
| `Request::validate()` + `Application::exec()` + `ValidationRedirectMiddleware` | `_validation_errors`, `_old_input` | `View::__construct()` → `$this->errors`; `Request::old()` |
| `FormRequest::failWith()` | `_form_errors`, `_form_old_input` | `FormRequest::errors()`, `FormRequest::old()` — statics |

**A view using `$this->errors` sees nothing after a `FormRequest` failure.** The form
redraws with no errors on it and no indication why, which reads as "validation is
broken" rather than as "two conventions".

Pick one per form. If your templates use `$this->errors` and `$request->old()` — what
the `View` gives you for free — use `Request::validate()`. Reach for `FormRequest` when
you want the rules in their own class, and read the errors back through
`FormRequest`'s statics in the same place.

---

## WWW Application Flow

In standard WWW applications, validation failures are automatically handled by the framework.

Inside `Pramnos\Application\Application::exec()`:

- validation errors are stored in session
- old input is stored in session
- the user is redirected back to the previous page

This means controllers only need to call `validate()` and do not need to manually handle failed validation in the common web flow.

### Example Controller

```php
public function save()
{
    $request = new \Pramnos\Http\Request();

    $data = $request->validate([
        'name' => 'required|string|min:3|max:255',
    ], [], [], 'POST');

    // Continue only if validation passes
}
```

---

## Using Validation in Views

The framework exposes request and validation data to views.

Inside `Pramnos\Application\View`, the following are available:

- `$this->request`
- `$this->errors`

This allows validation state to be used directly in templates.

### Example: Displaying All Errors

```php
<?php if (!empty($this->errors)) : ?>
    <div class="alert alert-danger">
        <strong>There are validation errors:</strong>
        <ul>
            <?php foreach ($this->errors as $fieldErrors) : ?>
                <?php foreach ($fieldErrors as $error) : ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
```

### Example: Repopulating Old Input

```php
<input
    type="text"
    name="name"
    value="<?php echo $this->request->old('name', $this->model->name); ?>"
>
```

This is especially useful in edit/create forms after failed validation.

---

## Flash Validation State

Validation state is flashed for the next request only.

This means:

- after a failed validation, errors and old input are available on the redirected page
- refreshing again should not keep showing the same validation state forever
- opening a different form should not reuse old validation state from another page

This behavior makes validation act like a standard form flash session workflow.

---

## API Usage

An API controller does not need to catch `ValidationException`. `Pramnos\Application\Api`
catches it around every controller action and around `src/Api/routes.php`, and answers with
a `422`:

```php
public function create()
{
    $data = (new \Pramnos\Http\Request())->validate([
        'name' => 'required|string|max:255',
    ], [], [], 'POST');

    // only reached when validation passed
}
```

```json
{
    "status": 422,
    "message": "…",
    "error": "ValidationError",
    "errors": { "name": ["The name field is required."] }
}
```

Catch it yourself only when an endpoint needs a different shape.

---

## Standalone Validator Usage

The validator can also be used without the `Request` object.

This is useful when validating plain arrays outside the HTTP layer.

```php
use Pramnos\Validation\Validator;

$data = [
    'name' => 'Example'
];

$rules = [
    'name' => 'required|string|min:3|max:255'
];

$validated = Validator::validate($data, $rules);
```

If validation fails, a `ValidationException` is thrown.

---

## Practical Notes

### 1. Validation should not change existing project behavior without a reason

When introducing validation into old controllers, it is usually better to start with forgiving rules and preserve existing behavior where possible.

For example, if a field accepts an empty string today, avoid making it suddenly required unless that is an intentional business rule change.

### 2. Use database structure as a guide, but not blindly

Database schema is useful for identifying maximum string lengths, nullable fields, and numeric fields, but old projects may still contain legacy behavior that is more forgiving than the raw schema.

### 3. Nullable foreign keys should be handled carefully

If a foreign key field is optional, it is often safer to map an empty request value to `null` instead of `0`, especially when the database enforces foreign key constraints.

---

## Example: Typical Controller Pattern

```php
public function save()
{
    $model = new \App\Models\Metric($this);
    $request = new \Pramnos\Http\Request();

    $data = $request->validate([
        'name' => 'required|string|max:255',
        'label' => 'nullable|string|max:255',
        'unitLabel' => 'nullable|string|max:128',
        'unit' => 'nullable|string|max:50',
        'datapointid' => 'nullable|integer|min:1',
        'locationid' => 'nullable|integer|min:1',
        'type' => 'nullable|integer|in:0,1',
        'value' => 'nullable|numeric',
    ], [], [], 'POST');

    $model->name = trim(strip_tags($data['name']));
    $model->label = isset($data['label']) ? trim(strip_tags($data['label'])) : '';
    $model->unitLabel = isset($data['unitLabel']) ? trim(strip_tags($data['unitLabel'])) : '';
    $model->unit = isset($data['unit']) ? trim(strip_tags($data['unit'])) : '';

    $model->datapointid = ($request->get('datapointid', '', 'post') === '')
        ? null
        : (int) $data['datapointid'];

    $model->locationid = ($request->get('locationid', '', 'post') === '')
        ? null
        : (int) $data['locationid'];

    $model->type = isset($data['type']) ? (int) $data['type'] : 0;
    $model->value = isset($data['value']) ? (float) $data['value'] : null;

    $model->save();
}
```

---

## Not provided

- Schema-aware helpers that derive rules from a table's columns. Use the schema as a guide
  by hand (see Practical Notes above).

---

## Summary

The Pramnos validation system provides:

- centralized validation logic
- reusable validation rules
- integration with requests, views, WWW applications, and APIs
- old input preservation
- session-based error flashing

It is designed to be lightweight, extensible, and safe to introduce gradually into existing projects.
