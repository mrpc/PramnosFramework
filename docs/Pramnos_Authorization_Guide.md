# Pramnos Authorization & Policies Guide

The **Policy Engine** provides a flexible framework for defining and evaluating authorization rules. Policies encapsulate authorization logic for models and resources.

**Classes:**
- `Pramnos\Auth\Policy` — Base policy class
- `Pramnos\Auth\PolicyEngine` — Policy evaluation

## Defining Policies

### Create a Policy Class

```php
<?php
namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    /**
     * Determine if user can view a post
     */
    public function view(User $user, Post $post)
    {
        return $post->published || $user->id === $post->user_id;
    }
    
    /**
     * Determine if user can create posts
     */
    public function create(User $user)
    {
        return $user->role === 'author' || $user->role === 'admin';
    }
    
    /**
     * Determine if user can update a post
     */
    public function update(User $user, Post $post)
    {
        return $user->id === $post->user_id || $user->role === 'admin';
    }
    
    /**
     * Determine if user can delete a post
     */
    public function delete(User $user, Post $post)
    {
        return $user->id === $post->user_id || $user->role === 'admin';
    }
}
```

## Using Policies

### Check Authorization

```php
$user = \Pramnos\User\User::getCurrentUser();
$post = Post::find(42);

$engine = new \Pramnos\Auth\PolicyEngine();

// Check if user can perform action
if ($engine->authorize('view', $post, $user)) {
    // Show post
} else {
    // Deny access
}

// Or using helper
if (auth()->can('update', $post)) {
    // Allow editing
}

// Deny with custom message
auth()->authorize('delete', $post);  // Throws exception if denied
```

## Before & After Hooks

Policies can short-circuit authorization with before/after hooks:

```php
class PostPolicy
{
    /**
     * Run before all other policy methods
     * Return boolean to allow/deny, or null to continue
     */
    public function before(User $user)
    {
        // Admins can do everything
        if ($user->role === 'admin') {
            return true;
        }
    }
    
    /**
     * Run after all other policy methods
     * Return boolean to override policy result
     */
    public function after(User $user, $action, $result)
    {
        // Superuser can override
        if ($user->role === 'superuser') {
            return true;
        }
    }
}
```

## Registering Policies

### Register in Service Provider

```php
<?php
namespace App\Providers;

use Pramnos\Application\ServiceProvider;
use App\Policies\PostPolicy;
use App\Models\Post;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Post::class => PostPolicy::class,
    ];
    
    public function boot()
    {
        // Register policies
        foreach ($this->policies as $model => $policy) {
            \Pramnos\Auth\Gate::policy($model, $policy);
        }
    }
}
```

## Model Authorization

### Direct Model Authorization

```php
$post = Post::find(42);

// Check if current user can perform action
if ($post->authorize('update')) {
    // User can update
}

// Or explicitly pass user
if ($post->authorize('update', $user)) {
    // User can update
}
```

## Gates

Simple authorization rules without policies:

```php
// Register gate
\Pramnos\Auth\Gate::define('moderate-content', function ($user) {
    return $user->role === 'moderator' || $user->role === 'admin';
});

// Check gate
if (auth()->can('moderate-content')) {
    // User is moderator
}

// Gate with parameters
\Pramnos\Auth\Gate::define('update-settings', function ($user, $setting) {
    return $user->ownsSettings($setting);
});

if (auth()->can('update-settings', $setting)) {
    // User can modify this setting
}
```

## Authorization in Controllers

```php
class PostController extends \Pramnos\Application\Controller
{
    public function edit($id)
    {
        $post = Post::find($id);
        
        // Authorize the action
        $this->authorize('update', $post);  // Throws if denied
        
        // If we reach here, authorization passed
        return view('posts.edit', ['post' => $post]);
    }
}
```

## Authorization Errors

### Handling Authorization Failures

```php
// Throws \Pramnos\Auth\AuthorizationException if denied
try {
    auth()->authorize('delete', $post);
} catch (\Pramnos\Auth\AuthorizationException $e) {
    return view('errors/unauthorized', ['message' => $e->getMessage()]);
}
```

### Custom Messages

```php
class PostPolicy
{
    public function delete(User $user, Post $post)
    {
        if ($user->id !== $post->user_id && $user->role !== 'admin') {
            throw new \Pramnos\Auth\AuthorizationException(
                'You do not own this post'
            );
        }
        
        return true;
    }
}
```

## Reference

For complete documentation on authorization features, see:

- [v1.2 New Features — Policy Engine](1.2-new-features.md#15-phase-4-policy-engine)
- [v1.2 New Features — Authorization Policies](1.2-new-features.md#35-phase-2-auth--pramnos-auth-scopes--pramnos-auth-oauthpolicyhelper)

**Topics covered in detailed reference:**

- Policy class structure and methods
- Before/after authorization hooks
- Model authorization macros
- Gate definition and evaluation
- Resource authorization
- Authorization caching and performance
- Custom authorization exceptions
- Middleware for authorization checks
