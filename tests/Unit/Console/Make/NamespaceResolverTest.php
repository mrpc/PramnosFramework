<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Make\NamespaceResolver;

/**
 * Unit tests for NamespaceResolver.
 *
 * NamespaceResolver is a collection of static, pure string-transformation
 * methods — no database, filesystem, or application context is needed.
 *
 * WHY these tests matter:
 * - getProperClassName() is the single source of truth for PascalCase class
 *   name derivation. Wrong output here silently generates files whose class
 *   names don't match their declared autoload path.
 * - getModelTableName() drives #PREFIX#table resolution in model scaffolding.
 *   An off-by-one (singular vs plural) would generate a model pointing at the
 *   wrong table.
 * - resolveBaseNamespace() / resolveBasePath() compose file paths for all
 *   make:* commands — errors here cause generated files to land in wrong
 *   directories, breaking autoloading.
 */
#[CoversClass(NamespaceResolver::class)]
class NamespaceResolverTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // getProperClassName()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A plural name with $forceSingular=true (model mode) must be singularized
     * and PascalCased — 'users' → 'User', not 'Users'.
     */
    public function testGetProperClassNameSingularizesPlurals(): void
    {
        // Act
        $result = NamespaceResolver::getProperClassName('users', true);

        // Assert
        $this->assertSame('User', $result);
    }

    /**
     * An already-singular name with $forceSingular=true must be returned as-is
     * (PascalCased) — 'user' → 'User', not 'Users'.
     */
    public function testGetProperClassNameSingularLeavedAlone(): void
    {
        // Act
        $result = NamespaceResolver::getProperClassName('user', true);

        // Assert — no change except PascalCase
        $this->assertSame('User', $result);
    }

    /**
     * A singular name with $forceSingular=false (view/controller mode) must be
     * pluralized — 'user' → 'Users'.
     */
    public function testGetProperClassNamePluralizesForViews(): void
    {
        // Act
        $result = NamespaceResolver::getProperClassName('user', false);

        // Assert
        $this->assertSame('Users', $result);
    }

    /**
     * An already-plural name with $forceSingular=false must be returned as-is
     * (PascalCased) — 'users' → 'Users', not 'Userss'.
     */
    public function testGetProperClassNamePluralLeavedAlone(): void
    {
        // Act
        $result = NamespaceResolver::getProperClassName('users', false);

        // Assert — no double-pluralization
        $this->assertSame('Users', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getModelTableName()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A singular name must be pluralized and prefixed with #PREFIX# — 'user'
     * → '#PREFIX#users'. This is the standard framework table convention.
     */
    public function testGetModelTableNameSingularInput(): void
    {
        // Act
        $result = NamespaceResolver::getModelTableName('user');

        // Assert
        $this->assertSame('#PREFIX#users', $result);
    }

    /**
     * An already-plural name must not be double-pluralized — 'users'
     * → '#PREFIX#users', not '#PREFIX#userss'.
     */
    public function testGetModelTableNamePluralInput(): void
    {
        // Act
        $result = NamespaceResolver::getModelTableName('users');

        // Assert — no double-pluralization
        $this->assertSame('#PREFIX#users', $result);
    }

    /**
     * The result must always be lowercase — a mixed-case input must be
     * normalized so it matches the DB table name exactly.
     */
    public function testGetModelTableNameLowercases(): void
    {
        // Act
        $result = NamespaceResolver::getModelTableName('Product');

        // Assert — lowercase table name
        $this->assertSame('#PREFIX#products', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // resolveBaseNamespace()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When $appName is non-empty the namespace must include it as a
     * sub-segment — 'App' + 'MyApp' → 'App\MyApp'.
     */
    public function testResolveBaseNamespaceWithAppName(): void
    {
        // Act
        $result = NamespaceResolver::resolveBaseNamespace(
            ['namespace' => 'App'],
            'MyApp'
        );

        // Assert
        $this->assertSame('App\\MyApp', $result);
    }

    /**
     * When $appName is empty the namespace must not include a trailing
     * backslash or extra segment — 'App' remains 'App'.
     */
    public function testResolveBaseNamespaceWithoutAppName(): void
    {
        // Act
        $result = NamespaceResolver::resolveBaseNamespace(
            ['namespace' => 'App'],
            ''
        );

        // Assert — no trailing backslash
        $this->assertSame('App', $result);
        $this->assertStringNotContainsString('\\', $result);
    }

    /**
     * When the applicationInfo array has no 'namespace' key the method must
     * fall back to 'App' — prevents undefined-index warnings in new projects.
     */
    public function testResolveBaseNamespaceFallsBackToApp(): void
    {
        // Act — empty applicationInfo array
        $result = NamespaceResolver::resolveBaseNamespace([], '');

        // Assert
        $this->assertSame('App', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // resolveBasePath()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * With a non-empty $appName the path must include the app name as a
     * sub-directory — /var/www/html + INCLUDES + MyApp = /var/www/html/src/MyApp.
     */
    public function testResolveBasePathWithAppName(): void
    {
        // Arrange
        $sep = DIRECTORY_SEPARATOR;

        // Act
        $result = NamespaceResolver::resolveBasePath('/var/www/html', 'src', 'MyApp');

        // Assert — root + sep + includes + sep + appName
        $this->assertSame('/var/www/html' . $sep . 'src' . $sep . 'MyApp', $result);
    }

    /**
     * With an empty $appName the path must stop at the includes directory —
     * no trailing separator or empty segment appended.
     */
    public function testResolveBasePathWithoutAppName(): void
    {
        // Arrange
        $sep = DIRECTORY_SEPARATOR;

        // Act
        $result = NamespaceResolver::resolveBasePath('/var/www/html', 'src', '');

        // Assert — no extra segment
        $this->assertSame('/var/www/html' . $sep . 'src', $result);
        // No trailing separator
        $this->assertStringNotContainsString($sep . $sep, $result);
    }
}
