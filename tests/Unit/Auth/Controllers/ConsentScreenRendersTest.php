<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Document\Document;
use Pramnos\Framework\Factory;

/**
 * The consent screen has to reach the response.
 *
 * `showConsentForm()` ended with `$view->display('authorize');` — and
 * `View::display()` **returns** the markup rather than echoing it. The return
 * value was discarded, and `authorize()` is declared `: void`, so nothing else
 * picked it up either.
 *
 * The result was a 200, with the theme rendered, the title set to "Authorize
 * Application", and no form. The only page on which a person grants an
 * application access to their account did not exist — and everything about the
 * response said it had worked. A relying party's users reached a blank page and
 * reported it as the relying party being broken.
 *
 * `showErrorPage()` had the mirror-image version: it `echo`ed, so its message went
 * out *before* the page the framework then rendered, giving a fragment followed
 * by a complete HTML document.
 *
 * Both are asserted here on the mechanism — content reaching the document — since
 * that is what was wrong. Whether the form is correct is the view's business.
 */
class ConsentScreenRendersTest extends TestCase
{
    protected function setUp(): void
    {
        Document::reset();
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /**
     * The error page is added to the document rather than echoed.
     *
     * Reachable without a database: an authorize request with no `client_id` fails
     * validation before anything is looked up.
     */
    public function testTheErrorPageReachesTheDocument(): void
    {
        // Arrange
        $_GET = ['client_id' => '', 'response_type' => 'code'];
        $controller = new Oauth(null);

        // Act
        ob_start();
        $controller->authorize();
        $echoed = (string) ob_get_clean();
        $document = (string) Factory::getDocument()->render();

        // Assert
        $this->assertStringContainsString('Authorization Error', $document,
            'the error must be part of the page, not printed in front of it');
        $this->assertStringNotContainsString('Authorization Error', $echoed,
            'and nothing may be echoed ahead of the document');
    }

    /**
     * The consent form is added to the document, not discarded.
     *
     * Asserted on the source, because reaching the form needs a signed-in user and
     * a client row — and the defect was never in the form, it was in what the
     * controller did with it. `display()`'s return value being used is the whole
     * fix, and it is visible here.
     */
    public function testTheConsentFormIsAddedToTheDocument(): void
    {
        // Act
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Pramnos/Auth/Controllers/Oauth.php'
        );

        // Assert
        $this->assertStringNotContainsString(
            "\$view->display('authorize');",
            $source,
            "display()'s return value must be used, not discarded"
        );
        $this->assertStringContainsString(
            "(string) \$view->display('authorize')",
            $source,
            'the rendered form must be added to the document'
        );
    }

    /**
     * No action on this controller echoes a page fragment.
     *
     * The two that did are fixed; this is the guard that stops a third. An echo
     * from a controller lands before the document the framework renders, so the
     * response is a fragment followed by a whole page — valid-looking in a browser
     * and broken for anything that parses it.
     */
    public function testNoActionEchoesAPageFragment(): void
    {
        // Act
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Pramnos/Auth/Controllers/Oauth.php'
        );

        // Assert — the JSON endpoints answer with a Response, so nothing here
        // needs to write to the output stream directly
        $this->assertStringNotContainsString("echo '<h1>", $source);
        $this->assertStringNotContainsString('echo "<h1>', $source);
    }
}
