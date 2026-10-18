<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\AuditLog;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Mcp\McpToolInterface;
use Pramnos\Mcp\PublicRegistry;
use Pramnos\Mcp\ScopedTool;

/**
 * `PublicRegistry::audited()` writes an audit row for every public tool call, on both backends:
 * the user, the tool, the client the token names, and the parameter names — never their values,
 * which may be anything a model typed.
 */
#[CoversClass(PublicRegistry::class)]
final class McpAuditHookTest extends TestCase
{
    private ?Database $db = null;

    private ?Database $previous = null;

    private string $tool = '';

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->queryBuilder()->table(AuditLog::TABLE)->where('object_id', $this->tool)->delete();
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        AuditLog::resetTableCache();
        PublicRegistry::reset();
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /** The calls of a tool are audited by name, client and parameter names. */
    #[DataProvider('databases')]
    public function testEveryCallIsAudited(string $type, string $host, int $port): void
    {
        // Arrange — the framework's database with the audit table, and a token naming its client
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable::class], $db);
        AuditLog::resetTableCache();
        $this->tool = 'audited_' . bin2hex(random_bytes(3));
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . \Pramnos\Auth\JWT::encode(['aud' => 'claude-connector'], str_repeat('k', 32), 'HS256');
        PublicRegistry::add(ScopedTool::wrap(new class ($this->tool) implements McpToolInterface {
            public function __construct(private string $toolName)
            {
            }

            /** Its name. */
            public function name(): string
            {
                return $this->toolName;
            }

            /** What it does. */
            public function description(): string
            {
                return 'Counts.';
            }

            /** What it takes. */
            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            /** A count. */
            public function execute(array $input): mixed
            {
                return 3;
            }
        }, 'mcp'));
        PublicRegistry::around(PublicRegistry::audited());

        // Act
        $result = PublicRegistry::availableTo(['mcp'], (object) ['userid' => 42, 'usertype' => 90])[0]->execute(['search' => 'secret words', 'limit' => 5]);
        $rows   = AuditLog::query(['event_type' => 'mcp.tool_call'], 200);
        $row    = array_values(array_filter($rows, fn (array $r): bool => ($r['object_id'] ?? null) === $this->tool))[0] ?? null;

        // Assert
        $this->assertSame(3, $result, 'the call went through');
        $this->assertNotNull($row, 'the call left a row');
        $metadata = is_array($row['metadata']) ? $row['metadata'] : (array) json_decode((string) $row['metadata'], true);
        $this->assertSame(42, (int) $row['actor_userid']);
        $this->assertSame(['tool' => $this->tool, 'client' => 'claude-connector', 'parameters' => ['search', 'limit']], $metadata);
        $this->assertStringNotContainsString('secret words', (string) json_encode($row), 'names, never values');
    }
}
