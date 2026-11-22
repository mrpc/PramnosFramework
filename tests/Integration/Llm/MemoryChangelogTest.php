<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Changelog\ChangelogWriter;
use Pramnos\Database\WriteSpool;
use Pramnos\Llm\Chat\Memory;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * FW-575/FW-576: {@see Memory} is not a {@see \Pramnos\Application\Model}, so it writes nothing
 * through `$emitChanges` — `keep()`, `amend()`, `forget()` and `forgetShared()` must raise their
 * own {@see ChangelogWriter::event()} on `pramnos.changelog_events`, on MySQL and PostgreSQL.
 *
 * What has to hold: `keep()` writes `model` or `learned` depending on how it was told (no content,
 * only kind/topic — there is nothing before it to restore); a correction, by the model's
 * `replaces` or the person's own `amend()`, writes `corrected` with the memory's previous and new
 * `content`; `forget()`/`forgetShared()` write `forgotten` with the content that was lost — history
 * of exactly what the same person already sees through `remember`/`correct`/`forget`, never a new
 * exposure of it (FW-576).
 */
#[CoversClass(Memory::class)]
class MemoryChangelogTest extends LlmTestCase
{
    use ChatDatabase;

    private int $person = 0;

    protected function setUp(): void
    {
        parent::setUp();
        WriteSpool::setDriver(WriteSpool::DRIVER_SYNC);
    }

    protected function tearDown(): void
    {
        WriteSpool::setDriver(null);
        if ($this->person > 0) {
            $this->db->queryBuilder()->table('assist.chat_memory')->where('userid', $this->person)->delete();
            $this->db->queryBuilder()->table(ChangelogWriter::EVENTS_TABLE)->where('entity', Memory::ENTITY)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->person)->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** The logged events for one memory id, oldest first. */
    private function eventsOf(int $memoryId): array
    {
        $result = $this->db->queryBuilder()->table(ChangelogWriter::EVENTS_TABLE)
            ->where('entity', Memory::ENTITY)->where('itemid', (string) $memoryId)->orderBy('eventid')->get();
        $rows = [];
        while ($result && ($row = $result->fetch()) !== null) {
            $rows[] = ['event' => (string) $row['event'], 'details' => $row['details'] === null ? [] : json_decode((string) $row['details'], true)];
        }

        return $rows;
    }

    #[DataProvider('databases')]
    public function testKeepAmendCorrectAndForgetEachLogTheirOwnEvent(string $type, string $host, int $port): void
    {
        // Arrange — a person, and the tables keep() and the log need.
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Changelog\CreateChangelogTables::class,
            \Pramnos\Framework\Migrations\Changelog\AddScopeToChangelogEvents::class,
            \Pramnos\Framework\Migrations\Llm\AddSourceToChatMemory::class,
            \Pramnos\Framework\Migrations\Llm\AddTopicToChatMemory::class,
        ], $this->db);
        $mark = 'mc' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@example.test', 'password' => 'x', 'regdate' => time()]);
        $this->person = (int) $this->db->getInsertId();
        $memory = new Memory(new Scope($this->person));

        // Act — the model keeps one, learning keeps another; the person amends the first, the
        // model corrects it again, and forgets the second.
        $kept      = $memory->keepOn('work', 'fact', 'Works remotely', null, 'me', 'model');
        $keptId    = (int) preg_replace('/\D.*/s', '', substr($kept, strlen('Remembered as memory ')));
        $afterKeep = $this->eventsOf($keptId);
        $learned   = $memory->keep('preference', 'Wants short answers', null, 'me', 'learned');
        $learnedId = (int) preg_replace('/\D.*/s', '', substr($learned, strlen('Remembered as memory ')));
        $afterLearn = $this->eventsOf($learnedId);
        $amended    = $memory->amend($keptId, 'Works from a co-working space', null);
        $memory->keepOn('work', 'fact', 'Works on-site now', $keptId);
        $forgotten = $memory->forget($learnedId);

        // Assert — one event per write; a new memory carries no content (nothing to restore
        // before it existed); a correction or a forget carries what it changed or lost. The
        // `details` column is `jsonb`: MySQL's native JSON and PostgreSQL's JSONB both reorder an
        // object's keys on storage, so every comparison below is key-order independent.
        $this->assertCount(1, $afterKeep);
        $this->assertSame('model', $afterKeep[0]['event'], 'keep() from the model: event model');
        $this->assertEqualsCanonicalizing(['kind' => 'fact', 'topic' => 'work'], $afterKeep[0]['details'], 'no content — there is nothing before a new memory to restore');
        $this->assertCount(1, $afterLearn);
        $this->assertSame('learned', $afterLearn[0]['event'], 'keep() from learning: event learned');
        $this->assertEqualsCanonicalizing(['kind' => 'preference'], $afterLearn[0]['details']);
        $this->assertTrue($amended);
        $this->assertTrue($forgotten);
        $afterAmendCorrect = $this->eventsOf($keptId);
        $this->assertCount(3, $afterAmendCorrect, 'kept, then the person\'s amend, then the model\'s correction');
        $this->assertSame('corrected', $afterAmendCorrect[1]['event'], 'the person\'s own amend() is logged too, not only the model\'s correction');
        $this->assertEqualsCanonicalizing(['old' => 'Works remotely', 'new' => 'Works from a co-working space'], $afterAmendCorrect[1]['details'], 'amend(): old/new content, no topic (unchanged)');
        $this->assertSame('corrected', $afterAmendCorrect[2]['event']);
        $this->assertEqualsCanonicalizing(['kind' => 'fact', 'topic' => 'work', 'old' => 'Works from a co-working space', 'new' => 'Works on-site now'], $afterAmendCorrect[2]['details'], 'the model\'s correction too: old/new content');
        $forgottenEvents = $this->eventsOf($learnedId);
        $this->assertSame('forgotten', $forgottenEvents[1]['event']);
        $this->assertEqualsCanonicalizing(['old' => 'Wants short answers'], $forgottenEvents[1]['details'], 'forget(): what was lost');
        $byUser = $this->db->queryBuilder()->table(ChangelogWriter::EVENTS_TABLE)
            ->where('entity', Memory::ENTITY)->where('itemid', (string) $keptId)->first();
        $this->assertSame($this->person, (int) $byUser->fields['userid'], 'logged against the person who did it');
    }
}
