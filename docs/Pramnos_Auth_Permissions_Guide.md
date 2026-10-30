---
use_cases:
  - Checking whether a user may do something to a resource
  - Defining OAuth2 scopes and their policy
  - Extending authentication with an addon
---

# Permissions, OAuth2 scopes and addons

Part of the [authentication guide](Pramnos_Authentication_Guide.md).

## Permissions System

There is **one** permission store: `authserver.permissions`. It is created by
the `auth` feature's migrations, so every installation that has users has it —
running an OAuth server is not a prerequisite. Two APIs read it, and which one
you want depends on how much of the model you need:

| | `Pramnos\Auth\Permissions` | `Pramnos\Auth\PermissionResolver` |
|---|---|---|
| Shape | one question, one answer | the user's whole effective grant list |
| Reads | grants for a user or role | grants, roles, priorities, expiry, audience, conditions |
| Writes | yes — `allow()` / `deny()` | no |
| Use it for | a check in a controller | anything conditional, scoped or audited |

`Permissions` is the simple face; it cannot express what it cannot ask about.
Grants carrying ABAC conditions are **skipped** by it rather than treated as
unconditional — the resolver hands conditions to the application to evaluate
against its own request context, and this API has no way to receive one.

### Setting Permissions

`allow()`, `deny()` and `removePermission()` are instance methods; get the
shared instance from the factory.

```php
$permissions = \Pramnos\Auth\Permissions::getInstance();

// Grant a user the right to create articles
$permissions->allow(
    $userId,           // Subject — a user id
    'articles',        // Resource      → object_type
    'create',          // Privilege     → action
    '',                // Element: '' means all articles → object_id NULL
    'module',          // Resource type (not stored; see below)
    'user'             // Subject type: user | group
);

// Grant a group — stored as a role, which is what the new model calls it
$permissions->allow($editorsRoleId, 'articles', 'edit', '', 'module', 'group');

// Deny — stored above allow so it wins a tie
$permissions->deny($userId, 'admin', 'access');

// Remove — absence is not the same answer as deny
$permissions->removePermission($userId, 'admin', 'access');
```

Two mappings worth knowing: the `admin` privilege is stored as the `*` action
(everything on this object), and `resourceType` has no column in the new model —
`object_type` already carries that distinction.

A subject type other than `user` or `group` cannot be represented. Rather than
store it under the wrong `subject_type`, the call is refused and logged.

### Checking Permissions

```php
$permissions = \Pramnos\Auth\Permissions::getInstance();

// Default: an unknown permission is "no"
if ($permissions->isAllowed($userId, 'articles', 'create')) {
    $this->showCreateForm();
} else {
    throw new \Exception('Insufficient permissions', 403);
}
```

Pass `false` as the last argument when you need to tell "denied" apart from
"nobody ever said":

```php
$verdict = $permissions->isAllowed(
    $userId, 'articles', 'create', '', 'module', 'user', false
);

// true = allowed, false = explicitly denied, null = no rule at all
```

That distinction matters. Collapsing `null` into `false` is how a screen ends up
refusing a user for whom no rule was ever written.

**Users with a role per organisation.** `isAllowed()` reads the user's roles for the
organisation the request is about once the application has set
`OrganizationScope::resolveWith()`; for one named organisation, use
`isAllowedInOrganization($userId, $organizationId, 'articles', 'create')`. See
[Checking permissions in an organisation](Pramnos_Authorization_Guide.md#checking-permissions-in-an-organisation).

### Roles

The new model expresses groups as roles, and `PermissionResolver` folds a user's
active roles into their effective permissions automatically. Assign one by
inserting into `authserver.user_roles`; role definitions live in
`authserver.roles`.

For the full picture — priorities, expiry, audience scoping and ABAC conditions
— use the resolver directly:

```php
$resolver = new \Pramnos\Auth\PermissionResolver($database);
$effective = $resolver->resolve($userId, null)['permissions'];
```

Each entry carries `object_type`, `object_id`, `action`, `grant` and
`conditions`, with deny-over-allow already applied.

## OAuth2 Scopes and Policy

`Pramnos\Auth\Scopes` — static scope registry for OAuth2 consent screens and validation.

### Your own catalogue

Subclass, override `getScopes()`, and **name the class in `app.php`**:

```php
// app.php
'scopes' => ['provider' => \App\Scopes::class],
```

The second half is not optional. Every internal call in `Scopes` is `static::`, so the
subclass answers consistently for anything that names it — but ten sites in the framework
write `Scopes::` literally, and those are the ones that face outward:

| | |
|---|---|
| `Discovery` ×4 | `scopes_supported` in the `/.well-known/` documents |
| `Oauth::authorize()` | whether a requested scope exists |
| `Oauth` consent view | the description shown beside each grant |
| `OAuth2\Repositories\ScopeRepository` | whether a scope is registered at all |
| `Mcp\PublicRegistry` | which tools a token's scopes reach |
| `mcp:token`, `init` | validation, and what the scaffolder offers |

Without the config line an installation can define forty-four scopes, publish fifteen,
render a consent screen with no description beside what the user is being asked to grant,
and have its own identifiers refused as unknown.

A class that is not a `Scopes` subclass is refused with an exception rather than ignored:
a misspelt name that was quietly dropped is the same failure by a different route.

### Replace or merge — say which, in code

The framework cannot pick, because a catalogue is not reliably a superset. The subclass
says it:

```php
// Merge — the framework's standard scopes, plus yours
public static function getScopes(): array
{
    return parent::getScopes() + ['Devices' => [/* … */]];
}

// Replace — this installation's vocabulary is the whole vocabulary
public static function getScopes(): array
{
    return ['Devices' => [/* … */]];
}
```

**Replacing drops `openid`, `profile`, `email` and `offline_access` unless you declare
them**, and the OAuth2 server needs those to answer an OIDC request. Merging is the safer
default; replacing is the honest one for an installation that has curated its own list.

The MCP scopes are appended either way. They are derived from what
[`PublicRegistry`](Pramnos_MCP_Guide.md#serving-it-over-http-to-somebody-elses-assistant)
actually offers rather than declared anywhere, so a catalogue written before the endpoint
existed cannot make it ungrantable.

```php
use Pramnos\Auth\Scopes;

// All scopes grouped by category (consent screen)
$grouped = Scopes::getScopes();
// ['Personal User Data' => ['profile' => [...], 'email' => [...]], ...]

// Flat scope → description map
$descriptions = Scopes::getScopeDescriptions();

// Scopes implicitly granted to all clients
$defaults = Scopes::getDefaultScopes(); // ['profile', 'email', 'user']

// Validate a scope string
[$hasInvalid, $invalidList] = Scopes::hasInvalidScopes('profile email unknown_scope');

// Resolve inherited scopes transitively
$resolved = Scopes::resolveInheritedScopes('system:notifications_write');
// ['system:notifications_read', 'system:notifications_write']
```

Standard scopes: `profile`, `email`, `phone`, `address`, `user`, `openid`, `offline_access`, `system:admin`, `system:audit_read`, `system:health`, `system:notifications_read/write`.

`Pramnos\Auth\OAuthPolicyHelper` — server-wide OAuth2 policy defaults.

```php
use Pramnos\Auth\OAuthPolicyHelper;

$methods = OAuthPolicyHelper::getDefaultAllowedAuthMethods();
// ['client_secret_basic', 'client_secret_post', 'private_key_jwt']

$grants = OAuthPolicyHelper::getDefaultAllowedGrantTypes();
// ['authorization_code', 'client_credentials', 'device_code', 'password', 'refresh_token', 'exchange_token']
```

These are the grants a client with no rows of its own in `oauth2_application_grants` may use.
The list **includes `password`**. To drop it — OAuth 2.1 deprecates the grant — set the list in
`app.php`; `pramnos init` writes this key for an `authserver` project:

```php
'authserver' => [
    'default_grants'       => ['authorization_code', 'client_credentials', 'device_code', 'refresh_token', 'exchange_token'],
    'default_auth_methods' => ['client_secret_basic', 'client_secret_post', 'private_key_jwt'],
    'default_scopes'       => [],
],
```

`jwt_bearer` is never a default grant, and a `system:` scope is never a default scope: either is
ignored with a warning if listed.

## OAuth2 Integration

The OAuth2 **server** is built in — do not hand-roll an `authorize()` action. With the
`authserver` feature, `Pramnos\Auth\Controllers\Oauth` serves the authorize, token, revoke,
introspect, userinfo, logout, client-registration and device-authorization endpoints on `league/oauth2-server` (`Pramnos\Auth\OAuth2\OAuth2ServerFactory`), with
PKCE, consent, client authentication and per-client grant and scope policy. Authorization codes
are stored by `AuthCodeRepository`, not as user tokens. How a client application registers and
signs in is in the [Third-Party Integration Guide](Pramnos_AuthServer_Integration_Guide.md);
signing in to *another* platform is the [OAuth2 Client Guide](Pramnos_OAuth_Client_Guide.md).

## Authentication Addons

`Addon::load($name)` takes a file name **or** a fully-qualified class name, and refuses
anything that is neither — a `null` or an empty string returns `false` before the name is used
for anything. `isActive()` and `getAddon()` do the same.

`Pramnos\Addon\Auth\UserDatabase` and `Pramnos\Addon\User\User` are deprecated — see
[Addon Guide](Pramnos_Addon_Guide.md#deprecated-bundled-addons) for their native replacements and
what the deprecation does and doesn't require. `Auth::auth()`/`Auth::logout()` already provide
the equivalent behavior automatically when no such addon is registered; the subclassing example
below remains the way to add custom logic to an addon that is still registered.

That is not defensive coding for its own sake: the `addons` setting holds a serialized list,
and an entry saved from an admin screen with no addon selected stores a null name — without the
refusal, every request would ask an impossible question per such entry.

### User Database Addon

The framework includes a user database addon for standard username/password authentication:

```php
// The UserDatabase addon is automatically triggered during authentication
// It checks credentials against the users table

// Custom validation can be added by extending the addon.
// Auth calls onAuth() with five arguments; the parent declares four, with defaults, so an
// override keeps those defaults and may add `$validate = true` as an optional fifth.
class CustomUserAuth extends \Pramnos\Addon\Auth\UserDatabase
{
    public function onAuth($username, $password,
        $remember = true, $encryptedPassword = false, $validate = true)
    {
        $result = parent::onAuth($username, $password, $remember, $encryptedPassword);

        if (!empty($result['status']) && !$this->isAccountApproved((int) $result['uid'])) {
            return [
                'status'     => false,
                'message'    => 'Your account is awaiting approval',
                'statusCode' => 403,
            ];
        }

        return $result;
    }
}
```

The second factor does not belong here: the login flow (`LoginFlow`) asks for it after the
password is accepted — see [Second factors](Pramnos_Auth_Second_Factors_Guide.md#second-factors-which-ones-exist-and-who-decides).

### Creating Custom Authentication Addons

```php
class LdapAuth extends \Pramnos\Addon\Addon
{
    public function onAuth($username, $password,
        $remember = true, $encryptedPassword = false, $validate = true)
    {
        // LDAP authentication logic
        $ldapConnection = ldap_connect(
            (string) \Pramnos\Application\Settings::getSetting('ldap_server')
        );
        
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

### Hooks on this page

| Name | Fires in |
|---|---|
| `auth.signin_risk` (`Event::fire()`) | `Auth/SignInRisk.php` |
| `auth.signin_country` (`Event::fire()`) | `Auth/SignInRisk.php` |
| `invitation.accepted` (`Event::fire()`) | `Auth/Invitations.php` |
| `account.data_export` (`Event::fire()`) | `Auth/Controllers/Account.php` |
| `account.data_erase` (`Event::fire()`) | `Auth/AccountErasure.php` |
| `Login` / `Logout` / `AuthCheck` (`Addon::triger()`) | `Auth.php` |
| `afterLogin()` / `afterLogout()` callback list | `Auth.php` — a third, pre-existing mechanism specific to this class; new code should prefer `Event` |

See [Extensibility Guide](Pramnos_Extensibility_Guide.md) for the full catalog and how these mechanisms compare.
