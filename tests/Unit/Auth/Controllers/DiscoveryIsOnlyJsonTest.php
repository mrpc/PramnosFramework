<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Discovery;
use Pramnos\Document\Document;
use Pramnos\Framework\Factory;

/**
 * A discovery endpoint's response must be JSON and nothing else.
 *
 * Every one of these actions used to `echo` its body and return. An echo writes
 * to the output stream and leaves the framework to render the page it was going
 * to render anyway — so the response was valid JSON followed by a complete HTML
 * document. `/.well-known/openid-configuration` was 173 KB, and no client that
 * calls `JSON.parse` on it could read it.
 *
 * It went unnoticed for as long as it existed because the JSON comes **first**.
 * Every way a person checks one of these looks right: the head of a curl, a
 * browser's raw view, the first screen of a log. The tests looked right too —
 * they captured the output stream, which held exactly the JSON and none of the
 * page appended after them.
 *
 * So this asserts the shape of the whole response, not the presence of the JSON
 * in it: what is rendered must parse, and must contain no markup.
 */
class DiscoveryIsOnlyJsonTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function jsonActions(): array
    {
        return [
            ['configuration'],
            ['jwks'],
            ['oauth2Metadata'],
            ['oauthProtectedResource'],
            ['health'],
            ['serverConfig'],
        ];
    }

    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Document::reset();
    }

    /**
     * The whole rendered response parses as JSON.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('jsonActions')]
    public function testTheWholeResponseIsJson(string $action): void
    {
        // Arrange
        $controller = new Discovery(null);

        // Act
        $controller->{$action}();
        $body = (string) Factory::getDocument('raw')->render();

        // Assert
        $this->assertNotSame('', $body, "$action() must produce a body");
        $this->assertIsArray(
            json_decode($body, true),
            "$action() must answer with JSON only — got: " . substr($body, 0, 120)
        );
    }

    /**
     * And carries no markup, which is what the appended page was.
     *
     * A JSON body containing `<html` parses only if the markup is inside a string
     * value, so this catches the case the parse check would let through: an
     * endpoint that embeds a rendered page in a field.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('jsonActions')]
    public function testTheResponseCarriesNoMarkup(string $action): void
    {
        // Arrange
        $controller = new Discovery(null);

        // Act
        $controller->{$action}();
        $body = (string) Factory::getDocument('raw')->render();

        // Assert
        foreach (['<html', '<!doctype', '<script', '<body'] as $markup) {
            $this->assertStringNotContainsStringIgnoringCase(
                $markup,
                $body,
                "$action() must not answer with a page"
            );
        }
    }

    /**
     * Nothing is written to the output stream.
     *
     * This is the mechanism rather than the symptom: as long as a body is echoed,
     * the framework is still going to render a page after it, and the next person
     * to add an endpoint here will reintroduce the bug by copying the one above.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('jsonActions')]
    public function testNothingIsEchoed(string $action): void
    {
        // Arrange
        $controller = new Discovery(null);

        // Act
        ob_start();
        $controller->{$action}();
        $echoed = (string) ob_get_clean();

        // Assert
        $this->assertSame('', $echoed, "$action() must not echo");
    }

    /**
     * The document these actions answer with is the one the framework renders.
     *
     * Setting the content on a document nothing renders would be the same bug
     * with a different shape — an empty response instead of an over-full one.
     */
    public function testTheAnsweringDocumentIsTheDefaultOne(): void
    {
        // Arrange
        $controller = new Discovery(null);

        // Act
        $controller->configuration();

        // Assert — asking with no type must hand back the same document
        $this->assertSame(
            (string) Factory::getDocument('raw')->render(),
            (string) Factory::getDocument()->render()
        );
    }
}
