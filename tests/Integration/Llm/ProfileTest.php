<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Database\WriteSpool;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Journal;
use Pramnos\Llm\Chat\Profile;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The assistant's profile of a person — who they are, what they hold to, the people in their
 * life, how they feel — on MySQL and PostgreSQL.
 *
 * What holds: nothing lasting becomes true on the model's word — it proposes, and only a
 * confirmation in a later answer makes it so; every confirmed change is a version, its before and
 * after in the changelog; how somebody feels is kept only with their consent; and a person's
 * entries are theirs alone.
 */
#[CoversClass(Profile::class)]
#[CoversClass(Journal::class)]
#[CoversClass(\Pramnos\Llm\Chat\Memory::class)]
class ProfileTest extends LlmTestCase
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
        $this->db->queryBuilder()->table('pramnos.changelog_events')->where('entity', Profile::ENTITY)->delete();
        $mark = 'dz' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@example.com', 'password' => 'x', 'regdate' => time()]);
        $id = (int) $this->db->getInsertId();
        $this->users[] = $id;
        \Pramnos\User\User::clearUserCache();
        if ($feelings) {
            (new \Pramnos\User\User($id))->setSetting(Profile::FEELINGS, '1');
        }

        return new Scope($id, null);
    }

    /** Run one of the profile's tools as the model would. */
    private static function call(Profile $dossier, string $tool, array $arguments): string
    {
        return ($dossier->tools()[$tool]['run'])($arguments);
    }

    /**
     * Erasing a person's profile takes every earlier version in the event log with it, and only
     * theirs; an organisation's goes the same way; nothing to erase answers 0; any other column
     * is refused rather than deleting by something unchecked.
     */
    #[DataProvider('databases')]
    public function testEraseTakesTheVersionsWithIt(string $type, string $host, int $port): void
    {
        // Arrange — a confirmed entry each, with its version in the log
        $leaving = $this->person($type, $host, $port);
        $staying = $this->person($type, $host, $port);
        $member  = new Scope($this->person($type, $host, $port)->userId, 9071);
        $ids     = [];
        foreach ([$leaving, $staying, $member] as $scope) {
            $proposed = (new Profile($scope))->note('values', 'Honesty first');
            $ids[]    = $id = (int) preg_replace('/\D.*/s', '', substr($proposed, strlen('Proposed as entry ')));
            (new Profile($scope))->confirm($id);
        }
        $events = fn (int $id): int => $this->db->queryBuilder()->table(\Pramnos\Changelog\ChangelogWriter::EVENTS_TABLE)
            ->where('entity', Profile::ENTITY)->where('itemid', (string) $id)->count();
        $this->assertSame(1, $events($ids[0]), 'the version is in the log before');

        // Act
        $erased  = Profile::erase('userid', $leaving->userId);
        $again   = Profile::erase('userid', $leaving->userId);
        $company = Profile::erase('organization_id', 9071, $this->db);

        // Assert
        $this->assertSame([1, 0, 1], [$erased, $again, $company]);
        $this->assertSame([], (new Profile($leaving))->entries());
        $this->assertSame(0, $events($ids[0]), 'the earlier versions went with it');
        $this->assertSame(0, $events($ids[2]), 'the organisation\'s too');
        $this->assertCount(1, (new Profile($staying))->entries(), 'another person\'s stays');
        $this->assertSame(1, $events($ids[1]));
        $without = $this->createMock(\Pramnos\Database\Database::class);
        $without->method('schema')->willReturn($this->createStub(\Pramnos\Database\SchemaBuilder::class));
        $this->assertSame(0, Profile::erase('userid', $staying->userId, $without), 'no table, nothing to erase');
        $this->expectException(\InvalidArgumentException::class);
        Profile::erase('space', 1);
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
        $now   = new Profile($scope);

        // Act
        $proposed = $now->note('goals', 'Run a marathon in 2027');
        $id       = (int) preg_replace('/\D.*/s', '', substr($proposed, strlen('Proposed as entry ')));
        $tooSoon  = $now->confirm($id);
        $context  = $now->context();
        $later    = new Profile($scope);
        $yes      = self::call($later, 'confirm_profile', ['id' => $id]);
        $later->note('goals', 'Run a half marathon in 2027', null, $id);
        $again    = (new Profile($scope))->confirm($id);

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
        $a     = new Profile($scope);
        $a->note('values', 'Family first');
        $valueId = $a->entries()[0]['dossier_id'];
        (new Profile($scope))->confirm($valueId);
        $b = new Profile($scope);
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
        $a     = new Profile($scope);

        // Act
        $noSubject = $a->note('relationships', 'A colleague');
        $a->note('relationships', 'Colleague on the project', 'Giorgos Papas');
        $a->note('relationships', 'Sister', 'Maria');
        $b = new Profile($scope);
        foreach ($b->entries() as $entry) {
            $b->confirm($entry['dossier_id']);
        }
        $c = new Profile($scope);
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
        $without = new Profile($this->person($type, $host, $port));
        $with    = new Profile($this->person($type, $host, $port, feelings: true));

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
        $mine  = new Profile($scope);
        $mine->note('goals', 'Learn Italian');
        $id    = $mine->entries()[0]['dossier_id'];
        $other = new Profile(new Scope($scope->userId + 100000, null));

        // Act / Assert
        $this->assertStringStartsWith('Error: an area is one of', $mine->note('hobbies', 'Chess'));
        $this->assertStringStartsWith('Error: say it in 1 to', $mine->note('goals', '   '));
        $this->assertStringStartsWith('Error: say it in 1 to', $mine->note('goals', str_repeat('x', Profile::CHARS + 1)));
        $this->assertSame('Error: there is no entry ' . $id . '.', $other->note('goals', 'Mine now', null, $id));
        $this->assertFalse($other->forget($id), 'somebody else\'s entry');
        $this->assertSame([], $other->history($id));
        for ($i = 1; $i < Profile::MAX; $i++) {
            $mine->note('goals', 'Goal ' . $i);
        }
        $this->assertStringStartsWith('Not kept: the profile is full', $mine->note('goals', 'One more'));
        $this->assertTrue($mine->forget($id));
        $this->assertCount(Profile::MAX - 1, $mine->entries());
    }

    /**
     * Learning in the background proposes to the profile what the person plainly said — a
     * proposal, waiting for their yes, never a fact — and sees what the profile already holds; an
     * area that is not one is left out, and without the profile on nothing is proposed.
     */
    #[DataProvider('databases')]
    public function testLearningProposesToTheProfile(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        (new Profile($scope))->note('identity', 'A nurse in Patras');
        $this->configure(['chat' => ['profile' => true, 'memory' => ['learn' => true]]]);
        $this->answer([
            [200, self::says('{"changes": [{"op": "propose", "area": "relationships", "subject": "Eleni", "content": "His wife"},'
                . ' {"op": "propose", "area": "values", "content": "Family first"}, {"op": "propose", "area": "hobbies", "content": "Chess"}]}')],
            [200, self::says('{"changes": [{"op": "propose", "area": "goals", "content": "Run a marathon"}]}')],
        ]);
        $memory = new \Pramnos\Llm\Chat\Memory($scope);

        // Act
        $done = $memory->learn(new \Pramnos\Llm\Client(), 'My wife Eleni and I put family first.');
        $this->configure(['chat' => ['memory' => ['learn' => true]]]);
        $off  = $memory->learn(new \Pramnos\Llm\Client(), 'I want to run a marathon.');

        // Assert
        $this->assertSame(2, $done['proposed'], 'the made-up area is left out');
        $eleni = array_values(array_filter((new Profile($scope))->entries(), static fn (array $e): bool => $e['subject'] === 'Eleni'))[0];
        $this->assertSame(['His wife', null], [$eleni['pending'], $eleni['content']], 'a proposal, waiting for their yes');
        $this->assertCount(3, (new Profile($scope))->entries());
        $this->assertSame(['identity', 'relationships', 'values'], array_column((new Profile($scope))->entries(), 'area'));
        $this->assertStringContainsString('A nurse in Patras', $this->sent[0]['body']['messages'][1]['content'], 'it saw the profile');
        $this->assertStringContainsString('"op": "propose"', $this->sent[0]['body']['messages'][0]['content']);
        $this->assertSame(0, $off['proposed'], 'off: nothing proposed');
        $this->assertStringNotContainsString('propose', $this->sent[1]['body']['messages'][0]['content']);
    }

    /**
     * Turned on, the profile's tools are offered in a person's own conversation and what they
     * confirmed is in its prompt; off — the default — neither, and never in the analyst's space.
     */
    #[DataProvider('databases')]
    public function testTheProfileInAConversation(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        (new Profile($scope))->note('identity', 'A nurse in Patras');
        (new Profile($scope))->confirm((new Profile($scope))->entries()[0]['dossier_id']);
        $this->answer([[200, self::says('Hello.')]]);

        // Act
        (new Conversations(new BaseAssistant()))->as($scope)->ask(null, 'Hi');
        $off = $this->sent[0];
        $this->configure(['chat' => ['profile' => true]]);
        (new Conversations(new BaseAssistant()))->as($scope)->ask(null, 'Hi');
        $on = $this->sent[1];

        // Assert
        $names = static fn (array $request): array => array_map(static fn (array $t): string => $t['function']['name'], (array) ($request['body']['tools'] ?? []));
        $this->assertNotContains('note_profile', $names($off));
        $this->assertStringNotContainsString('A nurse in Patras', self::systemOf($off));
        $this->assertContains('note_profile', $names($on));
        $this->assertContains('recall_person', $names($on));
        $this->assertStringContainsString('[1, identity] A nurse in Patras', preg_replace('/\[\d+, identity\]/', '[1, identity]', self::systemOf($on)));
        $this->assertFalse(Profile::on(new Scope($scope->userId, null, Scope::ADMIN)), 'not the analyst\'s space');
        $this->assertNotContains('journal', $names($on), 'each is turned on by itself');
        $this->assertTrue((new Profile($scope))->tools()['note_profile']['writes']);
        $this->assertSame(['personal' => true], (new Profile($scope))->tools()['recall_person']['tags']);
    }

    /**
     * The journal: the day's first entry makes a page, `Journal Y-m-d` on the person's clock,
     * and the next ones are added to it, each with its time; nothing is not an entry. The journal
     * offers its evening review and the calendar its morning plan, each only where it is on, beside
     * the application's own routines, which win by key.
     */
    #[DataProvider('databases')]
    public function testTheJournalAndTheRoutinesItOffers(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        $tools = (new Journal($scope, 'Europe/Athens'))->tools();
        $today = 'Journal ' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Athens')))->format('Y-m-d');

        // Act
        $first  = ($tools['journal']['run'])(['entry' => 'Closed the project plan.']);
        $second = ($tools['journal']['run'])(['entry' => 'Felt calmer after the walk.']);
        $empty  = ($tools['journal']['run'])(['entry' => '  ']);
        $pages  = (new \Pramnos\Llm\Chat\Artifacts($scope))->search(['kind' => 'text', 'query' => 'Journal']);
        $text   = (new \Pramnos\Llm\Chat\Artifacts($scope))->find((int) $pages[0]['artifact_id'])['content']['text'];
        $off    = \Pramnos\Llm\Chat\Routines::templates();
        $this->configure(['chat' => ['journal' => true]]);
        $journal = \Pramnos\Llm\Chat\Routines::templates();
        $this->configure(['chat' => ['journal' => true, 'calendar' => true, 'routine_templates' => ['evening_review' => ['title' => 'Mine', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '20:00']]]]);
        $on     = \Pramnos\Llm\Chat\Routines::templates();

        // Assert
        $this->assertSame(['Written in ' . $today . ', a new page.', 'Written in ' . $today . '.'], [$first, $second]);
        $this->assertSame('Error: there is nothing to write.', $empty);
        $this->assertCount(1, $pages, 'one page a day');
        $this->assertMatchesRegularExpression("/^- \\d\\d:\\d\\d Closed the project plan\\.\n\n- \\d\\d:\\d\\d Felt calmer after the walk\\.$/", $text);
        $this->assertSame(['personal' => true], $tools['journal']['tags']);
        $this->assertSame([], $off);
        $this->assertSame(['evening_review'], array_keys($journal), 'the journal\'s alone');
        $this->assertEqualsCanonicalizing(['evening_review', 'daily_plan'], array_keys($on));
        $this->assertSame('Mine', $on['evening_review']['title'], 'the application\'s own wins');
        $this->assertSame('08:00', $on['daily_plan']['at']);
    }
}
