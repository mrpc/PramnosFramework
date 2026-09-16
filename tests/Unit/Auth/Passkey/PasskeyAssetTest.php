<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Passkey;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Passkey\PasskeyAsset;

/**
 * The single WebAuthn source, and the two shapes it is delivered in.
 *
 * A passkey ceremony is exact — base64url in both directions, the precise field
 * names webauthn-lib deserialises — and its failure mode is a flat
 * `authentication_failed` that names nothing. That is the worst possible subject
 * for a second copy, so there is one source; these tests pin that neither
 * delivery mangles it and that the SPA shape really does forward to the one
 * implementation rather than carry its own.
 */
#[CoversClass(PasskeyAsset::class)]
class PasskeyAssetTest extends TestCase
{
    /**
     * The classic-script shape is what a `<script src>` tag can accept.
     *
     * An ESM `export` here is a syntax error that takes the whole file with it —
     * so the login page would have no ceremony at all, not a broken one.
     */
    public function testSourceIsAClassicScript(): void
    {
        // Act
        $source = PasskeyAsset::source();

        // Assert
        $this->assertStringContainsString('window.PramnosWebAuthn', $source);
        $this->assertStringNotContainsString("\nexport ", $source);
    }

    /**
     * The module is the source plus exports, not a reimplementation.
     *
     * This is the whole point of the class. The assertion is deliberately on a
     * detail of the ceremony (the base64url padding fix-up), because that is the
     * kind of line a second copy gets subtly wrong.
     */
    public function testTheModuleCarriesTheRealCeremony(): void
    {
        // Act
        $module = PasskeyAsset::spaModule('Demo');

        // Assert
        $this->assertStringContainsString(PasskeyAsset::source(), $module);
        $this->assertStringContainsString("s += '===='.slice(pad);", $module);
    }

    /**
     * Every function the generated client imports is exported.
     *
     * `lib/api.js` imports these by name. A missing one is not a broken passkey
     * button: an ES module with an unresolved named import fails to load, which
     * takes the API client — and therefore every screen — with it.
     */
    public function testItExportsWhatTheApiClientImports(): void
    {
        // Act
        $module = PasskeyAsset::spaModule('Demo');

        // Assert
        foreach ([
            'setTransport',
            'supported',
            'conditionalSupported',
            'authenticate',
            'register',
            'conditional',
            'cancelConditional',
        ] as $name) {
            $this->assertStringContainsString(
                'export function ' . $name . '(',
                $module,
                $name . '() is imported by the generated lib/api.js'
            );
        }
    }

    /**
     * The exports forward to the object the IIFE attached.
     *
     * Forwarding is what makes this one implementation. An export that did the
     * work itself would be the second copy this class exists to prevent, and
     * nothing about the file would look different.
     */
    public function testTheExportsForwardRatherThanReimplement(): void
    {
        // Act
        $module = PasskeyAsset::spaModule('Demo');

        // Assert
        $this->assertStringContainsString('root.PramnosWebAuthn || null', $module);
        $this->assertStringContainsString('return webauthn.authenticate(optionsUrl, verifyUrl, extra);', $module);
    }

    /**
     * Outside a browser the module still imports; it just cannot do anything.
     *
     * A SPA's `node --test` suite imports `lib/api.js`, which imports this. A
     * bare `window` reference would be a ReferenceError at import time — every
     * test in the project failing, for a feature none of them use.
     */
    public function testItIsImportableWithoutABrowser(): void
    {
        // Act
        $module = PasskeyAsset::spaModule('Demo');

        // Assert — the guard is present in both the module and the source it wraps.
        $this->assertStringContainsString(
            "typeof window !== 'undefined' ? window : globalThis",
            $module
        );
        $this->assertStringNotContainsString('    window.PramnosWebAuthn =', $module);
    }

    /**
     * The header names the application and says the file is not to be edited.
     *
     * It is the only warning anybody gets: the file sits in the project's own
     * source tree, and an edit made here is lost at the next resync.
     */
    public function testTheHeaderNamesTheApplicationAndWarnsAgainstEditing(): void
    {
        // Act
        $module = PasskeyAsset::spaModule('Glide');

        // Assert
        $this->assertStringContainsString('Passkey (WebAuthn) ceremony for Glide', $module);
        $this->assertStringContainsString('FRAMEWORK-OWNED, do not edit', $module);
    }

    /** With no application name the framework's own is used. */
    public function testItFallsBackToTheFrameworkName(): void
    {
        // Act
        $module = PasskeyAsset::spaModule();

        // Assert
        $this->assertStringContainsString('ceremony for Pramnos', $module);
    }
}
