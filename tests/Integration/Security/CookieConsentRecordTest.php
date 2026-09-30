<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Cookieconsent as ConsentController;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Security\CookieConsent;

/**
 * A signed-in visitor's cookie choice, written to `authserver.user_consents` for real.
 *
 * The consent trail is what answers "did they agree to analytics, and under which policy"
 * months later, so the rows are read back rather than the SQL inspected: one per category
 * the site asks about, refusals included, with the policy version in `consent_type`. Run
 * against MySQL (a prefixed table) and TimescaleDB (a schema-qualified hypertable), because
 * the same logical name resolves to different physical tables on each and a wrong
 * resolution would match nothing silently.
 */
#[CoversClass(CookieConsent::class)]
#[CoversClass(ConsentController::class)]
class CookieConsentRecordTest extends TestCase
{
    private const USER_ID = 424242;

    private Database $db;

    private ?Database $previousSingleton = null;

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'       => ['mysql', 'db', 3306],
            'timescaledb' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    private function connect(string $type, string $host, int $port): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $this->db           = new Database();
        $this->db->type     = $type;
        $this->db->server   = $host;
        $this->db->port     = $port;
        $this->db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        $this->previousSingleton = Factory::getDatabase();
        $singleton               = &Factory::getDatabase();
        $singleton               = $this->db;

        if ($type !== 'mysql') {
            $this->db->query('CREATE SCHEMA IF NOT EXISTS authserver');
        }

        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        $migration = new \Pramnos\Framework\Migrations\Auth\CreateUserConsentsTable($app);
        $migration->down();
        $migration->up();

        Settings::setSetting(CookieConsent::CATEGORIES_SETTING, 'analytics,marketing', false);
        Settings::setSetting(CookieConsent::VERSION_SETTING, '3', false);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->connected) {
            $this->db->schema()->dropTableIfExists('authserver.user_consents');
        }
        if ($this->previousSingleton !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previousSingleton;
        }
        Settings::deleteSetting(CookieConsent::CATEGORIES_SETTING);
        Settings::deleteSetting(CookieConsent::VERSION_SETTING);
    }

    /** @return array<string, array<string, mixed>> Rows keyed by consent_type */
    private function rows(): array
    {
        $result = $this->db->queryBuilder()->table('authserver.user_consents')
            ->where('userid', self::USER_ID)->get();

        $rows = [];
        foreach ($result as $row) {
            $rows[$row['consent_type']] = $row;
        }

        return $rows;
    }

    /**
     * One row per category asked about, the agreement and the refusal alike, each
     * carrying the policy version — a refusal is as much the record as an agreement.
     */
    #[DataProvider('databases')]
    public function testRecordWritesOneRowPerCategory(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);

        // Act
        $written = CookieConsent::record($this->db, self::USER_ID, ['analytics', 'preferences'], '203.0.113.9');

        // Assert
        $rows = $this->rows();
        $this->assertSame(2, $written);
        $this->assertEqualsCanonicalizing(['cookie:analytics@v3', 'cookie:marketing@v3'], array_keys($rows));
        $this->assertSame(1, (int) $rows['cookie:analytics@v3']['granted']);
        $this->assertNull($rows['cookie:analytics@v3']['revoked_at']);
        // "preferences" was sent but the site does not ask about it, so it is not recorded.
        $this->assertSame(0, (int) $rows['cookie:marketing@v3']['granted']);
        $this->assertNotNull($rows['cookie:marketing@v3']['revoked_at'], 'a refusal is stamped as revoked');
        $this->assertSame('consent', $rows['cookie:marketing@v3']['legal_basis']);
        $this->assertSame('203.0.113.9', $rows['cookie:marketing@v3']['ip_address']);
    }

    /**
     * The endpoint writes the trail for a signed-in visitor from the posted JSON.
     */
    #[DataProvider('databases')]
    public function testTheEndpointRecordsASignedInChoice(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $controller = $this->controller(self::USER_ID, '{"granted":["marketing"]}');

        // Act
        $response = $controller->record();

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['recorded' => 2], json_decode((string) $response->getBody(), true));
        $this->assertSame(1, (int) $this->rows()['cookie:marketing@v3']['granted']);
    }

    /**
     * Nobody signed in: nothing to key a row on, so 401 and no rows.
     */
    #[DataProvider('databases')]
    public function testTheEndpointRefusesAnAnonymousVisitor(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $controller = $this->controller(1, '{"granted":["marketing"]}');

        // Act
        $response = $controller->record();

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->rows());
    }

    /**
     * A body that is not the expected JSON records a refusal of everything rather
     * than failing — the least that can be read from it is "agreed to nothing".
     */
    #[DataProvider('databases')]
    public function testAnUnreadableBodyRecordsRefusals(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $controller = $this->controller(self::USER_ID, 'not json');

        // Act
        $response = $controller->record();

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([0, 0], array_map('intval', array_column($this->rows(), 'granted')));
    }

    /**
     * When the trail cannot be written — here, the table is gone — the endpoint says
     * so with a 500 rather than pretending; the cookie already decided.
     */
    #[DataProvider('databases')]
    public function testAFailedWriteIsReported(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $this->db->schema()->dropTableIfExists('authserver.user_consents');
        $controller = $this->controller(self::USER_ID, '{"granted":[]}');

        // Act
        $response = $controller->record();

        // Assert
        $this->assertSame(500, $response->getStatusCode());
    }

    /**
     * `GET /cookieconsent` hands a SPA shell the same configuration the tag carries,
     * or `enabled: false` when the site turned the banner off.
     */
    #[DataProvider('databases')]
    public function testDisplayServesTheConfiguration(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $controller = $this->controller(1, '');

        // Act
        $on = json_decode((string) $controller->display()->getBody(), true);
        Settings::setSetting(CookieConsent::ENABLED_SETTING, '0', false);
        $off = json_decode((string) $controller->display()->getBody(), true);
        Settings::deleteSetting(CookieConsent::ENABLED_SETTING);

        // Assert
        $this->assertSame('3', $on['version']);
        $this->assertSame(['necessary', 'analytics', 'marketing'], array_column($on['categories'], 'name'));
        $this->assertSame(['enabled' => false], $off);
    }

    private function controller(int $userId, string $body): ConsentController
    {
        return new class ($userId, $body) extends ConsentController {
            public function __construct(private int $user, private string $payload)
            {
                // No parent constructor: it resolves an application this test does not need.
            }

            protected function currentUserId(): int
            {
                return $this->user;
            }

            protected function rawBody(): string
            {
                return $this->payload;
            }
        };
    }
}
