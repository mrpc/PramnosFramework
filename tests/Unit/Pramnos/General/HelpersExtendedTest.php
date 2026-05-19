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

    // =========================================================================
    // secondsToTime
    // =========================================================================

    /**
     * secondsToTime() formats durations using DateTime::diff().
     * Four branches based on magnitude: <60s, 60-3600s, 3600-86400s, >86400s.
     */
    public function testSecondsToTimeUnderOneMinute(): void
    {
        // Arrange / Act
        $result = Helpers::secondsToTime(45);

        // Assert — only seconds component
        $this->assertStringContainsString('45 seconds', $result);
    }

    public function testSecondsToTimeBetweenOneMinuteAndOneHour(): void
    {
        // Arrange / Act — 125 seconds = 2 min 5 sec
        $result = Helpers::secondsToTime(125);

        // Assert — contains minutes and seconds
        $this->assertStringContainsString('minutes', $result);
        $this->assertStringContainsString('seconds', $result);
    }

    public function testSecondsToTimeBetweenOneHourAndOneDay(): void
    {
        // Arrange / Act — 7384 seconds = 2h 3m 4s
        $result = Helpers::secondsToTime(7384);

        // Assert — contains hours, minutes, seconds
        $this->assertStringContainsString('hours', $result);
        $this->assertStringContainsString('minutes', $result);
    }

    public function testSecondsToTimeOverOneDay(): void
    {
        // Arrange / Act — 90000 seconds = 1 day + some hours
        $result = Helpers::secondsToTime(90000);

        // Assert — contains "days"
        $this->assertStringContainsString('days', $result);
    }

    // =========================================================================
    // percent
    // =========================================================================

    /**
     * percent() returns null when total is zero (avoids division by zero).
     */
    public function testPercentReturnsNullWhenTotalIsZero(): void
    {
        // Arrange / Act
        $result = Helpers::percent(10, 0);

        // Assert
        $this->assertNull($result);
    }

    /**
     * percent() returns 0 when the amount is zero (regardless of total).
     */
    public function testPercentReturnsZeroWhenAmountIsZero(): void
    {
        // Arrange / Act
        $result = Helpers::percent(0, 100);

        // Assert
        $this->assertSame(0, $result);
    }

    /**
     * percent() calculates and formats the percentage correctly.
     */
    public function testPercentCalculatesCorrectly(): void
    {
        // Arrange / Act — 25 of 200 = 12.5% → formatted as 13 (number_format rounds)
        $result = Helpers::percent(25, 200);

        // Assert — formatted string representation
        $this->assertSame('13', $result);
    }

    // =========================================================================
    // subtractPercent
    // =========================================================================

    /**
     * subtractPercent() returns the amount unchanged when percent is 0.
     */
    public function testSubtractPercentWithZeroPercentReturnsAmountUnchanged(): void
    {
        // Arrange / Act
        $result = Helpers::subtractPercent(100.0, 0);

        // Assert
        $this->assertSame(100.0, $result);
    }

    /**
     * subtractPercent() returns the amount unchanged when amount is 0.
     */
    public function testSubtractPercentWithZeroAmountReturnsZero(): void
    {
        // Arrange / Act
        $result = Helpers::subtractPercent(0.0, 20);

        // Assert
        $this->assertSame(0.0, $result);
    }

    /**
     * subtractPercent() computes: result + (result * percent/100) = original.
     * I.e. it finds the base price before a percentage markup was added.
     */
    public function testSubtractPercentComputesCorrectBaseValue(): void
    {
        // Arrange — 120 with 20% markup applied means base is 100
        $result = Helpers::subtractPercent(120.0, 20);

        // Assert — base value is 100 (within float precision)
        $this->assertEqualsWithDelta(100.0, $result, 0.01);
    }

    // =========================================================================
    // checkUnserialize
    // =========================================================================

    /**
     * checkUnserialize() returns true for a valid serialized string.
     */
    public function testCheckUnserializeReturnsTrueForValidSerializedString(): void
    {
        // Arrange
        $serialized = serialize(['key' => 'value', 'num' => 42]);

        // Act
        $result = Helpers::checkUnserialize($serialized);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * checkUnserialize() returns true for the special serialized false value
     * 'b:0;' — this case must be handled explicitly because unserialize('b:0;')
     * returns false, which would otherwise be misidentified as an error.
     */
    public function testCheckUnserializeReturnsTrueForSerializedFalse(): void
    {
        // Arrange
        $serialized = 'b:0;';

        // Act
        $result = Helpers::checkUnserialize($serialized);

        // Assert — the special 'b:0;' case is treated as valid serialized data
        $this->assertTrue($result);
    }

    /**
     * checkUnserialize() returns false for a plain string that is not serialized.
     */
    public function testCheckUnserializeReturnsFalseForPlainString(): void
    {
        // Arrange
        $plain = 'just a plain string, not serialized';

        // Act
        $result = Helpers::checkUnserialize($plain);

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // isEven
    // =========================================================================

    /**
     * isEven() returns true for even numbers and false for odd numbers.
     */
    public function testIsEvenReturnsTrueForEvenNumbers(): void
    {
        $this->assertTrue(Helpers::isEven(0));
        $this->assertTrue(Helpers::isEven(2));
        $this->assertTrue(Helpers::isEven(100));
    }

    public function testIsEvenReturnsFalseForOddNumbers(): void
    {
        $this->assertFalse(Helpers::isEven(1));
        $this->assertFalse(Helpers::isEven(7));
        $this->assertFalse(Helpers::isEven(99));
    }

    // =========================================================================
    // shortenText
    // =========================================================================

    /**
     * shortenText() returns the text unchanged when it is shorter than the limit.
     */
    public function testShortenTextReturnsFullTextWhenUnderLimit(): void
    {
        // Arrange / Act
        $result = Helpers::shortenText('Short text', 100);

        // Assert
        $this->assertSame('Short text', $result);
    }

    /**
     * shortenText() truncates to the last word boundary and appends the moreText.
     */
    public function testShortenTextTruncatesAtWordBoundary(): void
    {
        // Arrange
        $text = 'The quick brown fox jumps over the lazy dog';

        // Act — limit to 20 chars
        $result = Helpers::shortenText($text, 20);

        // Assert — result is shorter than original and ends with the default ellipsis
        $this->assertLessThan(mb_strlen($text), mb_strlen($result));
        // Default $moreText is '&hellip;' (HTML entity), not the UTF-8 character.
        $this->assertStringContainsString('&hellip;', $result);
    }

    /**
     * shortenText() strips HTML tags before measuring length.
     */
    public function testShortenTextStripsHtmlTags(): void
    {
        // Arrange
        $html = '<p>Hello <strong>world</strong> this is a long paragraph</p>';

        // Act
        $result = Helpers::shortenText($html, 10);

        // Assert — no HTML tags in the output
        $this->assertStringNotContainsString('<p>', $result);
        $this->assertStringNotContainsString('<strong>', $result);
    }

    /**
     * shortenText() throws an Exception when the length argument is not numeric.
     */
    public function testShortenTextThrowsExceptionForNonNumericLength(): void
    {
        // Assert
        $this->expectException(\Exception::class);

        // Act
        Helpers::shortenText('some text', 'not-a-number');
    }

    // =========================================================================
    // getClosestArrayVal
    // =========================================================================

    /**
     * getClosestArrayVal() returns the value in the array closest to the needle.
     */
    public function testGetClosestArrayValReturnsClosestValue(): void
    {
        // Arrange
        $haystack = [10, 20, 30, 40, 50];

        // Act — 23 is closest to 20
        $result = Helpers::getClosestArrayVal(23, $haystack);

        // Assert
        $this->assertSame(20, $result);
    }

    /**
     * getClosestArrayVal() returns the exact value when needle is in the array.
     */
    public function testGetClosestArrayValReturnsExactMatchWhenPresent(): void
    {
        // Arrange
        $haystack = [5, 10, 15, 20];

        // Act
        $result = Helpers::getClosestArrayVal(15, $haystack);

        // Assert
        $this->assertSame(15, $result);
    }

    /**
     * getClosestArrayVal() with a single-element array returns that element.
     */
    public function testGetClosestArrayValWithSingleElementReturnsThatElement(): void
    {
        // Arrange / Act
        $result = Helpers::getClosestArrayVal(999, [42]);

        // Assert
        $this->assertSame(42, $result);
    }

    // =========================================================================
    // varDumpToString
    // =========================================================================

    /**
     * varDumpToString() for a scalar value returns a non-empty string without
     * HTML wrapping (format=false by default).
     */
    public function testVarDumpToStringScalarNoFormat(): void
    {
        // Arrange / Act
        $result = Helpers::varDumpToString(42);

        // Assert — contains the value, no <pre> tag
        $this->assertStringContainsString('42', $result);
        $this->assertStringNotContainsString('<pre>', $result);
    }

    /**
     * varDumpToString() with format=true wraps the output in <pre> tags and
     * HTML-encodes the content.
     */
    public function testVarDumpToStringWithFormatTrue(): void
    {
        // Arrange / Act
        $result = Helpers::varDumpToString('<b>test</b>', true);

        // Assert — wrapped in <pre>, special chars encoded
        $this->assertStringStartsWith('<pre>', $result);
        $this->assertStringEndsWith('</pre>', $result);
        // HTML-encoded angle bracket
        $this->assertStringContainsString('&lt;', $result);
    }

    /**
     * varDumpToString() for an array triggers the safePrintR path and produces
     * an array representation containing the keys and values.
     */
    public function testVarDumpToStringArray(): void
    {
        // Arrange
        $arr = ['foo' => 'bar', 'num' => 7];

        // Act
        $result = Helpers::varDumpToString($arr);

        // Assert — keys appear in the output
        $this->assertStringContainsString('foo', $result);
        $this->assertStringContainsString('bar', $result);
        $this->assertStringContainsString('num', $result);
    }

    /**
     * varDumpToString() for an object uses safePrintR (object branch) and
     * includes the class name and property names in the output.
     */
    public function testVarDumpToStringObject(): void
    {
        // Arrange
        $obj       = new \stdClass();
        $obj->prop = 'value';

        // Act
        $result = Helpers::varDumpToString($obj);

        // Assert — class name and property appear
        $this->assertStringContainsString('stdClass', $result);
        $this->assertStringContainsString('prop', $result);
    }

    /**
     * varDumpToString() with maxDepth=1 triggers the "max depth reached" guard
     * for nested arrays, keeping output bounded.
     */
    public function testVarDumpToStringRespectsMaxDepth(): void
    {
        // Arrange — nested 2 levels deep
        $nested = ['level1' => ['level2' => ['level3' => 'deep']]];

        // Act — depth limit of 1 prevents recursion past level1
        $result = Helpers::varDumpToString($nested, false, 1);

        // Assert — depth-limit message is present
        $this->assertStringContainsString('max depth reached', $result);
    }

    /**
     * varDumpToString() with maxElements=2 truncates arrays beyond the limit
     * and notes how many elements were omitted.
     */
    public function testVarDumpToStringRespectsMaxElements(): void
    {
        // Arrange — 5 elements, limit to 2
        $arr = [1, 2, 3, 4, 5];

        // Act
        $result = Helpers::varDumpToString($arr, false, 3, 2);

        // Assert — "more elements" message present
        $this->assertStringContainsString('more elements', $result);
    }

    // =========================================================================
    // pretty_var_dump
    // =========================================================================

    /**
     * pretty_var_dump() echoes its output wrapped in <pre> tags.
     * Output buffering captures it for assertion.
     */
    public function testPrettyVarDumpEchosPreWrappedOutput(): void
    {
        // Arrange
        ob_start();

        // Act
        Helpers::pretty_var_dump(['key' => 'val']);

        // Assert
        $output = ob_get_clean();
        $this->assertStringContainsString('<pre>', $output);
        $this->assertStringContainsString('</pre>', $output);
    }

    // =========================================================================
    // base64ToUrlSafe / urlSafeToBase64
    // =========================================================================

    /**
     * base64ToUrlSafe() replaces + with -, / with _, and removes = padding.
     */
    public function testBase64ToUrlSafeConvertsSpecialChars(): void
    {
        // Arrange — standard base64 that contains + and / and trailing =
        $standard = base64_encode("\xfb\xff\xfe");  // produces +//+  or similar non-url chars

        // Act
        $urlSafe = Helpers::base64ToUrlSafe($standard);

        // Assert — no + or / or = characters remain
        $this->assertStringNotContainsString('+', $urlSafe);
        $this->assertStringNotContainsString('/', $urlSafe);
        $this->assertStringNotContainsString('=', $urlSafe);
    }

    /**
     * A roundtrip base64ToUrlSafe → urlSafeToBase64 → base64_decode reproduces
     * the original binary string.
     */
    public function testBase64RoundtripPreservesOriginalData(): void
    {
        // Arrange — binary payload that generates + and / in base64
        $original = "Hello+World/Test=Value";

        // Act
        $urlSafe  = Helpers::base64ToUrlSafe(base64_encode($original));
        $restored = base64_decode(Helpers::urlSafeToBase64($urlSafe));

        // Assert
        $this->assertSame($original, $restored);
    }

    /**
     * urlSafeToBase64() adds the correct amount of = padding.
     */
    public function testUrlSafeToBase64AddsPaddingCorrectly(): void
    {
        // Arrange — URL-safe string with known padding need (length % 4 == 3)
        $urlSafe = 'YQ';  // base64 of 'a', needs 2 padding chars

        // Act
        $standard = Helpers::urlSafeToBase64($urlSafe);

        // Assert — padding added and decodes correctly
        $this->assertSame('a', base64_decode($standard));
    }

    // =========================================================================
    // formatBytes
    // =========================================================================

    /**
     * formatBytes() converts byte counts to human-readable strings.
     */
    public function testFormatBytesConvertsUnitsCorrectly(): void
    {
        $this->assertStringContainsString('B',  Helpers::formatBytes(0));
        $this->assertStringContainsString('B',  Helpers::formatBytes(500));
        $this->assertStringContainsString('KB', Helpers::formatBytes(1024));
        $this->assertStringContainsString('MB', Helpers::formatBytes(1024 * 1024));
        $this->assertStringContainsString('GB', Helpers::formatBytes(1024 * 1024 * 1024));
    }

    /**
     * formatBytes() respects the precision parameter.
     */
    public function testFormatBytesRespectsPrecision(): void
    {
        // Arrange / Act — 1536 bytes = 1.5 KB; precision=1
        $result = Helpers::formatBytes(1536, 1);

        // Assert
        $this->assertSame('1.5 KB', $result);
    }

    // =========================================================================
    // bool2string()
    // =========================================================================

    /**
     * bool2string() must return 'true' for boolean true and 'false' for any
     * other value.
     *
     * This covers lines 144-151 of Helpers.php — both branches of the
     * bool2string() method which was previously uncovered.
     */
    public function testBool2StringReturnsTrueStringForTrue(): void
    {
        // Act & Assert — true branch (line 147)
        $this->assertSame('true', Helpers::bool2string(true));

        // Act & Assert — false branch (line 149)
        $this->assertSame('false', Helpers::bool2string(false));
        $this->assertSame('false', Helpers::bool2string(0));
        $this->assertSame('false', Helpers::bool2string(''));
    }

    // =========================================================================
    // percent() / subtractPercent()
    // =========================================================================

    /**
     * percent() must return the percentage of numAmount out of numTotal,
     * NULL when total is 0, and 0 when amount is 0.
     *
     * Covers lines 707-719 of Helpers.php including both early-return branches
     * (total=0, amount=0) and the calculation path.
     */
    public function testPercentReturnsCorrectValue(): void
    {
        // Zero total → NULL (line 710)
        $this->assertNull(Helpers::percent(50, 0));

        // Zero amount → 0 (line 713)
        $this->assertSame(0, Helpers::percent(0, 100));

        // Normal case: 25/100 = 25%
        $this->assertEquals(25, (int) Helpers::percent(25, 100));

        // Over 100%
        $this->assertEquals(150, (int) Helpers::percent(150, 100));
    }

    /**
     * subtractPercent() must return the original amount when either argument is 0,
     * and calculate the correct pre-tax amount otherwise.
     *
     * Covers lines 728-733 of Helpers.php including the zero-guard and the
     * calculation expression.
     */
    public function testSubtractPercentHandlesZeroAndNormalCases(): void
    {
        // Zero amount → return amount unchanged (line 731)
        $this->assertEquals(0, Helpers::subtractPercent(0, 10));

        // Zero percent → return amount unchanged (line 731)
        $this->assertEquals(100, Helpers::subtractPercent(100, 0));

        // Normal: 110 with 10% tax → pre-tax ≈ 100
        $result = Helpers::subtractPercent(110, 10);
        $this->assertEqualsWithDelta(100.0, $result, 0.01, 'subtractPercent(110, 10) must return ~100');
    }

    // =========================================================================
    // getTime()
    // =========================================================================

    /**
     * getTime() with a specific timestamp and positive difference must return
     * the time advanced by the difference value.
     *
     * Covers lines 124-143 of Helpers.php: the getTime() method including the
     * non-null $time path and the difference-added path.
     */
    public function testGetTimeWithTimestampAndDifference(): void
    {
        // Arrange — a fixed Unix timestamp
        $base = mktime(12, 0, 0, 6, 15, 2025);

        // Act — getTime with specific timestamp and zero difference (non-null branch)
        // When difference=0 and Settings::getSetting('timedifference') returns 0,
        // result equals base.  Cast to int for comparison.
        $result = (int) Helpers::getTime($base, 0);
        $this->assertGreaterThan(0, $result, 'getTime() must return a positive timestamp');

        // Act — with a positive difference in hours (1 hour = 3600 seconds added)
        $resultWithDiff = (float) Helpers::getTime($base, 1);
        $this->assertEqualsWithDelta($base + 3600, $resultWithDiff, 1.0,
            'getTime() with difference=1 must add 3600 seconds');
    }

    /**
     * getTime() with null time must use the current time.
     *
     * Covers the null-time branch at line 126 of Helpers.php.
     */
    public function testGetTimeWithNullUsesCurrentTime(): void
    {
        // Act — null time defaults to now
        $before = time();
        $result = (int) Helpers::getTime(null, 0);
        $after  = time() + 3601; // max timedifference offset

        // Assert — result is near the current timestamp
        $this->assertGreaterThanOrEqual($before, $result);
        $this->assertLessThanOrEqual($after, $result);
    }

    // =========================================================================
    // formatMemory — TB branch
    // =========================================================================

    /**
     * formatMemory() must return a TB-suffixed string for values larger than
     * 1 TiB (1024^4 bytes). Covers lines 855-856 in Helpers.php.
     */
    public function testFormatMemoryReturnsTBForLargeValue(): void
    {
        // Arrange — 2 TiB = 2 * 1024^4 bytes
        $twoTiB = 2 * 1024 * 1024 * 1024 * 1024;

        // Act
        $result = Helpers::formatMemory($twoTiB);

        // Assert — must be in TB
        $this->assertStringContainsString('TB', $result);
    }

    // =========================================================================
    // get_user_browser — fallback to $_SERVER['HTTP_USER_AGENT']
    // =========================================================================

    /**
     * get_user_browser() with no argument must fall back to
     * $_SERVER['HTTP_USER_AGENT']. Covers line 207 in Helpers.php.
     */
    public function testGetUserBrowserFallsBackToServerHeader(): void
    {
        // Arrange — inject a Chrome user-agent into the server superglobal
        $original = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible) Chrome/100';

        // Act — no argument → must use $_SERVER['HTTP_USER_AGENT']
        $result = Helpers::get_user_browser();

        // Cleanup
        if ($original === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $original;
        }

        // Assert
        $this->assertSame('chrome', $result);
    }

    // =========================================================================
    // getBrowser — fallback path when get_browser() is unavailable
    // =========================================================================

    /**
     * getBrowser() with an unrecognised agent (get_browser() returns false because
     * browscap.ini is not configured in CI) must return an object with at least the
     * userAgent, browser, and version properties. Covers lines 234-267 in Helpers.php.
     */
    public function testGetBrowserReturnsFallbackObjectWhenBrowscapUnavailable(): void
    {
        // Act — CI typically has no browscap.ini, so get_browser() returns false
        $result = Helpers::getBrowser('Mozilla/5.0 Firefox/90');

        // Assert — always returns an object with the expected shape
        $this->assertIsObject($result);
        $this->assertObjectHasProperty('userAgent', $result);
        $this->assertObjectHasProperty('browser', $result);
        $this->assertObjectHasProperty('version', $result);
        $this->assertSame('Mozilla/5.0 Firefox/90', $result->userAgent);
    }

    // =========================================================================
    // get_user_browser — Flock branch
    // =========================================================================

    /**
     * get_user_browser() must return 'flock' when the user-agent string contains
     * the token 'Flock'. This covers the elseif branch for the Flock browser.
     */
    public function testGetUserBrowserDetectsFlock(): void
    {
        // Arrange — UA string that matches /Flock/i but NOT Chrome/Firefox/Safari/MSIE/Opera.
        // Real Flock UAs include "Firefox" which would match first; use a minimal stub.
        $agent = 'Flock/1.0 (compatible; Gecko)';

        // Act
        $result = Helpers::get_user_browser($agent);

        // Assert — Flock is identified correctly
        $this->assertSame('flock', $result,
            'get_user_browser() must return "flock" for a Flock browser UA string');
    }

    // =========================================================================
    // timepassed — all branches
    // =========================================================================

    /**
     * timepassed() must return a string containing "minutes" for a timestamp
     * less than one hour ago (hours == 0).
     *
     * Language::_() returns the key unchanged when no translation is loaded,
     * so the output contains literal keys like "minutes" and "ago".
     */
    public function testTimepassedReturnsMinutesForRecentTimestamp(): void
    {
        // Arrange — 10 minutes ago
        $date = time() - 600;

        // Act
        $result = Helpers::timepassed($date);

        // Assert — "X minutes ago" branch
        $this->assertStringContainsString('minutes', $result,
            'timepassed() must include "minutes" for a timestamp less than 1 hour ago');
        $this->assertStringContainsString('10', $result);
    }

    /**
     * timepassed() must return "X hours ago" (no minutes component) when the
     * timestamp is an exact multiple of 60 minutes ago (minutes == 0).
     */
    public function testTimepassedReturnsHoursOnlyWhenMinutesAreZero(): void
    {
        // Arrange — exactly 2 hours ago (no leftover minutes)
        $date = time() - (2 * 3600);

        // Act
        $result = Helpers::timepassed($date);

        // Assert — "X hours ago" branch (minutes == 0)
        $this->assertStringContainsString('hours', $result,
            'timepassed() must include "hours" for a timestamp exactly N hours ago');
        $this->assertStringContainsString('2', $result);
    }

    /**
     * timepassed() must return "X hours and Y minutes ago" when there are both
     * hours and leftover minutes but no days.
     */
    public function testTimepassedReturnsHoursAndMinutesForMixedDuration(): void
    {
        // Arrange — 2 hours and 30 minutes ago
        $date = time() - (2 * 3600 + 30 * 60);

        // Act
        $result = Helpers::timepassed($date);

        // Assert — "X hours and Y minutes ago" branch
        $this->assertStringContainsString('hours', $result,
            'timepassed() must include "hours" for a 2.5-hour-old timestamp');
        $this->assertStringContainsString('minutes', $result,
            'timepassed() must include "minutes" for a 2.5-hour-old timestamp');
    }

    /**
     * timepassed() must return "Yesterday" for a timestamp exactly one day ago.
     * This covers the special-case branch for days == 1.
     */
    public function testTimepassedReturnsYesterdayForOneDayAgo(): void
    {
        // Arrange — exactly 24 hours ago
        $date = time() - 86400;

        // Act
        $result = Helpers::timepassed($date);

        // Assert — "Yesterday" branch
        $this->assertStringContainsString('Yesterday', $result,
            'timepassed() must return "Yesterday" for a timestamp exactly 24 hours ago');
    }

    /**
     * timepassed() must return "X days ago" for a timestamp a few days in the
     * past (months == 0).
     */
    public function testTimepassedReturnsDaysForShortPast(): void
    {
        // Arrange — exactly 5 days ago
        $date = time() - (5 * 86400);

        // Act
        $result = Helpers::timepassed($date);

        // Assert — "X days ago" branch (years == 0 and months == 0)
        $this->assertStringContainsString('days', $result,
            'timepassed() must include "days" for a 5-day-old timestamp');
        $this->assertStringContainsString('5', $result);
    }

    /**
     * timepassed() must return "One month and X days ago" when exactly one month
     * (30 days) plus a few days have elapsed.
     */
    public function testTimepassedReturnsOneMonthAndDays(): void
    {
        // Arrange — 35 days ago → 1 month and 5 days
        $date = time() - (35 * 86400);

        // Act
        $result = Helpers::timepassed($date);

        // Assert — "One month and X days" branch (months == 1, days != 0)
        $this->assertStringContainsString('month', $result,
            'timepassed() must include "month" for a ~35-day-old timestamp');
    }

    /**
     * timepassed() must return "X months and Y days ago" when more than one month
     * plus leftover days have elapsed.
     */
    public function testTimepassedReturnsMonthsAndDaysForLongerPast(): void
    {
        // Arrange — 65 days ago → 2 months and 5 days
        $date = time() - (65 * 86400);

        // Act
        $result = Helpers::timepassed($date);

        // Assert — "X months and Y days ago" branch (months > 1, days != 0)
        $this->assertStringContainsString('months', $result,
            'timepassed() must include "months" for a ~65-day-old timestamp');
        $this->assertStringContainsString('days', $result,
            'timepassed() must include "days" for a ~65-day-old timestamp');
    }

    /**
     * timepassed() must return "X months ago" when the elapsed time is a round
     * multiple of 30 days (days == 0 after month subtraction).
     */
    public function testTimepassedReturnsMonthsOnlyWhenNoDaysRemainder(): void
    {
        // Arrange — exactly 90 days ago → 3 months, 0 leftover days
        $date = time() - (90 * 86400);

        // Act
        $result = Helpers::timepassed($date);

        // Assert — "X months ago" branch (years == 0, days == 0 after subtraction)
        $this->assertStringContainsString('months', $result,
            'timepassed() must include "months" for a 90-day-old timestamp');
    }

    /**
     * timepassed() must return "X years ago" when the elapsed time is a round
     * multiple of 12 months (months == 0 after year subtraction).
     */
    public function testTimepassedReturnsYearsOnlyWhenNoMonthsRemainder(): void
    {
        // Arrange — exactly 720 days (≈ 24 months = 2 years with 0 leftover months)
        $date = time() - (720 * 86400);

        // Act
        $result = Helpers::timepassed($date);

        // Assert — "X years ago" branch (months == 0 after subtraction)
        $this->assertStringContainsString('years', $result,
            'timepassed() must include "years" for a ~720-day-old timestamp');
    }

    /**
     * timepassed() must return "X year and Y months ago" when exactly 1 year
     * plus leftover months have elapsed.
     */
    public function testTimepassedReturnsOneYearAndMonths(): void
    {
        // Arrange — 390 days → 13 months → 1 year and 1 month
        $date = time() - (390 * 86400);

        // Act
        $result = Helpers::timepassed($date);

        // Assert — "X year and Y months ago" branch (years == 1, months != 0)
        $this->assertStringContainsString('year', $result,
            'timepassed() must include "year" for a ~390-day-old timestamp');
    }

    /**
     * timepassed() must return "X years and Y months ago" when more than one year
     * plus leftover months have elapsed.
     */
    public function testTimepassedReturnsMultipleYearsAndMonths(): void
    {
        // Arrange — 750 days → 25 months → 2 years and 1 month
        $date = time() - (750 * 86400);

        // Act
        $result = Helpers::timepassed($date);

        // Assert — "X years and Y months ago" branch (years > 1, months != 0)
        $this->assertStringContainsString('years', $result,
            'timepassed() must include "years" for a ~750-day-old timestamp');
    }

    // =========================================================================
    // objectDiff — non-scalar property branches
    // =========================================================================

    /**
     * objectDiff() must handle changed properties whose values are arrays
     * and include a description without embedding the raw array content.
     *
     * This covers the is_array($value) branch in the 'changed' section.
     */
    public function testObjectDiffChangedArrayPropertyProducesDescription(): void
    {
        // Arrange — 'tags' property changed from one array to another
        $first  = (object)['tags' => ['php', 'mysql']];
        $second = (object)['tags' => ['php', 'postgres']];

        // Act
        $diff = Helpers::objectDiff($first, $second);

        // Assert — 'tags' is in 'changed'; description must mention the property name
        $this->assertArrayHasKey('tags', $diff['changed'],
            'objectDiff() must detect a changed array-valued property');
        $this->assertStringContainsString('"tags"', $diff['description'],
            'objectDiff() must include the property name in the description for changed arrays');
    }

    /**
     * objectDiff() must handle removed properties whose values are arrays
     * without embedding the raw array in the description string.
     *
     * This covers the is_array($value) branch in the 'removed' section.
     */
    public function testObjectDiffRemovedArrayPropertyProducesDescription(): void
    {
        // Arrange — 'metadata' array-valued property is in first but not second
        $first  = (object)['name' => 'Alice', 'metadata' => ['role' => 'admin']];
        $second = (object)['name' => 'Alice'];

        // Act
        $diff = Helpers::objectDiff($first, $second);

        // Assert — 'metadata' in 'removed', description mentions property
        $this->assertArrayHasKey('metadata', $diff['removed'],
            'objectDiff() must detect a removed array-valued property');
        $this->assertStringContainsString('"metadata"', $diff['description'],
            'objectDiff() must include the property name in the description for removed arrays');
    }

    /**
     * objectDiff() must handle added properties whose values are arrays
     * without embedding the raw array in the description string.
     *
     * This covers the is_array($value) branch in the 'added' section.
     */
    public function testObjectDiffAddedArrayPropertyProducesDescription(): void
    {
        // Arrange — 'permissions' array-valued property is in second but not first
        $first  = (object)['name' => 'Bob'];
        $second = (object)['name' => 'Bob', 'permissions' => ['read', 'write']];

        // Act
        $diff = Helpers::objectDiff($first, $second);

        // Assert — 'permissions' in 'added', description mentions property
        $this->assertArrayHasKey('permissions', $diff['added'],
            'objectDiff() must detect an added array-valued property');
        $this->assertStringContainsString('"permissions"', $diff['description'],
            'objectDiff() must include the property name in the description for added arrays');
    }

    // =========================================================================
    // parseMemoryLimit — private method via reflection
    // =========================================================================

    /**
     * parseMemoryLimit() must return -1 when given the string '-1' (no memory limit).
     * This covers the early-return branch in the private static method.
     */
    public function testParseMemoryLimitReturnsMinusOneForUnlimited(): void
    {
        // Arrange — access the private static method via reflection
        $ref = new \ReflectionMethod(Helpers::class, 'parseMemoryLimit');
        $ref->setAccessible(true);

        // Act
        $result = $ref->invoke(null, '-1');

        // Assert — -1 means unlimited
        $this->assertSame(-1, $result,
            'parseMemoryLimit() must return -1 for the "-1" (no limit) memory_limit value');
    }

    /**
     * parseMemoryLimit() must correctly convert kilobyte-suffixed memory limits
     * (e.g. '128K') to bytes by multiplying by 1024.
     */
    public function testParseMemoryLimitConvertsKilobytes(): void
    {
        // Arrange
        $ref = new \ReflectionMethod(Helpers::class, 'parseMemoryLimit');
        $ref->setAccessible(true);

        // Act
        $result = $ref->invoke(null, '128K');

        // Assert — 128 * 1024 = 131072
        $this->assertSame(131072, $result,
            'parseMemoryLimit() must convert "128K" to 131072 bytes');
    }

    /**
     * parseMemoryLimit() must correctly convert gigabyte-suffixed memory limits
     * (e.g. '2G') to bytes by multiplying by 1024^3.
     */
    public function testParseMemoryLimitConvertsGigabytes(): void
    {
        // Arrange
        $ref = new \ReflectionMethod(Helpers::class, 'parseMemoryLimit');
        $ref->setAccessible(true);

        // Act
        $result = $ref->invoke(null, '2G');

        // Assert — 2 * 1024^3 = 2147483648
        $this->assertSame(2 * 1024 * 1024 * 1024, $result,
            'parseMemoryLimit() must convert "2G" to 2^31 bytes');
    }
}
