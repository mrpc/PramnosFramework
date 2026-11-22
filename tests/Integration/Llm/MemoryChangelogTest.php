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
 * FW-575: {@see Memory} is not a {@see \Pramnos\Application\Model}, so it writes nothing through
 * `$emitChanges` — `keep()`, `amend()`'s sibling `forget()` and `forgetShared()` must raise their
 * own {@see ChangelogWriter::event()} on `pramnos.changelog_events`, on MySQL and PostgreSQL.
 *
 * What has to hold: `keep()` writes `model` or `learned` depending on how it was told, a correction
 * writes `corrected`, `forget()`/`forgetShared()` write `forgotten` — each keyed by the memory's own
 * id, carrying only its kind/topic (never the memory's own free-text `content`, which may be PII).
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
    public function testKeepCorrectAndForgetEachLogTheirOwnEvent(string $type, string $host, int $port): void
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

        // Act — the model keeps one, learning keeps another, the person corrects the first and forgets the second.
        $kept      = $memory->keepOn('work', 'fact', 'Works remotely', null, 'me', 'model');
        $keptId    = (int) preg_replace('/\D.*/s', '', substr($kept, strlen('Remembered as memory ')));
        $afterKeep = $this->eventsOf($keptId);
        $learned   = $memory->keep('preference', 'Wants short answers', null, 'me', 'learned');
        $learnedId = (int) preg_replace('/\D.*/s', '', substr($learned, strlen('Remembered as memory ')));
        $afterLearn = $this->eventsOf($learnedId);
        $memory->keepOn('work', 'fact', 'Works on-site now', $keptId);
        $forgotten = $memory->forget($learnedId);

        // Assert — one event per write, the content never carried, the person who acted present.
        $this->assertSame([['event' => 'model', 'details' => ['kind' => 'fact', 'topic' => 'work']]], $afterKeep, 'keep() from the model: event model');
        $this->assertSame([['event' => 'learned', 'details' => ['kind' => 'preference']]], $afterLearn, 'keep() from learning: event learned');
        $this->assertTrue($forgotten);
        $afterCorrect = $this->eventsOf($keptId);
        $this->assertCount(2, $afterCorrect, 'the correction is a second event, not a replacement');
        $this->assertSame(['event' => 'corrected', 'details' => ['kind' => 'fact', 'topic' => 'work']], $afterCorrect[1]);
        foreach ([...$afterCorrect, ...$this->eventsOf($learnedId)] as $row) {
            $this->assertArrayNotHasKey('content', $row['details'], 'the memory\'s own words are never in the log — only kind/topic');
        }
        $forgottenEvents = $this->eventsOf($learnedId);
        $this->assertSame('forgotten', $forgottenEvents[1]['event']);
        $byUser = $this->db->queryBuilder()->table(ChangelogWriter::EVENTS_TABLE)
            ->where('entity', Memory::ENTITY)->where('itemid', (string) $keptId)->first();
        $this->assertSame($this->person, (int) $byUser->fields['userid'], 'logged against the person who did it');
    }
}
