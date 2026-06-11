<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Make\NamespaceResolver;

#[CoversClass(NamespaceResolver::class)]
class NamespaceResolverTest extends TestCase
{
    // =========================================================================
    // getProperClassName()
    // =========================================================================

    public function testGetProperClassNameSingularFromPlural(): void
    {
        $result = NamespaceResolver::getProperClassName('users', true);
        $this->assertSame('User', $result);
    }

    public function testGetProperClassNameSingularFromSingular(): void
    {
        $result = NamespaceResolver::getProperClassName('user', true);
        $this->assertSame('User', $result);
    }

    public function testGetProperClassNamePluralFromSingular(): void
    {
        $result = NamespaceResolver::getProperClassName('user', false);
        $this->assertStringEndsWith('s', strtolower($result));
    }

    public function testGetProperClassNamePluralFromPlural(): void
    {
        $result = NamespaceResolver::getProperClassName('users', false);
        $this->assertSame('Users', $result);
    }

    public function testGetProperClassNameCapitalizesFirstLetter(): void
    {
        $result = NamespaceResolver::getProperClassName('order', true);
        $this->assertSame('Order', $result);
    }

    // =========================================================================
    // getModelTableName()
    // =========================================================================

    public function testGetModelTableNameFromSingular(): void
    {
        $result = NamespaceResolver::getModelTableName('User');
        $this->assertStringStartsWith('#PREFIX#', $result);
        $this->assertStringContainsString('user', strtolower($result));
    }

    public function testGetModelTableNameFromPlural(): void
    {
        $result = NamespaceResolver::getModelTableName('users');
        $this->assertStringStartsWith('#PREFIX#', $result);
        $this->assertStringContainsString('users', $result);
    }

    public function testGetModelTableNameAlwaysLowercase(): void
    {
        $result = NamespaceResolver::getModelTableName('Order');
        $without = str_replace('#PREFIX#', '', $result);
        $this->assertSame(strtolower($without), $without);
    }

    // =========================================================================
    // resolveBaseNamespace()
    // =========================================================================

    public function testResolveBaseNamespaceNoAppName(): void
    {
        $result = NamespaceResolver::resolveBaseNamespace(['namespace' => 'MyApp'], '');
        $this->assertSame('MyApp', $result);
    }

    public function testResolveBaseNamespaceWithAppName(): void
    {
        $result = NamespaceResolver::resolveBaseNamespace(['namespace' => 'MyApp'], 'Blog');
        $this->assertSame('MyApp\\Blog', $result);
    }

    public function testResolveBaseNamespaceDefaultsToApp(): void
    {
        $result = NamespaceResolver::resolveBaseNamespace([], '');
        $this->assertSame('App', $result);
    }

    public function testResolveBaseNamespaceDefaultWithAppName(): void
    {
        $result = NamespaceResolver::resolveBaseNamespace([], 'MyApp');
        $this->assertSame('App\\MyApp', $result);
    }

    // =========================================================================
    // resolveBasePath()
    // =========================================================================

    public function testResolveBasePathNoAppName(): void
    {
        $result = NamespaceResolver::resolveBasePath('/var/www/html', 'INCLUDES', '');
        $this->assertStringContainsString('/var/www/html', $result);
        $this->assertStringContainsString('INCLUDES', $result);
        $this->assertStringNotContainsString('///', $result);
    }

    public function testResolveBasePathWithAppName(): void
    {
        $result = NamespaceResolver::resolveBasePath('/var/www/html', 'INCLUDES', 'Blog');
        $this->assertStringEndsWith('Blog', $result);
    }

    public function testResolveBasePathDoesNotDoubleSlash(): void
    {
        $result = NamespaceResolver::resolveBasePath('/root', 'inc', 'App');
        $this->assertStringNotContainsString('//', $result);
    }
}
