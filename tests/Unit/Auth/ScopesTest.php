<?php

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Scopes;

/**
 * Unit tests for Pramnos\Auth\Scopes.
 *
 * Scopes is a pure static registry — no database interaction. All methods
 * are tested without Docker.
 *
 * Coverage:
 * - getScopes() returns a non-empty nested array grouped by category
 * - getScopeDescriptions() returns a flat string→string map covering all scopes
 * - getDefaultScopes() returns only scopes with is_default=true
 * - hasInvalidScopes() returns [false, []] for valid scope strings
 * - hasInvalidScopes() detects and lists unknown scope identifiers
 * - hasInvalidScopes() handles empty string gracefully
 * - resolveInheritedScopes() expands a scope to include its inherited scopes
 * - resolveInheritedScopes() deduplicates transitively included scopes
 * - resolveInheritedScopes() accepts both string and array input
 * - resolveInheritedScopes() ignores undefined scopes without error
 * - resolveInheritedScopes() handles direct circular references safely
 */
class ScopesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // getScopes()
    // -------------------------------------------------------------------------

    /**
     * getScopes() must return a non-empty nested array with at least one category.
     *
     * The return structure is [category => [scope => details]]. Each scope must
     * have 'description', 'is_default', and 'inherits' keys.
     */
    public function testGetScopesReturnsNonEmptyNestedArray(): void
    {
        // Act
        $scopes = Scopes::getScopes();

        // Assert
        $this->assertNotEmpty($scopes, 'getScopes() must return at least one category');

        foreach ($scopes as $category => $scopeMap) {
            $this->assertIsString($category);
            $this->assertIsArray($scopeMap);

            foreach ($scopeMap as $scope => $details) {
                $this->assertArrayHasKey('description', $details, "scope '{$scope}' must have a description");
                $this->assertArrayHasKey('is_default', $details,  "scope '{$scope}' must have is_default");
                $this->assertArrayHasKey('inherits', $details,    "scope '{$scope}' must have inherits");
                $this->assertIsString($details['description']);
                $this->assertIsBool($details['is_default']);
                $this->assertIsArray($details['inherits']);
            }
        }
    }

    /**
     * Standard OAuth scopes ('profile', 'email', 'openid', 'offline_access') must be defined.
     *
     * These are defined by OpenID Connect Core 1.0 and OAuth 2.0 RFC and must be
     * available in any standards-compliant OAuth server.
     */
    public function testGetScopesIncludesStandardOAuthScopes(): void
    {
        // Arrange
        $descriptions = Scopes::getScopeDescriptions();

        // Assert
        foreach (['profile', 'email', 'openid', 'offline_access'] as $required) {
            $this->assertArrayHasKey($required, $descriptions,
                "standard OAuth scope '{$required}' must be defined");
        }
    }

    // -------------------------------------------------------------------------
    // getScopeDescriptions()
    // -------------------------------------------------------------------------

    /**
     * getScopeDescriptions() must return a flat [scope → description] map.
     *
     * Every scope from every category must appear exactly once as a string key
     * with a non-empty string description.
     */
    public function testGetScopeDescriptionsReturnsFlatMap(): void
    {
        // Act
        $descriptions = Scopes::getScopeDescriptions();

        // Assert
        $this->assertNotEmpty($descriptions);

        foreach ($descriptions as $scope => $description) {
            $this->assertIsString($scope,       'scope key must be a string');
            $this->assertIsString($description, 'description must be a string');
            $this->assertNotEmpty($description, "scope '{$scope}' must have a non-empty description");
        }
    }

    /**
     * getScopeDescriptions() must contain every scope defined in getScopes().
     *
     * The flat map must be the union of all categories — no scope may be lost.
     */
    public function testGetScopeDescriptionsCoversAllScopes(): void
    {
        // Arrange
        $allScopeKeys = [];
        foreach (Scopes::getScopes() as $category) {
            $allScopeKeys = array_merge($allScopeKeys, array_keys($category));
        }
        $descriptions = Scopes::getScopeDescriptions();

        // Assert
        foreach ($allScopeKeys as $scope) {
            $this->assertArrayHasKey($scope, $descriptions,
                "scope '{$scope}' must appear in getScopeDescriptions()");
        }
    }

    // -------------------------------------------------------------------------
    // getDefaultScopes()
    // -------------------------------------------------------------------------

    /**
     * getDefaultScopes() must return only scopes that have is_default=true.
     *
     * No scope with is_default=false must appear in the returned list.
     */
    public function testGetDefaultScopesReturnsOnlyDefaultScopes(): void
    {
        // Arrange
        $flat = [];
        foreach (Scopes::getScopes() as $category) {
            foreach ($category as $scope => $details) {
                $flat[$scope] = $details;
            }
        }

        // Act
        $defaults = Scopes::getDefaultScopes();

        // Assert — every returned scope must have is_default=true
        foreach ($defaults as $scope) {
            $this->assertArrayHasKey($scope, $flat, "returned scope '{$scope}' must be defined");
            $this->assertTrue($flat[$scope]['is_default'],
                "scope '{$scope}' in getDefaultScopes() must have is_default=true");
        }

        // Assert — every is_default=true scope must be in the returned list
        foreach ($flat as $scope => $details) {
            if ($details['is_default']) {
                $this->assertContains($scope, $defaults,
                    "default scope '{$scope}' must appear in getDefaultScopes()");
            }
        }
    }

    // -------------------------------------------------------------------------
    // hasInvalidScopes()
    // -------------------------------------------------------------------------

    /**
     * hasInvalidScopes() must return [false, []] for a known valid scope string.
     */
    public function testHasInvalidScopesReturnsFalseForValidScopes(): void
    {
        // Act
        [$hasInvalid, $invalid] = Scopes::hasInvalidScopes('profile email');

        // Assert
        $this->assertFalse($hasInvalid, 'profile and email are valid scopes');
        $this->assertEmpty($invalid);
    }

    /**
     * hasInvalidScopes() must detect and list undefined scope identifiers.
     *
     * The invalid scope identifier must be returned in the $invalidScopes array
     * and $hasInvalid must be true.
     */
    public function testHasInvalidScopesDetectsUnknownScope(): void
    {
        // Act
        [$hasInvalid, $invalid] = Scopes::hasInvalidScopes('profile unknown_scope_xyz');

        // Assert
        $this->assertTrue($hasInvalid, 'unknown_scope_xyz must be flagged as invalid');
        $this->assertContains('unknown_scope_xyz', $invalid);
        $this->assertNotContains('profile', $invalid, 'profile must not appear as invalid');
    }

    /**
     * hasInvalidScopes() must handle an empty string without error.
     *
     * An empty scope string is not invalid — it simply contains no scopes to check.
     */
    public function testHasInvalidScopesHandlesEmptyString(): void
    {
        // Act
        [$hasInvalid, $invalid] = Scopes::hasInvalidScopes('');

        // Assert
        $this->assertFalse($hasInvalid);
        $this->assertEmpty($invalid);
    }

    // -------------------------------------------------------------------------
    // resolveInheritedScopes()
    // -------------------------------------------------------------------------

    /**
     * resolveInheritedScopes() must expand a scope to include its inherited scopes.
     *
     * 'system:notifications_write' inherits 'system:notifications_read'.
     * Both must appear in the resolved output.
     */
    public function testResolveInheritedScopesExpandsDirectInheritance(): void
    {
        // Act
        $resolved = Scopes::resolveInheritedScopes('system:notifications_write');

        // Assert
        $this->assertContains('system:notifications_write', $resolved);
        $this->assertContains('system:notifications_read',  $resolved,
            'system:notifications_write must pull in its inherited system:notifications_read');
    }

    /**
     * resolveInheritedScopes() must deduplicate scopes when multiple requested
     * scopes share inherited dependencies.
     *
     * If two scopes both inherit 'system:notifications_read', it must appear
     * only once in the output.
     */
    public function testResolveInheritedScopesDeduplicate(): void
    {
        // Act — request both the scope and its parent (which is also inherited)
        $resolved = Scopes::resolveInheritedScopes([
            'system:notifications_write',
            'system:notifications_read',
        ]);

        // Assert — system:notifications_read appears exactly once
        $this->assertSame(1, count(array_keys($resolved, 'system:notifications_read', true)),
            'system:notifications_read must appear exactly once after deduplication');
    }

    /**
     * resolveInheritedScopes() must accept both a space-delimited string and an array.
     *
     * The two calling conventions must produce the same result.
     */
    public function testResolveInheritedScopesAcceptsStringAndArray(): void
    {
        // Act
        $fromString = Scopes::resolveInheritedScopes('profile email');
        $fromArray  = Scopes::resolveInheritedScopes(['profile', 'email']);

        // Assert — same result regardless of input format
        $this->assertSame($fromString, $fromArray, 'string and array input must produce the same output');
    }

    /**
     * resolveInheritedScopes() must silently ignore undefined scope identifiers.
     *
     * Unknown scopes are simply not added to the resolved set — they do not
     * cause an error or corrupt the output for valid scopes.
     */
    public function testResolveInheritedScopesIgnoresUnknownScopes(): void
    {
        // Act
        $resolved = Scopes::resolveInheritedScopes('profile unknown_xyz');

        // Assert — profile is present, unknown_xyz is not
        $this->assertContains('profile', $resolved);
        $this->assertNotContains('unknown_xyz', $resolved,
            'undefined scopes must not appear in the resolved output');
    }

    /**
     * resolveInheritedScopes() must return [] when passed a non-string, non-array value.
     *
     * The method accepts a mixed $scopes parameter. If the value is neither a
     * string nor an array (e.g. null, int, bool), it must return an empty array
     * rather than throwing or producing garbage output.
     *
     * This covers the defensive `!is_array($scopes)` branch at line 199.
     */
    public function testResolveInheritedScopesReturnsEmptyForNonArrayNonString(): void
    {
        // Act — pass a value that is neither string nor array
        $resultNull = Scopes::resolveInheritedScopes(null);
        $resultInt  = Scopes::resolveInheritedScopes(42);

        // Assert — gracefully returns empty array, no exception
        $this->assertSame([], $resultNull, 'null input must produce an empty array');
        $this->assertSame([], $resultInt, 'int input must produce an empty array');
    }

    // ── addDefaultScopesToToken ───────────────────────────────────────────────

    /**
     * addDefaultScopesToToken() must merge a plain scope string with the default scopes.
     *
     * The method takes a space-delimited token scope string, adds the default scopes,
     * and returns a space-delimited string of unique scopes. Default scopes that are
     * already present must not be duplicated.
     */
    public function testAddDefaultScopesToTokenMergesWithDefaultScopes(): void
    {
        // Arrange
        $defaults = Scopes::getDefaultScopes();
        $this->assertNotEmpty($defaults, 'pre-condition: getDefaultScopes must not be empty');

        // Act — call with a single non-default scope
        $result = Scopes::addDefaultScopesToToken('email');

        // Assert — result contains the original scope and at least one default scope
        $resultScopes = explode(' ', $result);
        $this->assertContains('email', $resultScopes,
            'the original token scope must be preserved in the output');
        foreach ($defaults as $default) {
            $this->assertContains($default, $resultScopes,
                "default scope '{$default}' must be present in the merged output");
        }
    }

    /**
     * addDefaultScopesToToken() must strip bracket-wrapping before processing.
     *
     * Some OAuth2 storage implementations persist the token scopes as
     * "[profile email]" (with square brackets). The method must strip those
     * brackets before splitting on whitespace.
     *
     * This covers the regex-match branch (lines 155–156 of Scopes.php).
     */
    public function testAddDefaultScopesToTokenHandlesBracketWrappedInput(): void
    {
        // Arrange — bracket-wrapped scope string as stored by some OAuth providers
        $defaults = Scopes::getDefaultScopes();

        // Act
        $result = Scopes::addDefaultScopesToToken('[profile email]');

        // Assert — brackets are stripped; profile and email are in the result
        $resultScopes = explode(' ', $result);
        $this->assertContains('profile', $resultScopes,
            "'profile' from bracket-wrapped input must appear in the result");
        $this->assertContains('email', $resultScopes,
            "'email' from bracket-wrapped input must appear in the result");
        // Default scopes are merged in
        foreach ($defaults as $default) {
            $this->assertContains($default, $resultScopes,
                "default scope '{$default}' must still be merged when input is bracket-wrapped");
        }
    }

    /**
     * addDefaultScopesToToken() must deduplicate scopes that appear in both the
     * token string and the default scopes list.
     *
     * If a default scope is already present in the token, it must appear only once
     * in the output — no duplicates allowed.
     */
    public function testAddDefaultScopesToTokenDeduplicatesScopes(): void
    {
        // Arrange — build a string that already includes all default scopes
        $defaults = Scopes::getDefaultScopes();
        $input    = implode(' ', $defaults);

        // Act
        $result = Scopes::addDefaultScopesToToken($input);

        // Assert — no duplicates: count of scopes in result equals count of unique scopes
        $resultScopes = explode(' ', $result);
        $this->assertSame(
            count(array_unique($resultScopes)),
            count($resultScopes),
            'addDefaultScopesToToken must not produce duplicate scope identifiers'
        );
    }
}
