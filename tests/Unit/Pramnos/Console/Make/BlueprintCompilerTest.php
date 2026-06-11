<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Make\BlueprintCompiler;

#[CoversClass(BlueprintCompiler::class)]
class BlueprintCompilerTest extends TestCase
{
    private BlueprintCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new BlueprintCompiler();
    }

    // =========================================================================
    // getSingularPrimaryKey()
    // =========================================================================

    public function testGetSingularPrimaryKeyStripsPrefix(): void
    {
        $this->assertSame('userid', $this->compiler->getSingularPrimaryKey('#PREFIX#users'));
    }

    public function testGetSingularPrimaryKeyPlainTable(): void
    {
        $this->assertSame('orderid', $this->compiler->getSingularPrimaryKey('orders'));
    }

    public function testGetSingularPrimaryKeyNonPluralTable(): void
    {
        $this->assertSame('productid', $this->compiler->getSingularPrimaryKey('product'));
    }

    public function testGetSingularPrimaryKeyUppercaseConvertedToLower(): void
    {
        $result = $this->compiler->getSingularPrimaryKey('Categories');
        $this->assertStringEndsWith('id', $result);
        $this->assertSame(strtolower($result), $result);
    }

    // =========================================================================
    // blueprintCall()
    // =========================================================================

    public function testBlueprintCallStringType(): void
    {
        $col = ['name' => 'email', 'type' => 'string', 'options' => ['length' => 100],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("\$table->string('email', 100)", $result);
        $this->assertStringEndsWith(';', $result);
    }

    public function testBlueprintCallStringDefaultLength(): void
    {
        $col = ['name' => 'name', 'type' => 'string', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        // default length 255 should NOT be printed
        $this->assertStringContainsString("\$table->string('name')", $result);
        $this->assertStringNotContainsString('255', $result);
    }

    public function testBlueprintCallIntegerType(): void
    {
        $col = ['name' => 'count', 'type' => 'integer', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("\$table->integer('count')", $result);
    }

    public function testBlueprintCallUnsignedInteger(): void
    {
        $col = ['name' => 'qty', 'type' => 'integer', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => true, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString('unsignedInteger', $result);
    }

    public function testBlueprintCallBigIntegerType(): void
    {
        $col = ['name' => 'bigval', 'type' => 'biginteger', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString('bigInteger', $result);
    }

    public function testBlueprintCallDecimalType(): void
    {
        $col = ['name' => 'price', 'type' => 'decimal',
                'options' => ['total' => 10, 'places' => 2],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("\$table->decimal('price', 10, 2)", $result);
    }

    public function testBlueprintCallBooleanType(): void
    {
        $col = ['name' => 'active', 'type' => 'boolean', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("boolean('active')", $result);
    }

    public function testBlueprintCallTextType(): void
    {
        $col = ['name' => 'body', 'type' => 'text', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("text('body')", $result);
    }

    public function testBlueprintCallJsonType(): void
    {
        $col = ['name' => 'meta', 'type' => 'json', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("json('meta')", $result);
    }

    public function testBlueprintCallUuidType(): void
    {
        $col = ['name' => 'uuid', 'type' => 'uuid', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("uuid('uuid')", $result);
    }

    public function testBlueprintCallDatetimeType(): void
    {
        $col = ['name' => 'created_at', 'type' => 'datetime', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("dateTime('created_at')", $result);
    }

    public function testBlueprintCallNullable(): void
    {
        $col = ['name' => 'desc', 'type' => 'string', 'options' => [],
                'nullable' => true, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString('->nullable()', $result);
    }

    public function testBlueprintCallDefaultEmptyString(): void
    {
        $col = ['name' => 'label', 'type' => 'string', 'options' => [],
                'nullable' => false, 'default' => '', 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("->default('')", $result);
    }

    public function testBlueprintCallDefaultNumeric(): void
    {
        $col = ['name' => 'count', 'type' => 'integer', 'options' => [],
                'nullable' => false, 'default' => '0', 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString('->default(0)', $result);
    }

    public function testBlueprintCallDefaultString(): void
    {
        $col = ['name' => 'status', 'type' => 'string', 'options' => [],
                'nullable' => false, 'default' => 'active', 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("->default('active')", $result);
    }

    public function testBlueprintCallDefaultBoolLiteral(): void
    {
        $col = ['name' => 'enabled', 'type' => 'boolean', 'options' => [],
                'nullable' => false, 'default' => 'true', 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString('->default(true)', $result);
    }

    public function testBlueprintCallUnique(): void
    {
        $col = ['name' => 'email', 'type' => 'string', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => true,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString('->unique()', $result);
    }

    public function testBlueprintCallComment(): void
    {
        $col = ['name' => 'field', 'type' => 'string', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => 'My comment'];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("->comment('My comment')", $result);
    }

    public function testBlueprintCallUnknownTypeDefaultsToString(): void
    {
        $col = ['name' => 'weird', 'type' => 'weirdobaloobo', 'options' => [],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("\$table->string('weird')", $result);
    }

    public function testBlueprintCallCharType(): void
    {
        $col = ['name' => 'code', 'type' => 'char', 'options' => ['length' => 3],
                'nullable' => false, 'default' => null, 'unique' => false,
                'unsigned' => false, 'comment' => ''];
        $result = $this->compiler->blueprintCall($col);
        $this->assertStringContainsString("char('code', 3)", $result);
    }

    // =========================================================================
    // buildMigrationUpBody()
    // =========================================================================

    public function testBuildMigrationUpBodyWithPrimaryKey(): void
    {
        $result = $this->compiler->buildMigrationUpBody(
            '#PREFIX#products', true, [], false, false, []
        );
        $this->assertStringContainsString('$schema->createTable(\'#PREFIX#products\'', $result);
        $this->assertStringContainsString('$table->increments(\'productid\')', $result);
    }

    public function testBuildMigrationUpBodyWithColumns(): void
    {
        $columns = [
            ['name' => 'name', 'type' => 'string', 'options' => [],
             'nullable' => false, 'default' => null, 'unique' => false,
             'unsigned' => false, 'comment' => ''],
        ];
        $result = $this->compiler->buildMigrationUpBody(
            'products', false, $columns, false, false, []
        );
        $this->assertStringContainsString("\$table->string('name')", $result);
    }

    public function testBuildMigrationUpBodyWithTimestamps(): void
    {
        $result = $this->compiler->buildMigrationUpBody(
            'items', false, [], true, false, []
        );
        $this->assertStringContainsString('$table->timestamps()', $result);
    }

    public function testBuildMigrationUpBodyWithSoftDeletes(): void
    {
        $result = $this->compiler->buildMigrationUpBody(
            'items', false, [], false, true, []
        );
        $this->assertStringContainsString('$table->softDeletes()', $result);
    }

    public function testBuildMigrationUpBodyWithForeignKeys(): void
    {
        $fks = [[
            'column' => 'user_id', 'references' => 'userid',
            'on' => '#PREFIX#users', 'onDelete' => 'cascade', 'onUpdate' => '',
        ]];
        $result = $this->compiler->buildMigrationUpBody(
            'posts', false, [], false, false, $fks
        );
        $this->assertStringContainsString("->foreign('user_id')", $result);
        $this->assertStringContainsString("->references('userid')", $result);
        $this->assertStringContainsString("->on('#PREFIX#users')", $result);
        $this->assertStringContainsString("->onDelete('cascade')", $result);
    }

    // =========================================================================
    // buildMigrationDownBody()
    // =========================================================================

    public function testBuildMigrationDownBody(): void
    {
        $result = $this->compiler->buildMigrationDownBody('#PREFIX#users');
        $this->assertStringContainsString("dropIfExists('#PREFIX#users')", $result);
    }
}
