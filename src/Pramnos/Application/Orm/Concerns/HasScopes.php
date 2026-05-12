<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm\Concerns;

/**
 * Local and global query scopes.
 *
 * ## Local scopes
 * Define a `scopeXxx(string $filter, ...args): string` method on the model.
 * Call it via `$model->applyScope('xxx', ...$args)` which appends the scope's
 * SQL fragment to a filter string.
 *
 * ```php
 * class Post extends OrmModel {
 *     public function scopePublished(string $filter): string {
 *         return $this->appendCondition($filter, 'status = "published"');
 *     }
 *     public function scopeOlderThan(string $filter, int $days): string {
 *         return $this->appendCondition($filter, "created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
 *     }
 * }
 *
 * // Usage
 * $posts = $post->applyScope('published')->_getList();
 * ```
 *
 * ## Global scopes
 * Registered via `addGlobalScope()`.  Applied automatically to all queries
 * via `applyGlobalScopes(string $filter): string`.
 *
 * ```php
 * Post::addGlobalScope('tenant', fn($f) => "$f AND tenant_id = " . Auth::tenantId());
 * ```
 *
 * @package     PramnosFramework
 * @subpackage  Application\Orm\Concerns
 */
trait HasScopes
{
    /**
     * Registered global scopes for this model class.
     * Structure: [ClassName => [scopeName => callable, ...]]
     *
     * @var array<string, array<string, callable>>
     */
    private static array $globalScopes = [];

    /** Scopes temporarily disabled for the current query (by name). */
    private array $withoutScopes = [];

    // -------------------------------------------------------------------------
    // Global scope registration
    // -------------------------------------------------------------------------

    /**
     * Register a global scope that is automatically applied to all queries.
     *
     * @param string   $name      Unique name (used for removal).
     * @param callable $callback  Receives (string $filter): string.
     */
    public static function addGlobalScope(string $name, callable $callback): void
    {
        self::$globalScopes[static::class][$name] = $callback;
    }

    /**
     * Remove a previously-registered global scope (affects all future queries).
     */
    public static function removeGlobalScope(string $name): void
    {
        unset(self::$globalScopes[static::class][$name]);
    }

    /**
     * Temporarily disable a global scope for the next query only.
     * Returns $this for fluent use.
     */
    public function withoutGlobalScope(string $name): static
    {
        $this->withoutScopes[] = $name;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Scope application
    // -------------------------------------------------------------------------

    /**
     * Apply all registered global scopes to $filter.
     */
    protected function applyGlobalScopes(string $filter): string
    {
        $scopes = self::$globalScopes[static::class] ?? [];
        foreach ($scopes as $name => $callback) {
            if (!in_array($name, $this->withoutScopes, true)) {
                $filter = $callback($filter);
            }
        }
        // Reset per-query disabled list
        $this->withoutScopes = [];
        return $filter;
    }

    /**
     * Invoke a local scope by name and return the modified filter string.
     *
     * @param  string $scope  Name without "scope" prefix (case-insensitive).
     * @param  mixed  ...$args
     */
    public function applyScope(string $scope, mixed ...$args): static
    {
        $method = 'scope' . ucfirst($scope);
        if (!method_exists($this, $method)) {
            throw new \BadMethodCallException(
                "Scope '{$scope}' not defined on " . static::class
            );
        }
        $this->_pendingScopes[] = [$method, $args];
        return $this;
    }

    /**
     * Apply all accumulated local scopes to the filter string.
     */
    protected function applyPendingScopes(string $filter): string
    {
        foreach ($this->_pendingScopes ?? [] as [$method, $args]) {
            $filter = $this->$method($filter, ...$args);
        }
        $this->_pendingScopes = [];
        return $filter;
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Append an SQL condition to an existing filter string using AND.
     * Returns the condition alone if $filter is empty.
     */
    protected function appendCondition(string $filter, string $condition): string
    {
        if ($condition === '') {
            return $filter;
        }
        return $filter === '' ? $condition : "({$filter}) AND ({$condition})";
    }
}
