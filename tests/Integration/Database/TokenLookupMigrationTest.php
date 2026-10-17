<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\Auth\AddTokenLookupToUsertokens;
use Pramnos\User\Token;

/**
 * `add_token_lookup_to_usertokens` on a table it does not get to choose.
 *
 * The same token stored twice gives the same digest twice, and a UNIQUE index on it failed and
 * took the whole migration batch with it — an installation had two hundred and fifty such pairs.
 * And whether the values were encrypted was decided by an `APP_KEY` being present for anything
 * at all, which broke an application whose own code still read them.
 */
#[CoversClass(AddTokenLookupToUsertokens::class)]
#[CoversClass(Token::class)]
final class TokenLookupMigrationTest extends TestCase
{
    private ?Database $db = null;

    private string|false $appKey = false;

    private mixed $savedAuth = null;

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function setUp(): void
    {
        $this->appKey = getenv('APP_KEY');
        putenv('APP_KEY=base64:' . base64_encode(random_bytes(32)));
        $app             = \Pramnos\Application\Application::currentInstance() ?? \Pramnos\Application\Application::getInstance();
        $this->savedAuth = $app->applicationInfo['auth'] ?? null;
    }

    /** The PostgreSQL schema this test made, dropped with it. */
    private string $pgSchema = '';

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            if ($this->pgSchema !== '') {
                $this->db->query('DROP SCHEMA ' . $this->pgSchema . ' CASCADE');
                $this->db->query('SET search_path TO public');
            } else {
                $this->db->schema()->dropTableIfExists('usertokens');
            }
        }
        $this->appKey === false ? putenv('APP_KEY') : putenv('APP_KEY=' . $this->appKey);
        \Pramnos\Application\Application::currentInstance()->applicationInfo['auth'] = $this->savedAuth;
        Token::forgetSchemaCache();
    }

    /** Its own `usertokens` under a prefix of its own, holding these live tokens; the migration over it. */
    private function table(string $type, string $host, int $port, array $tokens): AddTokenLookupToUsertokens
    {
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
        $this->db = $db;
        if ($type === 'postgresql') {
            // Index names are per schema there, and the real usertokens already holds this one:
            // a schema of its own, first on the search path.
            $this->pgSchema = 'tl' . bin2hex(random_bytes(3));
            $db->query('CREATE SCHEMA ' . $this->pgSchema);
            $db->query('SET search_path TO ' . $this->pgSchema . ', public');
            $db->schema = $this->pgSchema;
        } else {
            $db->prefix = 'tl' . bin2hex(random_bytes(2)) . '_';
        }
        $db->schema()->createTable('usertokens', function ($table): void {
            $table->increments('tokenid');
            $table->text('token')->nullable();
            $table->integer('status')->default(1);
            $table->integer('expires')->nullable();
        });
        foreach ($tokens as $token) {
            $db->queryBuilder()->table('#PREFIX#usertokens')->insert(['token' => $token, 'status' => 1]);
        }
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;

        return new AddTokenLookupToUsertokens($app);
    }

    /** Whether a second row with this digest is refused. */
    private function refusesASecond(string $lookup): bool
    {
        try {
            $stored = $this->db->queryBuilder()->table('#PREFIX#usertokens')->insert(['token' => 'x', 'status' => 0, 'token_lookup' => $lookup]);

            return $stored === false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Duplicated tokens: the migration completes, the lookup index is a plain one, and — with
     * `encrypt_tokens` off — the values stay as they were.
     */
    #[DataProvider('databases')]
    public function testDuplicatesGetAPlainIndexAndTheSettingKeepsThePlaintext(string $type, string $host, int $port): void
    {
        // Arrange
        \Pramnos\Application\Application::currentInstance()->applicationInfo['auth'] = ['encrypt_tokens' => false];
        $migration = $this->table($type, $host, $port, ['same-token', 'same-token', 'other-token']);

        // Act
        $migration->up();
        $values = array_column($this->rows(), 'token');

        // Assert
        $this->assertTrue($this->db->schema()->hasIndex('usertokens', 'idx_usertokens_token_lookup'));
        $this->assertFalse($this->refusesASecond(Token::lookup('other-token')), 'not unique: the duplicates are there');
        $this->assertSame(['same-token', 'same-token', 'other-token'], $values, 'left as they were');
        $this->assertFalse(Token::encryptsAtRest());
        $this->assertSame('plain', Token::storageFor('plain')['token']);
        putenv('APP_KEY');
        $this->assertFalse(Token::encryptsAtRest(), 'and without a key, whatever the setting');
    }

    /** Without duplicates, and by default, the index is unique and the values are encrypted. */
    #[DataProvider('databases')]
    public function testOtherwiseTheIndexIsUniqueAndTheValuesEncrypted(string $type, string $host, int $port): void
    {
        // Arrange
        \Pramnos\Application\Application::currentInstance()->applicationInfo['auth'] = [];
        $migration = $this->table($type, $host, $port, ['one-token', 'two-token']);

        // Act
        $migration->up();
        $rows = $this->rows();

        // Assert
        $this->assertTrue($this->refusesASecond(Token::lookup('one-token')), 'unique');
        $this->assertTrue(\Pramnos\Security\Encrypter::isEncrypted((string) $rows[0]['token']));
        $this->assertSame(Token::lookup('one-token'), $rows[0]['token_lookup']);
        $this->assertTrue(Token::encryptsAtRest());
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $result = $this->db->queryBuilder()->table('#PREFIX#usertokens')->orderBy('tokenid')->get();
        $rows   = [];
        while ($result && $result->fetch()) {
            $rows[] = $result->fields;
        }

        return $rows;
    }
}
