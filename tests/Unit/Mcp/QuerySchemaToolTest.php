<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Tools\QuerySchemaTool;
use Pramnos\Database\Database;

/**
 * Unit tests for Pramnos\Mcp\Tools\QuerySchemaTool.
 *
 * QuerySchemaTool is an MCP tool that introspects a database table and returns
 * its columns, indexes, and foreign keys. It works across MySQL and PostgreSQL
 * by issuing different SQL queries depending on $db->type.
 *
 * Because opening a real database connection is not possible in pure unit tests,
 * the Database object is replaced with a mock that captures the SQL strings
 * passed to query() and returns controlled result sets.
 *
 * Branches covered:
 *  - name(), description(), inputSchema() metadata methods.
 *  - execute() with empty table name → error returned.
 *  - execute() with disconnected DB → error returned.
 *  - execute() on MySQL → correct column/index/fk SQL dispatched.
 *  - execute() on PostgreSQL → correct column/index/fk SQL dispatched.
 *  - execute() returns the four expected top-level keys.
 *  - fetchColumns() / fetchIndexes() / fetchForeignKeys() return [] when
 *    query() returns null (defensive null-check in the tool).
 */
#[CoversClass(QuerySchemaTool::class)]
class QuerySchemaToolTest extends TestCase
{
    /** @var string[] SQL strings captured from Database::query() calls */
    private array $sqlLog = [];

    protected function setUp(): void
    {
        $this->sqlLog = [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a connected Database mock of the specified type.
     *
     * SQL strings passed to query() are appended to $this->sqlLog so
     * assertions can inspect which queries were issued.
     *
     * @param string $dbType    'mysql' or 'postgresql'
     * @param bool   $connected Whether $db->connected is true
     */
    private function buildDb(
        string $dbType,
        bool $connected = true
    ): Database&\PHPUnit\Framework\MockObject\MockObject {
        $db            = $this->createMock(Database::class);
        $db->connected = $connected;
        $db->type      = $dbType;

        // prepareQuery() just returns the first arg so we can inspect the raw SQL
        $db->method('prepareQuery')->willReturnCallback(
            function (string $sql, ...$args): string {
                // Substitute the single %s placeholder with the table name arg
                if (!empty($args)) {
                    $sql = preg_replace('/%s/', (string) $args[0], $sql, 1);
                }
                return $sql;
            }
        );

        $db->method('query')->willReturnCallback(
            function (string $sql): \Pramnos\Database\Result {
                $this->sqlLog[] = $sql;
                // Return a Result stub with an empty fetchAll()
                $res = $this->createMock(\Pramnos\Database\Result::class);
                $res->method('fetchAll')->willReturn([]);
                return $res;
            }
        );

        return $db;
    }

    // =========================================================================
    // Metadata
    // =========================================================================

    /**
     * name() must return 'query-schema' — the MCP tool name used in tool
     * dispatch and in schema registration.
     */
    public function testNameReturnsQuerySchema(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act / Assert
        $this->assertSame('query-schema', $tool->name(),
            'name() must return the string "query-schema"');
    }

    /**
     * description() must return a non-empty string that describes what the
     * tool does. The MCP spec requires a human-readable description in the
     * tool listing.
     */
    public function testDescriptionIsNonEmpty(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act
        $desc = $tool->description();

        // Assert
        $this->assertIsString($desc);
        $this->assertNotEmpty($desc, 'description() must return a non-empty string');
    }

    /**
     * inputSchema() must return an object-type schema with 'table' in the
     * required array.
     *
     * The MCP server uses this schema to validate incoming tool calls before
     * forwarding them to execute().
     */
    public function testInputSchemaStructure(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act
        $schema = $tool->inputSchema();

        // Assert — top-level type
        $this->assertSame('object', $schema['type'],
            'inputSchema type must be "object"');

        // Assert — 'table' property declared
        $this->assertArrayHasKey('table', $schema['properties'],
            'inputSchema must declare the "table" property');

        // Assert — 'table' is required
        $this->assertContains('table', $schema['required'],
            '"table" must appear in the required array');
    }

    // =========================================================================
    // execute() — input validation
    // =========================================================================

    /**
     * execute() with an empty 'table' value must return an error array rather
     * than proceeding to query the database.
     *
     * An empty table name would produce invalid SQL and must be rejected early.
     */
    public function testExecuteReturnsErrorForEmptyTableName(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act
        $result = $tool->execute(['table' => '']);

        // Assert
        $this->assertArrayHasKey('error', $result,
            'execute() must return an error for an empty table name');
        $this->assertStringContainsString('required', $result['error'],
            'Error message must indicate the table parameter is required');
    }

    /**
     * execute() with the 'table' key missing must also return an error.
     *
     * The tool must handle a completely absent key without throwing a PHP notice.
     */
    public function testExecuteReturnsErrorWhenTableKeyMissing(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act
        $result = $tool->execute([]);

        // Assert
        $this->assertArrayHasKey('error', $result,
            'execute() must return an error when the "table" key is absent');
    }

    /**
     * execute() must return an error when the database is not connected.
     *
     * The tool performs no queries when $db->connected is false; instead it
     * degrades gracefully with an error message that can be returned to the
     * MCP client.
     */
    public function testExecuteReturnsErrorWhenNotConnected(): void
    {
        // Arrange — connected = false
        $db   = $this->buildDb('mysql', connected: false);
        $tool = new QuerySchemaTool($db);

        // Act
        $result = $tool->execute(['table' => 'users']);

        // Assert
        $this->assertArrayHasKey('error', $result,
            'execute() must return an error when the DB is not connected');
        $this->assertStringContainsString('not connected', strtolower($result['error']),
            'Error message must indicate the database is not connected');
    }

    // =========================================================================
    // execute() — result structure
    // =========================================================================

    /**
     * execute() must return an array with the four required top-level keys:
     * table, columns, indexes, foreign_keys.
     *
     * These keys are the MCP response contract. Any missing key would break the
     * client that parses the response.
     */
    public function testExecuteReturnsRequiredTopLevelKeys(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act
        $result = $tool->execute(['table' => 'users']);

        // Assert — all four keys present
        $this->assertArrayHasKey('table',        $result, '"table" key must be present');
        $this->assertArrayHasKey('columns',      $result, '"columns" key must be present');
        $this->assertArrayHasKey('indexes',      $result, '"indexes" key must be present');
        $this->assertArrayHasKey('foreign_keys', $result, '"foreign_keys" key must be present');

        // Assert — table echoed back
        $this->assertSame('users', $result['table'],
            '"table" value must echo the requested table name');
    }

    // =========================================================================
    // execute() — MySQL SQL dispatch
    // =========================================================================

    /**
     * On MySQL, execute() must issue three queries that read from
     * information_schema: columns, statistics, and key_column_usage.
     *
     * This verifies that the MySQL branch of each fetch* method is activated
     * when $db->type is 'mysql'.
     */
    public function testExecuteMysqlIssuesthreeQueries(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act
        $tool->execute(['table' => 'orders']);

        // Assert — exactly three queries for columns, indexes, foreign_keys
        $this->assertCount(3, $this->sqlLog,
            'execute() must issue exactly 3 queries on MySQL (columns, indexes, fk)');
    }

    /**
     * MySQL column query must reference information_schema.columns and use
     * DATABASE() to scope to the current schema.
     */
    public function testExecuteMysqlColumnQueryUsesInformationSchema(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act
        $tool->execute(['table' => 'products']);

        // Assert — first query is the columns query
        $this->assertStringContainsString('information_schema.columns', strtolower($this->sqlLog[0]),
            'MySQL column query must read from information_schema.columns');
        $this->assertStringContainsString('database()', strtolower($this->sqlLog[0]),
            'MySQL column query must scope to DATABASE()');
        $this->assertStringContainsString('products', $this->sqlLog[0],
            'MySQL column query must include the table name');
    }

    /**
     * MySQL index query must reference information_schema.statistics.
     */
    public function testExecuteMysqlIndexQueryUsesStatistics(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act
        $tool->execute(['table' => 'orders']);

        // Assert — second query is the index query
        $this->assertStringContainsString('statistics', strtolower($this->sqlLog[1]),
            'MySQL index query must read from information_schema.statistics');
    }

    /**
     * MySQL foreign key query must reference key_column_usage and
     * referential_constraints.
     */
    public function testExecuteMysqlForeignKeyQueryUsesKeyColumnUsage(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act
        $tool->execute(['table' => 'orders']);

        // Assert — third query is the FK query
        $this->assertStringContainsString('key_column_usage', strtolower($this->sqlLog[2]),
            'MySQL FK query must read from key_column_usage');
        $this->assertStringContainsString('referential_constraints', strtolower($this->sqlLog[2]),
            'MySQL FK query must join referential_constraints');
    }

    // =========================================================================
    // execute() — PostgreSQL SQL dispatch
    // =========================================================================

    /**
     * On PostgreSQL, execute() must issue three queries that use the PostgreSQL
     * system catalogues (information_schema and pg_indexes) instead of MySQL's.
     */
    public function testExecutePostgresqlIssuesThreeQueries(): void
    {
        // Arrange
        $db   = $this->buildDb('postgresql');
        $tool = new QuerySchemaTool($db);

        // Act
        $tool->execute(['table' => 'measurements']);

        // Assert
        $this->assertCount(3, $this->sqlLog,
            'execute() must issue exactly 3 queries on PostgreSQL');
    }

    /**
     * PostgreSQL column query must scope to the 'public' schema rather than
     * DATABASE() (which is MySQL-only syntax).
     */
    public function testExecutePostgresqlColumnQueryUsesPublicSchema(): void
    {
        // Arrange
        $db   = $this->buildDb('postgresql');
        $tool = new QuerySchemaTool($db);

        // Act
        $tool->execute(['table' => 'measurements']);

        // Assert
        $this->assertStringContainsString("'public'", $this->sqlLog[0],
            'PostgreSQL column query must scope to the public schema');
        $this->assertStringNotContainsString('DATABASE()', $this->sqlLog[0],
            'PostgreSQL column query must not use MySQL DATABASE() function');
        $this->assertStringContainsString('measurements', $this->sqlLog[0]);
    }

    /**
     * PostgreSQL index query must read from pg_indexes rather than
     * information_schema.statistics.
     */
    public function testExecutePostgresqlIndexQueryUsesPgIndexes(): void
    {
        // Arrange
        $db   = $this->buildDb('postgresql');
        $tool = new QuerySchemaTool($db);

        // Act
        $tool->execute(['table' => 'measurements']);

        // Assert — second query is the index query
        $this->assertStringContainsString('pg_indexes', strtolower($this->sqlLog[1]),
            'PostgreSQL index query must read from pg_indexes');
    }

    /**
     * PostgreSQL foreign key query must use information_schema.table_constraints
     * rather than key_column_usage (MySQL approach).
     */
    public function testExecutePostgresqlForeignKeyQueryUsesTableConstraints(): void
    {
        // Arrange
        $db   = $this->buildDb('postgresql');
        $tool = new QuerySchemaTool($db);

        // Act
        $tool->execute(['table' => 'measurements']);

        // Assert — third query is the FK query
        $this->assertStringContainsString('table_constraints', strtolower($this->sqlLog[2]),
            'PostgreSQL FK query must use information_schema.table_constraints');
        $this->assertStringContainsString("'FOREIGN KEY'", $this->sqlLog[2],
            'PostgreSQL FK query must filter by constraint_type = FOREIGN KEY');
    }

    // =========================================================================
    // Defensive null-check in fetch* methods
    // =========================================================================

    /**
     * When query() returns null, fetchColumns / fetchIndexes / fetchForeignKeys
     * must return an empty array rather than throwing a TypeError.
     *
     * Some database drivers return null on error rather than throwing; the tool
     * must handle this gracefully.
     */
    public function testExecuteHandlesNullQueryResult(): void
    {
        // Arrange — query() always returns null
        $db            = $this->createMock(Database::class);
        $db->connected = true;
        $db->type      = 'mysql';
        $db->method('prepareQuery')->willReturnArgument(0);
        $db->method('query')->willReturn(null);

        $tool = new QuerySchemaTool($db);

        // Act — must not throw
        $result = $tool->execute(['table' => 'fragile_table']);

        // Assert — graceful empty arrays
        $this->assertSame([], $result['columns'],
            'columns must be [] when query() returns null');
        $this->assertSame([], $result['indexes'],
            'indexes must be [] when query() returns null');
        $this->assertSame([], $result['foreign_keys'],
            'foreign_keys must be [] when query() returns null');
    }

    /**
     * Whitespace-only table names must be treated as empty and return an error.
     *
     * The tool calls trim() on the input; a string like '   ' should be caught
     * by the same guard that catches '' (empty after trim).
     */
    public function testExecuteReturnsErrorForWhitespaceOnlyTableName(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act
        $result = $tool->execute(['table' => '   ']);

        // Assert
        $this->assertArrayHasKey('error', $result,
            'A whitespace-only table name must be treated as empty and return an error');
    }

    /**
     * execute() must echo the table name back in the result exactly as given
     * (after trim) so the MCP client can correlate the response with the request.
     */
    public function testExecuteEchosTableNameInResult(): void
    {
        // Arrange
        $db   = $this->buildDb('mysql');
        $tool = new QuerySchemaTool($db);

        // Act
        $result = $tool->execute(['table' => '  orders  ']);

        // Assert — trimmed table name is echoed back
        $this->assertSame('orders', $result['table'],
            'The table name in the result must be the trimmed input value');
    }
}
