<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Binary;
use Pramnos\Database\Database;
use Pramnos\Database\Result;

/**
 * Bytes into a `blob` / `bytea` column and back, the same on both databases (FW-165 §5).
 *
 * PostgreSQL takes a bound string as text: bytes that are not UTF-8 were refused, a backslash read
 * as an escape — one application's single INSERT of binary data failed 1,343 tests — and a
 * `bytea` came back as hex text where MySQL gave the bytes. `Binary` binds them as bytes, and a
 * `bytea` is read back as the bytes.
 */
#[CoversClass(Binary::class)]
#[CoversClass(Result::class)]
#[CoversClass(Database::class)]
final class BinaryValuesTest extends TestCase
{
    private ?Database $db = null;

    private string $table = '';

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->table !== '') {
            $this->db?->query('DROP TABLE IF EXISTS ' . $this->table);
        }
        $this->db?->close();
    }

    /**
     * Bytes that are not UTF-8, with a NUL, a backslash and a quote, go in through execute() and
     * through the query builder, and come back the same through query(), execute() and get();
     * a NULL stays NULL.
     */
    #[DataProvider('databases')]
    public function testBytesGoInAndComeBackTheSame(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->db    = $db;
        $this->table = 'binvals_' . bin2hex(random_bytes(3));
        $db->query('CREATE TABLE ' . $this->table . ' (id INT PRIMARY KEY, body ' . ($type === 'mysql' ? 'BLOB' : 'BYTEA') . ')');
        $bytes = "\x00\xff\xfe" . '\\x41' . "'" . "\x80end";

        // Act
        $db->execute('INSERT INTO ' . $this->table . ' (id, body) VALUES (%i, %s)', ...[1, new Binary($bytes)]);
        $db->queryBuilder()->table($this->table)->insert(['id' => 2, 'body' => new Binary($bytes)]);
        $db->queryBuilder()->table($this->table)->insert(['id' => 3, 'body' => null]);
        $viaQuery = $db->query('SELECT body FROM ' . $this->table . ' WHERE id = 1');
        $viaExecute = $db->execute('SELECT body FROM ' . $this->table . ' WHERE id = %i', ...[2]);
        $viaBuilder = $db->queryBuilder()->table($this->table)->select(['id', 'body'])->orderBy('id')->get();
        $all = [];
        while ($viaBuilder->fetch()) {
            $all[(int) $viaBuilder->fields['id']] = $viaBuilder->fields['body'];
        }

        // Assert
        $this->assertSame($bytes, $viaQuery->fields['body'], 'through query()');
        $this->assertSame($bytes, $viaExecute->fields['body'], 'through execute()');
        $this->assertSame([1 => $bytes, 2 => $bytes, 3 => null], $all, 'through the builder, row after row');
    }
}
