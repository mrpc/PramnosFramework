<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\ApplicationService;
use Pramnos\Auth\PasswordHash;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Schema;

/**
 * Managing applications without the administration screen.
 *
 * An application with its own panel calls this instead of re-implementing the rules. What
 * matters is that the rules hold on the real tables of both backends: the secret is stored
 * hashed and handed out once, a script scheme is refused out loud, and retiring an application
 * revokes what it holds.
 */
#[CoversClass(ApplicationService::class)]
class ApplicationServiceTest extends BaseTestCase
{
    private $db;

    private ApplicationService $service;

    /** @var list<int> Applications this test created, removed afterwards. */
    private array $created = [];

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

        \Pramnos\User\User::setupDb();
        Schema::table('applications', $this->db);
        Schema::table('usertokens', $this->db);
        $this->service = new ApplicationService($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $appId) {
            $this->db->queryBuilder()->table('usertokens')->where('applicationid', $appId)->delete();
            $this->db->queryBuilder()->table('applications')->where('appid', $appId)->delete();
        }

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** Create one application, remembering it for tearDown. */
    private function create(array $input = []): array
    {
        $created = $this->service->create($input + ['name' => 'Service client ' . bin2hex(random_bytes(3))]);
        $this->created[] = $created['appid'];

        return $created;
    }

    /** The stored row, secrets included. */
    private function row(int $appId): array
    {
        return (array) $this->db->queryBuilder()->table('applications')->where('appid', $appId)->first()->fields;
    }

    /**
     * Creating issues a key and a secret; the secret is stored as a hash that verifies.
     *
     * Returned once — the caller's only chance to show it — and never readable from the row.
     */
    public function testCreatingIssuesCredentialsAndStoresTheSecretHashed(): void
    {
        // Act
        $created = $this->create(['callback' => "https://a.example/cb\nhttps://b.example/cb", 'scope' => 'stations:read']);

        // Assert
        $row = $this->row($created['appid']);
        $this->assertGreaterThan(0, $created['appid']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $created['apikey']);
        $this->assertSame($created['apikey'], $row['apikey']);
        $this->assertNotSame($created['secret'], $row['apisecret'], 'the secret was stored in clear');
        $this->assertNotNull(PasswordHash::verify($created['secret'], (string) $row['apisecret']));
        $this->assertSame('https://a.example/cb https://b.example/cb', $row['callback'], 'callbacks were not normalised');
        $this->assertSame('stations:read', $row['scope']);
    }

    /**
     * The public description never carries a secret, hashed or otherwise.
     */
    public function testFindLeavesOutTheSecrets(): void
    {
        // Arrange
        $created = $this->create();

        // Act
        $found = $this->service->find($created['appid']);

        // Assert
        $this->assertSame($created['apikey'], $found['apikey']);
        $this->assertArrayNotHasKey('apisecret', $found);
        $this->assertArrayNotHasKey('broadcast_secret', $found);
        $this->assertNull($this->service->find(0));
    }

    /**
     * Refused input is refused before anything is written, with a sentence for a person.
     *
     * A missing name, a script-only callback scheme — which `parseRedirectUris()` would otherwise
     * drop silently on read — and a list longer than the column.
     */
    public function testRefusedInputWritesNothing(): void
    {
        // Act
        $noName = $this->service->validate(['name' => '  ']);
        $script = $this->service->validate(['name' => 'x', 'callback' => 'javascript:alert(1)']);
        $long   = $this->service->validate(['name' => 'x', 'callback' => 'https://a.example/' . str_repeat('a', 300)], 255);
        $thrown = null;
        try {
            $this->service->create(['name' => '']);
        } catch (\InvalidArgumentException $exception) {
            $thrown = $exception->getMessage();
        }

        // Assert
        $this->assertSame('A name is required.', $noName['error']);
        $this->assertStringContainsString('javascript:alert(1)', (string) $script['error']);
        $this->assertStringContainsString('Run the framework migrations', (string) $long['error']);
        $this->assertSame('A name is required.', $thrown);
    }

    /**
     * Fields are clamped to what the columns mean, and missing ones take the form's defaults.
     */
    public function testFieldsAreClampedAndDefaulted(): void
    {
        // Act
        $fields = $this->service->validate(['name' => 'x', 'status' => 7, 'apptype' => -2, 'accesstype' => 9, 'public' => '0'])['fields'];

        // Assert
        $this->assertSame([1, 0, 2, 1, 0, 'v1'], [
            $fields['status'], $fields['apptype'], $fields['accesstype'], $fields['public'],
            $fields['is_confidential'], $fields['apiversion'],
        ]);
        $this->assertNull($fields['callback']);
    }

    /**
     * Updating changes the fields and never the credentials.
     */
    public function testUpdatingLeavesTheCredentialsAlone(): void
    {
        // Arrange
        $created = $this->create();
        $before  = $this->row($created['appid']);

        // Act
        $updated = $this->service->update($created['appid'], ['name' => 'Renamed', 'scope' => 'stats:read']);
        $missing = $this->service->update(0, ['name' => 'Nobody']);

        // Assert
        $after = $this->row($created['appid']);
        $this->assertTrue($updated);
        $this->assertFalse($missing);
        $this->assertSame('Renamed', $after['name']);
        $this->assertSame([$before['apikey'], $before['apisecret']], [$after['apikey'], $after['apisecret']]);
    }

    /**
     * Rotating issues a secret that verifies, and the old one stops verifying.
     */
    public function testRotatingReplacesTheSecret(): void
    {
        // Arrange
        $created = $this->create();

        // Act
        $new     = $this->service->rotateSecret($created['appid']);
        $missing = $this->service->rotateSecret(0);

        // Assert
        $hash = (string) $this->row($created['appid'])['apisecret'];
        $this->assertNotNull(PasswordHash::verify((string) $new, $hash));
        $this->assertNull(PasswordHash::verify($created['secret'], $hash), 'the old secret still verifies');
        $this->assertNull($missing);
    }

    /**
     * Retiring switches the application off and revokes its active tokens, keeping the rows.
     *
     * The tokens listing shows active ones only, so it is empty afterwards.
     */
    public function testDeactivatingRevokesTheTokens(): void
    {
        // Arrange
        $created = $this->create();
        $userId  = (int) $this->db->queryBuilder()->table('users')->select(['userid'])->orderBy('userid', 'asc')->first()->fields['userid'];
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid' => $userId, 'tokentype' => 'oauth', 'token' => bin2hex(random_bytes(8)),
            'applicationid' => $created['appid'], 'status' => 1, 'created' => time(), 'lastused' => time(),
        ]);
        $listed = $this->service->tokens($created['appid']);

        // Act
        $done    = $this->service->deactivate($created['appid']);
        $missing = $this->service->deactivate(0);

        // Assert
        $this->assertCount(1, $listed, 'the active token was not listed');
        $this->assertArrayNotHasKey('token', $listed[0], 'the listing exposed the token itself');
        $this->assertTrue($done);
        $this->assertFalse($missing);
        $this->assertSame(0, (int) $this->row($created['appid'])['status']);
        $this->assertSame([], $this->service->tokens($created['appid']));
        $revoked = (int) $this->db->queryBuilder()->table('usertokens')
            ->where('applicationid', $created['appid'])->where('status', 3)->count();
        $this->assertSame(1, $revoked, 'the token row was not kept as revoked');
    }

    /**
     * The callback column's width is read from the catalogue, and a framework database has `text`.
     *
     * PostgreSQL reports `text` as unbounded (0); MySQL reports its 65,535-character limit. Either
     * way it is not the `varchar(255)` of a table that predates the migrations.
     */
    public function testTheCallbackCeilingOfAFrameworkDatabaseIsText(): void
    {
        // Act
        $ceiling = $this->service->callbackCeiling();

        // Assert
        $this->assertTrue($ceiling === 0 || $ceiling >= 65535, 'ceiling read as ' . $ceiling);
    }
}
