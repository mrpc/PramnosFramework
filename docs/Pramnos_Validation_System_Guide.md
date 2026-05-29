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
- `Pramnos\Http\Request`

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

Rules are declared as strings separated by `|`.

### Supported Rules

| Rule | Description |
|------|-------------|
| `required` | The field must be present and must not be empty |
| `nullable` | The field may be missing, `null`, or an empty string |
| `string` | The field must be a string |
| `integer` | The field must be an integer |
| `numeric` | The field must be numeric |
| `min:x` | Minimum numeric value or minimum string length |
| `max:x` | Maximum numeric value or maximum string length |
| `between:min,max` | Value or length must be within the specified interval |
| `in:a,b,c` | The field value must be one of the allowed values |
| `csrf` | The security token must be valid for the session |
| `url` | The field must be a valid URL format |
| `json` | The field must be a valid JSON string |

### Rule Details

#### `min`, `max`, and `between`
These rules are type-aware and adapt their behavior based on the input:
- **Strings**: Validates the **number of characters** (using `mb_strlen`).
- **Numbers**: Validates the **numeric value**.
- **Arrays**: Validates the **number of elements** in the array.

| Rule | String Example | Numeric Example | Array Example |
|------|----------------|-----------------|---------------|
| `min:3` | At least 3 chars | Value >= 3 | At least 3 items |
| `max:10` | At most 10 chars | Value <= 10 | At most 10 items |
| `between:1,5` | 1 to 5 chars | Value from 1 to 5 | 1 to 5 items |

#### `csrf`
The `csrf` rule verifies the security token for the request. Because Pramnos uses dynamic parameter names for CSRF tokens, the rule automatically verifies that:
1. The field name matches the current session token (`Session::getToken()`).
2. The field value is set to `'1'`.

*Note: This rule requires an active session.*

#### `url`
Validates that the input is a valid URL. It automatically adds `http://` if a protocol is missing (e.g., `google.com` becomes `http://google.com`).

#### `json`
Validates that the input is a valid JSON string. Uses `json_validate()` on PHP 8.3+ for better performance.

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

In API applications, validation should typically be handled by returning a structured response.

### Example

```php
try {
    $data = $request->validate([
        'name' => 'required|string|max:255',
    ], [], [], 'POST');
} catch (\Pramnos\Validation\ValidationException $e) {
    return [
        'status' => 422,
        'message' => $e->getMessage(),
        'error' => 'ValidationError',
        'errors' => $e->errors(),
    ];
}
```

The framework API handling can then translate this into a proper JSON response.

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

For example, if a field used to allow an empty string, avoid making it suddenly required unless that is an intentional business rule change.

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

## Future Improvements

Possible future enhancements for the validation system include:

- additional validation rules
- custom validation messages
- custom attribute names
- field-level error helpers in views
- Form Request classes
- middleware integration
- schema-aware validation helpers

---

## Summary

The Pramnos validation system provides:

- centralized validation logic
- reusable validation rules
- integration with requests, views, WWW applications, and APIs
- old input preservation
- session-based error flashing

It is designed to be lightweight, extensible, and safe to introduce gradually into existing projects.
