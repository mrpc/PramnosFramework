<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;

/**
 * A migration fingerprint is verified **against a database**, and the cache has to say which.
 *
 * The fingerprint answers "have these migration files been verified". It never said against
 * what, and while one checkout meant one database in practice that cost nothing. It now
 * routinely means two — the application's and the suite's — and a verification performed
 * against the first was suppressing the check for the second.
 *
 * What that looked like: the first run after the test-database fix pointed at an empty test
 * database and left it empty. Every test needing a table failed with "the table does not
 * exist", and **nothing anywhere mentioned migrations**. The cure was deleting
 * `var/migrations/*.verified` by hand — not a diagnosis anybody reaches from the failure.
 *
 * So both copies of the cache are asserted to be per-connection: the APCu key and the file
 * name. The fingerprint itself is deliberately unchanged — the copy stored inside the
 * database is per-database by construction, since it lives in the one it describes.
 */
#[CoversClass(Application::class)]
class MigrationFingerprintScopeTest extends TestCase
{
    private mixed $previous = null;

    protected function setUp(): void
    {
        $this->previous = Settings::getSetting('database');
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            Settings::setSetting('database', (array) $this->previous, false);
        }

        parent::tearDown();
    }

    /** An Application whose protected cache helpers can be read. */
    private function application(): object
    {
        return new class extends Application {
            public function __construct()
            {
                // The helpers under test read settings and constants, nothing else.
            }

            public function key(string $fingerprint): string
            {
                return $this->fingerprintCacheKey($fingerprint);
            }

            public function file(string $fingerprint): ?string
            {
                return $this->fingerprintCacheFile($fingerprint);
            }
        };
    }

    /** Point the settings at a database. */
    private function useDatabase(string $name, string $host = 'db'): void
    {
        Settings::setSetting('database', [
            'type'     => 'mysql',
            'hostname' => $host,
            'port'     => 3306,
            'database' => $name,
        ], false);
    }

    /**
     * Two databases in one checkout get two cache keys, and two files.
     *
     * This is the whole defect: the same checkout, the same migration files, and a
     * verification against the development database answering for the test one.
     */
    public function testTheCacheIsKeyedByDatabase(): void
    {
        // Arrange
        $application = $this->application();
        $fingerprint = 'abc123';

        // Act
        $this->useDatabase('project_db');
        $developmentKey  = $application->key($fingerprint);
        $developmentFile = $application->file($fingerprint);

        $this->useDatabase('project_db_test');
        $testKey  = $application->key($fingerprint);
        $testFile = $application->file($fingerprint);

        // Assert
        $this->assertNotSame($developmentKey, $testKey, 'the APCu key must distinguish them');
        $this->assertNotSame($developmentFile, $testFile, 'the .verified file must too');
    }

    /**
     * The host is part of it, not only the name.
     *
     * Two servers hosting a database of the same name is the ordinary shape of a staging
     * box beside a development one, and a cache that ignored the host would have them
     * answer for each other.
     */
    public function testTheHostIsPartOfTheIdentity(): void
    {
        // Arrange
        $application = $this->application();

        // Act
        $this->useDatabase('project_db', 'localhost');
        $local = $application->key('abc123');

        $this->useDatabase('project_db', 'staging.internal');
        $staging = $application->key('abc123');

        // Assert
        $this->assertNotSame($local, $staging);
    }

    /**
     * The same connection and the same files still hit the cache.
     *
     * The fast path has to stay a fast path: a key that varied for any other reason would
     * make every run re-verify, which is what the cache exists to avoid.
     */
    public function testTheSameConnectionStillMatches(): void
    {
        // Arrange
        $application = $this->application();
        $this->useDatabase('project_db');

        // Act & Assert
        $this->assertSame($application->key('abc123'), $application->key('abc123'));
        $this->assertSame($application->file('abc123'), $application->file('abc123'));

        // …and a different set of migration files is still a different key.
        $this->assertNotSame($application->key('abc123'), $application->key('def456'));
    }
}
