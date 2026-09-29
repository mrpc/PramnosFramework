<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Schema;
use Pramnos\Http\Middleware\ApiKeyScopeMiddleware;

/**
 * The scopes a real application row grants its key.
 *
 * `applications.scope` is the space-separated list the administration screen saves; this is
 * the read that had never existed. Both backends, because the row is found through
 * `Apikey::load()` on each.
 */
#[CoversClass(ApiKeyScopeMiddleware::class)]
class ApiKeyScopeResolverTest extends BaseTestCase
{
    private $db;

    private string $key = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        Schema::table('applications', $this->db);
        $this->key = bin2hex(random_bytes(16));
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table('applications')->where('apikey', $this->key)->delete();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /**
     * A key's application grants the scopes its row lists, parsed from the saved string.
     */
    public function testAnApplicationGrantsTheScopesItsRowLists(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('applications')->insert([
            'name' => 'Scoped client', 'apikey' => $this->key, 'scope' => 'stations:read stats:read', 'status' => 1,
        ]);

        // Act
        $granted = ApiKeyScopeMiddleware::grantedScopes($this->key);

        // Assert
        $this->assertSame(['stations:read', 'stats:read'], $granted);
    }

    /**
     * An application saved with no scope grants nothing, and so does a key with no row.
     *
     * Empty rather than null: null would mean «the application itself» and skip the check.
     */
    public function testNoScopeAndNoRowGrantNothing(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('applications')->insert([
            'name' => 'Unscoped client', 'apikey' => $this->key, 'status' => 1,
        ]);

        // Act
        $unscoped = ApiKeyScopeMiddleware::grantedScopes($this->key);
        $unknown  = ApiKeyScopeMiddleware::grantedScopes(bin2hex(random_bytes(16)));

        // Assert
        $this->assertSame([], $unscoped);
        $this->assertSame([], $unknown);
    }
}
