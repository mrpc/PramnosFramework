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
 * A filing (FW-016) said the framework's own `<head>` script was missing its nonce
 * because it is written into the markup rather than registered through `addScript()`,
 * and that under a nonce policy every page would therefore be stuck in its
 * no-JavaScript styling. The mechanism was misread: `Html::render()` post-processes the
 * whole finished document, so the injection is by tag and registration is irrelevant.
 *
 * A later filing then asked for the opposite, for a different and good reason. Since
 * `PageCache::store()` began refusing any body carrying the request's nonce, a nonce is
 * what makes a page uncacheable — so the two things that cannot use one should not have
 * one. A `<script>` whose type is not executable is a data block the browser never runs,
 * and the `no-js` flip is a fixed string whose **hash** can be in the policy instead.
 * Both now go out without a nonce, and the tests below cover which scripts still need
 * one — including the two the filing wrongly listed as data.
 *
 * The symptom FW-016 described is real all the same — a different application lost
 * exactly that way. Its `exec()` override did not call the parent, so `$cspNonce` stayed `''`, and
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
    private function application(string $nonce = '', array $info = []): Application
    {
        $rc  = new \ReflectionClass(Application::class);
        $app = $rc->newInstanceWithoutConstructor();
        $app->applicationInfo = $info;
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

    // ── Which scripts need a nonce, and which cannot use one ────────────────

    /**
     * A data block gets no nonce, because `script-src` cannot gate it.
     *
     * `script-src` gates script **execution**. A `<script>` whose declared type is not a
     * JavaScript MIME type is a data block: the browser never runs it, so there is
     * nothing for the policy to allow and the nonce is inert. Embedding data in
     * `application/json` is a well-known way to sidestep CSP precisely because of this.
     *
     * Harmless until {@see \Pramnos\Cache\Page\PageCache::store()} began refusing any
     * body carrying the request's nonce — a nonce reused across visitors is not a nonce.
     * From then on an inert nonce was the difference between a page that could be cached
     * and one that could not, measured that way in a consuming application: after moving
     * its own inline script into a file, what was left on its catalogue pages was 248
     * bytes of JSON-LD and 96 bytes of framework `<head>` script.
     */
    public function testADataBlockGetsNoNonce(): void
    {
        // Arrange
        $this->application('DataBlock123');

        // Act
        $out = $this->renderWith('<script type="application/ld+json">{"a":1}</script>');

        // Assert
        $this->assertStringContainsString('<script type="application/ld+json">', $out);
        $this->assertStringNotContainsString('nonce="DataBlock123" type="application/ld+json"', $out);
    }

    /**
     * `importmap` and `speculationrules` keep their nonce.
     *
     * **The filing that prompted this listed both as non-executable alongside
     * `application/ld+json`. They are not.** An import map needs an inline allowance like
     * any other script, and speculation rules are gated by `script-src` so specifically
     * that CSP has a dedicated `'inline-speculation-rules'` keyword for them; there are
     * open issues in other frameworks about exactly this.
     *
     * Following the filing literally would have broken both under a nonce policy —
     * silently, because nothing reports it until somebody first tries an import map.
     * Which is why the decision is an allow-list of executable types rather than a
     * deny-list of data ones: wrong in the allow-list direction costs an unnecessary
     * nonce, wrong the other way costs a working page.
     */
    public function testImportmapAndSpeculationRulesKeepTheirNonce(): void
    {
        // Arrange
        $this->application('Executable123');

        // Act
        $out = $this->renderWith(
            '<script type="importmap">{}</script>'
            . '<script type="speculationrules">{}</script>'
        );

        // Assert
        $this->assertStringContainsString('nonce="Executable123" type="importmap"', $out);
        $this->assertStringContainsString('nonce="Executable123" type="speculationrules"', $out);
    }

    /**
     * A MIME type with parameters is still recognised as executable.
     *
     * `text/javascript; charset=utf-8` is a JavaScript MIME type, and comparing the whole
     * attribute value against a list would have failed it — costing the script its nonce,
     * which is a blocked script rather than a missed optimisation.
     */
    public function testAMimeTypeWithParametersIsStillExecutable(): void
    {
        // Arrange
        $this->application('Params123');

        // Act
        $out = $this->renderWith('<script type="text/javascript; charset=utf-8">x()</script>');

        // Assert
        $this->assertStringContainsString('nonce="Params123"', $out);
    }

    /**
     * Anything naming javascript keeps its nonce, listed or not.
     *
     * Belt-and-braces, and the asymmetry is the reason: an unnecessary nonce costs
     * cacheability, a missing one costs a page. A spelling the list happens not to carry
     * must fail towards keeping the nonce.
     */
    public function testAnUnlistedJavascriptSpellingKeepsItsNonce(): void
    {
        // Arrange
        $this->application('Fallback123');

        // Act
        $out = $this->renderWith('<script type="application/vnd.example+javascript">x()</script>');

        // Assert
        $this->assertStringContainsString('nonce="Fallback123"', $out);
    }

    /**
     * An inline `<style>` keeps its nonce, because `style-src` does gate it.
     *
     * The half of this that must not change. `style-src` genuinely governs inline styles,
     * so those nonces are doing work — and a page with an inline `<style>` is
     * legitimately uncacheable for exactly the same reason as one with inline script.
     */
    public function testAnInlineStyleKeepsItsNonce(): void
    {
        // Arrange
        $this->application('Styled123');

        // Act
        $out = $this->renderWith('<style>.a{color:red}</style>');

        // Assert
        $this->assertStringContainsString('<style nonce="Styled123"', $out);
    }

    // ── The no-js flip is allowed by hash, not by nonce ──────────────────────

    /**
     * The flip carries no nonce, and the policy carries its hash instead.
     *
     * 96 bytes, and frequently the only inline script on a page — so nonced, it was the
     * whole of what stood between an otherwise static page and the cache.
     *
     * A hash rather than an external file, because the script has to run before the first
     * paint: a blocking request in `<head>` to answer *does JavaScript exist* is the very
     * thing the `no-js` class exists to answer without one.
     */
    public function testTheFlipIsAllowedByHashRatherThanNonce(): void
    {
        // Arrange
        $app = $this->application('');
        (new \ReflectionMethod(Application::class, 'ensureCspNonce'))->invoke($app);

        // Act
        $out    = (new Html())->render();
        $policy = $app->cspPolicy();

        // Assert — emitted without a nonce…
        $this->assertMatchesRegularExpression('/<script data-pramnos-hashed>/', $out);
        $this->assertStringNotContainsString('data-pramnos-hashed nonce=', $out);
        $this->assertStringNotContainsString('nonce="' . $app->cspNonce . '" data-pramnos-hashed', $out);

        // …and allowed by a hash in the policy.
        $this->assertMatchesRegularExpression("/'sha256-[A-Za-z0-9+\/=]+'/", $policy);
    }

    /**
     * The hash in the policy is the hash of the bytes actually emitted.
     *
     * The invariant that breaks a page if it drifts. A hash covers exact bytes, so a
     * policy computed from a different string than the one in the document blocks the
     * script — and a blocked flip leaves every page permanently in its no-JavaScript
     * styling, which is the failure this framework has already been reported for twice.
     *
     * Computed from the emitted document rather than from the constant, so that editing
     * the script and forgetting the policy fails here instead of in a browser.
     */
    public function testThePolicyHashCoversTheBytesActuallyEmitted(): void
    {
        // Arrange
        $app = $this->application('');
        (new \ReflectionMethod(Application::class, 'ensureCspNonce'))->invoke($app);

        // Act
        $out = (new Html())->render();
        $this->assertSame(
            1,
            preg_match('/<script data-pramnos-hashed>(.*?)<\/script>/s', $out, $m),
            'the flip must be in the document for its hash to mean anything'
        );
        $expected = "'sha256-" . base64_encode(hash('sha256', $m[1], true)) . "'";

        // Assert
        $this->assertStringContainsString($expected, $app->cspPolicy());
    }

    /**
     * With `unsafe-inline` asked for, neither the nonce nor the hash is emitted.
     *
     * A browser ignores `unsafe-inline` the moment a nonce or hash is present, so
     * emitting either would quietly cancel the thing the application configured. The
     * Tailwind theme is the case that needs it.
     */
    public function testUnsafeInlineSuppressesBothTheNonceAndTheHash(): void
    {
        // Arrange
        $app = $this->application('', ['csp' => ['script-src' => ["'unsafe-inline'"]]]);
        $app->cspNonce = 'Unsafe123';

        // Act
        $policy = $app->cspPolicy();

        // Assert — scoped to script-src, because style-src legitimately still carries
        // the nonce: only script-src was given unsafe-inline.
        $scriptSrc = '';
        foreach (explode('; ', $policy) as $directive) {
            if (str_starts_with($directive, 'script-src ')) {
                $scriptSrc = $directive;
            }
        }

        $this->assertSame("script-src 'self' 'unsafe-inline'", $scriptSrc);
        $this->assertStringNotContainsString('nonce-', $scriptSrc);
        $this->assertStringNotContainsString('sha256-', $scriptSrc);

        // …and style-src is untouched by any of this.
        $this->assertStringContainsString("style-src 'self' 'nonce-Unsafe123'", $policy);
    }

    /**
     * A page whose only inline script is a data block is storable.
     *
     * The end of the chain, and the reason any of this was worth doing. A catalogue page
     * with structured data and an external script now carries no nonce at all, so
     * `PageCache::store()` keeps it — where before, the two scripts the framework itself
     * emitted were the only thing refusing it.
     */
    public function testACataloguePageIsNowStorable(): void
    {
        // Arrange
        $app = $this->application();
        (new \ReflectionMethod(Application::class, 'ensureCspNonce'))->invoke($app);

        $doc = new Html();
        $doc->content = '<h1>Genres</h1>'
            . '<script type="application/ld+json">{"@context":"https://schema.org"}</script>'
            . '<script src="/js/chrome.js"></script>';
        $body = $doc->render();

        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/genres';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_GET = [];
        $_COOKIE = [];
        \Pramnos\Http\Request::resetInstance();

        // Assert — no nonce reached the body…
        $this->assertStringNotContainsString($app->cspNonce, $body);

        // …so the page cache keeps it.
        $cache = new \Pramnos\Cache\Page\PageCache(
            ['enabled' => true],
            new \Pramnos\Cache\Adapter\ArrayAdapter()
        );
        $this->assertTrue($cache->store(
            new \Pramnos\Http\Request(),
            \Pramnos\Http\Response::make($body)
        ));
    }

    /** Render a document whose body is the given markup. */
    private function renderWith(string $markup): string
    {
        $doc = new Html();
        $doc->content = $markup;

        return $doc->render();
    }

    /**
     * An inline script in the `<head>` markup is nonced like any other.
     *
     * The answer to FW-016, which said the framework's own `<head>` script was missing
     * its nonce because it is written into the markup rather than registered through
     * `addScript()`. It is not: `Html::render()` post-processes the whole finished string,
     * so the injection is by tag and registration has nothing to do with it.
     *
     * Asserted on a script placed in the body rather than on the `no-js` flip itself,
     * because the flip has since become the one deliberate exception — it is allowed by
     * hash, for cacheability. The mechanism the filing was wrong about is what this pins.
     */
    public function testAnInlineScriptIsNoncedWhereverItAppears(): void
    {
        // Arrange
        $this->application('NoJsCanary123');

        // Act — not registered through addScript(), exactly as the filing described.
        $out = $this->renderWith('<script>markupWritten()</script>');

        // Assert
        $this->assertStringContainsString('<script nonce="NoJsCanary123">markupWritten()', $out);
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
        $out    = $this->renderWith('<script>needsTheNonce()</script>');

        // Assert — the policy names the nonce the document was stamped with.
        $this->assertStringContainsString("'nonce-" . $app->cspNonce . "'", $policy);
        $this->assertStringContainsString('nonce="' . $app->cspNonce . '"', $out);
    }
}
