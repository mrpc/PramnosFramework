<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Scopes;
use Pramnos\Routing\Router;
use Pramnos\User\Token;

/**
 * Every reader of a `scope` column now reads it the same way.
 *
 * Fixing `Token::load()` fixed one of eight. Seven more sites parsed a scope
 * column themselves — `explode(' ', …)` in five of them, a `json_decode ?: explode`
 * in a sixth — so which shapes an installation could store still depended on
 * which code path happened to read them, and the two applications that adopted
 * the parser found the rest by auditing.
 *
 * The line drawn here, and it is the one worth remembering: **a column or a
 * stored token value goes through `Token::parseScopes()`; an OAuth request
 * parameter stays the space-delimited string RFC 6749 §3.3 defines.** Being
 * forgiving about what somebody wrote into a database years ago is not the same
 * as being forgiving about what a client sends today.
 */
#[CoversClass(Token::class)]
#[CoversClass(Scopes::class)]
#[CoversClass(Router::class)]
#[CoversClass(\Pramnos\Auth\Application::class)]
class ScopeReadersTest extends TestCase
{
    /**
     * `Router::normalizePermissions()` on an instance built without its container.
     *
     * The method is pure and the constructor wants an IoC container it has no use
     * for here; reflecting past it keeps the test about the parse.
     *
     * @param  array<int, string>|string|null $permissions
     * @return array<int, string>
     */
    private function normalize($permissions): array
    {
        $router = (new \ReflectionClass(Router::class))->newInstanceWithoutConstructor();
        // No setAccessible(): it is deprecated in 8.5 and has had no effect since 8.1.
        return (new \ReflectionMethod(Router::class, 'normalizePermissions'))
            ->invoke($router, $permissions);
    }

    /**
     * Every shape a column can hold, so each reader below can be asked about all of
     * them without repeating the list.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function shapesProvider(): array
    {
        return array(
            'space separated' => array('profile email', array('profile', 'email')),
            'json'            => array('["profile","email"]', array('profile', 'email')),
            'comma'           => array('profile, email', array('profile', 'email')),
            'bracketed list'  => array('[profile email]', array('profile', 'email')),
        );
    }

    /**
     * `[profile email]` — brackets round a space-separated list — is a real shape.
     *
     * Not JSON, no comma, so a whitespace split yields `['[profile', 'email]']`:
     * two scopes nobody granted and a refusal naming both. It is in a consuming
     * application's data, and the framework already accepted it in exactly one
     * place — `addDefaultScopesToToken()` stripped these brackets before this
     * parser existed. Accepting a shape in one reader and refusing it in the
     * canonical one is the same defect one level up.
     */
    public function testTheBracketedListParses(): void
    {
        // Act + Assert
        $this->assertSame(array('profile', 'email'), Token::parseScopes('[profile email]'));
        $this->assertSame(array('profile', 'email'), Token::parseScopes('[profile,email]'));
        $this->assertSame(array('single'), Token::parseScopes('[single]'));
    }

    /**
     * A JSON array is still read as JSON, not stripped of its brackets.
     *
     * `["a","b"]` is bracketed too. Stripping first would leave `"a","b"` and yield
     * scopes with quote marks in them — which match nothing and are hard to see.
     * The order of the two attempts is what keeps them apart.
     */
    public function testAJsonArrayIsNotMistakenForABracketedList(): void
    {
        // Act
        $scopes = Token::parseScopes('["profile","email"]');

        // Assert
        $this->assertSame(array('profile', 'email'), $scopes);
        $this->assertStringNotContainsString('"', implode('', $scopes));
    }

    /**
     * An empty bracketed list is empty, not a scope named nothing.
     */
    public function testAnEmptyBracketedListIsEmpty(): void
    {
        // Act + Assert
        $this->assertSame(array(), Token::parseScopes('[]'));
        $this->assertSame(array(), Token::parseScopes('[ ]'));
    }

    /**
     * `Auth\Application::getScopes()` reads `applications.scope`, which is a column.
     *
     * It decides which scopes a client may request at all, so a shape it cannot read
     * is a client refused the grants its own row lists.
     */
    #[DataProvider('shapesProvider')]
    public function testTheClientsAllowedScopesParseWhateverTheRowHeld(string $stored, array $expected): void
    {
        // Arrange — without the constructor, which wants a controller it does not use
        // to answer this question
        $application = (new \ReflectionClass(\Pramnos\Auth\Application::class))
            ->newInstanceWithoutConstructor();
        $application->scope = $stored;

        // Act + Assert
        $this->assertSame($expected, $application->getScopes());
        $this->assertTrue($application->hasScope('profile'));
    }

    /**
     * The router's permission list is the caller's own, so a shape it cannot read is
     * a refusal of access somebody was granted.
     *
     * The single-permission fallback was the original bug in miniature: any string
     * without a space became one permission, so `'mcp,mcp:logs'` was a permission
     * literally named `mcp,mcp:logs`.
     */
    #[DataProvider('shapesProvider')]
    public function testTheRoutersPermissionsParseWhateverTheyCameFrom(string $stored, array $expected): void
    {
        // Act + Assert
        $this->assertSame($expected, $this->normalize($stored));
    }

    /**
     * An array reaches the router untouched, which is the documented half of
     * `array|string` and the half that already worked.
     */
    public function testAnArrayOfPermissionsIsLeftAlone(): void
    {
        // Act + Assert
        $this->assertSame(array('a', 'b'), $this->normalize(array('a', 'b')));
        $this->assertSame(array(), $this->normalize(null));
    }

    /**
     * `resolveInheritedScopes()` takes `string|string[]`, and a string that reached it
     * has usually come from a column by way of some caller it cannot see.
     *
     * Parsing it the forgiving way costs nothing when the value was already the
     * standard shape, and is the difference between resolving a parent scope and
     * resolving nothing.
     */
    public function testInheritedScopesResolveFromAnyStoredShape(): void
    {
        // Act
        $fromBrackets = Scopes::resolveInheritedScopes('[system:notifications_write]');
        $fromSpace    = Scopes::resolveInheritedScopes('system:notifications_write');

        // Assert — the parent is reached either way
        $this->assertContains('system:notifications_read', $fromBrackets);
        $this->assertSame($fromSpace, $fromBrackets);
    }

    /**
     * `addDefaultScopesToToken()` keeps working, having handed its bracket rule over.
     *
     * It was the only reader in the framework that knew about `[profile email]`,
     * which is why the canonical parser did not. Now it delegates, and it gained
     * the other shapes for free.
     */
    public function testDefaultScopesStillMergeAndNowAcceptEveryShape(): void
    {
        // Arrange
        $defaults = Scopes::getDefaultScopes();

        // Act
        $fromBrackets = explode(' ', Scopes::addDefaultScopesToToken('[profile email]'));
        $fromComma    = explode(' ', Scopes::addDefaultScopesToToken('profile, email'));

        // Assert
        foreach (array('profile', 'email') as $asked) {
            $this->assertContains($asked, $fromBrackets);
            $this->assertContains($asked, $fromComma);
        }
        foreach ($defaults as $default) {
            $this->assertContains($default, $fromBrackets, 'a default scope stopped being merged');
        }
        // and nothing is listed twice, which the merge has always promised
        $this->assertSame(array_unique($fromBrackets), $fromBrackets);
    }

    /**
     * An OAuth request parameter is **not** parsed the forgiving way.
     *
     * RFC 6749 §3.3 defines `scope` as space-delimited, and a request is a live
     * conversation with a client that can be told it is wrong. The forgiveness
     * exists for rows written years ago by code that has since been fixed — it is
     * not a licence for a client to invent a syntax, and `hasInvalidScopes()`
     * deliberately still reads the standard form only.
     */
    public function testARequestParameterIsStillReadAsTheStandardDefinesIt(): void
    {
        // Act — a client sending a comma list is sending something the RFC does not define
        [$hasInvalid, $invalid] = Scopes::hasInvalidScopes('profile,email');

        // Assert — reported back rather than quietly accepted
        $this->assertTrue($hasInvalid);
        $this->assertSame(array('profile,email'), $invalid);
        // while the form the RFC does define is accepted
        [$stillValid] = Scopes::hasInvalidScopes('profile email');
        $this->assertFalse($stillValid);
    }
}
