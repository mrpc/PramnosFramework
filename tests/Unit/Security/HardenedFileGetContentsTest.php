<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\General\Helpers;

/**
 * `Helpers::fileGetContents()` goes through the outbound checks.
 *
 * WHAT: the wrapper refuses what `OutboundUrl` refuses, and keeps the return
 *       shape its callers were written against.
 * WHY:  it was a general HTTP helper with `CURLOPT_SSL_VERIFYPEER => false`, so
 *       no certificate was ever checked; it followed ten redirects to wherever
 *       the far end pointed; it restricted no scheme, so `file:///etc/passwd`
 *       and everything else compiled into curl was reachable; and it capped the
 *       body nowhere. It had no caller inside the framework, which made it the
 *       obvious choice for the next person who wanted «download this URL» —
 *       shipped alongside `OutboundUrl`, which does all of it properly.
 *
 * The refusals themselves — the scheme allow-list, private and reserved
 * addresses, the byte ceiling, redirects into this network — are
 * `OutboundUrl`'s and are covered by `OutboundUrlTest` and
 * `OutboundFetchTest`. What is tested here is that this method reaches them,
 * which is the thing that was missing.
 *
 * None of these make a network request: every one is refused before a socket
 * is opened.
 */
#[CoversClass(Helpers::class)]
class HardenedFileGetContentsTest extends TestCase
{
    /**
     * Addresses and schemes that must not be fetchable through this method.
     *
     * @param string $url    what a caller might be talked into passing
     * @param string $why    what it would have reached before
     */
    #[DataProvider('refusedUrls')]
    public function testItRefusesWhatTheOutboundChecksRefuse(string $url, string $why): void
    {
        // Act
        $result = Helpers::fileGetContents($url);

        // Assert
        $this->assertFalse($result, $why);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedUrls(): array
    {
        return [
            'local file' => [
                'file:///etc/passwd',
                'file:// was reachable: no scheme was restricted',
            ],
            'loopback' => [
                'http://127.0.0.1/',
                'anything listening on loopback was reachable — an admin panel, a database',
            ],
            'loopback by name' => [
                'http://localhost/',
                'the same thing by name rather than by address',
            ],
            'ipv6 loopback' => [
                'http://[::1]/',
                'a host with only a private AAAA record passes an A-only check',
            ],
            'cloud metadata' => [
                'http://169.254.169.254/latest/meta-data/',
                'the link-local metadata service is the classic SSRF target',
            ],
            'private range' => [
                'http://10.0.0.1/',
                'another service on the same subnet was reachable',
            ],
        ];
    }

    /**
     * The array return keeps its two keys, so a caller that reads them still can.
     *
     * `info` is no longer `curl_getinfo()` output — there is no curl handle to
     * ask — so it carries the four values a caller of this method plausibly
     * wanted from it. Asserted on a refusal, because that is the shape most
     * likely to be read and least likely to have been exercised.
     */
    public function testTheArrayReturnKeepsItsShape(): void
    {
        // Act
        $result = Helpers::fileGetContents('http://127.0.0.1/', false, true);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('info', $result);
        $this->assertFalse($result['content'], 'a refused fetch has no body');
        foreach (['url', 'http_code', 'size_download', 'error'] as $key) {
            $this->assertArrayHasKey($key, $result['info'], "info must carry {$key}");
        }
        $this->assertSame('http://127.0.0.1/', $result['info']['url']);
        $this->assertSame(0, $result['info']['http_code'], 'nothing was fetched');
        $this->assertSame(0, $result['info']['size_download']);
        $this->assertNotSame('', (string) $result['info']['error'],
            'the reason it was refused is the useful part');
    }

    /**
     * `$fakeRef` no longer changes anything, including whether a URL is refused.
     *
     * The parameter is kept so the signature does not change. Its purpose was a
     * Google referer and a Firefox user agent — hotlink-protection evasion — and
     * re-adding header injection to a hardened fetch path to preserve that is
     * not a trade worth making. What must not happen is a second code path
     * where it is honoured and the checks are not.
     */
    public function testFakeRefNoLongerSelectsADifferentPath(): void
    {
        // Act
        $without = Helpers::fileGetContents('http://169.254.169.254/', false, false, false);
        $with    = Helpers::fileGetContents('http://169.254.169.254/', false, false, true);

        // Assert
        $this->assertFalse($without);
        $this->assertFalse($with, 'fakeRef must not be a way around the address check');
    }

    /**
     * `$debug` echoes the URL and why it was refused.
     *
     * It echoed the URL and `curl_error()` before; the reason is the equivalent,
     * and is the only thing that tells a developer whether their URL was
     * rejected for its scheme, its address or its size.
     */
    public function testDebugEchoesTheUrlAndTheReason(): void
    {
        // Act
        ob_start();
        Helpers::fileGetContents('http://127.0.0.1/probe', true);
        $output = (string) ob_get_clean();

        // Assert
        $this->assertStringContainsString('http://127.0.0.1/probe', $output);
        $this->assertNotSame('', trim(strip_tags(str_replace('<br />', ' ', $output))));
        // The refusal has to be named, not just the URL echoed back.
        $this->assertMatchesRegularExpression(
            '/(address|private|refuse|not|scheme)/i',
            $output,
            'debug output must say why, not only what'
        );
    }

    /**
     * The URL is escaped before it is echoed.
     *
     * `$debug` writes into a page, and the URL is by definition attacker-shaped
     * — it is the parameter a caller was talked into passing.
     */
    public function testDebugEscapesTheUrlItEchoes(): void
    {
        // Act
        ob_start();
        Helpers::fileGetContents('http://127.0.0.1/"><script>alert(1)</script>', true);
        $output = (string) ob_get_clean();

        // Assert
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    /**
     * The vulnerable curl options are gone from the source.
     *
     * A source-level assertion on purpose, and the only kind available for this:
     * «TLS verification is off» cannot be observed from a refused fetch, and
     * proving it from a successful one needs a server with a bad certificate.
     * What can be pinned is that the option is not in the file — which is what
     * would come back if somebody restored the curl path for an environment
     * without `allow_url_fopen`.
     */
    public function testTheDisabledTlsVerificationIsNotInTheSource(): void
    {
        // Arrange — code only. The docblock quotes the old option to explain what
        // changed, and a scan that read comments would match that and pass for the
        // wrong reason, or fail for it.
        $code = '';
        foreach (token_get_all((string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/General/Helpers.php'
        )) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        // Assert — the option, in any spacing
        $this->assertDoesNotMatchRegularExpression(
            '/CURLOPT_SSL_VERIFYPEER\s*=>\s*(false|0)\b/',
            $code,
            'a fetch helper must not disable certificate verification'
        );
        $this->assertStringNotContainsString(
            'CURLOPT_FOLLOWLOCATION',
            $code,
            'redirects are followed by OutboundUrl, which re-checks each hop'
        );
    }

    /**
     * The limits the wrapper imposes are the ones it documents.
     *
     * They are constants because the method has to choose them on the caller's
     * behalf — which is also why the docblock says to call `OutboundUrl::fetch()`
     * directly and choose them yourself.
     */
    public function testTheLimitsAreDeclaredAndSane(): void
    {
        // Assert
        $this->assertSame(10485760, Helpers::REMOTE_FETCH_MAX_BYTES, '10 MiB');
        $this->assertSame(10, Helpers::REMOTE_FETCH_TIMEOUT);
        $this->assertSame(10, Helpers::REMOTE_FETCH_MAX_REDIRECTS);
        $this->assertGreaterThan(0, Helpers::REMOTE_FETCH_MAX_BYTES,
            'an unbounded body is what this replaced');
    }
}
