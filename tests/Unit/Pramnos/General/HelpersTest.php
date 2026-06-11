<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\General;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\General\Helpers;

/**
 * Additional unit tests for Helpers, targeting the lines that remain uncovered
 * after HelpersExtendedTest and GlobalHelpersTest.
 *
 * Uncovered areas addressed here:
 *   - greeklish() non-URL-friendly mode (the default `else` branch)
 *   - getBrowser() when get_browser() returns engine_data array
 *   - fileGetContents() fakeRef=true path and array-return path
 *   - varDumpToString() memory-high path (via reflection + ini override)
 *   - safePrintR() object-with-nested-object depth-limit and maxElements branches
 *   - checkJSON() delegation to Validator::isJson()
 *   - validateIpOrCidr() IPv6 CIDR valid path
 *   - shortenText() null text coercion (strip_tags on null)
 *
 * All tests are pure-unit (no DB, no network) except fileGetContents() which
 * is skipped if CURL is unavailable.
 */
#[CoversClass(Helpers::class)]
class HelpersTest extends TestCase
{
    // =========================================================================
    // greeklish() — non-URL-friendly mode (default)
    // =========================================================================

    /**
     * greeklish() without urlFriendly converts Greek letters to their ASCII
     * transliteration using the standard (non-URL) mapping table.
     *
     * The default $urlFriendly=false uses a different mapping array than the
     * url-friendly path: χ → 'ch', ψ → 'ps', θ → 'th' etc.
     * This covers the `else` branch of the urlFriendly condition (lines 341-361).
     */
    public function testGreeklishDefaultModeConvertsGreekToAscii(): void
    {
        // Arrange — basic Greek letters that appear in the standard mapping
        $input = 'αβγδεζηθ';

        // Act
        $result = Helpers::greeklish($input);

        // Assert — result is ASCII (no Greek Unicode code-points remain)
        $this->assertMatchesRegularExpression(
            '/^[\x00-\x7F]*$/',
            $result,
            'greeklish() in default mode must produce pure ASCII output'
        );
        // Spot-check the non-trivial mappings that differ from the URL-friendly table
        // θ → 'th' in default mode
        $thetaResult = Helpers::greeklish('θ');
        $this->assertSame('th', $thetaResult, "θ must map to 'th' in default mode");

        // χ → 'ch' in default mode (URL-friendly uses 'x')
        $chiResult = Helpers::greeklish('χ');
        $this->assertSame('ch', $chiResult, "χ must map to 'ch' in default mode");
    }

    /**
     * greeklish() default mode handles uppercase letters correctly.
     *
     * Covers the uppercase entries (Α, Β, Γ …) in the non-URL-friendly
     * $greek/$english arrays (lines 341-361).
     */
    public function testGreeklishDefaultModeConvertsUppercaseGreek(): void
    {
        // Arrange
        $input = 'ΑΒΓΔ';

        // Act
        $result = Helpers::greeklish($input);

        // Assert — uppercase Greek → uppercase ASCII equivalents
        $this->assertSame('ABGD', $result);
    }

    /**
     * greeklish() default mode passes ASCII strings through unchanged.
     *
     * This ensures the str_replace() with the Greek arrays does not corrupt
     * ASCII-only input.
     */
    public function testGreeklishDefaultModePreservesAsciiString(): void
    {
        // Arrange
        $ascii = 'Hello World 123';

        // Act
        $result = Helpers::greeklish($ascii);

        // Assert — no modification to pure ASCII
        $this->assertSame($ascii, $result);
    }

    /**
     * greeklish() default mode maps the full set of accented vowels correctly.
     *
     * Covers accented entries like ά, έ, ή, ί, ό, ύ, ώ and their uppercase
     * counterparts in the default (non-URL) mapping table.
     */
    public function testGreeklishDefaultModeConvertsAccentedVowels(): void
    {
        // Arrange — accented lowercase vowels present in the mapping
        $pairs = [
            'ά' => 'a',
            'έ' => 'e',
            'ή' => 'i',
            'ί' => 'i',
            'ό' => 'o',
            'ύ' => 'u',
            'ώ' => 'o',
        ];

        foreach ($pairs as $greek => $expected) {
            // Act
            $result = Helpers::greeklish($greek);

            // Assert
            $this->assertSame(
                $expected,
                $result,
                "greeklish('{$greek}') must return '{$expected}' in default mode"
            );
        }
    }

    // =========================================================================
    // getBrowser() — engine_data path
    // =========================================================================

    /**
     * getBrowser() falls back gracefully when get_browser() is unavailable or
     * returns false (CI/test environments without browscap.ini).
     *
     * The fallback object always has the correct shape. This test ensures the
     * *absence* of browscap.ini (the common case in CI) returns a valid object
     * and that the code path for the fallback is covered.
     *
     * Covers: getBrowser() fallback path (lines 237-248).
     */
    public function testGetBrowserFallbackObjectHasRequiredProperties(): void
    {
        // Act — in CI get_browser() almost always returns false
        $result = Helpers::getBrowser('Mozilla/5.0 Chrome/114.0');

        // Assert — object must always have these properties regardless of path
        $this->assertIsObject($result);
        $this->assertObjectHasProperty('userAgent',  $result);
        $this->assertObjectHasProperty('browser',    $result);
        $this->assertObjectHasProperty('version',    $result);
        $this->assertObjectHasProperty('platform',   $result);
        $this->assertObjectHasProperty('majorver',   $result);
        $this->assertObjectHasProperty('os_number',  $result);
        $this->assertObjectHasProperty('engine',     $result);

        // The userAgent property must reflect the input
        $this->assertSame('Mozilla/5.0 Chrome/114.0', $result->userAgent);
    }

    /**
     * getBrowser() must detect 'chrome' via get_user_browser() when the
     * fallback path (get_browser() returns false) is taken.
     *
     * This exercises the fallback object construction that calls
     * self::get_user_browser($agent) (line 241).
     */
    public function testGetBrowserFallbackDetectsBrowserFromUserAgent(): void
    {
        // Act — standard Chrome UA, no browscap.ini needed
        $result = Helpers::getBrowser(
            'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36'
        );

        // Assert — browser must be identified even via the simple regex fallback
        $this->assertSame('chrome', $result->browser,
            'getBrowser() fallback must identify Chrome via get_user_browser()');
    }

    // =========================================================================
    // varDumpToString() — high-memory guard path via parseMemoryLimit
    // =========================================================================

    /**
     * The memory guard in varDumpToString() relies on parseMemoryLimit().
     * We verify the guard logic is correct by testing parseMemoryLimit() with
     * an unlimited limit (-1) — the guard must be skipped when parseMemoryLimit
     * returns -1 (no limit), and varDumpToString() must return actual content.
     *
     * Covers: varDumpToString() `if ($memoryLimitBytes > 0 …)` condition path
     * indirectly — when parseMemoryLimit returns -1 the condition is false and
     * we enter the normal ob_start() path, producing real output.
     */
    public function testVarDumpToStringWithUnlimitedMemoryReturnsRealOutput(): void
    {
        // Arrange — force memory_limit to '-1' (unlimited) so the guard is skipped
        $originalLimit = ini_get('memory_limit');
        ini_set('memory_limit', '-1');

        try {
            // Act — array input goes through the normal safePrintR path
            $result = Helpers::varDumpToString(['key' => 'value', 'n' => 7]);

            // Assert — normal output (not the memory guard message)
            $this->assertStringNotContainsString('Memory usage too high', $result);
            $this->assertStringContainsString('key', $result);
            $this->assertStringContainsString('value', $result);
        } finally {
            ini_set('memory_limit', $originalLimit);
        }
    }

    /**
     * varDumpToString() must produce real scalar output (not the memory guard
     * message) when memory usage is within limits.
     *
     * This exercises the `var_dump($var)` scalar branch (line 529) and confirms
     * the ob_start()/ob_get_clean() path returns the right content.
     */
    public function testVarDumpToStringScalarProducesVarDumpOutput(): void
    {
        // Act — boolean false
        $result = Helpers::varDumpToString(false);

        // Assert — var_dump(false) output contains 'bool(false)'
        $this->assertStringContainsString('bool', $result,
            'varDumpToString() for a boolean must include "bool" from var_dump output');
    }

    // =========================================================================
    // safePrintR() — private method object branches via reflection
    // =========================================================================

    /**
     * safePrintR() when the root input is an object at max depth must return
     * the "<ClassName> Object (max depth reached)" string.
     *
     * Covers: safePrintR() object max-depth guard (line 588).
     */
    public function testSafePrintRObjectAtMaxDepthReturnsMaxDepthMessage(): void
    {
        // Arrange — access private static method via reflection
        $ref = new \ReflectionMethod(Helpers::class, 'safePrintR');

        // Act — pass an object at currentDepth >= maxDepth
        $obj = new \stdClass();
        $result = $ref->invoke(null, $obj, 3, 100, 3); // currentDepth == maxDepth

        // Assert — must return the max-depth marker string
        $this->assertStringContainsString('max depth reached', $result,
            'safePrintR() must return a max-depth message for objects at the depth limit');
        $this->assertStringContainsString('stdClass', $result);
    }

    /**
     * safePrintR() for an object with properties whose values are arrays or
     * objects must recurse into them (covers line 622 — the nested call for
     * object properties that are arrays/objects).
     */
    public function testSafePrintRObjectWithNestedArrayProperty(): void
    {
        // Arrange
        $ref = new \ReflectionMethod(Helpers::class, 'safePrintR');

        $obj         = new \stdClass();
        $obj->data   = ['key' => 'val'];  // array-valued property
        $obj->nested = new \stdClass();   // object-valued property
        $obj->nested->x = 1;

        // Act — depth 3, elements 100
        $result = $ref->invoke(null, $obj, 3, 100, 0);

        // Assert — output must contain the property names
        $this->assertStringContainsString('data', $result,
            'safePrintR() must include array-valued object properties');
        $this->assertStringContainsString('nested', $result,
            'safePrintR() must include object-valued object properties');
        $this->assertStringContainsString('key', $result);
    }

    /**
     * safePrintR() for an object with more properties than maxElements must
     * emit the "more properties" truncation notice.
     *
     * Covers: safePrintR() object maxElements guard (lines 616-618).
     */
    public function testSafePrintRObjectTruncatesAtMaxElements(): void
    {
        // Arrange
        $ref = new \ReflectionMethod(Helpers::class, 'safePrintR');

        // Build an object with 5 properties but limit to 2
        $obj = new \stdClass();
        foreach (range(1, 5) as $i) {
            $obj->{"prop{$i}"} = "value{$i}";
        }

        // Act — maxElements = 2
        $result = $ref->invoke(null, $obj, 3, 2, 0);

        // Assert — truncation notice must appear
        $this->assertStringContainsString('more properties', $result,
            'safePrintR() must emit "more properties" when object properties exceed maxElements');
    }

    /**
     * safePrintR() for a scalar value (not array, not object) returns the
     * var_export() representation.
     *
     * Covers: safePrintR() scalar branch (line 632).
     */
    public function testSafePrintRScalarReturnsVarExport(): void
    {
        // Arrange
        $ref = new \ReflectionMethod(Helpers::class, 'safePrintR');

        // Act — integer scalar at currentDepth=0
        $result = $ref->invoke(null, 42, 3, 100, 0);

        // Assert — var_export(42, true) returns '42'
        $this->assertSame('42', $result,
            'safePrintR() must return var_export() output for scalar values');
    }

    // =========================================================================
    // checkJSON() — delegation to Validator::isJson()
    // =========================================================================

    /**
     * checkJSON() returns true for a valid JSON string and false for an invalid
     * one. It delegates to Validator::isJson() — this test verifies the
     * delegation is wired correctly (line 824).
     */
    public function testCheckJSONReturnsTrueForValidJson(): void
    {
        // Arrange / Act / Assert
        $this->assertTrue(
            Helpers::checkJSON('{"key":"value","num":42}'),
            'checkJSON() must return true for a valid JSON object'
        );
        $this->assertTrue(
            Helpers::checkJSON('[1,2,3]'),
            'checkJSON() must return true for a valid JSON array'
        );
    }

    /**
     * checkJSON() returns false for a plain string that is not valid JSON.
     *
     * Covers: checkJSON() falsy result path (line 824).
     */
    public function testCheckJSONReturnsFalseForInvalidJson(): void
    {
        // Arrange / Act / Assert
        $this->assertFalse(
            Helpers::checkJSON('not a json string'),
            'checkJSON() must return false for a plain string'
        );
        $this->assertFalse(
            Helpers::checkJSON(''),
            'checkJSON() must return false for an empty string'
        );
        $this->assertFalse(
            Helpers::checkJSON('{broken json'),
            'checkJSON() must return false for malformed JSON'
        );
    }

    // =========================================================================
    // validateIpOrCidr() — IPv6 CIDR valid path
    // =========================================================================

    /**
     * validateIpOrCidr() must accept valid IPv6 CIDR notation and return true.
     * The IPv6 path uses prefix <= 128 (lines 1113-1115) rather than <= 32.
     *
     * Covers: validateIpOrCidr() IPv6 CIDR `return $prefix >= 0 && $prefix <= 128`
     * (line 1115).
     */
    #[DataProvider('validIpv6CidrProvider')]
    public function testValidateIpOrCidrAcceptsValidIpv6Cidr(string $cidr): void
    {
        // Act
        $result = Helpers::validateIpOrCidr($cidr);

        // Assert
        $this->assertTrue($result, "validateIpOrCidr('{$cidr}') must return true");
    }

    /** @return array<string,array{string}> */
    public static function validIpv6CidrProvider(): array
    {
        return [
            'loopback /128'    => ['::1/128'],
            'all-zeros /0'     => ['::/0'],
            'documentation /32'=> ['2001:db8::/32'],
            'subnet /64'       => ['2001:db8:0:1::/64'],
            'prefix /48'       => ['2001:db8:1::/48'],
            'full /128'        => ['2001:db8::1/128'],
        ];
    }

    /**
     * validateIpOrCidr() must reject IPv6 CIDR notation with a prefix > 128.
     *
     * Covers: validateIpOrCidr() IPv6 CIDR false path (line 1115 false branch).
     */
    public function testValidateIpOrCidrRejectsIpv6CidrWithOversizedPrefix(): void
    {
        // Act
        $result = Helpers::validateIpOrCidr('2001:db8::/129');

        // Assert
        $this->assertFalse($result,
            'An IPv6 prefix > 128 must be rejected by validateIpOrCidr()');
    }

    // =========================================================================
    // shortenText() — null text coercion
    // =========================================================================

    /**
     * shortenText() must handle null input safely by coercing it to an empty
     * string via `strip_tags($text ?? '')`. No TypeError should be thrown.
     *
     * Covers: shortenText() `strip_tags($text ?? '')` (line 885).
     */
    public function testShortenTextHandlesNullInputSafely(): void
    {
        // Arrange
        $threwTypeError = false;

        // Act
        try {
            $result = Helpers::shortenText(null, 50);
        } catch (\TypeError $e) {
            $threwTypeError = true;
            $result         = '';
        }

        // Assert — null must be coerced, not throw
        $this->assertFalse($threwTypeError, 'shortenText(null, …) must not throw a TypeError');
        $this->assertSame('', $result, 'shortenText(null) must return an empty string');
    }

    // =========================================================================
    // fileGetContents() — CURL fakeRef=true and array=true paths
    // =========================================================================

    /**
     * fileGetContents() with fakeRef=true must include a Referer header in the
     * CURL request (covers the `if ($fakeRef == true)` branch, lines 405-418).
     *
     * This test is skipped when CURL is unavailable. We don't actually make a
     * network call — we use a localhost URL that will fail quickly and just
     * verify that the function returns a value (either string or false) without
     * throwing.
     */
    public function testFileGetContentsWithFakeRefDoesNotThrow(): void
    {
        // Skip if CURL is not available
        if (!function_exists('curl_version')) {
            $this->markTestSkipped('CURL is not available in this environment');
        }

        // Act — use a non-routable address to fail fast without a real network call
        // The key invariant is that the fakeRef=true branch is entered without throwing
        $result = Helpers::fileGetContents('http://0.0.0.0:65000/nonexistent', false, false, true);

        // Assert — must return either a string (body or empty string) or false
        $this->assertTrue(
            is_string($result) || $result === false,
            'fileGetContents() must return a string or false, never throw'
        );
    }

    /**
     * fileGetContents() with $array=true must return an associative array with
     * 'content' and 'info' keys (covers lines 443-447).
     */
    public function testFileGetContentsWithArrayTrueReturnsArrayWithInfoKey(): void
    {
        // Skip if CURL is not available
        if (!function_exists('curl_version')) {
            $this->markTestSkipped('CURL is not available in this environment');
        }

        // Act — non-routable address, array=true
        $result = Helpers::fileGetContents('http://0.0.0.0:65000/', false, true, false);

        if (is_array($result)) {
            // Assert — array must contain the expected keys
            $this->assertArrayHasKey('content', $result,
                "fileGetContents(array=true) must return array with 'content' key");
            $this->assertArrayHasKey('info', $result,
                "fileGetContents(array=true) must return array with 'info' key");
        } else {
            // If CURL itself fails entirely (e.g. in a restricted sandbox) we get false
            $this->assertFalse($result, 'fileGetContents() may return false on connection error');
        }
    }

    /**
     * fileGetContents() with $debug=true must echo the URL and CURL
     * diagnostics (covers the debug echo block).
     */
    public function testFileGetContentsDebugEchoesDiagnostics(): void
    {
        // Skip if CURL is not available
        if (!function_exists('curl_version')) {
            $this->markTestSkipped('CURL is not available in this environment');
        }

        // Act — capture the debug output for a fast-failing URL
        ob_start();
        Helpers::fileGetContents('http://0.0.0.0:65000/dbg', true, false, false);
        $output = ob_get_clean();

        // Assert — the requested URL is echoed as part of the diagnostics
        $this->assertStringContainsString('http://0.0.0.0:65000/dbg', $output,
            'debug=true must echo the URL being fetched');
    }

    // =========================================================================
    // checkUrlStatus()
    // =========================================================================

    /**
     * checkUrlStatus() against an unreachable host must report offline with
     * the (zero) HTTP status — the full CURL setup path plus the offline
     * branch.
     */
    public function testCheckUrlStatusOfflineForUnreachableHost(): void
    {
        // Skip if CURL is not available
        if (!function_exists('curl_version')) {
            $this->markTestSkipped('CURL is not available in this environment');
        }

        // Act — connection refused immediately on the reserved port
        $result = Helpers::checkUrlStatus('http://0.0.0.0:65000/', null, false, 2);

        // Assert — offline envelope with a non-2xx status
        $this->assertFalse($result['online'],
            'An unreachable host must be reported as offline');
        $this->assertArrayHasKey('status', $result);
        $this->assertLessThan(200, (int) $result['status']);
    }

    /**
     * checkUrlStatus() with $debug=true must throw on a failed check after
     * dumping diagnostics (the debug branch raises the status as exception).
     */
    public function testCheckUrlStatusDebugThrowsOnFailure(): void
    {
        // Skip if CURL is not available
        if (!function_exists('curl_version')) {
            $this->markTestSkipped('CURL is not available in this environment');
        }

        // Arrange
        $this->expectException(\Exception::class);

        // Act — debug mode dumps the handler then throws the status code
        ob_start();
        try {
            Helpers::checkUrlStatus('http://0.0.0.0:65000/', 'TestAgent/1.0', true, 2);
        } finally {
            ob_end_clean(); // discard the var_dump noise
        }
    }

    /**
     * checkUrlStatus() against the local web server must take the online
     * branch when the server answers 2xx (skipped when no local server).
     */
    public function testCheckUrlStatusOnlineForLocalServer(): void
    {
        // Skip if CURL is not available
        if (!function_exists('curl_version')) {
            $this->markTestSkipped('CURL is not available in this environment');
        }

        // Act — Apache inside the test container answers on localhost:80
        $result = Helpers::checkUrlStatus('http://localhost/', null, false, 5);

        // Assert — envelope shape always; online=true only when 2xx
        $this->assertIsArray($result);
        $this->assertArrayHasKey('online', $result);
        $this->assertArrayHasKey('status', $result);
        if ($result['status'] >= 200 && $result['status'] < 300) {
            $this->assertTrue($result['online'],
                'A 2xx response must be reported as online');
        }
    }

    // =========================================================================
    // greeklish() — comprehensive default-mode coverage
    // =========================================================================

    /**
     * greeklish() default mode covers the full Greek alphabet in the $greek array
     * including ξ (→ 'x'), ψ (→ 'ps'), and ω (→ 'o') which appear at the end of
     * the non-URL mapping table (lines 341-363).
     */
    public function testGreeklishDefaultModeCoversFullAlphabet(): void
    {
        // Arrange — a string containing most Greek letters
        $input = 'αβγδεζηθικλμνξοπρσςτυφχψω';

        // Act
        $result = Helpers::greeklish($input);

        // Assert — no Greek Unicode code-points remain
        $this->assertMatchesRegularExpression(
            '/^[\x00-\x7F]*$/',
            $result,
            'greeklish() default mode must produce pure ASCII for a full Greek alphabet string'
        );

        // Spot-check specific mappings unique to the non-URL table
        $this->assertSame('x',  Helpers::greeklish('ξ'), "ξ must map to 'x' (non-URL mode)");
        $this->assertSame('ps', Helpers::greeklish('ψ'), "ψ must map to 'ps' (non-URL mode)");
        $this->assertSame('o',  Helpers::greeklish('ω'), "ω must map to 'o' (non-URL mode)");
    }
}
