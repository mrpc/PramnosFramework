# Pramnos Authentication & User Management Guide

## Overview

The Pramnos Framework provides a comprehensive authentication system that supports multiple authentication methods, user management, permissions, JWT tokens, session management, and OAuth2 capabilities. The system is modular and extensible through addons.

## Architecture

### Core Components

1. **Auth System** (`Pramnos\Auth\Auth`) - Main authentication controller
2. **User Management** (`Pramnos\User\User`) - User data and operations
3. **JWT Support** (`Pramnos\Auth\JWT`) - JSON Web Token implementation
4. **Permissions** (`Pramnos\Auth\Permissions`) - Access control system
5. **Session Management** (`Pramnos\Http\Session`) - Session handling
6. **Token Management** (`Pramnos\User\Token`) - User tokens and API access

### Authentication Flow

```php
// Basic authentication flow
$auth = \Pramnos\Auth\Auth::getInstance();
$success = $auth->auth($username, $password, $rememberMe);

if ($success) {
    // User is authenticated, session is set
    $user = \Pramnos\User\User::getCurrentUser();
} else {
    // Authentication failed
    $response = $auth->lastResponse;
    echo $response['message'];
}
```

## User Authentication

### Basic Login

```php
// Simple login
$auth = \Pramnos\Auth\Auth::getInstance();
$result = $auth->auth('user@example.com', 'password');

if ($result) {
    echo "Login successful";
    // User session is automatically created
} else {
    echo "Login failed: " . $auth->lastResponse['message'];
}
```

### Login with Remember Me

```php
// Login with persistent session
$auth = \Pramnos\Auth\Auth::getInstance();
$result = $auth->auth('user@example.com', 'password', true); // Third parameter enables "remember me"

if ($result) {
    // User will stay logged in across browser sessions
    $user = \Pramnos\User\User::getCurrentUser();
}
```

### Encrypted Password Authentication

```php
// Login with pre-encrypted password (for API scenarios)
$auth = \Pramnos\Auth\Auth::getInstance();
$hashedPassword = password_hash('plaintext_password', PASSWORD_DEFAULT);
$result = $auth->auth('user@example.com', $hashedPassword, false, true); // Fourth parameter indicates encrypted password
```

### Logout

```php
// Logout current user
$auth = \Pramnos\Auth\Auth::getInstance();
$auth->logout();
// This triggers the 'Logout' addon events and clears the session
```

## User Management

### Creating Users

```php
// Create a new user
$user = new \Pramnos\User\User();
$user->username = 'johndoe';
$user->email = 'john@example.com';
$user->password = password_hash('password', PASSWORD_DEFAULT);
$user->firstname = 'John';
$user->lastname = 'Doe';
$user->status = 1; // Active
$user->save();
```

### Loading Users

```php
// Load user by ID
$user = new \Pramnos\User\User(123);

// Load user by username/email
$user = new \Pramnos\User\User();
$user->loadByUsername('johndoe');

// Get current logged-in user
$currentUser = \Pramnos\User\User::getCurrentUser();
if ($currentUser) {
    echo "Welcome, " . $currentUser->firstname;
}
```

### User Data Management

```php
$user = new \Pramnos\User\User(123);

// Get user data as array
$userData = $user->getData();

// Update user information
$user->email = 'newemail@example.com';
$user->save();

// Delete user
$user->delete();
```

## JWT Token Authentication

### Generating JWT Tokens

```php
// Create JWT token for user
$payload = [
    'userId' => $user->userid,
    'username' => $user->username,
    'exp' => time() + 3600, // Expires in 1 hour
    'iat' => time(), // Issued at
];

$secret = 'your-secret-key';
$token = \Pramnos\Auth\JWT::encode($payload, $secret, 'HS256');
```

### Validating JWT Tokens

```php
try {
    $secret = 'your-secret-key';
    \Pramnos\Auth\JWT::$leeway = 60; // Allow 60 seconds clock skew
    
    $decoded = \Pramnos\Auth\JWT::decode($token, $secret, ['HS256']);
    
    // Token is valid, load user
    $user = new \Pramnos\User\User($decoded->userId);
    
} catch (\Exception $e) {
    // Token validation failed
    echo "Invalid token: " . $e->getMessage();
}
```

### API Authentication with JWT

```php
// In API controllers, JWT is automatically handled
class ApiController extends \Pramnos\Application\Controller
{
    public function secureEndpoint()
    {
        // JWT token is validated automatically if present in HTTP_ACCESSTOKEN header
        if (!isset($_SESSION['user']) || !is_object($_SESSION['user'])) {
            return ['status' => 401, 'message' => 'Authentication required'];
        }
        
        $user = $_SESSION['user'];
        return ['status' => 200, 'data' => 'Protected data for user ' . $user->userid];
    }
}
```

## Token Management

### User Tokens

The framework supports various token types for different purposes:

```php
$user = new \Pramnos\User\User(123);

// Add authentication token
$token = $user->addToken('auth', bin2hex(random_bytes(32)), 'API access token');

// Add Apple Push Notification token
$apnsToken = $user->addToken('apns', $deviceToken, 'iPhone device');

// Add OAuth2 access token
$accessToken = $user->addToken('access_token', $oauthToken, 'OAuth2 access', $refreshTokenId);
```

### Token Operations

```php
$user = new \Pramnos\User\User(123);

// Get user's active auth token
$authToken = $user->getToken();

// Get all user tokens
$allTokens = $user->getAllTokens();

// Load user by token
$user->loadByToken($tokenString, 'auth');

// Clean up old tokens (older than 30 days)
\Pramnos\User\User::cleanupAllAuthTokens(30);
```

### Working with Token Objects

```php
// Create token object
$token = new \Pramnos\User\Token();
$token->userid = $user->userid;
$token->tokentype = 'auth';
$token->token = bin2hex(random_bytes(32));
$token->notes = 'Mobile app access';
$token->expires = time() + (30 * 24 * 60 * 60); // 30 days
$token->save();

// Load existing token
$existingToken = new \Pramnos\User\Token($tokenId);
$details = $existingToken->getDetails();

// Track token usage
$existingToken->addAction(); // Logs the current request
```

## Permissions System

### Setting Permissions

```php
// Grant permission to user
\Pramnos\Auth\Permissions::allow(
    $userId,           // Subject (user ID)
    'articles',        // Resource
    'create',          // Privilege
    0,                 // Resource element (0 for all)
    'module',          // Resource type
    'user'             // Subject type
);

// Grant permission to group
\Pramnos\Auth\Permissions::allow(
    'editors',         // Subject (group name)
    'articles',        // Resource
    'edit',            // Privilege
    0,                 // Resource element
    'module',          // Resource type
    'group'            // Subject type
);

// Deny permission
\Pramnos\Auth\Permissions::deny(
    $userId,
    'admin',
    'access',
    0,
    'module',
    'user'
);
```

### Checking Permissions

```php
// Check if user has permission
$hasPermission = \Pramnos\Auth\Permissions::check(
    $userId,           // Subject
    'articles',        // Resource
    'create',          // Privilege
    0,                 // Resource element
    'module',          // Resource type
    'user'             // Subject type
);

if ($hasPermission) {
    // User can create articles
    $this->showCreateForm();
} else {
    // Access denied
    throw new \Exception('Insufficient permissions', 403);
}
```

### Group-Based Permissions

```php
// Users inherit permissions from their groups
$user = new \Pramnos\User\User($userId);
$userGroups = $user->getUserGroups(); // Get user's groups

// Check permission considering group membership
$hasPermission = \Pramnos\Auth\Permissions::check(
    $userId,
    'articles',
    'publish',
    0,
    'module',
    'user'
); // Automatically checks group permissions too
```

## Session Management

### Basic Session Operations

```php
$session = \Pramnos\Http\Session::getInstance();

// Check if user is logged in
if ($session->isLogged()) {
    $userId = $_SESSION['uid'];
    $username = $_SESSION['username'];
}

// Create snapshot for post-login redirect
$session->snapshot($_SERVER['REQUEST_URI']);

// Get and clear snapshot
$returnUrl = $session->getSnapshot();
if ($returnUrl) {
    $this->redirect($returnUrl);
}
```

### Session Security

```php
$session = \Pramnos\Http\Session::getInstance();

// Get session token for CSRF protection
$token = $session->getToken();

// Validate CSRF token
if ($session->checkToken('post', 'csrf_')) {
    // Token is valid, process request
} else {
    // Invalid token, possible CSRF attack
    throw new \Exception('Invalid security token', 403);
}

// Reset session (for logout)
$session->reset();
```

## Authentication Addons

### User Database Addon

The framework includes a user database addon for standard username/password authentication:

```php
// The UserDatabase addon is automatically triggered during authentication
// It checks credentials against the users table

// Custom validation can be added by extending the addon
class CustomUserAuth extends \Pramnos\Addon\Auth\UserDatabase
{
    public function onAuth($username, $password, $remember, $encryptedPassword, $validate)
    {
        // Add custom validation logic
        $result = parent::onAuth($username, $password, $remember, $encryptedPassword, $validate);
        
        if ($result['status']) {
            // Additional checks (e.g., account verification, 2FA)
            if (!$this->isTwoFactorVerified($result['uid'])) {
                return [
                    'status' => false,
                    'message' => 'Two-factor authentication required',
                    'statusCode' => 401
                ];
            }
        }
        
        return $result;
    }
}
```

### Creating Custom Authentication Addons

```php
class LdapAuth extends \Pramnos\Addon\Addon
{
    public function onAuth($username, $password, $remember, $encryptedPassword, $validate)
    {
        // LDAP authentication logic
        $ldapConnection = ldap_connect($this->config['ldap_server']);
        
        if (ldap_bind($ldapConnection, $username, $password)) {
            // Authentication successful
            $userInfo = $this->getLdapUserInfo($ldapConnection, $username);
            
            return [
                'status' => true,
                'username' => $username,
                'uid' => $userInfo['uid'],
                'email' => $userInfo['email'],
                'auth' => password_hash($password, PASSWORD_DEFAULT)
            ];
        }
        
        return [
            'status' => false,
            'message' => 'LDAP authentication failed'
        ];
    }
}
```

## Controller Authentication

### Protecting Controller Actions

```php
class ArticleController extends \Pramnos\Application\Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Define public actions (no authentication required)
        $this->addAction(['display', 'view']);
        
        // Define authenticated actions (login required)
        $this->addAuthAction(['create', 'edit', 'delete', 'save']);
        
        parent::__construct($application);
    }
    
    public function display()
    {
        // Public action - anyone can access
        return $this->getView('article')->display();
    }
    
    public function create()
    {
        // Authenticated action - user must be logged in
        // Framework automatically checks authentication
        return $this->getView('article')->display('create');
    }
}
```

### API Authentication

```php
class ApiController extends \Pramnos\Application\Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // API endpoints typically require authentication
        $this->addAuthAction(['list', 'create', 'update', 'delete']);
        parent::__construct($application);
    }
    
    public function list()
    {
        // Check if user is authenticated via JWT or session
        if (!isset($_SESSION['user']) || !is_object($_SESSION['user'])) {
            return ['status' => 401, 'error' => 'Authentication required'];
        }
        
        $user = $_SESSION['user'];
        // Return data specific to the authenticated user
        return ['status' => 200, 'data' => $this->getUserData($user->userid)];
    }
}
```

## Advanced Features

### Multi-Factor Authentication

```php
class UserWithMFA extends \Pramnos\User\User
{
    public function generateTotpSecret()
    {
        $secret = \Base32\Base32::encode(random_bytes(20));
        $this->totp_secret = $secret;
        $this->save();
        return $secret;
    }
    
    public function verifyTotp($code)
    {
        $totp = new \OTPHP\TOTP($this->totp_secret);
        return $totp->verify($code);
    }
    
    public function requiresMFA()
    {
        return !empty($this->totp_secret);
    }
}
```

### Password Security

```php
class SecureUser extends \Pramnos\User\User
{
    public function setPassword($plainPassword)
    {
        // Validate password strength
        if (!$this->isPasswordStrong($plainPassword)) {
            throw new \Exception('Password does not meet security requirements');
        }
        
        // Hash with current best practices
        $this->password = password_hash($plainPassword, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    public function verifyPassword($plainPassword)
    {
        return password_verify($plainPassword, $this->password);
    }
    
    private function isPasswordStrong($password)
    {
        // Implement password strength requirements
        return strlen($password) >= 8 
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password) 
            && preg_match('/[0-9]/', $password)
            && preg_match('/[^A-Za-z0-9]/', $password);
    }
}
```

### OAuth2 Integration

```php
// OAuth2 server capabilities are built into the API system
class OAuth2Controller extends \Pramnos\Application\Controller
{
    public function authorize()
    {
        // Handle OAuth2 authorization requests
        $clientId = $_GET['client_id'];
        $redirectUri = $_GET['redirect_uri'];
        $scope = $_GET['scope'] ?? 'read';
        
        // Validate client application
        $app = new \Pramnos\Application\Api\Apikey($clientId);
        if ($app->appid == 0 || $app->callback !== $redirectUri) {
            throw new \Exception('Invalid client application');
        }
        
        // If user is not logged in, redirect to login
        if (!\Pramnos\Http\Session::staticIsLogged()) {
            $this->redirect('/login?return_to=' . urlencode($_SERVER['REQUEST_URI']));
            return;
        }
        
        // Generate authorization code
        $user = \Pramnos\User\User::getCurrentUser();
        $authCode = bin2hex(random_bytes(32));
        
        $user->addToken('auth_code', $authCode, 'OAuth2 authorization code', null);
        
        // Redirect back to client with code
        $this->redirect($redirectUri . '?code=' . $authCode . '&state=' . ($_GET['state'] ?? ''));
    }
}
```

## Configuration

### Authentication Settings

```php
// In your application configuration
return [
    'authentication' => [
        'jwt_secret' => env('JWT_SECRET', 'your-secret-key'),
        'jwt_expiry' => env('JWT_EXPIRY', 3600), // 1 hour
        'session_timeout' => env('SESSION_TIMEOUT', 1800), // 30 minutes
        'remember_me_duration' => env('REMEMBER_ME_DURATION', 2592000), // 30 days
        'password_hash_algo' => PASSWORD_ARGON2ID,
        'require_email_verification' => env('REQUIRE_EMAIL_VERIFICATION', true),
        'enable_mfa' => env('ENABLE_MFA', false),
    ],
    
    'permissions' => [
        'cache_permissions' => env('CACHE_PERMISSIONS', true),
        'default_user_permissions' => [
            'profile' => ['read', 'update'],
            'content' => ['read']
        ]
    ]
];
```

### Database Setup

The authentication system requires several database tables. Use the framework's migration system to set them up:

```sql
-- Users table
CREATE TABLE `users` (
    `userid` int NOT NULL AUTO_INCREMENT,
    `username` varchar(255) NOT NULL,
    `email` varchar(255) NOT NULL,
    `password` varchar(255) NOT NULL,
    `firstname` varchar(255),
    `lastname` varchar(255),
    `status` tinyint DEFAULT 1,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`userid`),
    UNIQUE KEY `username` (`username`),
    UNIQUE KEY `email` (`email`)
);

-- User tokens table
CREATE TABLE `usertokens` (
    `tokenid` int NOT NULL AUTO_INCREMENT,
    `userid` int NOT NULL,
    `tokentype` varchar(50) NOT NULL,
    `token` varchar(255) NOT NULL,
    `created` int NOT NULL,
    `lastused` int DEFAULT 0,
    `expires` int DEFAULT NULL,
    `status` tinyint DEFAULT 1,
    `notes` text,
    PRIMARY KEY (`tokenid`),
    KEY `userid` (`userid`),
    KEY `token` (`token`),
    FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE
);

-- Permissions table
CREATE TABLE `permissions` (
    `id` int NOT NULL AUTO_INCREMENT,
    `subject` varchar(255) NOT NULL,
    `subjecttype` enum('user','group') NOT NULL,
    `resource` varchar(255) NOT NULL,
    `resourcetype` varchar(255) NOT NULL,
    `privilege` varchar(255) NOT NULL,
    `resourceelement` varchar(255) DEFAULT '0',
    `value` tinyint NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `subject_resource` (`subject`, `resource`, `privilege`)
);
```

## Best Practices

### Security Guidelines

1. **Always use prepared statements** - The framework's `prepareQuery()` method prevents SQL injection
2. **Validate JWT tokens properly** - Set appropriate expiry times and validate all claims
3. **Use strong passwords** - Implement password complexity requirements
4. **Enable CSRF protection** - Use session tokens for form submissions
5. **Implement rate limiting** - Prevent brute force attacks on login endpoints
6. **Use HTTPS** - Always transmit authentication data over secure connections
7. **Log security events** - Monitor failed login attempts and permission violations

### Performance Considerations

1. **Cache permissions** - Use the caching system for frequently checked permissions
2. **Optimize token queries** - Index token tables properly
3. **Clean up expired tokens** - Regularly remove old authentication tokens
4. **Use efficient session storage** - Consider Redis for session storage in production

### Error Handling

```php
try {
    $auth = \Pramnos\Auth\Auth::getInstance();
    $result = $auth->auth($username, $password);
    
    if (!$result) {
        $response = $auth->lastResponse;
        \Pramnos\Logs\Logger::logWarning('Failed login attempt', [
            'username' => $username,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'reason' => $response['message']
        ]);
        
        return ['status' => 401, 'message' => 'Authentication failed'];
    }
    
} catch (\Exception $e) {
    \Pramnos\Logs\Logger::logError('Authentication error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    return ['status' => 500, 'message' => 'Internal authentication error'];
}
```

This authentication system provides a robust foundation for securing your Pramnos Framework applications with support for modern authentication patterns, comprehensive user management, and flexible permission systems.

---

## Related Documentation

- **[Framework Guide](Pramnos_Framework_Guide.md)** - Core framework concepts and MVC patterns
- **[Database API Guide](Pramnos_Database_API_Guide.md)** - Database operations for user data management
- **[Cache System Guide](Pramnos_Cache_Guide.md)** - Caching user sessions and permissions
- **[Console Commands Guide](Pramnos_Console_Guide.md)** - CLI tools for user management
- **[Logging System Guide](Pramnos_Logging_Guide.md)** - Logging authentication events and security monitoring
- **[Email System Guide](Pramnos_Email_Guide.md)** - Password reset and notification emails
- **[Internationalization Guide](Pramnos_Internationalization_Guide.md)** - Multi-language authentication flows

---

For additional information on implementing authentication in your controllers and APIs, see the [Framework Guide](Pramnos_Framework_Guide.md#authentication-and-authorization) section on authentication patterns.