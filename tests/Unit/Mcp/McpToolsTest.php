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

    public function testListTablesToolExecuteMySql(): void
    {
        // Arrange
        $db = $this->createMockDatabase();
        $db->connected = true;
        $db->type = 'mysql';

        $res = $this->createMock(\Pramnos\Database\Result::class);
        $res->method('fetchAll')->willReturn([
            ['name' => 'users', 'row_count' => 10],
            ['name' => 'settings', 'row_count' => 5],
        ]);
        $db->method('query')->willReturn($res);

        $tool = new ListTablesTool($db);

        // Act
        $result = $tool->execute([]);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('users', $result[0]['table']);
        $this->assertSame(10, $result[0]['rows']);
    }

    public function testListTablesToolExecutePostgres(): void
    {
        // Arrange
        $db = $this->createMockDatabase();
        $db->connected = true;
        $db->type = 'postgresql';

        $res = $this->createMock(\Pramnos\Database\Result::class);
        $res->method('fetchAll')->willReturn([
            ['name' => 'logs', 'row_count' => 100],
        ]);
        $db->method('query')->willReturn($res);

        $tool = new ListTablesTool($db);

        // Act
        $result = $tool->execute([]);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('logs', $result[0]['table']);
        $this->assertSame(100, $result[0]['rows']);
    }

    public function testListTablesToolExecuteNullResult(): void
    {
        // Arrange
        $db = $this->createMockDatabase();
        $db->connected = true;
        $db->method('query')->willReturn(false);

        $tool = new ListTablesTool($db);

        // Act
        $result = $tool->execute([]);

        // Assert
        $this->assertSame([], $result);
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

    public function testQuerySchemaToolExecuteMySql(): void
    {
        // Arrange
        $db = $this->createMockDatabase();
        $db->connected = true;
        $db->type = 'mysql';
        $db->method('prepareQuery')->willReturn('SQL QUERY');

        $res = $this->createMock(\Pramnos\Database\Result::class);
        $res->method('fetchAll')->willReturn([
            ['column_name' => 'id', 'data_type' => 'int'],
        ]);
        $db->method('query')->willReturn($res);

        $tool = new QuerySchemaTool($db);

        // Act
        $result = $tool->execute(['table' => 'users']);

        // Assert
        $this->assertIsArray($result);
        $this->assertSame('users', $result['table']);
        $this->assertCount(1, $result['columns']);
        $this->assertSame('id', $result['columns'][0]['column_name']);
    }

    public function testQuerySchemaToolExecutePostgres(): void
    {
        // Arrange
        $db = $this->createMockDatabase();
        $db->connected = true;
        $db->type = 'postgresql';
        $db->method('prepareQuery')->willReturn('SQL QUERY');

        $res = $this->createMock(\Pramnos\Database\Result::class);
        $res->method('fetchAll')->willReturn([
            ['column_name' => 'id', 'data_type' => 'int'],
        ]);
        $db->method('query')->willReturn($res);

        $tool = new QuerySchemaTool($db);

        // Act
        $result = $tool->execute(['table' => 'users']);

        // Assert
        $this->assertIsArray($result);
        $this->assertSame('users', $result['table']);
        $this->assertCount(1, $result['columns']);
    }

    public function testQuerySchemaToolReturnsErrorWhenNotConnected(): void
    {
        // Arrange
        $db = $this->createMockDatabase();
        $db->connected = false;
        $tool = new QuerySchemaTool($db);

        // Act
        $result = $tool->execute(['table' => 'users']);

        // Assert
        $this->assertArrayHasKey('error', $result);
    }

    // ── ModelInspectTool ──────────────────────────────────────────────────────

    /**
     * ModelInspectTool::execute() with an empty string for 'class' must return
     * an error immediately rather than attempting reflection.
     *
     * This guards the early validation branch at the top of execute() — if the
     * caller omits the class name or passes an empty string, the tool must
     * return an error without calling class_exists() or ReflectionClass.
     */
    public function testModelInspectToolReturnsErrorForEmptyClass(): void
    {
        // Arrange
        $tool = new ModelInspectTool();

        // Act — empty string (also covers missing key via null-coalesce)
        $result = $tool->execute(['class' => '']);

        // Assert
        $this->assertArrayHasKey('error', $result,
            'execute() must return an error array when class is empty');
        $this->assertStringContainsString('required', $result['error'],
            'execute() must say the class parameter is required');
    }

    /**
     * ModelInspectTool::execute() on a class with a static property must read
     * it via ReflectionProperty::getValue() (the static branch of readProperty).
     *
     * The InspectableModel helper uses instance properties. This test uses a
     * distinct class that has a public static property to exercise the
     * $prop->isStatic() === true path in readProperty().
     */
    public function testModelInspectToolReadsStaticProperty(): void
    {
        // Arrange — InspectableModelWithStatic has a static $table
        $tool   = new ModelInspectTool();

        // Act
        $result = $tool->execute(['class' => InspectableModelWithStatic::class]);

        // Assert — static $table was read via getValue()
        $this->assertSame('static_models', $result['table'],
            'ModelInspectTool must read static properties via ReflectionProperty::getValue()');
    }

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
        $this->assertNotEmpty($tool->description(), 'description() must return a non-empty string (line 26)');
        $this->assertContains('class', $schema['required']);
    }

    /**
     * ModelInspectTool::findRelations() must detect methods whose source body
     * contains ORM relation keywords (hasOne, hasMany, belongsTo, etc.) and
     * include them in the 'relations' array (line 94).
     *
     * InspectableModelWithRelations has one such method — belongsTo() — whose
     * body contains the keyword, causing the regex branch at line 94 to fire.
     */
    public function testModelInspectToolDetectsRelationMethods(): void
    {
        // Arrange
        $tool = new ModelInspectTool();

        // Act
        $result = $tool->execute(['class' => InspectableModelWithRelations::class]);

        // Assert — the relation method appears in the 'relations' array
        $this->assertContains(
            'author',
            $result['relations'],
            'findRelations() must include methods whose body matches the relation keyword pattern'
        );
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

    /**
     * RouteListTool metadata: name, description and the optional 'filter'
     * string parameter in the input schema. Verifies the MCP contract that
     * AI clients rely on for tool discovery.
     */
    public function testRouteListToolMetadata(): void
    {
        // Arrange
        $app  = $this->createMock(\Pramnos\Application\Application::class);
        $tool = new RouteListTool($app);

        // Assert
        $this->assertSame('route-list', $tool->name());
        $this->assertNotEmpty($tool->description());
        $schema = $tool->inputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertSame('string', $schema['properties']['filter']['type']);
    }

    /**
     * RouteListTool::execute() with a real router must list every registered
     * route with method, uri, action string, permissions and name — sorted by
     * URI (then method). Verifies the three action-format conversions:
     * Closure → '(Closure)', array → 'Class@method', string → cast as-is.
     */
    public function testRouteListToolListsAllRouteActionFormats(): void
    {
        // Arrange — a real Router with three routes using different action types
        $router = new \Pramnos\Routing\Router(new \Pramnos\Application\Container());
        $router->get('/zebra', function () {
            return 'z';
        })->name('zebra.index');
        $router->post('/alpha', [\stdClass::class, 'handle']);
        $router->get('/middle', 'PagesController@show');

        // The Application mock exposes the router via Base magic accessors.
        // __isset must also be stubbed: `$app->router ?? null` calls __isset
        // first, and an unstubbed mock would report the property as unset.
        $app = $this->createMockAppWithRouter($router);

        $tool = new RouteListTool($app);

        // Act
        $routes = $tool->execute([]);

        // Assert — all three routes returned, sorted by URI
        $this->assertCount(3, $routes);
        $this->assertSame(['/alpha', '/middle', '/zebra'], array_column($routes, 'uri'));

        // Array action → 'Class@method'
        $alpha = $routes[0];
        $this->assertSame('POST', $alpha['method']);
        $this->assertSame('stdClass@handle', $alpha['action']);

        // String action → cast verbatim
        $middle = $routes[1];
        $this->assertSame('GET', $middle['method']);
        $this->assertSame('PagesController@show', $middle['action']);

        // Closure action → '(Closure)' placeholder, route name carried through
        $zebra = $routes[2];
        $this->assertSame('(Closure)', $zebra['action']);
        $this->assertSame('zebra.index', $zebra['name']);
        $this->assertIsArray($zebra['permissions']);
    }

    /**
     * RouteListTool::execute() with a 'filter' input must keep only routes
     * whose URI *or* action string contains the substring (case-insensitive),
     * dropping everything else. This is the navigation aid the AI uses to
     * narrow large route maps.
     */
    public function testRouteListToolFiltersByUriOrAction(): void
    {
        // Arrange — one route matching by URI, one by action, one not at all
        $router = new \Pramnos\Routing\Router(new \Pramnos\Application\Container());
        $router->get('/users/list', function () {
        });
        $router->get('/posts', 'UsersController@index'); // matches 'users' via action
        $router->get('/health', function () {
        });

        $app  = $this->createMockAppWithRouter($router);
        $tool = new RouteListTool($app);

        // Act — mixed case to prove case-insensitive matching
        $routes = $tool->execute(['filter' => '  UsErS ']);

        // Assert — '/health' filtered out, the other two kept
        $this->assertSame(['/posts', '/users/list'], array_column($routes, 'uri'));
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

    /**
     * Application mock whose Base::__get/__isset magic exposes a real Router.
     * Both magic methods must be stubbed because `$app->router ?? null`
     * triggers __isset before __get.
     */
    private function createMockAppWithRouter(
        \Pramnos\Routing\Router $router
    ): \Pramnos\Application\Application {
        $app = $this->createMock(\Pramnos\Application\Application::class);
        $app->method('__isset')->willReturnCallback(
            fn(string $name) => $name === 'router'
        );
        $app->method('__get')->willReturnCallback(
            fn(string $name) => $name === 'router' ? $router : null
        );
        return $app;
    }

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

/**
 * Helper class used by testModelInspectToolReadsStaticProperty.
 * Has a public static $table so the isStatic() branch in readProperty() is hit.
 */
class InspectableModelWithStatic
{
    public static string $table = 'static_models';
}

/**
 * Helper class used by testModelInspectToolDetectsRelationMethods.
 * Has a method whose body contains 'belongsTo', which triggers the relation
 * keyword regex in ModelInspectTool::findRelations() (line 94).
 */
class InspectableModelWithRelations
{
    public string $table = 'posts';

    public function author(): object
    {
        // belongsTo User via author_id
        return (object) [];
    }
}
