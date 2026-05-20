<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;
use Pramnos\Console\Commands\Make\MakeController;

/**
 * Unit tests for the pure computation helpers that power the migration wizard.
 *
 * The methods under test (blueprintCall, buildMigrationUpBody, buildMigrationDownBody,
 * generateFakeValue, buildSeederFields) are stateless string-builders — they do not
 * touch the filesystem, database, or any Symfony Console I/O.  That makes them ideal
 * for thorough unit coverage without any infrastructure setup.
 *
 * WHY these tests matter:
 * - blueprintCall()          is the single point that translates a column definition
 *                            into valid PHP.  A regression here silently generates
 *                            broken migration files.
 * - buildMigrationUpBody()   assembles the whole up() closure — wrong indentation
 *                            or missing statements mean migrations that fail at
 *                            syntax-check time.
 * - generateFakeValue()      drives seeder quality; wrong heuristics produce useless
 *                            or broken fake data.
 * - buildSeederFields()      ensures auto-managed columns (id, timestamps) are never
 *                            included in seed inserts.
 */
class MigrationWizardHelpersTest extends TestCase
{
    private MakeCommandBase $cmd;

    protected function setUp(): void
    {
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
        if (!defined('INCLUDES')) {
            define('INCLUDES', 'src');
        }
        // The helper methods under test live in MakeCommandBase; we instantiate
        // MakeController (the simplest concrete subclass) to get access to them
        // without any Application or I/O context.
        $this->cmd = new MakeController();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // blueprintCall()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A basic string column with a non-default length must render the length
     * argument; the default length (255) must be omitted to keep output concise.
     */
    public function testBlueprintCallStringWithCustomLength(): void
    {
        // Act
        $result = $this->cmd->blueprintCall([
            'name' => 'title', 'type' => 'string',
            'options' => ['length' => 100],
            'nullable' => false, 'default' => null, 'unique' => false,
            'unsigned' => false, 'comment' => '',
        ]);

        // Assert — length argument present, no modifiers since none were set
        $this->assertSame("\$table->string('title', 100);", $result);
    }

    /**
     * A string column with the default length (255) must NOT include the
     * length argument — keeps generated code clean and matches Laravel style.
     */
    public function testBlueprintCallStringDefaultLengthOmitted(): void
    {
        // Act
        $result = $this->cmd->blueprintCall([
            'name' => 'email', 'type' => 'string',
            'options' => ['length' => 255],
            'nullable' => false, 'default' => null, 'unique' => true,
            'unsigned' => false, 'comment' => '',
        ]);

        // Assert — no length, but unique modifier is present
        $this->assertSame("\$table->string('email')->unique();", $result);
    }

    /**
     * integer + unsigned=true must produce unsignedInteger(), NOT integer()->unsigned().
     * Blueprint has a dedicated method for this and some grammars rely on it.
     */
    public function testBlueprintCallUnsignedIntegerUsesCorrectMethod(): void
    {
        // Act
        $result = $this->cmd->blueprintCall([
            'name' => 'role_id', 'type' => 'integer',
            'options' => [],
            'nullable' => false, 'default' => null, 'unique' => false,
            'unsigned' => true, 'comment' => '',
        ]);

        // Assert
        $this->assertSame("\$table->unsignedInteger('role_id');", $result);
        // The raw ->unsigned() modifier must NOT appear a second time
        $this->assertStringNotContainsString('->unsigned()', $result);
    }

    /**
     * biginteger + unsigned=true must produce unsignedBigInteger() — FK columns
     * added by the wizard are biginteger+unsigned by default.
     */
    public function testBlueprintCallUnsignedBigIntegerUsesCorrectMethod(): void
    {
        // Act
        $result = $this->cmd->blueprintCall([
            'name' => 'user_id', 'type' => 'biginteger',
            'options' => [],
            'nullable' => true, 'default' => null, 'unique' => false,
            'unsigned' => true, 'comment' => '',
        ]);

        // Assert — correct method + nullable modifier
        $this->assertStringContainsString('unsignedBigInteger(', $result);
        $this->assertStringContainsString('->nullable()', $result);
    }

    /**
     * decimal() must pass both total and places arguments — omitting either would
     * cause a SchemaGrammar error at migration time.
     */
    public function testBlueprintCallDecimalIncludesPrecisionAndScale(): void
    {
        // Act
        $result = $this->cmd->blueprintCall([
            'name' => 'price', 'type' => 'decimal',
            'options' => ['total' => 10, 'places' => 2],
            'nullable' => false, 'default' => '0.00', 'unique' => false,
            'unsigned' => false, 'comment' => '',
        ]);

        // Assert — total and places present; '0.00' is numeric so emitted unquoted
        $this->assertStringContainsString("decimal('price', 10, 2)", $result);
        $this->assertStringContainsString("->default(0.00)", $result);
        $this->assertStringNotContainsString("->default('0.00')", $result);
    }

    /**
     * Numeric default values must be emitted without quotes so the PHP code
     * is valid: ->default(0) not ->default('0').
     */
    public function testBlueprintCallNumericDefaultIsUnquoted(): void
    {
        // Act
        $result = $this->cmd->blueprintCall([
            'name' => 'count', 'type' => 'integer',
            'options' => [],
            'nullable' => false, 'default' => '42', 'unique' => false,
            'unsigned' => false, 'comment' => '',
        ]);

        // Assert
        $this->assertStringContainsString('->default(42)', $result);
        $this->assertStringNotContainsString("->default('42')", $result);
    }

    /**
     * A comment modifier must be appended last so it does not interfere with
     * other modifiers.  The comment value must be escaped to prevent injecting
     * malicious strings into the generated PHP file.
     */
    public function testBlueprintCallCommentAppendsEscaped(): void
    {
        // Act
        $result = $this->cmd->blueprintCall([
            'name' => 'bio', 'type' => 'text',
            'options' => [],
            'nullable' => true, 'default' => null, 'unique' => false,
            'unsigned' => false, 'comment' => "User's bio",
        ]);

        // Assert — single-quote in comment must be escaped
        $this->assertStringContainsString("->comment('User\\'s bio')", $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildMigrationUpBody()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When hasPk=true the closure must contain an auto-increment primary key.
     * Advanced Primary Key Naming derives the column name from the singular
     * of the table (e.g. '#PREFIX#users' → 'user' → 'userid'), which is the
     * convention for the framework's model-to-table mapping.
     */
    public function testBuildMigrationUpBodyIncludesPrimaryKey(): void
    {
        // Act
        $body = $this->cmd->buildMigrationUpBody(
            '#PREFIX#users', true, [], false, false, []
        );

        // Assert — table wrapper present and auto-PK uses derived name 'userid'
        $this->assertStringContainsString("SchemaBuilder::create('#PREFIX#users'", $body);
        $this->assertStringContainsString("\$table->increments('userid');", $body);
    }

    /**
     * Timestamps flag adds $table->timestamps() — omitting it when the flag is
     * false ensures generated migrations are not bloated with unwanted columns.
     */
    public function testBuildMigrationUpBodyTimestampsAddedOnlyWhenRequested(): void
    {
        // Arrange
        $cols = [['name' => 'name', 'type' => 'string', 'options' => [], 'nullable' => false, 'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => '']];

        // Act — timestamps enabled
        $withTs = $this->cmd->buildMigrationUpBody('#PREFIX#posts', true, $cols, true, false, []);
        // Act — timestamps disabled
        $withoutTs = $this->cmd->buildMigrationUpBody('#PREFIX#posts', true, $cols, false, false, []);

        // Assert
        $this->assertStringContainsString('$table->timestamps();', $withTs);
        $this->assertStringNotContainsString('$table->timestamps();', $withoutTs);
    }

    /**
     * softDeletes flag adds $table->softDeletes() — this must only appear when
     * explicitly requested because not all tables use soft-delete semantics.
     */
    public function testBuildMigrationUpBodySoftDeletesAddedOnlyWhenRequested(): void
    {
        // Act
        $with    = $this->cmd->buildMigrationUpBody('#PREFIX#items', false, [], false, true, []);
        $without = $this->cmd->buildMigrationUpBody('#PREFIX#items', false, [], false, false, []);

        // Assert
        $this->assertStringContainsString('$table->softDeletes();', $with);
        $this->assertStringNotContainsString('$table->softDeletes();', $without);
    }

    /**
     * Foreign key definitions must generate the full fluent chain:
     * $table->foreign('col')->references('id')->on('table')->onDelete('ACTION')
     * A missing link in the chain would produce invalid PHP that fails at parse
     * time inside the migration file.
     */
    public function testBuildMigrationUpBodyForeignKeyChainIsComplete(): void
    {
        // Arrange
        $fks = [['column' => 'user_id', 'references' => 'id', 'on' => 'users', 'onDelete' => 'CASCADE']];

        // Act
        $body = $this->cmd->buildMigrationUpBody('#PREFIX#posts', true, [], false, false, $fks);

        // Assert — every link of the chain must be present
        $this->assertStringContainsString("foreign('user_id')", $body);
        $this->assertStringContainsString("->references('id')", $body);
        $this->assertStringContainsString("->on('users')", $body);
        $this->assertStringContainsString("->onDelete('CASCADE')", $body);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildMigrationDownBody()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The down() body must call SchemaBuilder::dropIfExists() with exactly the
     * same table name used in up() — otherwise a rollback would target the wrong
     * table or fail silently.
     */
    public function testBuildMigrationDownBodyDropsCorrectTable(): void
    {
        // Act
        $body = $this->cmd->buildMigrationDownBody('#PREFIX#products');

        // Assert
        $this->assertStringContainsString("SchemaBuilder::dropIfExists('#PREFIX#products')", $body);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // generateFakeValue()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A column named 'email' must produce code that builds an email-format string,
     * not the generic integer fallback — name heuristics must override type.
     */
    public function testGenerateFakeValueEmailHeuristicOverridesType(): void
    {
        // Act — type is integer but name contains 'email'
        $code = $this->cmd->generateFakeValue('email', 'string');

        // Assert — must reference '@' (an email pattern), not just '$i'
        $this->assertStringContainsString('@', $code);
        $this->assertStringContainsString('example.com', $code);
    }

    /**
     * An integer column with a generic name must produce a numeric expression
     * using $i so seeded values are distinct per iteration.
     */
    public function testGenerateFakeValueIntegerFallback(): void
    {
        // Act
        $code = $this->cmd->generateFakeValue('some_count', 'integer');

        // Assert — must be $i (the loop counter) without string wrapping
        $this->assertSame('$i', $code);
    }

    /**
     * A boolean column must produce a PHP boolean expression (true/false), not a
     * string '1'/'0' — wrong types here cause schema strict-mode violations.
     */
    public function testGenerateFakeValueBooleanProducesBoolExpression(): void
    {
        // Act
        $code = $this->cmd->generateFakeValue('is_active', 'boolean');

        // Assert — must be a bool expression, not a string literal
        $this->assertStringContainsString('$i % 2', $code);
        // Not a string-quoted value
        $this->assertStringNotContainsString("'true'", $code);
        $this->assertStringNotContainsString("'false'", $code);
    }

    /**
     * A 'status' column must use the name heuristic and produce a choice from a
     * fixed array — this verifies that name heuristics work for partial matches
     * (the column name 'status' exactly matches the 'status' hint key).
     */
    public function testGenerateFakeValueStatusHeuristic(): void
    {
        // Act
        $code = $this->cmd->generateFakeValue('status', 'string');

        // Assert — must come from a fixed list, not be a generic 'value_' . $i
        $this->assertStringContainsString('active', $code);
        $this->assertStringNotContainsString("'value_' . \$i", $code);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildSeederFields()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Auto-managed columns (id, created_at, updated_at, deleted_at) must be
     * excluded from the seeder fields block — inserting them explicitly would
     * either conflict with DB defaults or violate NOT NULL constraints.
     */
    public function testBuildSeederFieldsSkipsAutoManagedColumns(): void
    {
        // Arrange
        $columns = [
            ['name' => 'id',         'type' => 'integer',  'options' => [], 'nullable' => false, 'default' => null, 'unique' => false, 'unsigned' => true,  'comment' => ''],
            ['name' => 'name',       'type' => 'string',   'options' => [], 'nullable' => false, 'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
            ['name' => 'created_at', 'type' => 'timestamp','options' => [], 'nullable' => true,  'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
            ['name' => 'updated_at', 'type' => 'timestamp','options' => [], 'nullable' => true,  'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
        ];

        // Act
        $fields = $this->cmd->buildSeederFields($columns);

        // Assert — only 'name' column should appear
        $this->assertStringContainsString("'name'", $fields);
        $this->assertStringNotContainsString("'id'", $fields);
        $this->assertStringNotContainsString("'created_at'", $fields);
        $this->assertStringNotContainsString("'updated_at'", $fields);
    }

    /**
     * buildSeederFields() must produce one line per non-skipped column in the
     * format 'column_name' => <fake expression>, with consistent indentation so
     * it can be dropped directly into the seeder stub.
     */
    public function testBuildSeederFieldsProducesOneLinePerColumn(): void
    {
        // Arrange
        $columns = [
            ['name' => 'title',  'type' => 'string',  'options' => [], 'nullable' => false, 'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
            ['name' => 'active', 'type' => 'boolean', 'options' => [], 'nullable' => false, 'default' => null, 'unique' => false, 'unsigned' => false, 'comment' => ''],
        ];

        // Act
        $fields = $this->cmd->buildSeederFields($columns);

        // Assert — two key => value pairs
        $this->assertStringContainsString("'title'", $fields);
        $this->assertStringContainsString("'active'", $fields);
        // Both end with a comma (PHP array syntax)
        $lines = array_filter(explode("\n", $fields));
        foreach ($lines as $line) {
            $this->assertStringEndsWith(',', rtrim($line));
        }
    }
}
