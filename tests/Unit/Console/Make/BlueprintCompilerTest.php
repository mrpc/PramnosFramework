<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Make\BlueprintCompiler;

/**
 * Unit tests for BlueprintCompiler.
 *
 * BlueprintCompiler is a pure, stateless string builder — it converts column
 * definition arrays into valid PHP Blueprint calls and assembles migration
 * up()/down() method bodies. No database, filesystem, or application context
 * is needed.
 *
 * WHY these tests matter:
 * - blueprintCall()         is the single source of truth for translating a
 *                           column definition into valid PHP. A regression here
 *                           silently produces broken migration files.
 * - buildMigrationUpBody()  assembles the whole up() closure — wrong indentation
 *                           or missing statements produce migrations that fail at
 *                           syntax-check time.
 * - buildMigrationDownBody() must reference the exact same table name as up()
 *                           so rollbacks hit the right table.
 * - getSingularPrimaryKey() drives PK column naming; wrong output here causes
 *                           model-to-table resolution failures at runtime.
 */
#[CoversClass(BlueprintCompiler::class)]
class BlueprintCompilerTest extends TestCase
{
    private BlueprintCompiler $compiler;

    protected function setUp(): void
    {
        // Arrange — fresh stateless instance; no shared state between tests
        $this->compiler = new BlueprintCompiler();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getSingularPrimaryKey()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A plural table name with the #PREFIX# placeholder must produce the
     * expected singular+id PK name.  This is the standard case (e.g. '#PREFIX#users' → 'userid').
     */
    public function testGetSingularPrimaryKeyStripsPrefix(): void
    {
        // Act
        $result = $this->compiler->getSingularPrimaryKey('#PREFIX#users');

        // Assert — prefix removed, trailing 's' dropped, 'id' appended
        $this->assertSame('userid', $result);
    }

    /**
     * A table name without the #PREFIX# placeholder must still produce the
     * correct singular+id key — used when the raw table name is passed directly.
     */
    public function testGetSingularPrimaryKeyWithoutPrefix(): void
    {
        // Act
        $result = $this->compiler->getSingularPrimaryKey('orders');

        // Assert
        $this->assertSame('orderid', $result);
    }

    /**
     * A table name that does not end in 's' must not have any character stripped —
     * avoids mangling names like 'staff' into 'stafid'.
     */
    public function testGetSingularPrimaryKeyNoTrailingS(): void
    {
        // Act
        $result = $this->compiler->getSingularPrimaryKey('#PREFIX#staff');

        // Assert — no trailing 's' to remove; appends 'id' directly
        $this->assertSame('staffid', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // blueprintCall()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A string column with a non-default length must include the length
     * argument; the default length (255) must be omitted to keep generated
     * code clean and match framework conventions.
     */
    public function testBlueprintCallStringCustomLength(): void
    {
        // Act
        $result = $this->compiler->blueprintCall([
            'name' => 'title', 'type' => 'string',
            'options' => ['length' => 100],
            'nullable' => false, 'default' => null, 'unique' => false,
            'unsigned' => false, 'comment' => '',
        ]);

        // Assert — length present, no extra modifiers
        $this->assertSame("\$table->string('title', 100);", $result);
    }

    /**
     * A string column at the default length (255) must omit the length
     * argument entirely — keeps generated output minimal.
     */
    public function testBlueprintCallStringDefaultLengthOmitted(): void
    {
        // Act
        $result = $this->compiler->blueprintCall([
            'name' => 'email', 'type' => 'string',
            'options' => ['length' => 255],
            'nullable' => false, 'default' => null, 'unique' => true,
            'unsigned' => false, 'comment' => '',
        ]);

        // Assert — no length argument; unique modifier present
        $this->assertSame("\$table->string('email')->unique();", $result);
    }

    /**
     * integer + unsigned=true must use unsignedInteger() — the Blueprint
     * provides a dedicated method for this and some SQL grammars rely on it.
     * The ->unsigned() modifier must NOT appear separately for integer types.
     */
    public function testBlueprintCallUnsignedIntegerUsesCorrectMethod(): void
    {
        // Act
        $result = $this->compiler->blueprintCall([
            'name' => 'role_id', 'type' => 'integer',
            'options' => [],
            'nullable' => false, 'default' => null, 'unique' => false,
            'unsigned' => true, 'comment' => '',
        ]);

        // Assert — dedicated method used; raw ->unsigned() must not appear twice
        $this->assertSame("\$table->unsignedInteger('role_id');", $result);
        $this->assertStringNotContainsString('->unsigned()', $result);
    }

    /**
     * biginteger + unsigned=true must use unsignedBigInteger() — FK columns
     * created by the migration wizard are biginteger+unsigned by default.
     */
    public function testBlueprintCallUnsignedBigIntegerUsesCorrectMethod(): void
    {
        // Act
        $result = $this->compiler->blueprintCall([
            'name' => 'user_id', 'type' => 'biginteger',
            'options' => [],
            'nullable' => true, 'default' => null, 'unique' => false,
            'unsigned' => true, 'comment' => '',
        ]);

        // Assert — correct method; nullable modifier follows
        $this->assertStringContainsString('unsignedBigInteger(', $result);
        $this->assertStringContainsString('->nullable()', $result);
        $this->assertStringNotContainsString('->unsigned()', $result);
    }

    /**
     * decimal() must pass both total and places arguments — omitting either
     * causes a SchemaGrammar error at migration run time.
     */
    public function testBlueprintCallDecimalIncludesPrecisionAndScale(): void
    {
        // Act
        $result = $this->compiler->blueprintCall([
            'name' => 'price', 'type' => 'decimal',
            'options' => ['total' => 10, 'places' => 2],
            'nullable' => false, 'default' => '0.00', 'unique' => false,
            'unsigned' => false, 'comment' => '',
        ]);

        // Assert — total and places present; '0.00' is numeric so emitted unquoted
        $this->assertStringContainsString("decimal('price', 10, 2)", $result);
        $this->assertStringContainsString('->default(0.00)', $result);
        $this->assertStringNotContainsString("->default('0.00')", $result);
    }

    /**
     * Numeric default values must be emitted unquoted so generated PHP is
     * valid: ->default(0) not ->default('0').
     */
    public function testBlueprintCallNumericDefaultIsUnquoted(): void
    {
        // Act
        $result = $this->compiler->blueprintCall([
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
     * The literal default values 'true', 'false', and 'null' must be emitted
     * unquoted so they resolve to PHP keywords, not strings.
     */
    public function testBlueprintCallLiteralDefaultKeywordsUnquoted(): void
    {
        // Act
        $trueResult  = $this->compiler->blueprintCall([
            'name' => 'flag', 'type' => 'boolean', 'options' => [],
            'nullable' => false, 'default' => 'true', 'unique' => false,
            'unsigned' => false, 'comment' => '',
        ]);
        $nullResult  = $this->compiler->blueprintCall([
            'name' => 'opt', 'type' => 'string', 'options' => [],
            'nullable' => true, 'default' => 'null', 'unique' => false,
            'unsigned' => false, 'comment' => '',
        ]);

        // Assert — PHP keywords not wrapped in quotes
        $this->assertStringContainsString('->default(true)', $trueResult);
        $this->assertStringContainsString('->default(null)', $nullResult);
    }

    /**
     * A comment modifier must be appended last and single-quotes inside the
     * comment value must be escaped — unescaped quotes would produce invalid PHP.
     */
    public function testBlueprintCallCommentAppendsEscaped(): void
    {
        // Act
        $result = $this->compiler->blueprintCall([
            'name' => 'bio', 'type' => 'text',
            'options' => [],
            'nullable' => true, 'default' => null, 'unique' => false,
            'unsigned' => false, 'comment' => "User's bio",
        ]);

        // Assert — apostrophe in comment escaped
        $this->assertStringContainsString("->comment('User\\'s bio')", $result);
    }

    /**
     * A nullable column must include the ->nullable() modifier — omitting it
     * would make the column NOT NULL and break existing DB rows on migration.
     */
    public function testBlueprintCallNullableModifier(): void
    {
        // Act
        $result = $this->compiler->blueprintCall([
            'name' => 'deleted_at', 'type' => 'datetime',
            'options' => [],
            'nullable' => true, 'default' => null, 'unique' => false,
            'unsigned' => false, 'comment' => '',
        ]);

        // Assert
        $this->assertStringContainsString('->nullable()', $result);
    }

    /**
     * An unknown column type must fall back to string() rather than throwing —
     * this ensures forward compatibility when the schema grammar adds new types.
     */
    public function testBlueprintCallUnknownTypeFallsBackToString(): void
    {
        // Act
        $result = $this->compiler->blueprintCall([
            'name' => 'custom_col', 'type' => 'vector',
            'options' => [],
            'nullable' => false, 'default' => null, 'unique' => false,
            'unsigned' => false, 'comment' => '',
        ]);

        // Assert — falls back to string(), column name preserved
        $this->assertStringContainsString("string('custom_col')", $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildMigrationUpBody()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When hasPk=true the closure must include an auto-increment PK whose name
     * is derived from the singular of the table name (framework convention:
     * '#PREFIX#users' → 'userid').
     */
    public function testBuildMigrationUpBodyIncludesPrimaryKey(): void
    {
        // Act
        $body = $this->compiler->buildMigrationUpBody(
            '#PREFIX#users', true, [], false, false, []
        );

        // Assert — schema->createTable wrapper and derived PK name
        $this->assertStringContainsString("\$schema->createTable('#PREFIX#users'", $body);
        $this->assertStringContainsString("\$table->increments('userid');", $body);
    }

    /**
     * Timestamps flag must add $table->timestamps() only when true — the
     * absence case ensures generated migrations are not bloated with unwanted
     * columns when the caller does not request them.
     */
    public function testBuildMigrationUpBodyTimestampsAddedOnlyWhenRequested(): void
    {
        // Arrange
        $cols = [[
            'name' => 'name', 'type' => 'string', 'options' => [],
            'nullable' => false, 'default' => null, 'unique' => false,
            'unsigned' => false, 'comment' => '',
        ]];

        // Act
        $withTs    = $this->compiler->buildMigrationUpBody('#PREFIX#posts', true, $cols, true, false, []);
        $withoutTs = $this->compiler->buildMigrationUpBody('#PREFIX#posts', true, $cols, false, false, []);

        // Assert
        $this->assertStringContainsString('$table->timestamps();', $withTs);
        $this->assertStringNotContainsString('$table->timestamps();', $withoutTs);
    }

    /**
     * softDeletes flag must add $table->softDeletes() only when true — not all
     * tables use soft-delete semantics, so it must not appear unless requested.
     */
    public function testBuildMigrationUpBodySoftDeletesAddedOnlyWhenRequested(): void
    {
        // Act
        $with    = $this->compiler->buildMigrationUpBody('#PREFIX#items', false, [], false, true, []);
        $without = $this->compiler->buildMigrationUpBody('#PREFIX#items', false, [], false, false, []);

        // Assert
        $this->assertStringContainsString('$table->softDeletes();', $with);
        $this->assertStringNotContainsString('$table->softDeletes();', $without);
    }

    /**
     * Foreign key definitions must generate the full fluent chain:
     * ->foreign()->references()->on()->onDelete() — a missing link produces
     * invalid PHP that fails at parse time inside the migration file.
     */
    public function testBuildMigrationUpBodyForeignKeyChainIsComplete(): void
    {
        // Arrange
        $fks = [[
            'column' => 'user_id', 'references' => 'id',
            'on' => 'users', 'onDelete' => 'CASCADE',
        ]];

        // Act
        $body = $this->compiler->buildMigrationUpBody('#PREFIX#posts', true, [], false, false, $fks);

        // Assert — every link of the chain must be present
        $this->assertStringContainsString("foreign('user_id')", $body);
        $this->assertStringContainsString("->references('id')", $body);
        $this->assertStringContainsString("->on('users')", $body);
        $this->assertStringContainsString("->onDelete('CASCADE')", $body);
    }

    /**
     * A foreign key without an onDelete clause must not include ->onDelete()
     * in the generated output — optional FK behaviour must not be forced.
     */
    public function testBuildMigrationUpBodyForeignKeyWithoutOnDelete(): void
    {
        // Arrange
        $fks = [[
            'column' => 'category_id', 'references' => 'id',
            'on' => 'categories', 'onDelete' => '',
        ]];

        // Act
        $body = $this->compiler->buildMigrationUpBody('#PREFIX#articles', false, [], false, false, $fks);

        // Assert — chain present but onDelete absent
        $this->assertStringContainsString("foreign('category_id')", $body);
        $this->assertStringNotContainsString('->onDelete(', $body);
    }

    /**
     * Column definitions passed to buildMigrationUpBody() must appear inside
     * the SchemaBuilder closure, verifying end-to-end integration between
     * blueprintCall() and the closure builder.
     */
    public function testBuildMigrationUpBodyIncludesColumnCalls(): void
    {
        // Arrange
        $cols = [[
            'name' => 'slug', 'type' => 'string', 'options' => ['length' => 200],
            'nullable' => false, 'default' => null, 'unique' => true,
            'unsigned' => false, 'comment' => '',
        ]];

        // Act
        $body = $this->compiler->buildMigrationUpBody('#PREFIX#pages', true, $cols, false, false, []);

        // Assert — column call rendered and placed inside the closure
        $this->assertStringContainsString("\$table->string('slug', 200)->unique();", $body);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildMigrationDownBody()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The down() body must call ->schema()->dropIfExists() with exactly the
     * same table name used in up() — a different name here means rollbacks would
     * silently target the wrong table or fail entirely.
     */
    public function testBuildMigrationDownBodyDropsCorrectTable(): void
    {
        // Act
        $body = $this->compiler->buildMigrationDownBody('#PREFIX#products');

        // Assert
        $this->assertStringContainsString("->schema()->dropIfExists('#PREFIX#products')", $body);
    }
}
