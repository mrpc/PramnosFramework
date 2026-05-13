<?php

declare(strict_types=1);

namespace Tests\Unit\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\User\Token;

/**
 * Unit tests for Pramnos\User\Token.
 *
 * Token is an ORM-style model that wraps a database row.  Most of its methods
 * require a live database connection — those are covered by the integration and
 * characterization test suites.
 *
 * The tests here focus on the pure-logic surface:
 *
 *   - __construct(null)  → object created with default empty values
 *   - __construct(array) → properties filled via fillProperties()
 *   - fillProperties()   → deviceinfo format normalisation (serialize / JSON / other)
 *   - getData()          → pure property serialisation to array
 *   - getDetails()       → returns an empty-sentinel array when tokenid = 0
 *   - getStatistics()    → returns a zero-sentinel array when tokenid = 0
 *   - getActions()       → returns an empty-sentinel array when tokenid = 0
 */
#[CoversClass(Token::class)]
class TokenTest extends TestCase
{
    // =========================================================================
    // Constructor — null argument
    // =========================================================================

    /**
     * The no-argument constructor creates a Token with all defaults.
     * _isnew is true because no existing record was loaded.
     */
    public function testDefaultConstructorSetsDefaultValues(): void
    {
        // Arrange / Act
        $token = new Token();

        // Assert — default field values
        $this->assertSame(0, $token->tokenid);
        $this->assertSame('', $token->token);
        $this->assertSame('', $token->tokentype);
        $this->assertSame(0, $token->status);
        $this->assertSame([], $token->deviceinfo);
        $this->assertSame([], $token->scope);
    }

    // =========================================================================
    // Constructor — array argument → fillProperties()
    // =========================================================================

    /**
     * When an array is passed to the constructor, fillProperties() copies each
     * key to the matching public property, and _isnew is set to false.
     * deviceinfo as an empty string → empty array (neither serialised nor JSON).
     */
    public function testArrayConstructorFillsPropertiesAndSetsIsNewFalse(): void
    {
        // Arrange
        $data = [
            'tokenid'   => 42,
            'userid'    => 7,
            'token'     => 'abc123',
            'tokentype' => 'auth',
            'status'    => 1,
            'deviceinfo' => '',
        ];

        // Act
        $token = new Token($data);

        // Assert — properties filled
        $this->assertSame(42, $token->tokenid);
        $this->assertSame(7, $token->userid);
        $this->assertSame('abc123', $token->token);
        $this->assertSame('auth', $token->tokentype);
        $this->assertSame(1, $token->status);
        // deviceinfo normalised to empty array for empty/non-decodable string
        $this->assertSame([], $token->deviceinfo);
    }

    // =========================================================================
    // fillProperties — deviceinfo normalisation
    // =========================================================================

    /**
     * deviceinfo stored as a PHP serialized string is unserialized back to an
     * array during fillProperties().  This handles the legacy storage format
     * where deviceinfo was saved with serialize().
     */
    public function testFillPropertiesUnserializesDeviceinfo(): void
    {
        // Arrange — serialized array
        $browserData = ['browser' => 'Chrome', 'platform' => 'Windows'];
        $data = [
            'tokenid'    => 1,
            'deviceinfo' => serialize($browserData),
        ];

        // Act
        $token = new Token($data);

        // Assert — deviceinfo is the original array
        $this->assertSame($browserData, $token->deviceinfo);
    }

    /**
     * deviceinfo stored as a JSON string is decoded back to an array.
     * The newer storage format uses json_encode() rather than serialize().
     */
    public function testFillPropertiesDecodesJsonDeviceinfo(): void
    {
        // Arrange — JSON string
        $browserData = ['browser' => 'Firefox', 'version' => '115'];
        $data = [
            'tokenid'    => 2,
            'deviceinfo' => json_encode($browserData),
        ];

        // Act
        $token = new Token($data);

        // Assert — decoded to array
        $this->assertSame($browserData, $token->deviceinfo);
    }

    /**
     * deviceinfo that is neither serialised nor valid JSON is normalised to an
     * empty array to prevent downstream code from dealing with raw strings.
     */
    public function testFillPropertiesNormalisesInvalidDeviceinfo(): void
    {
        // Arrange — non-serialized, non-JSON string
        $data = [
            'tokenid'    => 3,
            'deviceinfo' => 'some random text that is not JSON or serialized',
        ];

        // Act
        $token = new Token($data);

        // Assert
        $this->assertSame([], $token->deviceinfo);
    }

    /**
     * deviceinfo as the serialized false value 'b:0;' is treated as valid
     * serialised data by checkUnserialize() and unserialized to boolean false.
     * The result is stored as-is (no additional array normalisation applies here).
     *
     * This is a legitimate edge case: a token whose browser-info was deliberately
     * serialized as false.  The value is stored verbatim as the PHP boolean false.
     */
    public function testFillPropertiesHandlesSerializedFalse(): void
    {
        // Arrange
        $data = [
            'tokenid'    => 4,
            'deviceinfo' => 'b:0;',
        ];

        // Act
        $token = new Token($data);

        // Assert — checkUnserialize('b:0;') = true → unserialize('b:0;') = false
        // The serialized-false branch stores the unserialized value directly.
        $this->assertFalse($token->deviceinfo);
    }

    // =========================================================================
    // getData()
    // =========================================================================

    /**
     * getData() returns an associative array of scalar properties.  The
     * 'created' and 'removedate' and 'lastused' fields are formatted as ISO 8601
     * dates; 'status' is mapped to a human-readable string.
     */
    public function testGetDataReturnsFormattedArray(): void
    {
        // Arrange — create token with known timestamps and status
        $data = [
            'tokenid'    => 10,
            'userid'     => 5,
            'token'      => 'tok_abc',
            'tokentype'  => 'auth',
            'created'    => mktime(12, 0, 0, 1, 1, 2024),
            'removedate' => 0,
            'lastused'   => 0,
            'status'     => 1,
            'deviceinfo' => '',
        ];
        $token = new Token($data);

        // Act
        $result = $token->getData();

        // Assert — key fields present
        $this->assertIsArray($result);
        $this->assertArrayHasKey('tokenid', $result);
        $this->assertArrayHasKey('created', $result);

        // Assert — 'created' is formatted as ISO 8601
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', $result['created']);

        // Assert — 'status' mapped to string ('Active' for status=1)
        $this->assertSame('Active', $result['status']);

        // Assert — removedate=0 and lastused=0 → null
        $this->assertNull($result['removedate']);
        $this->assertNull($result['lastused']);
    }

    /**
     * getData() sets 'status' to 'Inactive' for status=0 and 'Deleted' for status=2.
     */
    public function testGetDataMapsAllStatusValues(): void
    {
        // Arrange
        $base = ['tokenid' => 1, 'created' => time(), 'removedate' => 0, 'lastused' => 0];

        // Act / Assert — status 0
        $t0 = new Token(array_merge($base, ['status' => 0, 'deviceinfo' => '']));
        $this->assertSame('Inactive', $t0->getData()['status']);

        // Act / Assert — status 2
        $t2 = new Token(array_merge($base, ['status' => 2, 'deviceinfo' => '']));
        $this->assertSame('Deleted', $t2->getData()['status']);
    }

    /**
     * getData() includes 'deviceinfo' in the output when it is a non-empty array.
     */
    public function testGetDataIncludesDeviceinfoWhenNonEmpty(): void
    {
        // Arrange
        $browserData = ['browser' => 'Chrome'];
        $data = [
            'tokenid'    => 11,
            'created'    => time(),
            'removedate' => 0,
            'lastused'   => 0,
            'status'     => 1,
            'deviceinfo' => json_encode($browserData),
        ];
        $token = new Token($data);

        // Act
        $result = $token->getData();

        // Assert
        $this->assertArrayHasKey('deviceinfo', $result);
        $this->assertSame($browserData, $result['deviceinfo']);
    }

    /**
     * getData() formats removedate and lastused as ISO 8601 strings when non-zero.
     */
    public function testGetDataFormatsNonZeroDates(): void
    {
        // Arrange
        $ts = mktime(12, 0, 0, 6, 15, 2024);
        $data = [
            'tokenid'    => 12,
            'created'    => $ts,
            'removedate' => $ts,
            'lastused'   => $ts,
            'status'     => 0,
            'deviceinfo' => '',
        ];
        $token = new Token($data);

        // Act
        $result = $token->getData();

        // Assert — both formatted as ISO 8601 dates
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', $result['removedate']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', $result['lastused']);
    }

    // =========================================================================
    // getDetails() — tokenid = 0 sentinel
    // =========================================================================

    /**
     * getDetails() returns a hardcoded zero/empty-string array when tokenid = 0,
     * avoiding any database call.  This lets callers safely call getDetails()
     * on an uninitialised Token and get a predictable structure.
     */
    public function testGetDetailsWithZeroTokenIdReturnsEmptySentinel(): void
    {
        // Arrange — default token, tokenid=0
        $token = new Token();

        // Act
        $result = $token->getDetails();

        // Assert — must be the sentinel array (tokenid=0, all empty/zero)
        $this->assertSame(0, $result['tokenid']);
        $this->assertSame(0, $result['userid']);
        $this->assertSame('', $result['token']);
        $this->assertSame('', $result['tokentype']);
        $this->assertArrayHasKey('username', $result);
        $this->assertArrayHasKey('firstname', $result);
        $this->assertArrayHasKey('app_name', $result);
    }

    // =========================================================================
    // getStatistics() — tokenid = 0 sentinel
    // =========================================================================

    /**
     * getStatistics() returns a zero-filled array when tokenid = 0, avoiding
     * any database call.
     */
    public function testGetStatisticsWithZeroTokenIdReturnsZeroSentinel(): void
    {
        // Arrange
        $token = new Token();

        // Act
        $result = $token->getStatistics();

        // Assert — zero-filled sentinel
        $this->assertSame(0, $result['total_actions']);
        $this->assertNull($result['first_action']);
        $this->assertNull($result['last_action']);
        $this->assertSame(0, $result['active_days']);
    }

    // =========================================================================
    // getActions() — tokenid = 0 sentinel
    // =========================================================================

    /**
     * getActions() returns an empty data array and total=0 when tokenid = 0,
     * avoiding any database call.
     */
    public function testGetActionsWithZeroTokenIdReturnsEmptyResult(): void
    {
        // Arrange
        $token = new Token();

        // Act
        $result = $token->getActions();

        // Assert — empty sentinel
        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['total']);
    }
}
