<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\General;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\General\Helpers;

/**
 * Unit tests for Helpers methods not covered by GeneralCharacterizationTest.
 *
 * All tests are pure-logic with no DB, network, or persistent global state.
 * Methods that require Language::getInstance() (timepassed, objectDiff) are
 * covered only for their structural output, not translated strings.
 */
#[CoversClass(Helpers::class)]
class HelpersExtendedTest extends TestCase
{
    // =========================================================================
    // generatePassword
    // =========================================================================

    /**
     * generatePassword() always returns a non-empty string of exactly $length
     * characters: ($injectpos chars from md5) + (1 special char) +
     * ($length - 1 - $injectpos chars from md5 tail).
     */
    public function testGeneratePasswordReturnsNonEmptyString(): void
    {
        // Arrange / Act
        $password = Helpers::generatePassword();

        // Assert – always produces a string
        $this->assertIsString($password);
        $this->assertNotEmpty($password);
    }

    /**
     * generatePassword() returns exactly $length characters.
     * Formula: $injectpos chars + 1 special + ($length - 1 - $injectpos) chars = $length.
     */
    public function testGeneratePasswordReturnsExactLength(): void
    {
        // Arrange / Act / Assert – length argument now controls total output length
        $this->assertSame(8,  strlen(Helpers::generatePassword(8)));
        $this->assertSame(12, strlen(Helpers::generatePassword(12)));
        $this->assertSame(16, strlen(Helpers::generatePassword(16)));
    }

    /**
     * generatePassword() always contains exactly one special character from
     * "!@#$%^&*()_+-=[]{}|~".
     */
    public function testGeneratePasswordContainsSpecialChar(): void
    {
        // Arrange
        $specialChars = '!@#$%^&*()_+-=[]{}|~';

        // Act – run a few times to avoid lucky random passes
        for ($i = 0; $i < 10; $i++) {
            $password = Helpers::generatePassword(10);

            // Assert
            $found = false;
            for ($j = 0; $j < strlen($specialChars); $j++) {
                if (strpos($password, $specialChars[$j]) !== false) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Password '$password' has no special character");
        }
    }

    // =========================================================================
    // get_user_browser
    // =========================================================================

    /** @return array<string,array{string,string}> */
    public static function userAgentProvider(): array
    {
        return [
            'Chrome'  => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/114.0', 'chrome'],
            'Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0', 'firefox'],
            'Safari'  => ['Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Version/16.0 Safari/605.1.15', 'safari'],
            'Opera'   => ['Opera/9.80 (Windows NT 6.1) Presto/2.12.388', 'opera'],
            'MSIE'    => ['Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1)', 'ie'],
            'Unknown' => ['SomeRandomCrawlerBot/1.0', ''],
        ];
    }

    /**
     * get_user_browser() identifies the browser from a UA string.
     * Chrome, Firefox, Safari, Opera, IE are all detected; unknown returns ''.
     *
     * @param string $agent    User-agent string
     * @param string $expected Expected browser identifier
     */
    #[DataProvider('userAgentProvider')]
    public function testGetUserBrowser(string $agent, string $expected): void
    {
        // Arrange / Act
        $result = Helpers::get_user_browser($agent);

        // Assert
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // fixFilesArray
    // =========================================================================

    /**
     * fixFilesArray() converts the PHP multi-file upload format
     * from ['key']['index'] to ['index']['key'], which is the natural
     * format for iterating uploaded files.
     */
    public function testFixFilesArrayRestructuresMultiUpload(): void
    {
        // Arrange – PHP's odd multi-file format
        $files = [
            'name'     => ['file0.txt', 'file1.txt'],
            'type'     => ['text/plain', 'text/plain'],
            'tmp_name' => ['/tmp/phpA', '/tmp/phpB'],
            'error'    => [0, 0],
            'size'     => [100, 200],
        ];

        // Act
        Helpers::fixFilesArray($files);

        // Assert – now indexed by position, each containing all keys
        $this->assertArrayHasKey(0, $files);
        $this->assertArrayHasKey(1, $files);
        $this->assertSame('file0.txt', $files[0]['name']);
        $this->assertSame('file1.txt', $files[1]['name']);
        $this->assertSame('/tmp/phpA', $files[0]['tmp_name']);
        $this->assertSame(100, $files[0]['size']);
        // Original top-level keys must be gone
        $this->assertArrayNotHasKey('name', $files);
        $this->assertArrayNotHasKey('tmp_name', $files);
    }

    /**
     * fixFilesArray() leaves a single-file array (scalar values) unchanged
     * because only array values trigger restructuring.
     */
    public function testFixFilesArrayIgnoresScalarValues(): void
    {
        // Arrange – standard single-file upload, values are scalars not arrays
        $files = [
            'name'     => 'single.txt',
            'type'     => 'text/plain',
            'tmp_name' => '/tmp/phpC',
            'error'    => 0,
            'size'     => 50,
        ];
        $original = $files;

        // Act
        Helpers::fixFilesArray($files);

        // Assert – unchanged
        $this->assertSame($original, $files);
    }

    // =========================================================================
    // greeklish (url-friendly mode)
    // =========================================================================

    /**
     * greeklish() with urlFriendly=true replaces spaces with '-' and
     * removes punctuation characters.
     */
    public function testGreeklishUrlFriendlyConvertsSpacesToDashes(): void
    {
        // Arrange
        $input = 'αβγ δεζ';

        // Act
        $result = Helpers::greeklish($input, true);

        // Assert – space → '-'
        $this->assertStringContainsString('-', $result);
        $this->assertStringNotContainsString(' ', $result);
    }

    /**
     * greeklish() with urlFriendly=true produces output with only ASCII-safe
     * characters (no Greek Unicode remaining).
     */
    public function testGreeklishUrlFriendlyProducesAscii(): void
    {
        // Arrange
        $input = 'Ελληνικό κείμενο';

        // Act
        $result = Helpers::greeklish($input, true);

        // Assert – result is pure ASCII
        $this->assertMatchesRegularExpression('/^[\x00-\x7F]*$/', $result);
    }

    // =========================================================================
    // clearhtml
    // =========================================================================

    /**
     * clearhtml() strips HTML tags and returns plain text.
     * The old implementation used the '/e' modifier (removed in PHP 7), which
     * made preg_replace() return null on PHP 8+.  The fix replaces the last
     * pattern with preg_replace_callback() + mb_chr() for numeric HTML entities.
     */
    public function testClearhtmlStripsHtmlTags(): void
    {
        // Arrange
        $html = '<p>Hello <strong>World</strong></p>';

        // Act
        $result = Helpers::clearhtml($html);

        // Assert — HTML tags removed, text preserved
        $this->assertIsString($result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('World', $result);
        $this->assertStringNotContainsString('<p>', $result);
        $this->assertStringNotContainsString('<strong>', $result);
    }

    /**
     * clearhtml() converts numeric HTML entities (&#NNN;) to their UTF-8
     * characters using mb_chr(), not the removed PHP 7+ /e modifier.
     * Uses &#9733; (★ U+2605) which is not in the named-entity list,
     * so it reaches the preg_replace_callback path.
     */
    public function testClearhtmlConvertsNumericEntities(): void
    {
        // Arrange — &#9733; is the BLACK STAR character ★ (U+2605)
        $html = '&#9733;';

        // Act
        $result = Helpers::clearhtml($html);

        // Assert — numeric entity decoded to actual UTF-8 character
        $this->assertSame('★', $result);
    }

    // =========================================================================
    // formatMemory
    // =========================================================================

    /** @return array<string,array{int,string}> */
    public static function formatMemoryProvider(): array
    {
        return [
            'bytes'     => [512,                      '512.00 Bytes'],
            'kilobytes' => [2048,                     '2.00KB'],
            'megabytes' => [1024 * 1024 * 3,          '3.00MB'],
            'gigabytes' => [1024 * 1024 * 1024 * 2,  '2.00GB'],
        ];
    }

    /**
     * formatMemory() converts a byte count to a human-readable string with
     * the appropriate unit (Bytes, KB, MB, GB).
     *
     * @param int    $memory   Input in bytes
     * @param string $expected Expected formatted string
     */
    #[DataProvider('formatMemoryProvider')]
    public function testFormatMemory(int $memory, string $expected): void
    {
        // Arrange / Act
        $result = Helpers::formatMemory($memory);

        // Assert
        $this->assertSame($expected, $result);
    }

    /**
     * formatMemory() returns false for non-numeric input.
     */
    public function testFormatMemoryReturnsFalseForNonNumeric(): void
    {
        // Arrange / Act / Assert
        $this->assertFalse(Helpers::formatMemory('not-a-number'));
    }

    // =========================================================================
    // greekStrToUpper
    // =========================================================================

    /**
     * greekStrToUpper() uppercases the string and removes Greek accent marks
     * (e.g. Ά→Α, Έ→Ε, Ή→Η, Ί→Ι, Ό→Ο, Ύ→Υ, Ώ→Ω).
     */
    public function testGreekStrToUpperRemovesAccents(): void
    {
        // Arrange
        $input = 'άέήίόύώ'; // All lowercase accented Greek vowels

        // Act
        $result = Helpers::greekStrToUpper($input);

        // Assert – uppercased and accent-free
        $this->assertSame('ΑΕΗΙΟΥΩ', $result);
    }

    /**
     * greekStrToUpper() handles ASCII input without altering it unexpectedly.
     */
    public function testGreekStrToUpperHandlesAscii(): void
    {
        // Arrange / Act
        $result = Helpers::greekStrToUpper('hello world');

        // Assert
        $this->assertSame('HELLO WORLD', $result);
    }

    // =========================================================================
    // optimizeTime
    // =========================================================================

    /**
     * optimizeTime() floors a timestamp to the nearest $round-minute boundary.
     * E.g. with round=5 and a timestamp 2 minutes past a boundary, the result
     * is exactly 5 minutes before the next boundary.
     */
    public function testOptimizeTimeRoundsDownToMinuteBoundary(): void
    {
        // Arrange – a known timestamp: 2023-01-01 00:04:30 UTC = 1672531470
        $timestamp = 1672531470; // 4 min 30 sec into the hour

        // Act – round to 1-minute boundaries (default)
        $result = Helpers::optimizeTime($timestamp, 1);

        // Assert – result must be divisible by 60 and ≤ original
        $this->assertSame(0, $result % 60);
        $this->assertLessThanOrEqual($timestamp, $result);
    }

    /**
     * optimizeTime() with round=5 floors to the nearest 5-minute mark.
     */
    public function testOptimizeTimeWith5MinuteRound(): void
    {
        // Arrange – 00:07:00 UTC (7 minutes) → should round to 00:05:00
        $timestamp = mktime(0, 7, 0, 1, 1, 2023);

        // Act
        $result = Helpers::optimizeTime($timestamp, 5);

        // Assert – result divisible by 300 (5 * 60)
        $this->assertSame(0, $result % 300);
        $this->assertLessThanOrEqual($timestamp, $result);
    }

    /**
     * optimizeTime() with no arguments uses current time and returns an int.
     */
    public function testOptimizeTimeDefaultUsesCurrentTime(): void
    {
        // Arrange – capture now
        $before = time();

        // Act
        $result = Helpers::optimizeTime();

        // Assert – result is a valid past-or-equal timestamp
        $this->assertIsInt($result);
        $this->assertLessThanOrEqual($before, $result);
    }

    // =========================================================================
    // sortArrayoOfObjects
    // =========================================================================

    /**
     * sortArrayoOfObjects() sorts an array of objects by a given property
     * in ascending order by default.
     */
    public function testSortArrayOfObjectsAscending(): void
    {
        // Arrange
        $a = (object)['name' => 'Charlie', 'score' => 30];
        $b = (object)['name' => 'Alice',   'score' => 10];
        $c = (object)['name' => 'Bob',     'score' => 20];
        $array = [$a, $b, $c];

        // Act
        Helpers::sortArrayoOfObjects($array, 'score');

        // Assert – sorted ascending by score
        $this->assertSame(10, $array[0]->score);
        $this->assertSame(20, $array[1]->score);
        $this->assertSame(30, $array[2]->score);
    }

    /**
     * sortArrayoOfObjects() with order='desc' reverses the sort.
     */
    public function testSortArrayOfObjectsDescending(): void
    {
        // Arrange
        $a = (object)['score' => 10];
        $b = (object)['score' => 30];
        $c = (object)['score' => 20];
        $array = [$a, $b, $c];

        // Act
        Helpers::sortArrayoOfObjects($array, 'score', 'desc');

        // Assert – sorted descending
        $this->assertSame(30, $array[0]->score);
        $this->assertSame(20, $array[1]->score);
        $this->assertSame(10, $array[2]->score);
    }

    /**
     * sortArrayoOfObjects() throws an Exception when passed a non-array.
     */
    public function testSortArrayOfObjectsThrowsOnNonArray(): void
    {
        // Arrange
        $notAnArray = 'string';

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('sortArrayOfObjects expected an array');

        // Act
        Helpers::sortArrayoOfObjects($notAnArray, 'score');
    }

    /**
     * sortArrayoOfObjects() on an empty array returns true immediately.
     */
    public function testSortArrayOfObjectsEmptyArrayReturnsTrue(): void
    {
        // Arrange
        $array = [];

        // Act
        $result = Helpers::sortArrayoOfObjects($array, 'score');

        // Assert
        $this->assertTrue($result);
    }

    // =========================================================================
    // objectDiff
    // =========================================================================

    /**
     * objectDiff() detects changed property values and records them under
     * the 'changed' key with 'original' and 'new' sub-keys.
     *
     * The 'description' key is a string (content may vary by language config).
     */
    public function testObjectDiffDetectsChangedProperties(): void
    {
        // Arrange
        $first  = (object)['name' => 'Alice', 'score' => 10];
        $second = (object)['name' => 'Alice', 'score' => 20];

        // Act
        $diff = Helpers::objectDiff($first, $second);

        // Assert – 'changed' contains 'score'
        $this->assertArrayHasKey('score', $diff['changed']);
        $this->assertSame(10, $diff['changed']['score']['original']);
        $this->assertSame(20, $diff['changed']['score']['new']);
        // Unchanged 'name' must not appear in changed
        $this->assertArrayNotHasKey('name', $diff['changed']);
        $this->assertIsString($diff['description']);
    }

    /**
     * objectDiff() records properties present only in the second object
     * under the 'added' key.
     */
    public function testObjectDiffDetectsAddedProperties(): void
    {
        // Arrange
        $first  = (object)['name' => 'Alice'];
        $second = (object)['name' => 'Alice', 'email' => 'a@b.com'];

        // Act
        $diff = Helpers::objectDiff($first, $second);

        // Assert
        $this->assertArrayHasKey('email', $diff['added']);
        $this->assertSame('a@b.com', $diff['added']['email']);
        $this->assertEmpty($diff['removed']);
    }

    /**
     * objectDiff() records properties missing from the second object
     * under the 'removed' key.
     */
    public function testObjectDiffDetectsRemovedProperties(): void
    {
        // Arrange
        $first  = (object)['name' => 'Alice', 'role' => 'admin'];
        $second = (object)['name' => 'Alice'];

        // Act
        $diff = Helpers::objectDiff($first, $second);

        // Assert
        $this->assertArrayHasKey('role', $diff['removed']);
        $this->assertEmpty($diff['added']);
    }

    /**
     * objectDiff() returns empty added/removed/changed when both objects
     * are identical.
     */
    public function testObjectDiffIdenticalObjectsReturnsEmpty(): void
    {
        // Arrange
        $first  = (object)['name' => 'Alice', 'score' => 10];
        $second = (object)['name' => 'Alice', 'score' => 10];

        // Act
        $diff = Helpers::objectDiff($first, $second);

        // Assert
        $this->assertEmpty($diff['added']);
        $this->assertEmpty($diff['removed']);
        $this->assertEmpty($diff['changed']);
        $this->assertSame('', $diff['description']);
    }

    // =========================================================================
    // isValidCoordinate
    // =========================================================================

    /** @return array<string,array{mixed,mixed,bool}> */
    public static function coordinateProvider(): array
    {
        return [
            'valid Athens'        => [37.9838, 23.7275, true],
            'valid poles'         => [90.0, 0.0, true],
            'null island (0,0)'   => [0.0, 0.0, false],  // sentinel for "no GPS fix"
            'lat out of range'    => [91.0, 45.0, false],
            'lon out of range'    => [45.0, 181.0, false],
            'non-numeric lat'     => ['abc', 23.0, false],
            'non-numeric lon'     => [37.0, 'xyz', false],
            'string numbers'      => ['37.9', '23.7', true],  // numeric strings accepted
            'negative valid'      => [-33.87, 151.21, true],  // Sydney
        ];
    }

    /**
     * isValidCoordinate() returns true only for valid lat/lon pairs.
     * The (0,0) null-island sentinel is treated as invalid.
     *
     * @param mixed $lat      Latitude input
     * @param mixed $lon      Longitude input
     * @param bool  $expected Expected result
     */
    #[DataProvider('coordinateProvider')]
    public function testIsValidCoordinate(mixed $lat, mixed $lon, bool $expected): void
    {
        // Arrange / Act
        $result = Helpers::isValidCoordinate($lat, $lon);

        // Assert
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // validateIpOrCidr
    // =========================================================================

    /** @return array<string,array{string,bool}> */
    public static function ipCidrProvider(): array
    {
        return [
            'valid IPv4'            => ['192.168.1.1',    true],
            'valid IPv6'            => ['2001:db8::1',    true],
            'valid IPv4 CIDR /24'   => ['10.0.0.0/24',   true],
            'valid IPv4 CIDR /0'    => ['0.0.0.0/0',     true],
            'valid IPv4 CIDR /32'   => ['192.168.1.1/32', true],
            'valid IPv6 CIDR /64'   => ['2001:db8::/64',  true],
            'invalid IP'            => ['999.999.999.999', false],
            'empty string'          => ['',               false],
            'text string'           => ['not-an-ip',      false],
            'CIDR prefix too large' => ['192.168.1.0/33', false],
            'IPv6 CIDR prefix /129' => ['2001:db8::/129', false],
        ];
    }

    /**
     * validateIpOrCidr() accepts valid IPv4/IPv6 addresses and CIDR ranges
     * and rejects invalid inputs.
     *
     * @param string $ip       Input string
     * @param bool   $expected Expected validity
     */
    #[DataProvider('ipCidrProvider')]
    public function testValidateIpOrCidr(string $ip, bool $expected): void
    {
        // Arrange / Act
        $result = Helpers::validateIpOrCidr($ip);

        // Assert
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // greekdate
    // =========================================================================

    /** @return array<string,array{int,int,string}> */
    public static function greekdateProvider(): array
    {
        return [
            'January active'    => [1,  0, 'Ιανουάριος'],
            'February active'   => [2,  0, 'Φεβρουάριος'],
            'March active'      => [3,  0, 'Μάρτιος'],
            'April active'      => [4,  0, 'Απρίλιος'],
            'September active'  => [9,  0, 'Σεπτέμβρης'],
            'October active'    => [10, 0, 'Οκτώβρης'],
            'November active'   => [11, 0, 'Νοέμβρης'],
            'December active'   => [12, 0, 'Δεκέμβρης'],
            'January passive'   => [1,  1, 'Ιανουαρίου'],
            'May passive'       => [5,  1, 'Μαΐου'],
            'September passive' => [9,  1, 'Σεπτεμβρίου'],
            'October passive'   => [10, 1, 'Οκτωβρίου'],
            'November passive'  => [11, 1, 'Νοεμβρίου'],
            'December passive'  => [12, 1, 'Δεκεμβρίου'],
        ];
    }

    /**
     * greekdate() returns the Greek month name in active (form=0) or
     * passive (form=1) grammatical form for all 12 months.
     *
     * The old str_replace() implementation was broken for months 10-12 because
     * integer needles were cast to strings, causing '1' to match inside '10','11','12'.
     * Now fixed with direct array lookup: $monthnames[(int)$month - 1].
     *
     * @param int    $month    Month number (1-12)
     * @param int    $form     0 = active, 1 = passive
     * @param string $expected Expected Greek month name
     */
    #[DataProvider('greekdateProvider')]
    public function testGreekdate(int $month, int $form, string $expected): void
    {
        // Arrange / Act
        $result = Helpers::greekdate($month, $form);

        // Assert
        $this->assertSame($expected, $result);
    }
}
