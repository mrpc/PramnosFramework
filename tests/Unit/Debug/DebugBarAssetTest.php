<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Debug\DebugBarAsset;

/**
 * The single toolbar source, and the two shapes it is delivered in.
 *
 * The toolbar used to be drawn twice — PHP building HTML for server-rendered
 * pages, a separate scaffolded module for SPA projects — from the same collector
 * data. They drifted, and the `✕` that hid nothing then had to be fixed in both.
 * What this class guarantees is that there is one source; what these tests pin is
 * that neither delivery mangles it.
 */
#[CoversClass(DebugBarAsset::class)]
class DebugBarAssetTest extends TestCase
{
    /**
     * The classic-script shape is what an inline `<script>` can accept.
     *
     * An ESM `export` in an inline script is a syntax error that takes the whole
     * script with it — every handler in the toolbar, not just one.
     */
    public function testSourceIsAClassicScript(): void
    {
        // Act
        $source = DebugBarAsset::source();

        // Assert
        $this->assertStringContainsString('window.__pramnosDebugBar', $source,
            'the IIFE publishes its entry points on window');
        $this->assertStringNotContainsString("\nexport ", $source,
            'no ESM syntax: this shape is inlined into a page');
        // The guard that keeps production silent.
        $this->assertStringContainsString('if (!payload && entries.length === 0)', $source);
    }

    /**
     * The SPA shape is a module whose `record` forwards to the same instance.
     *
     * A second implementation in the module would be the duplication this class
     * exists to remove — so the export must delegate, not re-implement.
     */
    public function testSpaModuleExportsRecordAndDelegatesToTheOneInstance(): void
    {
        // Act
        $module = DebugBarAsset::spaModule('Acme');

        // Assert
        $this->assertStringContainsString('export function record(', $module);
        $this->assertStringContainsString('window.__pramnosDebugBar', $module);
        $this->assertStringContainsString('bar.record(method, path, status, debug, extra)', $module,
            'the export forwards rather than duplicating');
        // The whole source is present, not a subset of it.
        $this->assertStringContainsString(DebugBarAsset::source(), $module);
    }

    /**
     * The module also exports `reportError`, and it delegates the same way.
     *
     * This is the half of the Errors tab that application code has to reach: the
     * global handlers catch what nobody caught, while an `ApiError` a screen
     * handled and a `<svelte:boundary>` failure are handed over explicitly. A
     * missing export means the generated API client fails to import — at build
     * time, which is at least loud, but it means no project gets those rows.
     */
    public function testSpaModuleExportsReportErrorAndDelegates(): void
    {
        // Act
        $module = DebugBarAsset::spaModule('Acme');

        // Assert
        $this->assertStringContainsString('export function reportError(', $module);
        $this->assertStringContainsString('bar.reportError(error, context)', $module,
            'the export forwards rather than duplicating');
    }

    /**
     * The generated file tells whoever opens it not to edit it.
     *
     * It is framework code that happens to live in the project: an edit here is
     * lost on the next `project:resync --debug-panel`, and the same panel is what
     * every other project uses.
     */
    public function testSpaModuleHeaderNamesTheApplicationAndWarnsAgainstEditing(): void
    {
        // Act
        $module = DebugBarAsset::spaModule('Acme');

        // Assert
        $this->assertStringContainsString('Debug panel for Acme', $module);
        $this->assertStringContainsString('FRAMEWORK-OWNED, do not edit', $module);
        $this->assertStringContainsString('project:resync --debug-panel', $module,
            'the header gives the way to refresh it');
    }

    /** With no application name, the framework's own is used rather than a blank. */
    public function testSpaModuleFallsBackToTheFrameworkName(): void
    {
        // Act
        $module = DebugBarAsset::spaModule('');

        // Assert
        $this->assertStringContainsString('Debug panel for Pramnos', $module);
    }

    /**
     * The bar can carry the application's name instead of the framework's.
     *
     * On a server-rendered page the brand is the only thing saying which
     * application the toolbar belongs to, which matters when two are open.
     */
    public function testWithAppNameSubstitutesTheBrand(): void
    {
        // Arrange
        $source = DebugBarAsset::source();

        // Act
        $branded = DebugBarAsset::withAppName($source, 'Acme');

        // Assert
        $this->assertStringContainsString('&#9881; Acme', $branded);
        $this->assertStringNotContainsString('&#9881; Pramnos', $branded);
        // An empty name leaves the source untouched rather than blanking the brand.
        $this->assertSame($source, DebugBarAsset::withAppName($source, ''));
    }

    /**
     * A name with markup in it cannot break out of the bar.
     *
     * The application name comes from `app/app.php`, so it is not attacker input —
     * but it is substituted into HTML, and an unescaped `<` there would corrupt
     * the bar rather than show a title.
     */
    public function testAnAppNameWithMarkupIsEscaped(): void
    {
        // Act
        $branded = DebugBarAsset::withAppName(DebugBarAsset::source(), '<b>x</b>');

        // Assert
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $branded);
        $this->assertStringNotContainsString('<b>x</b>', $branded);
    }
}
