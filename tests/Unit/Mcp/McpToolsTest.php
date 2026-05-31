<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Tools\ListTablesTool;
use Pramnos\Mcp\Tools\ModelInspectTool;
use Pramnos\Mcp\Tools\QuerySchemaTool;
use Pramnos\Mcp\Tools\RouteListTool;
use Pramnos\Mcp\Tools\MigrationStatusTool;

/**
 * Unit tests for built-in MCP tools.
 *
 * These tests verify each tool's metadata (name, description, inputSchema) and
 * its behaviour when the backing system is unavailable (disconnected DB, no
 * router, unknown class). The goal is to confirm that tools degrade gracefully
 * rather than throwing, and that the schema structure matches what the MCP spec
 * expects.
 *
 * Integration tests (with a real database) are in McpServerMySQLTest.
 */
class McpToolsTest extends TestCase
{
    // ── ListTablesTool ────────────────────────────────────────────────────────

    /**
     * ListTablesTool must return its name/description and an empty inputSchema
     * since it takes no parameters.
     */
    public function testListTablesToolMetadata(): void
    {
        // Arrange
        $db   = $this->createMockDatabase();
        $tool = new ListTablesTool($db);

        // Assert
        $this->assertSame('list-tables', $tool->name());
        $this->assertNotEmpty($tool->description());
        $schema = $tool->inputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertSame([], $schema['properties']);
    }

    /**
     * ListTablesTool::execute() must return an error array when the database
     * is not connected, not throw an exception.
     */
    public function testListTablesToolReturnsErrorWhenNotConnected(): void
    {
        // Arrange
        $db            = $this->createMockDatabase();
        $db->connected = false;
        $tool          = new ListTablesTool($db);

        // Act
        $result = $tool->execute([]);

        // Assert — graceful degradation, not a thrown exception
        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ── QuerySchemaTool ───────────────────────────────────────────────────────

    /**
     * QuerySchemaTool must require the 'table' parameter in its inputSchema.
     */
    public function testQuerySchemaToolHasRequiredTableParam(): void
    {
        // Arrange
        $db   = $this->createMockDatabase();
        $tool = new QuerySchemaTool($db);

        // Act
        $schema = $tool->inputSchema();

        // Assert
        $this->assertSame('query-schema', $tool->name());
        $this->assertContains('table', $schema['required']);
    }

    /**
     * QuerySchemaTool::execute() with an empty table name must return an error
     * rather than executing a query with an empty table name.
     */
    public function testQuerySchemaToolReturnsErrorForEmptyTableName(): void
    {
        // Arrange
        $db   = $this->createMockDatabase();
        $tool = new QuerySchemaTool($db);

        // Act
        $result = $tool->execute(['table' => '']);

        // Assert
        $this->assertArrayHasKey('error', $result);
    }

    // ── ModelInspectTool ──────────────────────────────────────────────────────

    /**
     * ModelInspectTool must require the 'class' parameter in its inputSchema.
     */
    public function testModelInspectToolHasRequiredClassParam(): void
    {
        // Arrange / Act
        $tool   = new ModelInspectTool();
        $schema = $tool->inputSchema();

        // Assert
        $this->assertSame('model-inspect', $tool->name());
        $this->assertContains('class', $schema['required']);
    }

    /**
     * ModelInspectTool::execute() for a non-existent class must return an
     * error array, not trigger a PHP fatal error or throw.
     */
    public function testModelInspectToolReturnsErrorForUnknownClass(): void
    {
        // Arrange / Act
        $tool   = new ModelInspectTool();
        $result = $tool->execute(['class' => 'Nonexistent\\Ghost\\Class']);

        // Assert
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not found', $result['error']);
    }

    /**
     * ModelInspectTool::execute() for a known class must return table, fillable,
     * casts, methods, and relations keys.
     *
     * Uses a simple anonymous class declared inline so this test has zero
     * external dependencies.
     */
    public function testModelInspectToolInspectsKnownClass(): void
    {
        // Arrange — a plain named class that ModelInspectTool can reflect on
        $class = InspectableModel::class;

        $tool   = new ModelInspectTool();
        $result = $tool->execute(['class' => $class]);

        // Assert — required keys present
        $this->assertArrayHasKey('table',      $result);
        $this->assertArrayHasKey('fillable',   $result);
        $this->assertArrayHasKey('casts',      $result);
        $this->assertArrayHasKey('methods',    $result);
        $this->assertArrayHasKey('relations',  $result);
        $this->assertSame('inspectable_models', $result['table']);
    }

    // ── RouteListTool ─────────────────────────────────────────────────────────

    /**
     * RouteListTool must return an error when no router is available.
     *
     * The app mock has no router property, so the tool should degrade
     * gracefully.
     */
    public function testRouteListToolReturnsErrorWhenNoRouter(): void
    {
        // Arrange
        $app        = $this->createMock(\Pramnos\Application\Application::class);
        $app->router = null;
        $tool        = new RouteListTool($app);

        // Act
        $result = $tool->execute([]);

        // Assert
        $this->assertArrayHasKey('error', $result);
    }

    // ── MigrationStatusTool ───────────────────────────────────────────────────

    /**
     * MigrationStatusTool must return its name/description and empty inputSchema properties.
     */
    public function testMigrationStatusToolMetadata(): void
    {
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $tool = new MigrationStatusTool($app);

        $this->assertSame('migration-status', $tool->name());
        $this->assertNotEmpty($tool->description());
        $schema = $tool->inputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertSame([], $schema['properties']);
    }

    /**
     * MigrationStatusTool::execute() must return an error when no database connection is available.
     */
    public function testMigrationStatusToolReturnsErrorWhenNoDatabase(): void
    {
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->database = null;
        $tool = new MigrationStatusTool($app);

        $result = $tool->execute([]);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('No database connection', $result['error']);
    }

    /**
     * MigrationStatusTool::execute() must return pending and applied counts and lists.
     */
    public function testMigrationStatusToolExecuteSuccess(): void
    {
        $db = $this->createMockDatabase();
        
        $schemaMock = $this->getMockBuilder(\Pramnos\Database\SchemaBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $schemaMock->method('hasColumn')->willReturn(true);
        $db->method('schema')->willReturn($schemaMock);

        // Setup getHistory result
        $db->method('query')->willReturnCallback(function ($sql) use ($db) {
            $res = $this->createMock(\Pramnos\Database\Result::class);
            $res->fields = [
                'key' => 'add_missing_foreign_keys_to_existing_tables',
                'result' => 1,
                'batch' => 1,
                'when' => '2026-05-31 12:00:00'
            ];
            $res->method('fetch')->willReturnCallback(function () use ($res) {
                static $called = false;
                if ($called) {
                    return null;
                }
                $called = true;
                return $res->fields;
            });
            return $res;
        });

        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->database = $db;

        $tool = new MigrationStatusTool($app);
        $result = $tool->execute([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('pending_count', $result);
        $this->assertArrayHasKey('applied_count', $result);
        $this->assertArrayHasKey('pending', $result);
        $this->assertArrayHasKey('last_applied', $result);
        
        $this->assertGreaterThanOrEqual(1, $result['applied_count']);
        $this->assertSame('add_missing_foreign_keys_to_existing_tables', $result['last_applied']['slug']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function createMockDatabase(): \Pramnos\Database\Database&\PHPUnit\Framework\MockObject\MockObject
    {
        $db            = $this->createMock(\Pramnos\Database\Database::class);
        $db->connected = true;
        $db->type      = 'mysql';
        return $db;
    }
}

/**
 * Helper class used by testModelInspectToolInspectsKnownClass.
 * Must be a real named class (not anonymous) so ReflectionClass can target it.
 */
class InspectableModel
{
    public string $table    = 'inspectable_models';
    public array  $fillable = ['name', 'email'];
    public array  $casts    = ['created_at' => 'datetime'];

    public function save(): bool
    {
        return true;
    }
}
