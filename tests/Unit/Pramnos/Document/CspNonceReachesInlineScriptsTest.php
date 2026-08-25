<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Document\DocumentTypes\Html;

/**
 * Every inline script the framework emits carries the request's CSP nonce — and there
 * is always a nonce to carry.
 *
 * Two findings from consuming applications meet here.
 *
 * A filing said the `no-js` flip script — the two lines that swap `class="no-js"` for
 * `js` on `<html>` — was missing the nonce, and that under a nonce policy it would be
 * blocked, leaving every page permanently in its no-JavaScript styling. **The script
 * does get a nonce**: `Html::render()` post-processes the finished document and injects
 * one into every inline `<script>`, that one included. The first test here is that
 * assertion, because the claim was plausible enough to be filed and the answer was not
 * written down anywhere.
 *
 * The symptom described, though, is real — a different application lost exactly that
 * way. Its `exec()` override did not call the parent, so `$cspNonce` stayed `''`, and
 * with no nonce there is nothing to inject and nothing for the policy to allow: every
 * inline script on every server-rendered page was refused. It was reported as *"the
 * night-mode button does not work"*, twice, because a blocked inline script is present
 * and correct in the response and no test can see the browser decline to run it. The
 * remaining tests cover the guarantee that closes it.
 */
#[CoversClass(Html::class)]
#[CoversClass(Application::class)]
class CspNonceReachesInlineScriptsTest extends TestCase
{
    protected function tearDown(): void
    {
        $rc = new \ReflectionClass(Application::class);
        $rc->getProperty('appInstances')->setValue(null, []);
        $rc->getProperty('lastUsedApplication')->setValue(null, null);
    }

    /**
     * A live application with the given nonce and nothing else booted.
     */
    private function application(string $nonce): Application
    {
        $rc  = new \ReflectionClass(Application::class);
        $app = $rc->newInstanceWithoutConstructor();
        $app->applicationInfo = [];
        $app->cspNonce = $nonce;

        $rc->getProperty('appInstances')->setValue(null, ['default' => $app]);
        $rc->getProperty('lastUsedApplication')->setValue(null, 'default');

        return $app;
    }

    /** @return list<string> Every `<script …>` opening tag in the rendered document */
    private function scriptTags(): array
    {
        preg_match_all('/<script[^>]*>/i', (new Html())->render(), $matches);

        return $matches[0];
    }

    /**
     * The `no-js` flip script carries the nonce.
     *
     * The answer to the filing. `Html::render()` runs a post-process over the whole
     * finished string, so it does not matter that this script is emitted inline in the
     * `<head>` markup rather than through `addScript()` — the injection is by tag, not
     * by registration.
     */
    public function testTheNoJsFlipScriptCarriesTheNonce(): void
    {
        // Arrange
        $this->application('NoJsCanary123');

        // Act
        $tags = $this->scriptTags();

        // Assert — there is such a script, and it is nonced.
        $this->assertNotEmpty($tags, 'the no-js flip script must be in the document');
        foreach ($tags as $tag) {
            $this->assertStringContainsString('nonce="NoJsCanary123"', $tag,
                'every inline script must carry the nonce, including the no-js flip');
        }
    }

    /**
     * With no nonce, no `nonce=` attribute is invented.
     *
     * Establishes the precondition for the test below, and pins the honest behaviour:
     * a nonce-less document must not carry `nonce=""`, which browsers ignore and which
     * would make the failure look like a policy problem instead of a missing nonce.
     */
    public function testWithNoNonceNoAttributeIsAdded(): void
    {
        // Arrange
        $this->application('');

        // Act
        $tags = $this->scriptTags();

        // Assert
        foreach ($tags as $tag) {
            $this->assertStringNotContainsString('nonce=', $tag);
        }
    }

    /**
     * `Application::render()` generates a nonce when `exec()` did not.
     *
     * The guarantee that closes the reported symptom. An application overriding
     * `exec()` without calling the parent is the ordinary route to a nonce-less
     * request, and until now the only route to a nonce *was* `exec()`.
     *
     * Asserted through `ensureCspNonce()` rather than through `render()` itself,
     * because `render()` also redirects, sends a header and builds a document — none of
     * which this is about, and all of which need a booted application.
     */
    public function testRenderGeneratesANonceWhenExecDidNot(): void
    {
        // Arrange — an application that never reached exec().
        $app = $this->application('');
        $this->assertSame('', $app->cspNonce);

        // Act
        (new \ReflectionMethod(Application::class, 'ensureCspNonce'))->invoke($app);

        // Assert — a real nonce, of the same shape exec() produces.
        $this->assertNotSame('', $app->cspNonce);
        $this->assertSame(24, strlen($app->cspNonce),
            'base64 of 16 random bytes, as exec() has always generated');
    }

    /**
     * An existing nonce is left alone.
     *
     * `render()` runs after `exec()` on the normal path, and the document is stamped
     * with whatever the policy named. Replacing it here would send a policy naming one
     * nonce and a body carrying another — every inline script blocked, which is the
     * exact failure this method exists to prevent.
     */
    public function testAnExistingNonceIsNotReplaced(): void
    {
        // Arrange
        $app = $this->application('AlreadyGenerated456');

        // Act
        (new \ReflectionMethod(Application::class, 'ensureCspNonce'))->invoke($app);

        // Assert
        $this->assertSame('AlreadyGenerated456', $app->cspNonce);
    }

    /**
     * The policy and the body agree on the nonce.
     *
     * The two halves are produced by different code at different times — the header by
     * `cspPolicy()` before the document renders, the attributes by a post-process
     * afterwards — so "they use the same nonce" is an invariant worth asserting rather
     * than assuming. A mismatch blocks every inline script and looks like nothing.
     */
    public function testThePolicyAndTheDocumentAgree(): void
    {
        // Arrange
        $app = $this->application('');
        (new \ReflectionMethod(Application::class, 'ensureCspNonce'))->invoke($app);

        // Act
        $policy = $app->cspPolicy();
        $tags   = $this->scriptTags();

        // Assert
        $this->assertStringContainsString("'nonce-" . $app->cspNonce . "'", $policy);
        foreach ($tags as $tag) {
            $this->assertStringContainsString('nonce="' . $app->cspNonce . '"', $tag);
        }
    }
}
