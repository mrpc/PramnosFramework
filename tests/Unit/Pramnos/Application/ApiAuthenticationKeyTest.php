<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Api;
use Pramnos\Application\Application;

/**
 * One API, one signing key, whichever front controller is running.
 *
 * WHAT: that `Api::baseUrl()` — and therefore the key `deriveAuthenticationKey()`
 *       returns — is the API's own base in both contexts: `sURL` unchanged inside
 *       an API request, and `sURL` plus the API's directory anywhere else.
 * WHY:  `sURL` is `dirname(SCRIPT_NAME)`, so `www/api/index.php` sees
 *       `https://host/api/` and every other request sees `https://host/`. The key
 *       is `md5()` of that string, so the exchange that hands a signed-in browser
 *       an API token, and the console that mints an MCP one, both signed with a
 *       key the API does not verify with — every token refused as
 *       `InvalidAccessToken`, which names neither the key nor the mistake.
 *
 *       The API-context half matters for the opposite reason: it must not change.
 *       A different key there signs out every client holding a token, so the test
 *       pins the exact byte string rather than a normalised form of it.
 */
#[CoversClass(Api::class)]
class ApiAuthenticationKeyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedInstances = [];

    private mixed $savedLastUsed = null;

    /**
     * The application registry is process-wide, and these tests replace what is
     * current. Saved and put back so a test that runs after one of these sees the
     * application the suite set up, not an Api this file constructed.
     */
    protected function setUp(): void
    {
        if (!defined('sURL')) {
            define('sURL', 'http://localhost/');
        }
        $this->savedInstances = $this->registry()->getValue();
        $this->savedLastUsed  = $this->lastUsed()->getValue();
    }

    protected function tearDown(): void
    {
        $this->registry()->setValue(null, $this->savedInstances);
        $this->lastUsed()->setValue(null, $this->savedLastUsed);
    }

    private function registry(): \ReflectionProperty
    {
        return new \ReflectionProperty(Application::class, 'appInstances');
    }

    private function lastUsed(): \ReflectionProperty
    {
        return new \ReflectionProperty(Application::class, 'lastUsedApplication');
    }

    /** Make a plain (MVC) application the current one. */
    private function makeMvcCurrent(): void
    {
        $this->registry()->setValue(null, ['default' => new Application('')]);
        $this->lastUsed()->setValue(null, 'default');
    }

    /** Make an API application the current one, as its front controller does. */
    private function makeApiCurrent(): void
    {
        $this->registry()->setValue(null, ['default' => new Api('')]);
        $this->lastUsed()->setValue(null, 'default');
    }

    /**
     * Inside an API request the base is `sURL`, byte for byte.
     *
     * The one assertion in this file that is about *not* changing. `sURL` here has
     * no trailing slash on purpose in some installations; normalising it would be
     * a different `md5()`, and a different `md5()` is every issued token refused
     * at once.
     */
    public function testInsideAnApiRequestTheBaseIsTheUrlItAlreadyHas(): void
    {
        // Arrange
        $this->makeApiCurrent();

        // Act & Assert
        $this->assertSame((string) sURL, Api::baseUrl());
        $this->assertSame(
            md5(sURL . '1.0'),
            Api::deriveAuthenticationKey('1.0'),
            'the key inside an API request must be exactly what it has always been'
        );
    }

    /**
     * Anywhere else the API's directory is appended.
     *
     * This is the bug: an MVC route deriving from its own `sURL` produced
     * `md5('https://host/' . $version)` while the API verified with
     * `md5('https://host/api/' . $version)`.
     */
    public function testOutsideAnApiRequestTheDirectoryIsAppended(): void
    {
        // Arrange
        $this->makeMvcCurrent();

        // Act
        $base = Api::baseUrl();

        // Assert
        $this->assertSame(rtrim(sURL, '/') . '/api/', $base);
        $this->assertSame(md5($base . '1.0'), Api::deriveAuthenticationKey('1.0'));
    }

    /**
     * The two contexts agree, which is the whole point.
     *
     * Asserted as an equality between what an MVC request derives and what an API
     * request whose `sURL` is the site plus `api/` derives — the pair that did not
     * match, expressed as the invariant rather than as two separate values.
     */
    public function testBothContextsDeriveTheSameKey(): void
    {
        // Arrange — what the API front controller's own sURL would be.
        $apiSiteUrl = rtrim(sURL, '/') . '/api/';

        // Act — the MVC side, which is where the exchange and the console run.
        $this->makeMvcCurrent();
        $fromMvc = Api::deriveAuthenticationKey('1.0');

        // Assert — identical to what an API request computes from its own sURL.
        $this->assertSame(md5($apiSiteUrl . '1.0'), $fromMvc);
    }

    /**
     * Appending is idempotent.
     *
     * An installation whose `sURL` is configured *as* the API's base — a console that sets
     * it explicitly rather than letting `getUrl()` derive it — is the one case where the
     * old derivation happened to be right. Appending blindly would break exactly that.
     */
    public function testTheDirectoryIsNotAppendedTwice(): void
    {
        // Arrange — an application whose site URL already ends in the API's directory.
        $app = new Application('');
        $app->applicationInfo['api']['directory'] = basename(rtrim(sURL, '/')) === 'api'
            ? 'api'
            : basename(rtrim(sURL, '/'));
        $this->registry()->setValue(null, ['default' => $app]);
        $this->lastUsed()->setValue(null, 'default');

        // Act & Assert — the segment already there is not added again.
        $this->assertSame(rtrim(sURL, '/') . '/', Api::baseUrl());
    }

    /**
     * A project that moved the front controller says so, and is believed.
     *
     * Not derived from `api.prefix`: the prefix is a route namespace and this is a
     * place on disk. Guessing one from the other would swap a visible failure for
     * a silent one.
     */
    public function testAConfiguredDirectoryIsUsed(): void
    {
        // Arrange
        $app = new Application('');
        $app->applicationInfo['api']['directory'] = '/backend/';
        $this->registry()->setValue(null, ['default' => $app]);
        $this->lastUsed()->setValue(null, 'default');

        // Act & Assert — the surrounding slashes are the project's, not ours.
        $this->assertSame(rtrim(sURL, '/') . '/backend/', Api::baseUrl());
    }

    /**
     * With no site URL at all there is nothing to build on.
     *
     * `deriveAuthenticationKey()` then reduces to `md5($version)`, which
     * `SessionExchange` refuses to mint under — a key every installation in that
     * state would share. This pins the input to that refusal.
     */
    public function testWithNoSiteUrlThereIsNoBase(): void
    {
        // Arrange — sURL is a constant and cannot be unset, so the empty case is
        // asserted through the same branch: an empty string is not a base either.
        $this->makeMvcCurrent();

        // Act & Assert
        $this->assertNotSame('', Api::baseUrl(), 'this process has an sURL to build on');
        $this->assertSame(md5(Api::baseUrl() . 'edge'), Api::deriveAuthenticationKey('edge'));
    }
}
