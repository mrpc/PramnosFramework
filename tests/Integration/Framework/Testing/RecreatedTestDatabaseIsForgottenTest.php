<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Framework\Testing;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Framework\Testing\TestEnvironment;

/** The migration markers of an application, reachable. */
class RecreatedDatabaseMarkers extends Application
{
    /** No application is built: only the marker methods are wanted. */
    public function __construct()
    {
    }

    /** Remember a fingerprint as verified. */
    public function remember(string $fingerprint): void
    {
        $this->rememberVerifiedFingerprint($fingerprint);
    }

    /** Whether a fingerprint reads as verified. */
    public function verified(string $fingerprint): bool
    {
        return $this->fingerprintAlreadyVerified($fingerprint);
    }
}

/**
 * A test database created under the name of one that is gone starts with nothing remembered
 * about the old one.
 *
 * Dropped and created again — by `rebuildIfOvergrown()`, or by hand — it is a new database to
 * the server and the same one to two caches keyed on its name: the migration markers said "up to
 * date", so nothing migrated and every test failed on a missing table, and the SQL cache answered
 * the settings of the dropped database, so a migration that writes a setting only when it is
 * unset wrote nothing.
 */
#[CoversClass(TestEnvironment::class)]
class RecreatedTestDatabaseIsForgottenTest extends TestCase
{
    private const PROBE = 'pramnos_recreate_probe';

    /** @return array<string, array{string, string, int, string}> */
    public static function servers(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    protected function tearDown(): void
    {
        Application::forgetVerifiedMigrations();
        foreach (self::servers() as [$type, $host, $port, $user]) {
            try {
                $dsn = $type === 'mysql' ? "mysql:host=$host;port=$port" : "pgsql:host=$host;port=$port;dbname=postgres";
                (new PDO($dsn, $user, 'secret'))->exec('DROP DATABASE IF EXISTS ' . self::PROBE);
            } catch (\Throwable) {
                // Not reachable: nothing was made there.
            }
        }
        parent::tearDown();
    }

    /** Run the server's setup step for the probe database. */
    private function setUpDatabase(string $type, string $host, int $port, string $user): void
    {
        $method = new \ReflectionMethod(TestEnvironment::class, $type === 'mysql' ? 'setupMysql' : 'setupPostgres');
        try {
            $method->invoke(null, $host, $port, self::PROBE, $user, 'secret', null);
        } catch (\PDOException $exception) {
            $this->markTestSkipped($host . ' not reachable: ' . $exception->getMessage());
        }
    }

    /**
     * Creating the database forgets what was remembered under its name; finding it there
     * forgets nothing — every run would otherwise re-check its migrations for nothing.
     */
    #[DataProvider('servers')]
    public function testCreatingTheDatabaseForgetsTheOldOneAndFindingItDoesNot(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $markers = new RecreatedDatabaseMarkers();
        $this->setUpDatabase($type, $host, $port, $user);
        (new PDO($type === 'mysql' ? "mysql:host=$host;port=$port" : "pgsql:host=$host;port=$port;dbname=postgres", $user, 'secret'))
            ->exec('DROP DATABASE IF EXISTS ' . self::PROBE);
        $markers->remember('recreated-probe');

        // Act — created
        $this->setUpDatabase($type, $host, $port, $user);
        $afterCreating = $markers->verified('recreated-probe');
        $markers->remember('recreated-probe');
        // Act — found
        $this->setUpDatabase($type, $host, $port, $user);
        $afterFinding = $markers->verified('recreated-probe');

        // Assert
        $this->assertFalse($afterCreating, 'a created database kept the old one\'s markers');
        $this->assertTrue($afterFinding, 'a database that was there lost its markers');
    }

    /**
     * Forgetting the old database removes the markers that said its migrations were done, and
     * the SQL cache of the connection — the settings query first.
     */
    public function testForgettingRemovesTheMarkersAndTheCachedQueries(): void
    {
        // Arrange
        $markers = new RecreatedDatabaseMarkers();
        $markers->remember('recreated-probe');
        $db = \Pramnos\Database\Database::getInstance();
        $db->cacheStore('recreated-probe-query', ['emailtheme' => 'old'], 'settings', 300);
        $this->assertTrue($markers->verified('recreated-probe'), 'the marker was not written: nothing to test');
        $this->assertNotEmpty($db->cacheRead('recreated-probe-query', 'settings'), 'no SQL cache here: nothing to test');

        // Act
        (new \ReflectionMethod(TestEnvironment::class, 'forgetTheDatabaseBefore'))->invoke(null);

        // Assert
        $this->assertFalse($markers->verified('recreated-probe'), 'the old database\'s migrations would be skipped');
        $this->assertEmpty($db->cacheRead('recreated-probe-query', 'settings'), 'the old database\'s settings would be read');
    }
}
