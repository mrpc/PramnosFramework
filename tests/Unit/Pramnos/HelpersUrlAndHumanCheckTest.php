<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos;

use PHPUnit\Framework\TestCase;

/**
 * The two helpers whose output every page depends on, and neither was executed.
 *
 * `getUrl()` is what `sURL` and `URL` are defined from at bootstrap, so it decides every absolute
 * URL the application ever writes — every link, every asset, every redirect, every URL in every
 * mail. Its uncovered branches were the ones that decide the *scheme* and the *port*, which is
 * exactly where getting it wrong is invisible in development and total in production.
 *
 * `humanCheckField()` renders the hidden inputs for the proof-of-work check on the public forms.
 * Its uncovered half was the CSP nonce, and the failure mode there is the worst kind: without the
 * nonce a strict policy drops the script silently, no solution is ever computed, and the check
 * then refuses **every** submission — a lockout that looks exactly like the check working.
 */
class HelpersUrlAndHumanCheckTest extends TestCase
{
    private array $savedServer = [];

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
    }

    // ── getUrl() ──────────────────────────────────────────────────────────────

    /**
     * Plain HTTP gives `http://`, and HTTPS gives `https://`.
     *
     * `$_SERVER['HTTPS']` is `'on'` on a TLS request and absent or empty otherwise — and on some
     * servers it is the string `'off'`, which is why the check is against `'on'` rather than for
     * emptiness alone.
     */
    public function testTheSchemeFollowsTheRequest(): void
    {
        // Arrange & Act & Assert
        $_SERVER = ['SERVER_NAME' => 'example.test', 'SCRIPT_NAME' => '/index.php'];
        $this->assertStringStartsWith('http://', getUrl(), 'a plain request produced https');

        $_SERVER['HTTPS'] = 'on';
        $this->assertStringStartsWith('https://', getUrl());

        $_SERVER['HTTPS'] = 'off';
        $this->assertStringStartsWith(
            'http://',
            getUrl(),
            "a literal 'off' was read as a TLS request"
        );
    }

    /**
     * A TLS-terminating proxy in front is believed, via `X-Forwarded-Proto`.
     *
     * The configuration almost every deployment has: TLS ends at the load balancer and the
     * application is reached over plain HTTP. Without this branch every absolute URL the site
     * writes is `http://` — which browsers then block as mixed content on an `https://` page, so
     * the stylesheet, the script and the form action all fail at once and the page looks broken
     * rather than misconfigured.
     */
    public function testATlsTerminatingProxyIsBelieved(): void
    {
        // Arrange
        $_SERVER = [
            'SERVER_NAME'           => 'example.test',
            'SCRIPT_NAME'           => '/index.php',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        // Act & Assert
        $this->assertStringStartsWith('https://', getUrl(), 'the proxy header was ignored');
    }

    /**
     * The standard ports are left out and a non-standard one is kept.
     *
     * `https://example.test:443/` is legal and wrong to write: it appears in canonical URLs, in
     * `Referer` headers and in OAuth redirect-URI comparisons, which are string comparisons — so
     * a redundant `:443` is a redirect URI that no longer matches the one the client registered.
     * A development port must survive for the opposite reason: without it every link on
     * `localhost:8080` points at port 80.
     */
    public function testTheStandardPortsAreOmittedAndOthersKept(): void
    {
        // Arrange
        $base = ['SERVER_NAME' => 'example.test', 'SCRIPT_NAME' => '/index.php'];

        // Act & Assert
        $_SERVER = $base + ['SERVER_PORT' => '80'];
        $this->assertStringNotContainsString(':80', getUrl());

        $_SERVER = $base + ['SERVER_PORT' => '443', 'HTTPS' => 'on'];
        $this->assertStringNotContainsString(':443', getUrl());

        $_SERVER = $base + ['SERVER_PORT' => '8080'];
        $this->assertStringContainsString(':8080', getUrl(), 'a development port was dropped');

        // And with no port at all — a CLI request, or a server that does not set it.
        $_SERVER = $base;
        $this->assertStringNotContainsString(':', substr(getUrl(), 8), 'a port appeared from nowhere');
    }

    /**
     * With no server name there is no URL, rather than a URL with a hole in it.
     *
     * A CLI request has no `SERVER_NAME`. `http:///assets/style.css` is what the alternative
     * produces — a string that looks like a URL, resolves to nothing, and is written into mail
     * sent from a queue worker.
     */
    public function testWithNoServerNameThereIsNoUrl(): void
    {
        // Arrange
        $_SERVER = ['SCRIPT_NAME' => '/bin/pramnos'];

        // Act & Assert
        $this->assertSame('/', getUrl(), 'a URL was invented without a host to put in it');
    }

    /**
     * It always ends in a slash, because everything concatenates onto it.
     *
     * `sURL . 'login'` is how the whole framework builds a link. One missing slash turns every
     * one of them into `https://example.testlogin`.
     */
    public function testItAlwaysEndsInASlash(): void
    {
        // Act & Assert
        foreach (['/index.php', '/sub/index.php', '/sub/'] as $script) {
            $_SERVER = ['SERVER_NAME' => 'example.test', 'SCRIPT_NAME' => $script];

            $this->assertStringEndsWith('/', getUrl(), 'no trailing slash for ' . $script);
        }
    }

    // ── humanCheckField() ─────────────────────────────────────────────────────

    /**
     * With no challenge the field renders nothing at all.
     *
     * The reason the same line is safe on every form: a screen calls it unconditionally, and an
     * installation that has not switched the check on for that form gets an empty string rather
     * than a broken widget or a `null` in the markup.
     */
    public function testWithNoChallengeNothingIsRendered(): void
    {
        // Act & Assert
        $this->assertSame('', humanCheckField(null));
        $this->assertSame('', humanCheckField([]));
        $this->assertSame('', humanCheckField(['challenge' => '']));
    }

    /**
     * A challenge renders both fields the endpoint reads, and the token is escaped.
     *
     * `human_challenge` carries the token and `human_solution` is where the script writes what it
     * computed. The verification requires both, so a field missing either is a form nobody can
     * submit — and the token goes into an HTML attribute, where an unescaped quote would end it.
     */
    public function testAChallengeRendersBothFieldsWithTheTokenEscaped(): void
    {
        // Arrange
        $challenge = [
            'challenge'  => 'abc"><script>alert(1)</script>',
            'difficulty' => 4,
            'expires'    => 1771061400,
        ];

        // Act
        $html = humanCheckField($challenge);

        // Assert
        $this->assertStringContainsString('name="human_challenge"', $html);
        $this->assertStringContainsString('name="human_solution"', $html);
        $this->assertStringNotContainsString(
            'value="abc"',
            $html,
            'the token broke out of its attribute'
        );
        $this->assertStringContainsString('&quot;', $html, 'the token was not escaped');

        /*
         * And it cannot close the script element either.
         *
         * `</script>` ends a script tag wherever it appears in one: HTML looks for those
         * characters and does not know it is inside a JavaScript string literal, so quoting is no
         * defence. The JSON is encoded with `JSON_HEX_TAG` for that reason. Unreachable with the
         * real token — `hex.int.int.hmac` has no `<` in it — and this is a global helper a view
         * may call with an array of its own.
         */
        $inline = substr($html, (int) strpos($html, '<script'));
        $this->assertSame(
            2,
            substr_count($inline, '</script>'),
            'a token containing </script> closed the script element early'
        );
    }

    /**
     * The two `<script>` tags carry the CSP nonce when there is one.
     *
     * The branch this file exists for. A project with a strict `Content-Security-Policy` drops an
     * un-nonced inline script **silently** — no error, no console message the operator sees — so
     * no solution is ever computed and the check refuses every submission. That is a public form
     * nobody can send, presenting as the check doing its job.
     *
     * Both tags need it: the inline one that hands the challenge to the form, and the `src` one
     * that loads the worker.
     */
    public function testBothScriptsCarryTheNonceWhenThereIsOne(): void
    {
        // Arrange
        $application = \Pramnos\Application\Application::getInstance();
        $saved       = $application->cspNonce ?? null;
        $application->cspNonce = 'nonce-value-123';

        try {
            // Act
            $html = humanCheckField(['challenge' => 'token', 'difficulty' => 4, 'expires' => 1]);

            // Assert
            $this->assertSame(
                2,
                substr_count($html, 'nonce="nonce-value-123"'),
                'one of the two scripts is missing the nonce, so a strict policy drops it'
            );
            $this->assertSame(2, substr_count($html, '<script'), 'the tag count changed');
        } finally {
            $application->cspNonce = $saved;
        }
    }

    /** With no nonce configured, no empty attribute is emitted. */
    public function testWithNoNonceNoAttributeIsEmitted(): void
    {
        // Arrange
        $application = \Pramnos\Application\Application::getInstance();
        $saved       = $application->cspNonce ?? null;
        $application->cspNonce = '';

        try {
            // Act
            $html = humanCheckField(['challenge' => 'token', 'difficulty' => 4, 'expires' => 1]);

            // Assert
            $this->assertStringNotContainsString('nonce=', $html);
            $this->assertStringContainsString('<script>', $html);
        } finally {
            $application->cspNonce = $saved;
        }
    }

    /**
     * The field id is derived from the token, so two checks on one page do not collide.
     *
     * The script finds its own hidden input by id. Two forms with the check on the same page —
     * a sign-in and a registration beside it — would otherwise both write into the first one,
     * and the second would submit an empty solution.
     */
    public function testTheFieldIdIsDerivedFromTheToken(): void
    {
        // Act
        $first  = humanCheckField(['challenge' => 'token-one', 'difficulty' => 4, 'expires' => 1]);
        $second = humanCheckField(['challenge' => 'token-two', 'difficulty' => 4, 'expires' => 1]);

        // Assert
        preg_match('~id="(pf-hc-[a-f0-9]+)"~', $first, $one);
        preg_match('~id="(pf-hc-[a-f0-9]+)"~', $second, $two);

        $this->assertNotEmpty($one[1] ?? '', 'the field has no id for the script to find');
        $this->assertNotSame(
            $one[1] ?? '',
            $two[1] ?? '',
            'two challenges share an id, so two forms on a page would collide'
        );
    }

    /** The whole challenge travels to the browser, since the worker needs its difficulty. */
    public function testTheChallengeTravelsWithItsDifficulty(): void
    {
        // Act
        $html = humanCheckField(['challenge' => 'token', 'difficulty' => 4, 'expires' => 99]);

        // Assert
        $this->assertStringContainsString('data-pf-humancheck', $html);
        $this->assertStringContainsString('difficulty', $html, 'the worker is not told how hard');
        $this->assertStringContainsString('pf-humancheck.js', $html, 'the worker is not loaded');
    }
}
