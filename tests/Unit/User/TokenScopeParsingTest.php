<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\User\Token;

/**
 * What `usertokens.scope` holds, and what it has to come back as.
 *
 * Three shapes are in that column across installations and the framework wrote
 * two of them itself — the space-separated form RFC 6749 §3.3 requires, from
 * `AccessTokenRepository`, and JSON from `Token::save()`. Comma-separated rows
 * predate both.
 *
 * **The one that did not parse was the standard one**, so every token this
 * framework's own OAuth2 server issued satisfied no scope check anywhere: the
 * whole string arrived as a single element, matched nothing, and `tools/list`
 * came back empty with nothing to say why. Reported by a consuming application
 * that had mounted the endpoint and could not tell a missing grant from a bug.
 */
#[CoversClass(Token::class)]
class TokenScopeParsingTest extends TestCase
{
    /**
     * Every shape that column holds parses to the same list.
     *
     * The first row is the one that was broken, and it is the one the framework
     * writes. The last two are why this is a parser and not one more `elseif`:
     * `'a, b'` produced `' b'` with the space, which matches no scope anywhere and
     * looks correct in a var_dump; and repeated whitespace is what a value picks up
     * from a form or a config file.
     *
     * @param mixed $raw
     */
    #[DataProvider('shapesProvider')]
    public function testEveryStoredShapeParsesToTheSameList($raw, array $expected): void
    {
        // Act
        $scopes = Token::parseScopes($raw);

        // Assert
        $this->assertSame($expected, $scopes);
    }

    /**
     * @return array<string, array{0: mixed, 1: array<int, string>}>
     */
    public static function shapesProvider(): array
    {
        $three = array('mcp', 'mcp:diagnostics', 'mcp:logs');

        return array(
            'space separated (RFC 6749, and the broken one)' => array('mcp mcp:diagnostics mcp:logs', $three),
            'json array (what save() used to write)'         => array('["mcp","mcp:diagnostics","mcp:logs"]', $three),
            'comma separated (legacy rows)'                  => array('mcp,mcp:diagnostics,mcp:logs', $three),
            'comma separated as anybody would type it'       => array('mcp, mcp:diagnostics, mcp:logs', $three),
            'already an array'                               => array($three, $three),
            'ragged whitespace'                              => array("  mcp\t\tmcp:logs \n", array('mcp', 'mcp:logs')),
            'one scope'                                      => array('mcp', array('mcp')),
            'empty'                                          => array('', array()),
            'whitespace only'                                => array('   ', array()),
        );
    }

    /**
     * A value that is not a scope list produces no scopes, rather than one.
     *
     * The old normalisation wrapped whatever it had in an array, so `null` became
     * `[null]` and an object became `[$object]` — a list of one thing that is not a
     * scope, which is a different failure from an empty list and a worse one,
     * because an empty list satisfies nothing while a junk list satisfies nothing
     * *and* reads as a grant somebody made.
     *
     * @param mixed $raw
     */
    #[DataProvider('nonListProvider')]
    public function testSomethingThatIsNotAScopeListYieldsNothing($raw): void
    {
        // Act + Assert
        $this->assertSame(array(), Token::parseScopes($raw));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonListProvider(): array
    {
        return array(
            'null'   => array(null),
            'false'  => array(false),
            'object' => array(new \stdClass()),
            'int'    => array(42),
        );
    }

    /**
     * A numeric scope is a string in the list, not an integer.
     *
     * `json_decode('123')` is a valid document and an `int`, and the old code took
     * it — so `$token->scope` came back as `123`, not a list at all. Everything
     * downstream does `in_array($needed, $scopes, true)`, which is `false` against
     * an int no matter what it holds.
     */
    public function testANumericScopeStaysAListOfStrings(): void
    {
        // Act
        $scopes = Token::parseScopes('123');

        // Assert
        $this->assertSame(array('123'), $scopes);
        $this->assertIsString($scopes[0]);
    }

    /**
     * Duplicates collapse, because a scope held twice is held once.
     *
     * A token whose column reads `mcp mcp mcp:logs` — which an `addDefaultScopesToToken()`
     * round-trip can produce — must not report three scopes to a caller counting them.
     */
    public function testDuplicatesCollapse(): void
    {
        // Act + Assert
        $this->assertSame(array('mcp', 'mcp:logs'), Token::parseScopes('mcp mcp mcp:logs'));
    }

    /**
     * The constructor normalises, so nothing downstream has to.
     *
     * This is the path every reader actually takes: a row comes back from the
     * database and becomes a `Token`. Both MCP readers and the router ask
     * `$token->scope` and expect a list.
     */
    public function testTheConstructorNormalisesTheColumn(): void
    {
        // Act
        $token = new Token(array('scope' => 'mcp mcp:db_read'));

        // Assert
        $this->assertSame(array('mcp', 'mcp:db_read'), $token->scope);
    }

    /**
     * A round trip through the parser is the standard shape.
     *
     * Which is the decision this fix settles: read all three shapes forever,
     * because those rows exist and nobody is migrating somebody else's database —
     * and write exactly one, the one RFC 6749 §3.3 defines and the one this
     * framework's own `AccessTokenRepository` has always written.
     */
    public function testTheStandardShapeIsWhatComesBackOut(): void
    {
        // Act — the expression `Token::save()` and `addScopedToken()` both use
        $stored = implode(' ', Token::parseScopes('["mcp","mcp:logs"]'));

        // Assert
        $this->assertSame('mcp mcp:logs', $stored);
        // and reading that back gives the same list, so the shapes converge rather
        // than alternating on every save
        $this->assertSame(Token::parseScopes('["mcp","mcp:logs"]'), Token::parseScopes($stored));
    }
}
