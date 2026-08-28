<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Mcp\Tools\RouteListTool;

/**
 * Reading an application's routes without running them.
 *
 * `route-list` used to answer `{"error": "No routes found"}` on an application with fifty of
 * them, with a note explaining that including a routes file would serve a request instead of
 * describing one. The note was true — this project's file ends in
 * `return $router->dispatch($request)` — and the answer was still useless: it reads as a fact
 * about the application rather than a limitation of the tool.
 *
 * The routes are statically readable, so they are read. Same trade that fixed route
 * *discovery* an hour earlier: tokenising costs microseconds and cannot dispatch anything.
 */
#[CoversClass(RouteListTool::class)]
class RouteListFromFilesTest extends TestCase
{
    private function tool(): RouteListTool
    {
        return new RouteListTool(
            (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor()
        );
    }

    /** @return list<array<string, mixed>> */
    private function parse(string $code): array
    {
        /** @var list<array<string, mixed>> $routes */
        $routes = (new \ReflectionMethod(RouteListTool::class, 'parseRoutes'))
            ->invoke($this->tool(), "<?php\n" . $code);

        return $routes;
    }

    /**
     * Every verb is found, with its URI and action.
     */
    public function testTheVerbsAreRead(): void
    {
        // Arrange & Act
        $routes = $this->parse(<<<'CODE'
        $router->get('/things', 'Things@index');
        $router->post('/things', 'Things@store');
        $router->put('/things/{id}', 'Things@replace');
        $router->patch('/things/{id}', 'Things@update');
        $router->delete('/things/{id}', 'Things@destroy');
        CODE);

        // Assert
        $this->assertCount(5, $routes);
        $this->assertSame(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            array_column($routes, 'method')
        );
        $this->assertSame('/things/{id}', $routes[2]['uri']);
        $this->assertSame('Things@replace', $routes[2]['action']);
        $this->assertSame('routes-file', $routes[0]['source']);
        $this->assertIsInt($routes[0]['line'], 'the line, so it can be opened');
    }

    /**
     * A literal group prefix is applied, and stops applying when the group closes.
     *
     * Without tracking the closure's braces every route after a group would inherit its
     * prefix — reporting `/admin/health` for a route that answers on `/health`. A URI that is
     * confidently wrong is worse than one left out, because somebody calls it.
     */
    public function testAGroupPrefixAppliesAndThenStops(): void
    {
        // Arrange & Act
        $routes = $this->parse(<<<'CODE'
        $router->group(['prefix' => '/admin', 'middleware' => ['auth']], function ($r) {
            $r->get('/users', 'Users@index');
        });
        $router->get('/health', 'Health@check');
        CODE);

        // Assert
        $this->assertSame('/admin/users', $routes[0]['uri']);
        $this->assertSame('/health', $routes[1]['uri'], 'the group closed');
    }

    /**
     * Nested groups compose.
     */
    public function testNestedGroupsCompose(): void
    {
        // Arrange & Act
        $routes = $this->parse(<<<'CODE'
        $router->group(['prefix' => '/api'], function ($r) {
            $r->group(['prefix' => '/v2'], function ($r) {
                $r->get('/me', 'Me@show');
            });
        });
        CODE);

        // Assert
        $this->assertSame('/api/v2/me', $routes[0]['uri']);
    }

    /**
     * A prefix that is an expression is reported as that expression, not guessed.
     *
     * The first version accepted the leading literal of a concatenation, so
     * `'prefix' => '/' . (defined('APIVERSION') ? APIVERSION : '1.0')` came back as `/` and
     * every route in the group was listed at `//me`. That is the failure mode this whole file
     * is against: an answer that looks precise and is wrong.
     */
    public function testAnUnresolvablePrefixIsShownAsItIsWritten(): void
    {
        // Arrange & Act
        $routes = $this->parse(<<<'CODE'
        $router->group(['prefix' => '/' . (defined('APIVERSION') ? APIVERSION : '1.0')], function ($r) {
            $r->get('/me', 'Me@show');
        });
        CODE);

        // Assert
        $this->assertStringNotContainsString('//me', $routes[0]['uri']);
        $this->assertStringContainsString('APIVERSION', $routes[0]['uri']);
        $this->assertStringEndsWith('/me', $routes[0]['uri']);
    }

    /**
     * A comment before the group's array does not swallow it.
     *
     * Comments are tokens with text, and the text was being concatenated and then split on
     * commas — so a comment containing a comma became argument zero, the prefix was not found,
     * and every route in the group silently lost it. This project's own routes file has exactly
     * such a comment, which is how it was noticed.
     */
    public function testACommentBeforeTheArrayIsIgnored(): void
    {
        // Arrange & Act
        $routes = $this->parse(<<<'CODE'
        $router->group(
            // The version prefix, from app.php ('api_version'), so both agree.
            ['prefix' => '/v1'],
            function ($r) {
                $r->get('/me', 'Me@show');
            }
        );
        CODE);

        // Assert
        $this->assertSame('/v1/me', $routes[0]['uri']);
    }

    /**
     * A closure action names the controller call inside it.
     *
     * `(closure)` says nothing, and closures are how this codebase writes every API route.
     * The controller and method are what somebody is looking for.
     */
    public function testAClosureActionNamesTheControllerInsideIt(): void
    {
        // Arrange & Act
        $routes = $this->parse(<<<'CODE'
        $router->get('/me', function () {
            return (new \App\Api\Controllers\Me($this))->display();
        });
        CODE);

        // Assert
        $this->assertSame('(closure) Me@display', $routes[0]['action']);
    }

    /**
     * `match()` puts its methods first, and is read accordingly.
     */
    public function testMatchIsReadWithItsMethodsFirst(): void
    {
        // Arrange & Act
        $routes = $this->parse(<<<'CODE'
        $router->match(['get', 'post'], '/form', 'Form@handle');
        CODE);

        // Assert
        $this->assertSame('/form', $routes[0]['uri']);
        $this->assertSame('Form@handle', $routes[0]['action']);
    }

    /**
     * A verb-named function that is not a router call is not a route.
     *
     * `$collection->get('key')` and `Config::get('x')` are everywhere. Only `->verb(` on
     * something is considered, and a URI that is not a literal string is marked as an
     * expression rather than invented.
     */
    public function testSomethingThatIsNotARouteIsNotListed(): void
    {
        // Arrange & Act
        $routes = $this->parse(<<<'CODE'
        $value = get('not a route');
        $thing = Config::get('also.not.a.route');
        CODE);

        // Assert — the static call is a `->`-less form, so neither is a route
        $this->assertSame([], $routes);
    }

    /**
     * A route registered with a variable URI is reported as the variable.
     */
    public function testANonLiteralUriIsMarked(): void
    {
        // Arrange & Act
        $routes = $this->parse(<<<'CODE'
        $router->get($prefix . '/thing', 'Thing@index');
        CODE);

        // Assert
        $this->assertStringContainsString('$prefix', $routes[0]['uri']);
        $this->assertStringStartsWith('{', $routes[0]['uri'], 'marked, not passed off as a URI');
    }

    /**
     * An interpolated string in a routes file does not unwind the group stack.
     *
     * Same token asymmetry that broke `find-symbol` earlier today: `"{$x}"` opens with a
     * `T_CURLY_OPEN` token and closes with a plain `}`.
     */
    public function testAnInterpolatedStringDoesNotBreakTheGroupStack(): void
    {
        // Arrange & Act
        $routes = $this->parse(<<<'CODE'
        $router->group(['prefix' => '/v1'], function ($r) {
            $label = "hello {$name}";
            $r->get('/after', 'After@index');
        });
        CODE);

        // Assert
        $this->assertSame('/v1/after', $routes[0]['uri']);
    }
}
