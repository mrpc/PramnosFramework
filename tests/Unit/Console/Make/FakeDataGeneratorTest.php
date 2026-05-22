<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Make\FakeDataGenerator;

/**
 * Unit tests for FakeDataGenerator.
 *
 * FakeDataGenerator is a pure, stateless value-heuristic class — it maps
 * column names and types to plausible PHP fake-value expressions that can be
 * dropped directly into a for-loop seeder body. No database, filesystem, or
 * application context is needed.
 *
 * WHY these tests matter:
 * - generateFakeValue() uses two-tier logic: name hints first, type fallback
 *   second. If a name hint is accidentally overshadowed (e.g. 'lat' matching
 *   before 'latitude'), the wrong expression is generated.
 * - buildSeederFields() must exclude auto-managed columns (id, created_at,
 *   updated_at, deleted_at) — inserting them explicitly conflicts with DB
 *   defaults or NOT NULL constraints.
 */
#[CoversClass(FakeDataGenerator::class)]
class FakeDataGeneratorTest extends TestCase
{
    private FakeDataGenerator $gen;

    protected function setUp(): void
    {
        // Arrange — fresh stateless instance; no shared state between tests
        $this->gen = new FakeDataGenerator();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // generateFakeValue() — name-based heuristics
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A column named 'email' must produce an email-pattern expression regardless
     * of type — name heuristics must override the type-based fallback.
     */
    public function testEmailHeuristicOverridesType(): void
    {
        // Act — type is integer but name contains 'email'
        $code = $this->gen->generateFakeValue('email', 'integer');

        // Assert — '@' and 'example.com' identify an email pattern
        $this->assertStringContainsString('@', $code);
        $this->assertStringContainsString('example.com', $code);
    }

    /**
     * A column whose name contains 'first_name' must return a name-list
     * expression — verifies multi-word hint keys work with str_contains().
     */
    public function testFirstNameHeuristic(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('first_name', 'string');

        // Assert — must come from the fixed name list, not a generic pattern
        $this->assertStringContainsString('Alice', $code);
    }

    /**
     * A column named 'username' must return the 'user_' . $i pattern — verifies
     * that partial-name hints ('username' contains 'username') work correctly.
     */
    public function testUsernameHeuristic(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('username', 'string');

        // Assert
        $this->assertStringContainsString("'user_' . \$i", $code);
    }

    /**
     * A column named 'latitude' must return a lat coordinate expression, not the
     * generic 'lat' expression — 'latitude' must match before 'lat' so the more
     * specific hint wins (HINTS ordering test).
     */
    public function testLatitudeBeforeLat(): void
    {
        // Act
        $latitudeCode = $this->gen->generateFakeValue('latitude', 'decimal');
        $latCode      = $this->gen->generateFakeValue('lat', 'decimal');

        // Assert — both should produce coordinate-style expressions
        $this->assertStringContainsString('37.97', $latitudeCode);
        $this->assertStringContainsString('37.97', $latCode);
    }

    /**
     * A column named 'longitude' must return a lng coordinate expression,
     * not match 'lon' accidentally — verifies ordering for 'lng'/'lon' hints.
     */
    public function testLongitudeHeuristic(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('longitude', 'decimal');

        // Assert — Athens longitude (~23.73)
        $this->assertStringContainsString('23.73', $code);
    }

    /**
     * A 'status' column must return a fixed-list choice, not the generic fallback —
     * verifies exact-name hints work and don't accidentally match partial names.
     */
    public function testStatusHeuristic(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('status', 'string');

        // Assert — must come from the ['active','inactive','pending'] list
        $this->assertStringContainsString('active', $code);
        $this->assertStringNotContainsString("'value_' . \$i", $code);
    }

    /**
     * A 'password' column must return a password_hash() expression — storing
     * plain-text passwords in seeded data would be a security footgun.
     */
    public function testPasswordHeuristic(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('password', 'string');

        // Assert — uses the PHP password hashing function
        $this->assertStringContainsString('password_hash(', $code);
    }

    /**
     * A 'slug' column must produce a URL-safe 'record-' . $i expression —
     * verifies the slug hint produces distinct, filesystem-safe values.
     */
    public function testSlugHeuristic(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('slug', 'string');

        // Assert
        $this->assertStringContainsString("'record-' . \$i", $code);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // generateFakeValue() — type-based fallbacks
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * An integer column with a non-hint name must produce the plain $i loop
     * counter so each seeded row gets a distinct value.
     */
    public function testIntegerFallback(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('some_count', 'integer');

        // Assert — bare $i, not wrapped in quotes or a function call
        $this->assertSame('$i', $code);
    }

    /**
     * A boolean column must produce a PHP boolean expression — using integer
     * 0/1 here causes strict-mode schema violations in some databases.
     */
    public function testBooleanFallback(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('is_active', 'boolean');

        // Assert — evaluates to true/false, not to a string
        $this->assertStringContainsString('$i % 2', $code);
        $this->assertStringNotContainsString("'true'", $code);
        $this->assertStringNotContainsString("'false'", $code);
    }

    /**
     * A decimal column with a non-hint name must produce a rounded float
     * expression so seeds contain realistic numeric data.
     */
    public function testDecimalFallback(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('some_value', 'decimal');

        // Assert — round() call present, uses $i for distinct values
        $this->assertStringContainsString('round(', $code);
        $this->assertStringContainsString('$i', $code);
    }

    /**
     * A date column must produce a date() expression with a Y-m-d format so
     * seeds contain valid ISO dates that all DB drivers accept.
     */
    public function testDateFallback(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('published_on', 'date');

        // Assert
        $this->assertStringContainsString("date('Y-m-d'", $code);
        $this->assertStringContainsString('$i', $code);
    }

    /**
     * A datetime column must produce a date() expression with a Y-m-d H:i:s
     * format — required for columns declared as datetime/timestamp.
     */
    public function testDatetimeFallback(): void
    {
        // Act — covers both 'datetime' and 'timestamp' branches
        $dtCode = $this->gen->generateFakeValue('happened_at', 'datetime');
        $tsCode = $this->gen->generateFakeValue('recorded_at', 'timestamp');

        // Assert
        $this->assertStringContainsString("Y-m-d H:i:s", $dtCode);
        $this->assertStringContainsString("Y-m-d H:i:s", $tsCode);
    }

    /**
     * A text column with a non-hint name must produce a Lorem ipsum string
     * so the seeded content is readable and distinct per row.
     */
    public function testTextFallback(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('body_content', 'text');

        // Assert — lorem ipsum identifier, row number embedded
        $this->assertStringContainsString('Lorem ipsum', $code);
        $this->assertStringContainsString('$i', $code);
    }

    /**
     * A json column must produce a json_encode() call so the seeded column
     * contains valid JSON, not a raw PHP value.
     */
    public function testJsonFallback(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('metadata', 'json');

        // Assert
        $this->assertStringContainsString('json_encode(', $code);
    }

    /**
     * A uuid column must produce a sprintf() expression with the standard
     * 8-4-4-4-12 UUID format — wrong formats break UUID-typed columns.
     */
    public function testUuidFallback(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('external_id', 'uuid');

        // Assert — sprintf with UUID format markers
        $this->assertStringContainsString('sprintf(', $code);
        $this->assertStringContainsString('%04x', $code);
    }

    /**
     * An unknown column type must fall back to the generic 'value_' . $i
     * expression — prevents exceptions when unrecognised types are encountered.
     */
    public function testUnknownTypeFallback(): void
    {
        // Act
        $code = $this->gen->generateFakeValue('custom_field', 'vector');

        // Assert — generic fallback used
        $this->assertSame("'value_' . \$i", $code);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildSeederFields()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Auto-managed columns (id, created_at, updated_at, deleted_at) must be
     * excluded from the seeder fields block — inserting them explicitly would
     * conflict with DB auto-increment defaults or NOT NULL constraints.
     */
    public function testBuildSeederFieldsSkipsAutoManagedColumns(): void
    {
        // Arrange
        $columns = [
            ['name' => 'id',         'type' => 'integer',   'options' => [], 'nullable' => false, 'default' => null, 'unique' => false, 'unsigned' => true,  'comment' => ''],
            ['name' => 'name',       'type' => 'string',    'options' => [], 'nullable' => false, 'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
            ['name' => 'created_at', 'type' => 'timestamp', 'options' => [], 'nullable' => true,  'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
            ['name' => 'updated_at', 'type' => 'timestamp', 'options' => [], 'nullable' => true,  'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
            ['name' => 'deleted_at', 'type' => 'timestamp', 'options' => [], 'nullable' => true,  'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
        ];

        // Act
        $fields = $this->gen->buildSeederFields($columns);

        // Assert — only 'name' column should appear in the output
        $this->assertStringContainsString("'name'", $fields);
        $this->assertStringNotContainsString("'id'",         $fields);
        $this->assertStringNotContainsString("'created_at'", $fields);
        $this->assertStringNotContainsString("'updated_at'", $fields);
        $this->assertStringNotContainsString("'deleted_at'", $fields);
    }

    /**
     * Each non-skipped column must produce exactly one 'key' => value, line
     * with a trailing comma — the format must be directly insertable into the
     * $this->insert() array in the seeder stub.
     */
    public function testBuildSeederFieldsProducesOneLinePerColumn(): void
    {
        // Arrange
        $columns = [
            ['name' => 'title',  'type' => 'string',  'options' => [], 'nullable' => false, 'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
            ['name' => 'active', 'type' => 'boolean', 'options' => [], 'nullable' => false, 'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
        ];

        // Act
        $fields = $this->gen->buildSeederFields($columns);

        // Assert — both keys present
        $this->assertStringContainsString("'title'", $fields);
        $this->assertStringContainsString("'active'", $fields);

        // Each line must end with a comma (PHP array syntax)
        $lines = array_filter(explode("\n", $fields));
        foreach ($lines as $line) {
            $this->assertStringEndsWith(',', rtrim($line));
        }
    }

    /**
     * buildSeederFields() on an all-skip column list must return an empty
     * string — a table with only id/timestamps must not produce broken PHP.
     */
    public function testBuildSeederFieldsAllSkippedReturnsEmpty(): void
    {
        // Arrange — only auto-managed columns
        $columns = [
            ['name' => 'id',         'type' => 'integer',   'options' => [], 'nullable' => false, 'default' => null, 'unique' => false, 'unsigned' => true,  'comment' => ''],
            ['name' => 'created_at', 'type' => 'timestamp', 'options' => [], 'nullable' => true,  'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
        ];

        // Act
        $fields = $this->gen->buildSeederFields($columns);

        // Assert — empty string so the {{ fields }} token renders as blank
        $this->assertSame('', $fields);
    }
}
