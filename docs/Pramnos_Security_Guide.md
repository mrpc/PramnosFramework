# Pramnos Security Guide

Security is a core concern in Pramnos Framework v1.2. This guide covers built-in security features and best practices.

## CSRF Protection

### Cross-Site Request Forgery Prevention

Pramnos includes automatic CSRF protection using session-stable, random token names.

#### In Forms

```html
<!-- Use helper to include CSRF field -->
<?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>

<!-- Generates: -->
<!-- <input type="hidden" name="pramnos_token_abc123" value="xyz789"> -->
```

#### In Controllers

```php
class UserController extends \Pramnos\Application\Controller
{
    public function save()
    {
        // Validate CSRF token (automatic in framework)
        if (!$this->validateToken()) {
            throw new \RuntimeException('CSRF token validation failed');
        }
        
        // Safe to proceed with state-changing operation
    }
}
```

#### Middleware

CSRF validation can be enforced via middleware:

```php
$router->group(['middleware' => ['csrf']], function ($router) {
    $router->post('/users', 'UserController@store');
    $router->patch('/users/{id}', 'UserController@update');
});
```

## Session Security

### Session Cookie Hardening

Pramnos v1.2 includes hardened session cookie settings:

```php
// In app configuration
'session' => [
    'lifetime'    => 120,
    'path'        => '/',
    'domain'      => null,
    'secure'      => true,      // HTTPS only
    'http_only'   => true,      // No JavaScript access
    'same_site'   => 'Lax',     // CSRF/XSRF protection
],
```

### Session Handling

```php
$session = \Pramnos\Http\Session::getInstance();

// Create session
$session->set('user_id', $user->id);

// Retrieve session data
$userId = $session->get('user_id');

// Regenerate session ID (on login)
$session->regenerate();

// Destroy session (on logout)
$session->destroy();
```

## Password Security

### Hashing

Always hash passwords before storing:

```php
// DO NOT store plain passwords
$plainPassword = $_POST['password'];

// Hash using secure algorithm (bcrypt/scrypt)
$hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

// OR use PHP 8.1+ modern syntax
$hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

// Store $hashedPassword in database
```

### Password Verification

```php
// Verify against stored hash
if (password_verify($plainPassword, $storedHash)) {
    // Password correct
} else {
    // Password incorrect
}

// Check if hash needs rehashing (algorithm updated)
if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
    $newHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    // Update database with new hash
}
```

## XSS Prevention

### Output Escaping

Always escape user-supplied data before outputting:

```php
<!-- Unsafe — can inject scripts -->
<?php echo $userComment; ?>

<!-- Safe — escapes HTML -->
<?php echo htmlspecialchars($userComment, ENT_QUOTES, 'UTF-8'); ?>

<!-- Using view helpers -->
<?php echo $view->escape($userComment); ?>
<?php echo e($userComment); ?>
```

### Context-Aware Escaping

```php
// HTML context
$escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

// JavaScript context
$escaped = json_encode($text);

// URL context
$escaped = urlencode($text);

// CSS context
$escaped = preg_replace('![^a-z0-9-]!i', '', $text);
```

### View Escaping Helpers

Pramnos v1.2 includes view helpers for safe output:

```php
<!-- Escape for HTML -->
{{ $user->name }}
{!! $user->name !!}  <!-- No escaping -->

<!-- Escape for attributes -->
<a href="{{ route('post', $post->id) }}">{{ $post->title }}</a>

<!-- Escape for JavaScript -->
<script>
  const data = {{ json_encode($data) }};
</script>
```

## SQL Injection Prevention

### Use Parameterized Queries

```php
// UNSAFE — never do this
$sql = "SELECT * FROM users WHERE email = '" . $_POST['email'] . "'";
$result = $db->query($sql);

// SAFE — use QueryBuilder
$user = $db->queryBuilder()
    ->from('users')
    ->where('email', $_POST['email'])  // Automatically parameterized
    ->first();

// SAFE — use prepareQuery with printf-style
$sql = $db->prepareQuery("SELECT * FROM users WHERE email = %s", $_POST['email']);
$result = $db->query($sql);
```

### QueryBuilder Escaping

The `QueryBuilder` automatically handles escaping:

```php
$users = $db->queryBuilder()
    ->from('users')
    ->where('username', 'LIKE', '%' . $search . '%')  // Auto-escaped
    ->get();
```

## Authentication

### Login/Logout

```php
// Login
$user = \Pramnos\User\User::authenticate($username, $password);
if ($user) {
    $session->set('userid', $user->userid);
    // Success
} else {
    // Authentication failed
}

// Logout
$session->destroy();
```

### Login Lockout

Prevent brute-force attacks:

```php
$lockout = new \Pramnos\Auth\Loginlockout($user);

// Check if user is locked out
if ($lockout->isLocked()) {
    return "Too many login attempts. Try again in " . $lockout->getRemainingTime() . " seconds";
}

// Record failed attempt
$lockout->recordFailure();

// Clear failures on success
$lockout->clearFailures();
```

### Two-Factor Authentication

Protect accounts with 2FA:

```php
$totp = new \Pramnos\Auth\TOTPHelper($user);

// Generate secret
$secret = $totp->generateSecret();

// Verify code
if ($totp->verify($code)) {
    // Code valid
} else {
    // Code invalid
}
```

## Content Security Policy

### CSP Headers

Protect against XSS by restricting script sources:

```php
// In controller or middleware
$response->setHeader('Content-Security-Policy', 
    "default-src 'self'; script-src 'nonce-" . $nonce . "'; style-src 'unsafe-inline'");

// In template
<script nonce="<?php echo $nonce; ?>">
    // Only this script executes
</script>
```

### Nonce Generation

```php
$nonce = bin2hex(random_bytes(16));
// Pass to template and validate on execution
```

## File Upload Security

### Validate Uploads

```php
if ($_FILES['avatar']['size'] > 5 * 1024 * 1024) {
    throw new \RuntimeException('File too large');
}

$allowed = ['jpg', 'png', 'gif'];
$ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);

if (!in_array(strtolower($ext), $allowed)) {
    throw new \RuntimeException('File type not allowed');
}

// Verify MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['avatar']['tmp_name']);

if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'])) {
    throw new \RuntimeException('Invalid file type');
}

// Move to secure location
move_uploaded_file($_FILES['avatar']['tmp_name'], '/secure/uploads/avatar_' . uniqid() . '.jpg');
```

## Dependency Security

### Keep Dependencies Updated

```bash
# Check for security vulnerabilities
composer audit

# Update dependencies
composer update

# Require security patches
composer require symfony/security --security-advisories
```

## Security Headers

### Recommended Headers

```php
// In base controller or middleware
$response->setHeader('X-Content-Type-Options', 'nosniff');
$response->setHeader('X-Frame-Options', 'SAMEORIGIN');
$response->setHeader('X-XSS-Protection', '1; mode=block');
$response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
$response->setHeader('Permissions-Policy', 'geolocation=(), microphone=()');
```

## Reference

For complete documentation on security features, see:

- [v1.2 New Features — CSRF Hardening](1.2-new-features.md#21-phase-4-security--csrf-hardening)
- [v1.2 New Features — Session Cookie Hardening](1.2-new-features.md#22-phase-4-security--session-cookie-hardening)
- [v1.2 New Features — View Escaping Helpers](1.2-new-features.md#23-phase-4-security--view-escaping-helpers)
- [v1.2 New Features — Login Lockout](1.2-new-features.md#33-phase-2-auth--pramnos-auth-loginlockout)
- [v1.2 New Features — 2FA/TOTP](1.2-new-features.md#34-phase-2-auth--pramnos-auth-totphelper--pramnos-auth-twofactorauthservice)

**Topics covered in detailed reference:**

- CSRF token generation and validation strategies
- Session configuration and lifecycle
- Password hashing and verification
- Output escaping for different contexts
- SQL injection prevention patterns
- Authentication flows and session management
- Two-factor authentication implementation
- API token security and OAuth2
- File upload validation
- Security headers and CSP
- Dependency vulnerability scanning
