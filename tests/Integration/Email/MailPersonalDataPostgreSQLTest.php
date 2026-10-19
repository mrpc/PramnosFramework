<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;
use Pramnos\Email\BodyStore;
use Pramnos\Email\MailPersonalData;
use Pramnos\Email\Retention;

/**
 * {@see MailPersonalDataTest} on PostgreSQL — and, on TimescaleDB, with bodies in `mail_bodies`.
 */
#[CoversClass(BodyStore::class)]
#[CoversClass(MailPersonalData::class)]
#[CoversClass(Retention::class)]
final class MailPersonalDataPostgreSQLTest extends MailPersonalDataTest
{
    /** The PostgreSQL container; null skips when it is not reachable. */
    protected function connection(): ?Database
    {
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            return $db->connect(false) ? $db : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Bodies in `mail_bodies` go with their messages: an erased message's row there is deleted,
     * a stripped one's too — compressed chunk or not — and another message's stays.
     */
    public function testBodiesInTheDatabaseGoWithTheirMessages(): void
    {
        // Arrange
        if (!$this->db->capabilities()->hasTimescaleDB()) {
            $this->markTestSkipped('No TimescaleDB on this connection.');
        }
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Messaging\CreateMailBodiesTable::class], $this->db);
        $this->configure(['store' => 'database']);
        $old    = time() - 86400 * 200;
        $recent = time() - 60;
        $erase  = $this->sent('erase@example.com', $recent, BodyStore::IN_DATABASE);
        $strip  = $this->sent('strip@example.com', $old, BodyStore::IN_DATABASE);
        $stays  = $this->sent('stays@example.com', $recent, BodyStore::IN_DATABASE);
        foreach ([$erase => $recent, $strip => $old, $stays => $recent] as $id => $date) {
            BodyStore::putRow($id, $date, '<p>Body of ' . $id . '</p>');
        }
        // The old chunk compressed, as the policy would have done after a week.
        $this->db->query("SELECT compress_chunk(c, true) FROM show_chunks('mail_bodies', older_than => now() - interval '100 days') c");

        // Act
        $erased   = MailPersonalData::erase('erase@example.com');
        $stripped = Retention::strip(86400 * 100);

        // Assert
        $this->assertSame(1, $erased);
        $this->assertNull(BodyStore::getRow($erase, $recent), 'the erased message\'s body is gone');
        $this->assertGreaterThanOrEqual(1, $stripped);
        $this->assertNull(BodyStore::getRow($strip, $old), 'and the stripped one\'s, from a compressed chunk');
        $this->assertNotNull(BodyStore::getRow($stays, $recent), 'another message\'s stays');
        $this->db->queryBuilder()->table('mail_bodies')->where('mail_id', $stays)->delete();
    }
}
