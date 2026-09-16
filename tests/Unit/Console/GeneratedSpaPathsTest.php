<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;

/**
 * Who owns the API prefix, and the routes the shipped client calls.
 *
 * **The prefix is `lib/api.js`'s.** It reads `apiPrefix` from `window.__PRAMNOS__` — so a
 * deployment can move the API — and adds it in `fetch(apiPrefix + url)`. A caller that
 * carries it too produces `/api/1.0/api/1.0/<entity>`, and every screen `create:crud`
 * generates for a SPA answered `Could not load: HTTP 404` on its first load, which is the
 * first thing anybody clicks after generating one.
 *
 * **The screen's own generated test could not see it**, and that is the part worth
 * remembering: it asserts the path handed to a *mocked* client, so the mock is exactly
 * where the prefix stops. A test that stubs `fetch` instead would have caught it, which is
 * why this one reads the templates rather than trusting them.
 *
 * The buildless stack's screen stub had it right all along (`'/{{ resource }}'`), so the
 * two stacks disagreed — which is the evidence for which of them was wrong.
 */
#[CoversClass(Init::class)]
class GeneratedSpaPathsTest extends TestCase
{
    private function stub(string $name): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/templates/' . $name
        );
    }

    /**
     * Nothing that goes through the client carries the prefix.
     *
     * Swept across every stub rather than asserted on the two that were wrong: the next
     * one will be a third file, and the rule is about ownership rather than about these
     * templates.
     */
    public function testNoStubPrefixesAPathItHandsToTheClient(): void
    {
        // Arrange
        $templates = glob(dirname(__DIR__, 3) . '/scaffolding/templates/*.stub') ?: [];
        $this->assertNotEmpty($templates);

        // Act — any api.get/post/put/patch/delete whose first argument starts with the
        // prefix token.
        $offenders = [];
        foreach ($templates as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match_all(
                "/api\\.(?:get|post|put|patch|delete)\\(\\s*[`'\"]\\{\\{ apiPrefix \\}\\}/",
                $contents,
                $matches
            )) {
                $offenders[] = basename($file) . ' (' . count($matches[0]) . ')';
            }

            // …and the CRUD screen's RESOURCE constant, which every request in the screen
            // is built from.
            if (preg_match("/const RESOURCE = '\\{\\{ apiPrefix \\}\\}/", $contents)) {
                $offenders[] = basename($file) . ' (RESOURCE)';
            }
        }

        // Assert
        $this->assertSame([], $offenders, "These hand the client a prefixed path:\n" . implode("\n", $offenders));
    }

    /**
     * A raw `fetch()` still carries it, because nothing else will add it.
     *
     * The counterpart, and the reason the sweep above is narrow: `lib/i18n.js` fetches
     * `{{ apiPrefix }}/language` directly, correctly. A rule of "no prefix anywhere" would
     * have broken it while fixing the screens.
     */
    public function testARawFetchStillCarriesThePrefix(): void
    {
        // Act & Assert
        $i18n = $this->stub('spa-i18n.js.stub');
        $this->assertStringContainsString("'{{ apiPrefix }}/language'", $i18n);
        $this->assertStringContainsString('await fetch(url', $i18n);
    }

    /**
     * The two-factor route the shipped client posts to is generated.
     *
     * `ApiAccount` has always registered the action and `lib/api.js` has always called it;
     * only the route was missing, so a user with two-factor enabled could not sign in to a
     * scaffolded SPA at all — password accepted, `two_factor_required` returned, code
     * posted, 404.
     *
     * Asserted as the three agreeing, because that is the shape of the defect: the
     * controller, the client and the generator each had to name it and one did not.
     */
    public function testTheTwoFactorLoginRouteIsGeneratedAndMatchesTheClient(): void
    {
        // Arrange — what the client posts to.
        $client = $this->stub('spa-api-client.js.stub');
        $this->assertStringContainsString("request('/account/login2fa'", $client);

        // …what the controller answers.
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Auth/Controllers/ApiAccount.php'
        );
        $this->assertStringContainsString('public function login2fa()', $controller);

        // Act & Assert — and what the generator routes.
        $init = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/Init.php'
        );
        $this->assertStringContainsString("post('/account/login2fa'", $init);
        $this->assertStringContainsString('->login2fa();', $init);

        // And it is documented, or a reader of the generated API docs cannot find it.
        $this->assertStringContainsString("\$paths['/account/login2fa']", $init);
    }
}
