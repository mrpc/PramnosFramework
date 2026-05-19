<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Scopes;

/**
 * Unit tests for the Scopes OAuth2 scope registry.
 *
 * Scopes is a pure static-data class — no database or HTTP dependencies.
 * Tests verify:
 *  - getScopes() structure: categories, required keys per scope entry
 *  - getScopeDescriptions() flat map is consistent with getScopes()
 *  - getDefaultScopes() returns only scopes marked is_default = true
 *  - addDefaultScopesToToken() strips brackets, merges, deduplicates
 *  - hasInvalidScopes() correctly identifies valid and unknown scopes
 *  - resolveInheritedScopes() expands inherited scope chains
 */
#[CoversClass(Scopes::class)]
class ScopesTest extends TestCase
{
    // ── getScopes() ───────────────────────────────────────────────────────────

    /**
     * getScopes() must return a non-empty array of categories.
     *
     * The entire class is driven by this registry; if it returns empty the
     * rest of the methods are trivially broken.
     */
    public function testGetScopesReturnsNonEmptyArray(): void
    {
        // Act
        $scopes = Scopes::getScopes();

        // Assert
        $this->assertIsArray($scopes, 'getScopes() must return an array');
        $this->assertNotEmpty($scopes, 'getScopes() must not return an empty array');
    }

    /**
     * Every scope entry inside getScopes() must have the three required keys:
     * description (string), is_default (bool), inherits (array).
     *
     * Without these keys downstream callers will issue undefined-index notices.
     */
    public function testGetScopesAllEntriesHaveRequiredKeys(): void
    {
        // Act
        $scopes = Scopes::getScopes();

        // Assert — walk every category and every scope entry
        foreach ($scopes as $category => $entries) {
            $this->assertIsArray($entries,
                "Category '$category' must be an array of scope entries");

            foreach ($entries as $scopeId => $details) {
                $this->assertArrayHasKey('description', $details,
                    "Scope '$scopeId' must have a 'description' key");
                $this->assertArrayHasKey('is_default', $details,
                    "Scope '$scopeId' must have an 'is_default' key");
                $this->assertArrayHasKey('inherits', $details,
                    "Scope '$scopeId' must have an 'inherits' key");

                $this->assertIsString($details['description'],
                    "Scope '$scopeId' description must be a string");
                $this->assertIsBool((bool) $details['is_default'],
                    "Scope '$scopeId' is_default must be boolean-castable");
                $this->assertIsArray($details['inherits'],
                    "Scope '$scopeId' inherits must be an array");
            }
        }
    }

    /**
     * The known standard scopes (profile, email, openid, system:admin) must all
     * be present somewhere in getScopes() so that OAuth flows work correctly.
     */
    public function testGetScopesContainsKnownStandardScopes(): void
    {
        // Arrange — collect all scope identifiers across all categories
        $allScopeIds = [];
        foreach (Scopes::getScopes() as $entries) {
            $allScopeIds = array_merge($allScopeIds, array_keys($entries));
        }

        // Act / Assert
        $required = ['profile', 'email', 'openid', 'system:admin'];
        foreach ($required as $scope) {
            $this->assertContains($scope, $allScopeIds,
                "Standard scope '$scope' must be present in getScopes()");
        }
    }

    // ── getScopeDescriptions() ────────────────────────────────────────────────

    /**
     * getScopeDescriptions() must return a flat map where every key is a scope
     * identifier and every value is the description string for that scope.
     *
     * The flat map is used to render consent-screen text.
     */
    public function testGetScopeDescriptionsReturnsFlatMap(): void
    {
        // Act
        $descriptions = Scopes::getScopeDescriptions();

        // Assert — flat map, non-empty
        $this->assertIsArray($descriptions, 'getScopeDescriptions() must return an array');
        $this->assertNotEmpty($descriptions, 'getScopeDescriptions() must not be empty');

        // Each value must be a non-empty string
        foreach ($descriptions as $scope => $desc) {
            $this->assertIsString($scope,
                'Keys in getScopeDescriptions() must be strings');
            $this->assertIsString($desc,
                "Description for scope '$scope' must be a string");
            $this->assertNotEmpty($desc,
                "Description for scope '$scope' must not be empty");
        }
    }

    /**
     * getScopeDescriptions() must contain exactly the same scope identifiers
     * as getScopes() (counted across all categories) — no extra, no missing.
     *
     * Ensures getScopeDescriptions() is a consistent flattened view.
     */
    public function testGetScopeDescriptionsMatchesGetScopes(): void
    {
        // Arrange — collect all scope IDs from getScopes()
        $expectedIds = [];
        foreach (Scopes::getScopes() as $entries) {
            $expectedIds = array_merge($expectedIds, array_keys($entries));
        }
        sort($expectedIds);

        // Act
        $actualIds = array_keys(Scopes::getScopeDescriptions());
        sort($actualIds);

        // Assert — same set
        $this->assertSame($expectedIds, $actualIds,
            'getScopeDescriptions() keys must exactly match scope IDs from getScopes()');
    }

    // ── getDefaultScopes() ────────────────────────────────────────────────────

    /**
     * getDefaultScopes() must return only the scopes where is_default = true.
     *
     * Default scopes are implicitly granted to every OAuth client — returning
     * non-default scopes here would be a privilege-escalation bug.
     */
    public function testGetDefaultScopesReturnsOnlyDefaultOnes(): void
    {
        // Arrange — collect expected defaults from raw data
        $expected = [];
        foreach (Scopes::getScopes() as $entries) {
            foreach ($entries as $scopeId => $details) {
                if (!empty($details['is_default'])) {
                    $expected[] = $scopeId;
                }
            }
        }

        // Act
        $actual = Scopes::getDefaultScopes();

        // Assert — same set (order may differ)
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual,
            'getDefaultScopes() must return exactly the scopes with is_default = true');
    }

    /**
     * getDefaultScopes() must not contain 'system:admin' — that scope is
     * explicitly non-default and must never be auto-granted.
     */
    public function testGetDefaultScopesDoesNotIncludeSystemAdmin(): void
    {
        // Act
        $defaults = Scopes::getDefaultScopes();

        // Assert
        $this->assertNotContains('system:admin', $defaults,
            'system:admin must never be a default scope — it grants full admin access');
    }

    // ── addDefaultScopesToToken() ─────────────────────────────────────────────

    /**
     * addDefaultScopesToToken() must merge the token's existing scopes with
     * all default scopes, returning a unique space-delimited string.
     */
    public function testAddDefaultScopesToTokenMergesWithDefaults(): void
    {
        // Arrange — 'openid' is NOT a default scope; default scopes include profile, email, user
        $tokenScopeString = 'openid';

        // Act
        $result = Scopes::addDefaultScopesToToken($tokenScopeString);

        // Assert — result includes both the original scope and all defaults
        $resultScopes = explode(' ', $result);
        $this->assertContains('openid', $resultScopes,
            'addDefaultScopesToToken() must preserve the existing token scope');

        $defaults = Scopes::getDefaultScopes();
        foreach ($defaults as $default) {
            $this->assertContains($default, $resultScopes,
                "addDefaultScopesToToken() must include default scope '$default'");
        }
    }

    /**
     * addDefaultScopesToToken() must strip enclosing square brackets from the
     * input string before merging.
     *
     * Some legacy token scope strings are stored as "[profile email]".
     */
    public function testAddDefaultScopesToTokenStripsSquareBrackets(): void
    {
        // Arrange — bracket-wrapped scope string (legacy format)
        $tokenScopeString = '[openid offline_access]';

        // Act
        $result = Scopes::addDefaultScopesToToken($tokenScopeString);

        // Assert — no brackets remain in output
        $this->assertStringNotContainsString('[', $result,
            'addDefaultScopesToToken() must strip leading [ from the scope string');
        $this->assertStringNotContainsString(']', $result,
            'addDefaultScopesToToken() must strip trailing ] from the scope string');

        // The scopes that were inside the brackets must still be present
        $resultScopes = explode(' ', $result);
        $this->assertContains('openid', $resultScopes);
        $this->assertContains('offline_access', $resultScopes);
    }

    /**
     * addDefaultScopesToToken() must deduplicate scopes — if the token already
     * contains a default scope it must appear only once in the result.
     */
    public function testAddDefaultScopesToTokenDeduplicatesScopes(): void
    {
        // Arrange — 'profile' is a default scope; passing it explicitly
        $tokenScopeString = 'profile openid';

        // Act
        $result = Scopes::addDefaultScopesToToken($tokenScopeString);

        // Assert — 'profile' appears exactly once
        $resultScopes = explode(' ', $result);
        $this->assertSame(
            1,
            count(array_filter($resultScopes, fn($s) => $s === 'profile')),
            "addDefaultScopesToToken() must not duplicate 'profile' even when it is both in the token and in defaults"
        );
    }

    /**
     * addDefaultScopesToToken() must handle an empty string input by
     * returning just the default scopes.
     */
    public function testAddDefaultScopesToTokenHandlesEmptyString(): void
    {
        // Act
        $result = Scopes::addDefaultScopesToToken('');

        // Assert — result equals the default scopes
        $resultScopes = explode(' ', $result);
        sort($resultScopes);
        $defaults = Scopes::getDefaultScopes();
        sort($defaults);
        $this->assertSame($defaults, $resultScopes,
            'addDefaultScopesToToken() with empty input must return exactly the default scopes');
    }

    // ── hasInvalidScopes() ────────────────────────────────────────────────────

    /**
     * hasInvalidScopes() must return [false, []] when all requested scopes
     * are known valid scope identifiers.
     */
    public function testHasInvalidScopesReturnsFalseForValidScopes(): void
    {
        // Arrange — all valid scopes
        $scopeString = 'profile email openid';

        // Act
        [$hasInvalid, $invalid] = Scopes::hasInvalidScopes($scopeString);

        // Assert
        $this->assertFalse($hasInvalid,
            'hasInvalidScopes() must return false when all scopes are valid');
        $this->assertEmpty($invalid,
            'hasInvalidScopes() must return an empty invalid list when all scopes are valid');
    }

    /**
     * hasInvalidScopes() must return [true, ['unknown:scope']] when an
     * unrecognised scope identifier is in the string.
     *
     * This guards against typos and attempts to request non-existent privileges.
     */
    public function testHasInvalidScopesDetectsUnknownScopes(): void
    {
        // Arrange — mix of valid and unknown scopes
        $scopeString = 'profile unknown:scope totally:fake';

        // Act
        [$hasInvalid, $invalid] = Scopes::hasInvalidScopes($scopeString);

        // Assert
        $this->assertTrue($hasInvalid,
            'hasInvalidScopes() must return true when unknown scopes are present');
        $this->assertContains('unknown:scope', $invalid,
            "hasInvalidScopes() must list 'unknown:scope' in the invalid array");
        $this->assertContains('totally:fake', $invalid,
            "hasInvalidScopes() must list 'totally:fake' in the invalid array");
        $this->assertNotContains('profile', $invalid,
            "hasInvalidScopes() must NOT list valid scope 'profile' as invalid");
    }

    /**
     * hasInvalidScopes() must return [false, []] for an empty string without
     * throwing any errors.
     */
    public function testHasInvalidScopesHandlesEmptyString(): void
    {
        // Act
        [$hasInvalid, $invalid] = Scopes::hasInvalidScopes('');

        // Assert
        $this->assertFalse($hasInvalid,
            'hasInvalidScopes() must return false for an empty scope string');
        $this->assertEmpty($invalid,
            'hasInvalidScopes() must return an empty invalid list for an empty scope string');
    }

    // ── resolveInheritedScopes() ──────────────────────────────────────────────

    /**
     * resolveInheritedScopes() must expand system:admin to include all the
     * scopes it transitively inherits.
     *
     * system:admin inherits profile, email, phone, address, user, openid,
     * offline_access, system:audit_read, system:health,
     * system:notifications_read, system:notifications_write.
     * system:notifications_write in turn inherits system:notifications_read.
     */
    public function testResolveInheritedScopesExpandsSystemAdmin(): void
    {
        // Act
        $resolved = Scopes::resolveInheritedScopes(['system:admin']);

        // Assert — must include system:admin itself and its direct inherits
        $this->assertContains('system:admin', $resolved,
            'resolveInheritedScopes() must include the original scope');
        $this->assertContains('profile', $resolved,
            "resolveInheritedScopes() must include 'profile' inherited by system:admin");
        $this->assertContains('system:notifications_read', $resolved,
            "resolveInheritedScopes() must include 'system:notifications_read' transitively inherited via system:notifications_write");
    }

    /**
     * resolveInheritedScopes() must accept a space-delimited string in addition
     * to an array, as documented in the method signature.
     */
    public function testResolveInheritedScopesAcceptsString(): void
    {
        // Arrange — pass as string instead of array
        $resolved = Scopes::resolveInheritedScopes('profile email');

        // Assert — returns array with at least the two requested scopes
        $this->assertIsArray($resolved,
            'resolveInheritedScopes() must return an array regardless of input type');
        $this->assertContains('profile', $resolved);
        $this->assertContains('email', $resolved);
    }

    /**
     * resolveInheritedScopes() must return a sorted, deduplicated array even
     * when multiple input scopes share inherited scopes.
     *
     * system:notifications_write inherits system:notifications_read.
     * Requesting both must not produce duplicates.
     */
    public function testResolveInheritedScopesDeduplicatesResults(): void
    {
        // Arrange — both scopes share system:notifications_read
        $resolved = Scopes::resolveInheritedScopes(
            ['system:notifications_write', 'system:notifications_read']
        );

        // Assert — system:notifications_read appears exactly once
        $count = count(array_filter($resolved, fn($s) => $s === 'system:notifications_read'));
        $this->assertSame(1, $count,
            "resolveInheritedScopes() must deduplicate 'system:notifications_read'");
    }

    /**
     * resolveInheritedScopes() must return an empty array when given an empty
     * array input without throwing.
     */
    public function testResolveInheritedScopesHandlesEmptyInput(): void
    {
        // Act
        $resolved = Scopes::resolveInheritedScopes([]);

        // Assert
        $this->assertIsArray($resolved,
            'resolveInheritedScopes() must return an array for empty input');
        $this->assertEmpty($resolved,
            'resolveInheritedScopes() must return empty array for empty input');
    }

    /**
     * resolveInheritedScopes() must return an empty array when given a scope
     * identifier that does not exist in the registry.
     */
    public function testResolveInheritedScopesHandlesUnknownScope(): void
    {
        // Act — unknown scope should not throw, just be silently skipped
        $resolved = Scopes::resolveInheritedScopes(['nonexistent:scope']);

        // Assert — empty result, no exception
        $this->assertIsArray($resolved);
        $this->assertEmpty($resolved,
            'resolveInheritedScopes() must return empty array for unknown scopes');
    }

    /**
     * resolveInheritedScopes() must handle a non-array, non-string input
     * (e.g. null cast) gracefully and return an empty array without crashing.
     */
    public function testResolveInheritedScopesHandlesNonArrayNonStringInput(): void
    {
        // Act — passing a non-string, non-array value (triggers the !is_array guard)
        $resolved = Scopes::resolveInheritedScopes(42); // @phpstan-ignore-line

        // Assert
        $this->assertIsArray($resolved,
            'resolveInheritedScopes() must return an array for any input type');
        $this->assertEmpty($resolved,
            'resolveInheritedScopes() must return empty array for non-string, non-array input');
    }
}
