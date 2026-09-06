<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Controllers\McpController;
use Pramnos\Mcp\McpServiceProvider;
use Pramnos\Mcp\PublicRegistry;
use Pramnos\Mcp\ScopedMcpTool;
use Pramnos\Mcp\Tools\WhoAmITool;
use Pramnos\User\Token;

/**
 * The reported symptom, end to end: an authenticated token, four scopes, no tools.
 *
 * A consuming application mounted `POST /mcp`, issued a token through this
 * framework's own OAuth2 token endpoint, and got `{"tools":[]}` back. The token
 * was fine. `AccessTokenRepository` writes scopes space-separated because RFC
 * 6749 §3.3 says to, `Token` did not parse that shape, and the whole string
 * arrived as a single element that matched no scope anywhere.
 *
 * The controller had the correct parse — `explode(' ', …)` — in the branch it
 * takes when the value is *not* an array. `Token` normalises in its constructor,
 * so that branch could never run for a real token: the right code was there and
 * was unreachable.
 */
#[CoversClass(McpController::class)]
#[CoversClass(Token::class)]
class McpScopeParsingTest extends TestCase
{
    protected function setUp(): void
    {
        PublicRegistry::reset();
        unset($_SESSION['usertoken']);
    }

    protected function tearDown(): void
    {
        PublicRegistry::reset();
        unset($_SESSION['usertoken']);
        parent::tearDown();
    }

    private function controller(): object
    {
        return new class extends McpController {
            /** @return list<string> */
            public function exposeScopesOf(): array
            {
                return $this->scopesOf();
            }
        };
    }

    /**
     * Whatever shape the row held, the caller reaches their tools.
     *
     * The first case is the reported one and the one the framework writes itself.
     * Driven through `Token`, because that is what the middleware puts in the
     * session — constructing the array by hand would test a path no request takes.
     */
    #[DataProvider('storedShapesProvider')]
    public function testATokenReachesItsToolsWhateverShapeTheRowHeld(string $stored): void
    {
        // Arrange
        McpServiceProvider::offerDiagnostics(null);
        $_SESSION['usertoken'] = new Token(array('scope' => $stored));

        // Act
        $scopes = $this->controller()->exposeScopesOf();
        $names  = array_map(
            static fn (ScopedMcpTool $t): string => $t->name(),
            PublicRegistry::visibleTo($scopes)
        );

        // Assert
        $this->assertContains('mcp:logs', $scopes, 'the scopes did not parse: ' . json_encode($scopes));
        $this->assertContains('log-errors', $names, 'authenticated with a real grant and no tools');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function storedShapesProvider(): array
    {
        return array(
            'space separated — what this framework writes' => array('mcp mcp:diagnostics mcp:logs'),
            'json — what Token::save() used to write'      => array('["mcp","mcp:diagnostics","mcp:logs"]'),
            'comma separated — legacy rows'                => array('mcp, mcp:diagnostics, mcp:logs'),
        );
    }

    /**
     * `whoami` reports the same scopes the controller acted on.
     *
     * It is documented as the first thing to reach for when a tool is missing, so a
     * second reading of the column here would send somebody looking in the wrong
     * place — the diagnostic agreeing with the endpoint is the whole of its value.
     */
    public function testWhoamiAgreesWithTheControllerThatGatedTheCall(): void
    {
        // Arrange
        $_SESSION['usertoken'] = new Token(array('scope' => 'mcp mcp:db_read'));

        // Act
        $reported = (new WhoAmITool())->execute(array())['scopes'];
        $acted    = $this->controller()->exposeScopesOf();

        // Assert
        $this->assertSame($acted, $reported);
        $this->assertSame(array('mcp', 'mcp:db_read'), $reported);
    }

    /**
     * An empty scope list and a list that failed to parse are no longer the same
     * observation.
     *
     * The filing asked for these to be told apart, and the fix separates them
     * structurally rather than by adding a message: a non-empty column can no
     * longer produce an empty list, because the fallback is a whitespace split and
     * a non-empty string always splits into something. So an empty answer now means
     * exactly one thing — the column was empty — and that is a configuration
     * somebody can be told about rather than a bug hiding behind a correct-looking
     * refusal.
     */
    public function testAnEmptyAnswerNowMeansOnlyOneThing(): void
    {
        // Arrange — a column with anything in it at all
        $_SESSION['usertoken'] = new Token(array('scope' => 'something-nobody-defined'));

        // Act
        $scopes = $this->controller()->exposeScopesOf();

        // Assert — it parsed; it simply grants nothing
        $this->assertSame(array('something-nobody-defined'), $scopes);
        $this->assertSame(array(), PublicRegistry::visibleTo($scopes));

        // and the genuinely empty column is the only way back to an empty list
        $_SESSION['usertoken'] = new Token(array('scope' => ''));
        $this->assertSame(array(), $this->controller()->exposeScopesOf());
    }

    /**
     * A caller with no token at all still reaches nothing.
     *
     * The fix widened what parses, and the thing that must not have widened with it
     * is the answer to "no credential" — failing closed there costs a puzzled
     * client, and failing open costs whatever the tools do.
     */
    public function testNoTokenStillReachesNothing(): void
    {
        // Arrange
        McpServiceProvider::offerDiagnostics(null);
        unset($_SESSION['usertoken']);

        // Act + Assert
        $this->assertSame(array(), $this->controller()->exposeScopesOf());
        $this->assertSame(array(), PublicRegistry::visibleTo(array()));
    }
}
