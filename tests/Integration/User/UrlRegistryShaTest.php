<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Auth\AddShaToUrls;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\Token;
use Pramnos\User\UrlRegistry;

/**
 * The `urls` registry made sound (FW-365, step 1), on MySQL here and PostgreSQL in the sibling: a
 * new address whose crc32 an old one shares gets a row of its own; an old row is found by its
 * text and given its sha; `urls:prune` deletes what nothing refers to, never the young; and
 * `urls:rehash` fills the sha, merges an address kept twice — its references moved — and makes
 * the unique index, a second run finding nothing to do.
 */
#[CoversClass(Token::class)]
#[CoversClass(UrlRegistry::class)]
#[CoversClass(AddShaToUrls::class)]
#[CoversClass(\Pramnos\Console\Commands\UrlsPrune::class)]
#[CoversClass(\Pramnos\Console\Commands\UrlsRehash::class)]
class UrlRegistryShaTest extends BaseTestCase
{
    /** The connection under test. */
    private $db;

    /** A table of the test's own that keeps url ids, declared as a reference. */
    private const REFS = 'url_refs_probe';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        $app = Application::getInstance();
        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        try {
            if (!$this->db->connected) {
                $this->db->connect();
            }
        } catch (\Throwable) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }
        $app->database = $this->db;
        require_once ROOT . '/database/migrations/framework/auth/2026_11_09_000016_add_sha_to_urls.php';
        $this->runMigrations([\Pramnos\Framework\Migrations\Auth\CreateUrlsTable::class, \Pramnos\Framework\Migrations\Auth\CreateTokenactionsTable::class,
            AddShaToUrls::class], $this->db);
        $schema = $this->db->schema();
        // A clean registry: the unique index a previous run made would refuse the doubles this test needs.
        if ($schema->hasIndex('urls', UrlRegistry::INDEX)) {
            $schema->table('urls', static fn ($t) => $t->dropUnique(UrlRegistry::INDEX));
        }
        $schema->dropTableIfExists(self::REFS);
        $schema->createTable(self::REFS, static function ($t): void {
            $t->increments('id');
            $t->integer('urlid');
        });
        $this->db->queryBuilder()->table('#PREFIX#tokenactions')->delete();
        $this->db->queryBuilder()->table('#PREFIX#urls')->delete();
        UrlRegistry::reset();
        UrlRegistry::referencedBy(self::REFS, 'urlid');
        $this->clearCache();
    }

    protected function tearDown(): void
    {
        if ($this->db && $this->db->connected) {
            $this->db->schema()->dropTableIfExists(self::REFS);
        }
        UrlRegistry::reset();
        $this->clearCache();
        parent::tearDown();
    }

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** The lookup's cache, emptied — and whether `urls` has `sha`, asked again. */
    private function clearCache(): void
    {
        (new \ReflectionProperty(Token::class, 'urlIdCache'))->setValue(null, []);
        (new \ReflectionProperty(Token::class, 'urlsHaveSha'))->setValue(null, null);
    }

    /** A row as an installation from before sha keeps it. */
    private function old(string $url, ?int $crc = null, ?int $at = null): int
    {
        $this->db->queryBuilder()->table('#PREFIX#urls')->insert(['url' => $url, 'hash' => $crc ?? crc32($url), 'sha' => null, 'createdat' => $at]);

        return (int) $this->db->getInsertId() ?: (int) $this->db->queryBuilder()->table('#PREFIX#urls')->max('urlid');
    }

    /** A reference to a url id. */
    private function refer(int $urlid): void
    {
        $this->db->queryBuilder()->table(self::REFS)->insert(['urlid' => $urlid]);
    }

    /**
     * The lookup, prune and rehash, end to end, as an installation with years of rows lives
     * through them.
     */
    public function testTheRegistryIsMadeSound(): void
    {
        // Arrange
        $collided = $this->old('/an/old/page', crc32('/a/new/page'));   // shares the new one's crc32
        $first    = $this->old('/twice');
        $second   = $this->old('/twice');                                // the race of two inserts
        $orphan   = $this->old('/nobody/refers');
        $young    = $this->old('/just/seen', null, time());
        $kept     = $this->old('/referred');
        foreach ([$first, $second, $kept, $collided] as $id) {
            $this->refer($id);
        }

        // Act
        $new      = Token::urlId('/a/new/page');
        $found    = Token::urlId('/referred');
        $this->clearCache();
        $again    = Token::urlId('/a/new/page');
        $dry      = UrlRegistry::prune(2, null, null, true);
        $pruned   = (new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\UrlsPrune()));
        $code     = $pruned->execute(['--batch' => '100']);
        $left     = array_map('intval', array_column($this->db->queryBuilder()->table('#PREFIX#urls')->select(['urlid'])->getAllOrFail(), 'urlid'));
        $rehash   = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\UrlsRehash());
        $rehashed = $rehash->execute([]);
        $twice    = UrlRegistry::rehash();
        $refs     = array_map('intval', array_column($this->db->queryBuilder()->table(self::REFS)->select(['urlid'])->getAllOrFail(), 'urlid'));
        $this->clearCache();
        $afterIdx = Token::urlId('/twice');

        // Assert
        $this->assertNotSame($collided, $new, 'a crc32 shared with an old address is not that address');
        $this->assertSame($new, $again, 'found again by its sha');
        $this->assertSame($kept, $found, 'an old row found by its text');
        $this->assertSame(sha1('/referred'), $this->db->queryBuilder()->table('#PREFIX#urls')->where('urlid', $kept)->first()->fields['sha'], 'and given its sha');
        $this->assertSame(1, $dry, 'the dry run counts the orphan only');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 went.', $pruned->getDisplay());
        $this->assertNotContains($orphan, $left, 'nothing refers to it: gone');
        $this->assertContains($young, $left, 'first seen today: kept');
        $this->assertContains($new, $left, 'a new one, kept');
        $this->assertSame(0, $rehashed);
        $this->assertStringContainsString('1 merged, unique index made', $rehash->getDisplay());
        $this->assertSame(['filled' => 0, 'merged' => 0, 'indexed' => false], $twice, 'a second run: nothing to do');
        $this->assertNotContains($second, $refs, 'its references moved to the first');
        $this->assertSame(2, count(array_keys($refs, $first)), 'both now refer to the first');
        $this->assertSame($first, $afterIdx);
        $this->assertTrue($this->db->schema()->hasIndex('urls', UrlRegistry::INDEX));
    }

    /**
     * A declared table that is not there stops a prune before it deletes anything: passed over,
     * the addresses only it referred to would go — and the foreign key from `tokenactions` takes
     * rows with an address that goes.
     */
    public function testAMissingReferenceStopsThePrune(): void
    {
        // Arrange
        $orphan = $this->old('/nobody/refers');
        UrlRegistry::referencedBy('no_such_table', 'urlid');

        // Act
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\UrlsPrune());
        $code   = $tester->execute([]);

        // Assert
        $this->assertSame(1, $code);
        $this->assertStringContainsString('no_such_table.urlid is not there', $tester->getDisplay());
        $this->assertSame(1, $this->db->queryBuilder()->table('#PREFIX#urls')->where('urlid', $orphan)->count(), 'nothing deleted');
    }
}
