<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Make\FakeDataGenerator;

#[CoversClass(FakeDataGenerator::class)]
class FakeDataGeneratorTest extends TestCase
{
    private FakeDataGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new FakeDataGenerator();
    }

    // =========================================================================
    // generateFakeValue() — name-based heuristics
    // =========================================================================

    public function testEmailHint(): void
    {
        $result = $this->generator->generateFakeValue('email', 'string');
        $this->assertStringContainsString('@example.com', $result);
    }

    public function testUsernameHint(): void
    {
        $result = $this->generator->generateFakeValue('username', 'string');
        $this->assertStringContainsString('user_', $result);
    }

    public function testNameHint(): void
    {
        $result = $this->generator->generateFakeValue('name', 'string');
        $this->assertStringContainsString('Name', $result);
    }

    public function testSlugHint(): void
    {
        $result = $this->generator->generateFakeValue('slug', 'string');
        $this->assertStringContainsString('record-', $result);
    }

    public function testPasswordHint(): void
    {
        $result = $this->generator->generateFakeValue('password', 'string');
        $this->assertStringContainsString('password_hash', $result);
    }

    public function testStatusHint(): void
    {
        $result = $this->generator->generateFakeValue('status', 'string');
        $this->assertStringContainsString('active', $result);
    }

    public function testPriceHint(): void
    {
        $result = $this->generator->generateFakeValue('price', 'decimal');
        $this->assertStringContainsString('9.99', $result);
    }

    public function testTokenHint(): void
    {
        $result = $this->generator->generateFakeValue('token', 'string');
        $this->assertStringContainsString('random_bytes', $result);
    }

    public function testUrlHint(): void
    {
        $result = $this->generator->generateFakeValue('url', 'string');
        $this->assertStringContainsString('https://example.com', $result);
    }

    public function testIpHint(): void
    {
        // 'ip' is a hint — use a column name that only matches the 'ip' hint
        $result = $this->generator->generateFakeValue('ip', 'string');
        $this->assertStringContainsString('192.168.', $result);
    }

    // =========================================================================
    // generateFakeValue() — type-based fallbacks
    // =========================================================================

    public function testIntegerTypeFallback(): void
    {
        $result = $this->generator->generateFakeValue('random_column', 'integer');
        $this->assertSame('$i', $result);
    }

    public function testBigIntegerTypeFallback(): void
    {
        $result = $this->generator->generateFakeValue('random_column', 'biginteger');
        $this->assertSame('$i', $result);
    }

    public function testFloatTypeFallback(): void
    {
        // Use a column name that doesn't match any hint
        $result = $this->generator->generateFakeValue('custom_float_col', 'float');
        $this->assertStringContainsString('9.99', $result);
    }

    public function testBooleanTypeFallback(): void
    {
        $result = $this->generator->generateFakeValue('is_something', 'boolean');
        $this->assertStringContainsString('% 2', $result);
    }

    public function testDateTypeFallback(): void
    {
        $result = $this->generator->generateFakeValue('some_date', 'date');
        $this->assertStringContainsString("date('Y-m-d'", $result);
    }

    public function testDatetimeTypeFallback(): void
    {
        $result = $this->generator->generateFakeValue('some_datetime', 'datetime');
        $this->assertStringContainsString("date('Y-m-d H:i:s'", $result);
    }

    public function testTextTypeFallback(): void
    {
        // Use a column name that doesn't match any hint
        $result = $this->generator->generateFakeValue('raw_text_field', 'text');
        $this->assertStringContainsString('Lorem ipsum', $result);
    }

    public function testJsonTypeFallback(): void
    {
        $result = $this->generator->generateFakeValue('metadata', 'json');
        $this->assertStringContainsString('json_encode', $result);
    }

    public function testUuidTypeFallback(): void
    {
        $result = $this->generator->generateFakeValue('identifier', 'uuid');
        $this->assertStringContainsString('mt_rand', $result);
    }

    public function testDefaultStringFallback(): void
    {
        $result = $this->generator->generateFakeValue('something_unknown', 'string');
        $this->assertStringContainsString('value_', $result);
    }

    // =========================================================================
    // buildSeederFields()
    // =========================================================================

    public function testBuildSeederFieldsSkipsAutoManagedColumns(): void
    {
        $columns = [
            ['name' => 'id', 'type' => 'integer', 'options' => []],
            ['name' => 'created_at', 'type' => 'datetime', 'options' => []],
            ['name' => 'updated_at', 'type' => 'datetime', 'options' => []],
            ['name' => 'deleted_at', 'type' => 'datetime', 'options' => []],
            ['name' => 'email', 'type' => 'string', 'options' => []],
        ];

        $result = $this->generator->buildSeederFields($columns);

        $this->assertStringNotContainsString("'id'", $result);
        $this->assertStringNotContainsString("'created_at'", $result);
        $this->assertStringNotContainsString("'updated_at'", $result);
        $this->assertStringNotContainsString("'deleted_at'", $result);
        $this->assertStringContainsString("'email'", $result);
    }

    public function testBuildSeederFieldsProducesKeyValuePairs(): void
    {
        $columns = [
            ['name' => 'username', 'type' => 'string', 'options' => []],
            ['name' => 'age', 'type' => 'integer', 'options' => []],
        ];

        $result = $this->generator->buildSeederFields($columns);

        $this->assertStringContainsString("'username'", $result);
        $this->assertStringContainsString("'age'", $result);
        $this->assertStringContainsString('=>', $result);
    }

    public function testBuildSeederFieldsEmptyColumns(): void
    {
        $result = $this->generator->buildSeederFields([]);
        $this->assertSame('', $result);
    }
}
