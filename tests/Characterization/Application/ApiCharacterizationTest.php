<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Api;

/**
 * Characterization tests for Api response/status helper contracts.
 *
 * Scope: _httpStatusToText() and _translateStatus() behavior.
 */
#[CoversClass(Api::class)]
class ApiCharacterizationTest extends TestCase
{
    /**
     * Build an Api test-double without running Application constructor.
     *
     * This keeps tests deterministic and avoids filesystem/bootstrap side effects.
     */
    private function makeApiStub(): ApiCharacterizationStub
    {
        $ref = new \ReflectionClass(ApiCharacterizationStub::class);
        /** @var ApiCharacterizationStub $api */
        $api = $ref->newInstanceWithoutConstructor();
        return $api;
    }

    /** @return array<string,array{int|string,string}> */
    public static function statusTextProvider(): array
    {
        return [
            '201' => [201, 'Created'],
            '202' => [202, 'Accepted (Request accepted, and queued for execution)'],
            '400' => [400, 'Bad request'],
            '401' => [401, 'Authentication failure'],
            '403' => [403, 'Forbidden'],
            '404' => [404, 'Resource not found'],
            '405' => [405, 'Method Not Allowed'],
            '409' => [409, 'Conflict'],
            '412' => [412, 'Precondition Failed'],
            '413' => [413, 'Request Entity Too Large'],
            '422' => [422, 'Unprocessable Entity'],
            '500' => [500, 'Internal Server Error'],
            '501' => [501, 'Not Implemented'],
            '503' => [503, 'Service Unavailable'],
        ];
    }

    /**
     * _httpStatusToText() maps known HTTP codes to fixed human-readable strings.
     */
    #[DataProvider('statusTextProvider')]
    public function testHttpStatusToTextKnownMappings($code, string $expected): void
    {
        // Arrange
        $api = $this->makeApiStub();

        // Act
        $actual = $api->statusTextForTest($code);

        // Assert
        $this->assertSame($expected, $actual);
    }

    /**
     * _httpStatusToText() returns 'OK' for unknown status codes.
     */
    public function testHttpStatusToTextUnknownCodeFallsBackToOk(): void
    {
        // Arrange
        $api = $this->makeApiStub();

        // Act
        $actual = $api->statusTextForTest(999);

        // Assert
        $this->assertSame('OK', $actual);
    }

    /**
     * _translateStatus() with a plain string returns default envelope with message.
     */
    public function testTranslateStatusStringInputBuildsDefaultEnvelope(): void
    {
        // Arrange
        $api = $this->makeApiStub();

        // Act
        $json = $api->translateStatusForTest('hello');
        $data = json_decode($json, true);

        // Assert
        $this->assertIsArray($data);
        $this->assertSame(200, $data['status']);
        $this->assertSame('OK', $data['statusmessage']);
        $this->assertSame('hello', $data['message']);
        $this->assertFalse($data['error']);
    }

    /**
     * _translateStatus() with an array merges defaults and preserves extra keys.
     */
    public function testTranslateStatusArrayInputMergesDefaultsAndKeepsExtraData(): void
    {
        // Arrange
        $api = $this->makeApiStub();

        // Act
        $json = $api->translateStatusForTest([
            'status' => 200,
            'message' => 'ok',
            'data' => ['id' => 7],
        ]);
        $data = json_decode($json, true);

        // Assert
        $this->assertSame(200, $data['status']);
        $this->assertSame('OK', $data['statusmessage']);
        $this->assertSame('ok', $data['message']);
        $this->assertSame(['id' => 7], $data['data']);
        $this->assertFalse($data['error']);
    }

    /**
     * _translateStatus() with non-array, non-string input falls back to defaults.
     */
    public function testTranslateStatusNonArrayNonStringInputFallsBackToDefaults(): void
    {
        // Arrange
        $api = $this->makeApiStub();

        // Act
        $json = $api->translateStatusForTest(12345);
        $data = json_decode($json, true);

        // Assert
        $this->assertSame(200, $data['status']);
        $this->assertSame('OK', $data['statusmessage']);
        $this->assertSame('', $data['message']);
        $this->assertFalse($data['error']);
    }

    /**
     * For non-200 statuses with default statusmessage='OK', _translateStatus()
     * fills statusmessage from _httpStatusToText().
     */
    public function testTranslateStatusInjectsStatusTextWhenStatusIsNon200(): void
    {
        // Arrange
        $api = $this->makeApiStub();

        // Act
        $json = $api->translateStatusForTest([
            'status' => 404,
            'message' => 'missing',
        ]);
        $data = json_decode($json, true);

        // Assert
        $this->assertSame(404, $data['status']);
        $this->assertSame('Resource not found', $data['statusmessage']);
        $this->assertSame('missing', $data['message']);
    }

    /**
     * Custom statusmessage is preserved (no automatic replacement).
     */
    public function testTranslateStatusKeepsCustomStatusMessage(): void
    {
        // Arrange
        $api = $this->makeApiStub();

        // Act
        $json = $api->translateStatusForTest([
            'status' => 403,
            'statusmessage' => 'Denied by policy',
            'message' => 'blocked',
        ]);
        $data = json_decode($json, true);

        // Assert
        $this->assertSame(403, $data['status']);
        $this->assertSame('Denied by policy', $data['statusmessage']);
        $this->assertSame('blocked', $data['message']);
    }

    /**
     * _translateStatus() always returns valid JSON string output.
     */
    public function testTranslateStatusAlwaysReturnsJsonString(): void
    {
        // Arrange
        $api = $this->makeApiStub();

        // Act
        $json = $api->translateStatusForTest(['status' => 500, 'message' => 'err']);

        // Assert
        $this->assertIsString($json);
        $this->assertNotFalse(json_decode($json, true));
    }
}

/**
 * Small helper subclass exposing protected methods for characterization.
 */
class ApiCharacterizationStub extends Api
{
    public function statusTextForTest($status): string
    {
        return $this->_httpStatusToText($status);
    }

    public function translateStatusForTest($status): string
    {
        return $this->_translateStatus($status);
    }
}
