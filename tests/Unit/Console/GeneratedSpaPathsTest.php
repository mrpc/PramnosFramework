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

    /**
     * Every passkey endpoint the shipped client calls is routed and documented.
     *
     * The same three-way agreement as the two-factor route above, and the same
     * defect was waiting: the ceremony endpoints and the WebAuthn client have
     * both existed for a long time, but nothing routed them under the API — so a
     * SPA had every piece of passwordless sign-in except the ability to reach it.
     *
     * Asserted per path rather than as one blob: half a ceremony is the worst
     * outcome, because the first call succeeds and the failure surfaces on the
     * second, after the authenticator has already asked the user for a
     * fingerprint.
     */
    public function testEveryPasskeyRouteIsGeneratedAndMatchesTheClient(): void
    {
        // Arrange
        $client = $this->stub('spa-api-client.js.stub');
        $init   = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/Init.php'
        );
        $controller = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Auth/Controllers/ApiPasskey.php'
        );

        // Act & Assert — the four ceremony paths the client names.
        foreach (['loginOptions', 'login', 'registerOptions', 'register'] as $action) {
            $this->assertStringContainsString("'/passkey/{$action}'", $client);
            $this->assertStringContainsString("post('/passkey/{$action}'", $init);
            $this->assertStringContainsString("\$paths['/passkey/{$action}']", $init);
        }

        // …and the three management ones.
        $this->assertStringContainsString("get('/passkey/list'", $init);
        $this->assertStringContainsString("post('/passkey/rename'", $init);
        $this->assertStringContainsString("post('/passkey/revoke'", $init);
        $this->assertStringContainsString("api.get('/passkey/list')", $client);

        // The controller behind them answers with a token, which is the reason a
        // separate one exists at all — the web controller establishes a session.
        $this->assertStringContainsString('IssuesAccessTokens', $controller);

        // The ceremony itself is the framework's single source, imported rather
        // than re-implemented in the client.
        $this->assertStringContainsString("from './webauthn.js'", $client);
        $this->assertStringNotContainsString('navigator.credentials', $client);
    }

    /**
     * Every way of signing in that the client supports has a screen that reaches it.
     *
     * The gap this closes is one step further along than a missing route, and it has
     * happened for both methods in turn: the endpoint answers, the client function
     * exists, and nothing in the scaffolded application ever calls it — so the feature
     * is present in every sense except the one that matters to somebody trying to sign
     * in.
     *
     * Two-factor is the sharp case. `login()` throws `TwoFactorRequired` rather than
     * returning, so a screen that does not catch it shows "Could not sign in" to an
     * account whose password was *correct*, and there is no way forward from that
     * screen at all.
     *
     * Asserted on both stacks, because a scaffolded project gets one of them and the
     * other one's user never finds out.
     */
    public function testBothSignInScreensCompleteEveryLoginTheClientSupports(): void
    {
        // Arrange
        $client  = $this->stub('spa-api-client.js.stub');
        $screens = [
            'spa-svelte-app.svelte.stub' => $this->stub('spa-svelte-app.svelte.stub'),
            'spa-vanilla-main.js.stub'   => $this->stub('spa-vanilla-main.js.stub'),
        ];

        // The client's half of the contract, so a rename breaks this test rather than
        // the generated application.
        $this->assertStringContainsString('export class TwoFactorRequired', $client);
        $this->assertStringContainsString('export async function loginTwoFactor(', $client);
        $this->assertStringContainsString('export async function loginWithPasskey(', $client);
        $this->assertStringContainsString('export function passkeySupported(', $client);

        // Act & Assert
        foreach ($screens as $name => $screen) {
            $this->assertStringContainsString(
                'TwoFactorRequired',
                $screen,
                "{$name} never catches the pending second factor, so an account with 2FA "
                . 'cannot sign in and is told its password was wrong'
            );
            $this->assertStringContainsString(
                'loginTwoFactor(',
                $screen,
                "{$name} catches the second factor but never posts the code"
            );
            $this->assertStringContainsString(
                'one-time-code',
                $screen,
                "{$name} asks for a code without the autocomplete token that lets a phone "
                . 'offer the message it just received'
            );
            $this->assertStringContainsString(
                'loginWithPasskey(',
                $screen,
                "{$name} offers no passwordless sign-in, though the routes and the client exist"
            );
            $this->assertStringContainsString(
                'passkeySupported(',
                $screen,
                "{$name} offers the passkey button unconditionally — it fails when pressed on "
                . 'plain http:// and in older browsers, where nothing says why'
            );
        }
    }

    /**
     * No generated SPA markup uses a daisyUI class the shipped version removed.
     *
     * The scaffolder pins daisyUI 5 and generated daisyUI 4 markup: `form-control` and
     * `label-text` are gone in 5, so the wrapper that stacked a label above its field
     * stopped doing anything. Three fields rendered as three ragged rows of different
     * widths — a form that looks broken on first sight, in the screen `create:crud`
     * writes for you.
     *
     * Swept over the SPA templates only. `form-control` is also Bootstrap's class for an
     * input, and the bootstrap theme's views use it correctly; the two share a name and
     * nothing else.
     *
     * The comments that explain this replacement mention the old names, so the sweep
     * reads markup rather than text.
     */
    public function testNoGeneratedSpaMarkupUsesRemovedDaisyUiClasses(): void
    {
        // Arrange — the SPA's own templates: Svelte components and the shells.
        $templates = array_merge(
            glob(dirname(__DIR__, 3) . '/scaffolding/templates/*.svelte.stub') ?: [],
            glob(dirname(__DIR__, 3) . '/scaffolding/templates/spa-*.js.stub') ?: []
        );
        $this->assertNotEmpty($templates);

        // daisyUI 4 names with no equivalent in 5. Each renders as nothing at all, which
        // is why the failure is a layout collapse rather than a wrong colour.
        $removed = ['form-control', 'label-text', 'label-text-alt'];

        // Act
        $offenders = [];
        foreach ($templates as $file) {
            $markup = (string) file_get_contents($file);
            $markup = (string) preg_replace('/<!--.*?-->/s', '', $markup);
            $markup = (string) preg_replace('#/\*.*?\*/#s', '', $markup);

            foreach ($removed as $class) {
                // In a class attribute or a Svelte `class:` directive, not in prose.
                if (preg_match('/class(?::[\w-]+)?\s*=\s*["\x27{][^"\x27}]*\b'
                    . preg_quote($class, '/') . '\b/', $markup)) {
                    $offenders[] = basename($file) . ': ' . $class;
                }
            }
        }

        // Assert
        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * Every block a generated Svelte file opens is closed.
     *
     * A Svelte template is compiled, not parsed leniently: one unclosed `{#if}` is a
     * build error, and in a scaffolded project that is not a broken screen but an
     * application that does not start. These files are edited by hand — by this
     * generator's authors, not by the people who receive them — and the failure surfaces
     * for the first time in somebody else's `npm run build`.
     *
     * Counted per kind rather than in total, so an `{#if}` closed with `{/each}` is
     * caught rather than balancing out.
     */
    public function testEveryGeneratedSvelteTemplateClosesItsBlocks(): void
    {
        // Arrange
        $templates = glob(dirname(__DIR__, 3) . '/scaffolding/templates/*.svelte.stub') ?: [];
        $this->assertNotEmpty($templates, 'no Svelte stubs were found to check');

        // Act
        $offenders = [];
        foreach ($templates as $file) {
            // Only the markup: `{#if` inside the `<script>` block would be a comment or a
            // string, and neither is a block the compiler pairs.
            $contents = (string) file_get_contents($file);
            $markup   = str_contains($contents, '</script>')
                ? substr($contents, strpos($contents, '</script>'))
                : $contents;

            // HTML comments go too. These templates explain themselves in the
            // markup, and a comment that *names* a block — "{#if body} makes the
            // extra field vanish" — is documentation, not a block to pair.
            $markup = (string) preg_replace('/<!--.*?-->/s', '', $markup);

            foreach (['if', 'each', 'await', 'key', 'snippet'] as $kind) {
                $opened = preg_match_all('/\{#' . $kind . '\b/', $markup);
                $closed = preg_match_all('/\{\/' . $kind . '\}/', $markup);
                if ($opened !== $closed) {
                    $offenders[] = sprintf(
                        '%s: %d {#%s} opened, %d {/%s} closed',
                        basename($file),
                        $opened,
                        $kind,
                        $closed,
                        $kind
                    );
                }
            }
        }

        // Assert
        $this->assertSame([], $offenders, implode("\n", $offenders));
    }
}
