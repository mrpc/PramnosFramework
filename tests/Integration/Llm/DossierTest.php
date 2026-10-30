<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Database\WriteSpool;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Companion\Companion;
use Pramnos\Llm\Companion\Dossier;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The companion's profile of a person — who they are, what they hold to, the people in their
 * life, how they feel — on MySQL and PostgreSQL.
 *
 * What holds: nothing lasting becomes true on the model's word — it proposes, and only a
 * confirmation in a later answer makes it so; every confirmed change is a version, its before and
 * after in the changelog; how somebody feels is kept only with their consent; and a person's
 * entries are theirs alone.
 */
#[CoversClass(Dossier::class)]
#[CoversClass(Companion::class)]
class DossierTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        WriteSpool::setDriver(WriteSpool::DRIVER_SYNC);
    }

    protected function tearDown(): void
    {
        WriteSpool::setDriver(null);
        if ($this->users !== []) {
            $this->db->queryBuilder()->table('#PREFIX#usersettings')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $this->users)->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Connect, with the changelog's tables, and a person whose settings can be read. */
    private function person(string $type, string $host, int $port, bool $feelings = false): Scope
    {
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Core\CreateUsersettingsTable::class,
            \Pramnos\Framework\Migrations\Changelog\CreateChangelogTables::class,
            \Pramnos\Framework\Migrations\Changelog\AddScopeToChangelogEvents::class,
        ], $this->db);
        // The profile's table is rebuilt for each class, its ids with it: the log's rows go too.
        $this->db->queryBuilder()->table('pramnos.changelog_events')->where('entity', Dossier::ENTITY)->delete();
        $mark = 'dz' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@example.com', 'password' => 'x', 'regdate' => time()]);
        $id = (int) $this->db->getInsertId();
        $this->users[] = $id;
        \Pramnos\User\User::clearUserCache();
        if ($feelings) {
            (new \Pramnos\User\User($id))->setSetting(Dossier::FEELINGS, '1');
        }

        return new Scope($id, null);
    }

    /** Run one of the profile's tools as the model would. */
    private static function call(Dossier $dossier, string $tool, array $arguments): string
    {
        return ($dossier->tools()[$tool]['run'])($arguments);
    }

    /**
     * A goal is proposed, not kept: the same answer cannot confirm it; the next one can, and it
     * is version 1. A correction is proposed again, confirmed, and is version 2 — both versions
     * in the changelog, oldest first.
     */
    #[DataProvider('databases')]
    public function testAProposalBecomesTrueOnlyOnTheNextAnswersConfirmation(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        $now   = new Dossier($scope);

        // Act
        $proposed = $now->note('goals', 'Run a marathon in 2027');
        $id       = (int) preg_replace('/\D.*/s', '', substr($proposed, strlen('Proposed as entry ')));
        $tooSoon  = $now->confirm($id);
        $context  = $now->context();
        $later    = new Dossier($scope);
        $yes      = self::call($later, 'confirm_profile', ['id' => $id]);
        $later->note('goals', 'Run a half marathon in 2027', null, $id);
        $again    = (new Dossier($scope))->confirm($id);

        // Assert
        $this->assertStringContainsString('not kept yet', $proposed);
        $this->assertStringStartsWith('Not confirmed: it was proposed in this answer', $tooSoon);
        $this->assertStringContainsString("Proposed, not confirmed — not true until they say so (id, area):\n- [{$id}, goals] Run a marathon", $context);
        $this->assertStringNotContainsString('confirmed about themselves', $context, 'nothing is true yet');
        $this->assertSame('Confirmed: Run a marathon in 2027', $yes);
        $this->assertSame('Confirmed: Run a half marathon in 2027', $again);
        $entry = $later->entries()[0];
        $this->assertSame([2, 'Run a half marathon in 2027', null], [$entry['version'], $entry['content'], $entry['pending']]);
        $this->assertSame([
            [1, null, 'Run a marathon in 2027'],
            [2, 'Run a marathon in 2027', 'Run a half marathon in 2027'],
        ], array_map(static fn (array $v): array => [$v['version'], $v['from'], $v['to']], $later->history($id)));
        $this->assertSame("What this person confirmed about themselves (id, area):\n- [{$id}, goals] Run a half marathon in 2027", $later->context());
    }

    /**
     * No: an entry that was only a proposal goes; a change to a true entry is dropped and the
     * entry stays as it was. Nothing waiting cannot be confirmed or rejected.
     */
    #[DataProvider('databases')]
    public function testRejecting(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        $a     = new Dossier($scope);
        $a->note('values', 'Family first');
        $valueId = $a->entries()[0]['dossier_id'];
        (new Dossier($scope))->confirm($valueId);
        $b = new Dossier($scope);
        $b->note('identity', 'A nurse');
        $nurseId = $b->entries()[0]['dossier_id'] === $valueId ? $b->entries()[1]['dossier_id'] : $b->entries()[0]['dossier_id'];

        // Act
        $b->note('values', 'Work first', null, $valueId);
        $droppedChange = self::call($b, 'reject_profile', ['id' => $valueId]);
        $droppedNew    = $b->reject($nurseId);

        // Assert
        $this->assertSame(['Dropped.', 'Dropped.'], [$droppedChange, $droppedNew]);
        $this->assertCount(1, $b->entries(), 'the nurse proposal is gone');
        $this->assertSame(['Family first', null], [$b->entries()[0]['content'], $b->entries()[0]['pending']]);
        $this->assertStringStartsWith('Error: entry', $b->reject($valueId));
        $this->assertStringStartsWith('Error: entry', $b->confirm($valueId));
    }

    /**
     * The relationship graph: one entry per person, matched whatever the case, named in subject;
     * recall_person finds them by part of a name, or lists everybody.
     */
    #[DataProvider('databases')]
    public function testThePeopleInTheirLife(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        $a     = new Dossier($scope);

        // Act
        $noSubject = $a->note('relationships', 'A colleague');
        $a->note('relationships', 'Colleague on the project', 'Giorgos Papas');
        $a->note('relationships', 'Sister', 'Maria');
        $b = new Dossier($scope);
        foreach ($b->entries() as $entry) {
            $b->confirm($entry['dossier_id']);
        }
        $c = new Dossier($scope);
        $c->note('relationships', 'Manager since October', 'giorgos papas');

        // Assert
        $this->assertStringStartsWith('Error: a relationship names the person', $noSubject);
        $this->assertCount(2, $c->entries(), 'the second note about Giorgos is a change to his entry');
        $this->assertSame(['Giorgos Papas' => 'Colleague on the project', 'Maria' => 'Sister'], $c->people());
        $this->assertSame('Giorgos Papas: Colleague on the project', self::call($c, 'recall_person', ['name' => 'giorgos']));
        $this->assertSame("Giorgos Papas: Colleague on the project\nMaria: Sister", self::call($c, 'recall_person', []));
        $this->assertSame('Nobody of that name is known.', self::call($c, 'recall_person', ['name' => 'Nikos']));
    }

    /**
     * How they feel: refused without their consent, with a sentence for the model; with it,
     * kept at once — no confirmation — in one entry whose versions are the mood over time.
     */
    #[DataProvider('databases')]
    public function testMoodNeedsConsentAndNoConfirmation(string $type, string $host, int $port): void
    {
        // Arrange
        $without = new Dossier($this->person($type, $host, $port));
        $with    = new Dossier($this->person($type, $host, $port, feelings: true));

        // Act
        $refused = $without->note('mood', 'Stressed, 2 of 5');
        $kept    = self::call($with, 'note_profile', ['area' => 'mood', 'content' => 'Stressed, 2 of 5']);
        $with->note('mood', 'Better, 4 of 5');

        // Assert
        $this->assertStringStartsWith('Not kept: this person has not agreed', $refused);
        $this->assertSame([], $without->entries());
        $this->assertSame('Kept: Stressed, 2 of 5', $kept);
        $this->assertCount(1, $with->entries());
        $this->assertSame([2, 'Better, 4 of 5'], [$with->entries()[0]['version'], $with->entries()[0]['content']]);
        $this->assertTrue($with->keepsFeelings());
    }

    /**
     * What is refused: an unknown area, nothing or too much said, a correction of an entry that
     * is not there — or is somebody else's — and a profile that is full. Forgetting is the
     * person's own entry only.
     */
    #[DataProvider('databases')]
    public function testWhatIsRefused(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        $mine  = new Dossier($scope);
        $mine->note('goals', 'Learn Italian');
        $id    = $mine->entries()[0]['dossier_id'];
        $other = new Dossier(new Scope($scope->userId + 100000, null));

        // Act / Assert
        $this->assertStringStartsWith('Error: an area is one of', $mine->note('hobbies', 'Chess'));
        $this->assertStringStartsWith('Error: say it in 1 to', $mine->note('goals', '   '));
        $this->assertStringStartsWith('Error: say it in 1 to', $mine->note('goals', str_repeat('x', Dossier::CHARS + 1)));
        $this->assertSame('Error: there is no entry ' . $id . '.', $other->note('goals', 'Mine now', null, $id));
        $this->assertFalse($other->forget($id), 'somebody else\'s entry');
        $this->assertSame([], $other->history($id));
        for ($i = 1; $i < Dossier::MAX; $i++) {
            $mine->note('goals', 'Goal ' . $i);
        }
        $this->assertStringStartsWith('Not kept: the profile is full', $mine->note('goals', 'One more'));
        $this->assertTrue($mine->forget($id));
        $this->assertCount(Dossier::MAX - 1, $mine->entries());
    }

    /**
     * Turned on, the companion's tools are offered in a person's own conversation and what they
     * confirmed is in its prompt; off — the default — neither, and never in the analyst's space.
     */
    #[DataProvider('databases')]
    public function testTheCompanionInAConversation(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        (new Dossier($scope))->note('identity', 'A nurse in Patras');
        (new Dossier($scope))->confirm((new Dossier($scope))->entries()[0]['dossier_id']);
        $this->answer([[200, self::says('Hello.')]]);

        // Act
        (new Conversations(new BaseAssistant()))->as($scope)->ask(null, 'Hi');
        $off = $this->sent[0];
        $this->configure(['chat' => ['companion' => true]]);
        (new Conversations(new BaseAssistant()))->as($scope)->ask(null, 'Hi');
        $on = $this->sent[1];

        // Assert
        $names = static fn (array $request): array => array_map(static fn (array $t): string => $t['function']['name'], (array) ($request['body']['tools'] ?? []));
        $this->assertNotContains('note_profile', $names($off));
        $this->assertStringNotContainsString('A nurse in Patras', self::systemOf($off));
        $this->assertContains('note_profile', $names($on));
        $this->assertContains('recall_person', $names($on));
        $this->assertStringContainsString('[1, identity] A nurse in Patras', preg_replace('/\[\d+, identity\]/', '[1, identity]', self::systemOf($on)));
        $this->assertFalse(Companion::on(new Scope($scope->userId, null, Scope::ADMIN)), 'not the analyst\'s space');
        $this->assertTrue((new Dossier($scope))->tools()['note_profile']['writes']);
        $this->assertSame(['personal' => true], (new Dossier($scope))->tools()['recall_person']['tags']);
    }
}
