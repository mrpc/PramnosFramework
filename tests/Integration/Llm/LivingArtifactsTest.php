<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Artifacts;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * Artifacts as a workspace across conversations — read, changed, found and pinned by the model —
 * on MySQL and PostgreSQL.
 *
 * What holds: a change in any mode is checked whole, as a new artifact would be, and touches only
 * this person's own; a title finds the exact one before a partial one; and what is pinned is in
 * front of the model at the start of every question, as it stands then.
 */
#[CoversClass(Artifacts::class)]
class LivingArtifactsTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
    }

    protected function tearDown(): void
    {
        if ($this->users !== []) {
            $this->db->queryBuilder()->table('#PREFIX#usersettings')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $this->users)->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A user for pins, which are kept as their setting. */
    private function user(): int
    {
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Core\CreateUsersettingsTable::class, \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], $this->db);
        $mark = 'la' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@example.com', 'password' => 'x', 'regdate' => time()]);
        $id = (int) $this->db->getInsertId();
        $this->users[] = $id;
        \Pramnos\User\User::clearUserCache();
        $this->db->cacheflush('userlist');

        return $id;
    }

    /** Run one of the artifact tools as the model would. */
    private static function call(Artifacts $artifacts, string $tool, array $arguments): string
    {
        return ($artifacts->tools()[$tool]['run'])($arguments);
    }

    /**
     * Read by id, or by title — the exact one before one that only holds the words — and never
     * somebody else's; each kind as text a model reads, cut with a line saying so.
     */
    #[DataProvider('databases')]
    public function testReadingAnArtifact(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $mine   = new Artifacts(new Scope(7, null));
        $table  = $mine->create(['kind' => 'table', 'title' => 'Sales by month', 'columns' => ['Month', 'Total'], 'rows' => [['Jan', 10], ['Feb', null]]]);
        $exact  = $mine->create(['kind' => 'text', 'title' => 'Sales', 'text' => 'The plan.']);
        $list   = $mine->create(['kind' => 'list', 'title' => 'Buyers', 'entity' => 'user', 'ids' => [4, 9], 'labels' => ['Ann', 'Bob']]);
        $chart  = $mine->create(['kind' => 'chart', 'title' => 'Trend', 'chart_type' => 'line', 'labels' => ['a', 'b'], 'series' => [['label' => 'x', 'data' => [1, 2]]]]);
        $theirs = (new Artifacts(new Scope(8, null)))->create(['kind' => 'text', 'title' => 'Theirs', 'text' => 'secret']);

        // Act
        $byId    = self::call($mine, 'read_artifact', ['id' => $table['artifact_id']]);
        $byTitle = self::call($mine, 'read_artifact', ['title' => 'sales']);
        $partial = self::call($mine, 'read_artifact', ['title' => 'by month']);
        $other   = self::call($mine, 'read_artifact', ['id' => $theirs['artifact_id']]);
        $none    = self::call($mine, 'read_artifact', []);

        // Assert
        $this->assertSame('#' . $table['artifact_id'] . " table: Sales by month\nMonth\tTotal\nJan\t10\nFeb\t", $byId);
        $this->assertStringStartsWith('#' . $exact['artifact_id'] . ' text: Sales', $byTitle, 'the exact title before a longer one');
        $this->assertStringContainsString('Sales by month', $partial);
        $this->assertSame('Error: there is no such artifact.', $other, 'somebody else\'s is not there');
        $this->assertSame('Error: there is no such artifact.', $none);
        $this->assertStringContainsString("4\tAnn\n9\tBob", self::call($mine, 'read_artifact', ['id' => $list['artifact_id']]));
        $this->assertStringContainsString("line chart; labels: a, b\nx: 1, 2", self::call($mine, 'read_artifact', ['id' => $chart['artifact_id']]));
        $long = $mine->create(['kind' => 'text', 'title' => 'Long', 'text' => str_repeat('word ', 100)]);
        $cut  = Artifacts::asText($mine->find($long['artifact_id']), 100);
        $this->assertStringEndsWith('[… cut here; read_artifact ' . $long['artifact_id'] . ' for the whole]', $cut);
        $this->assertSame(100, mb_strlen($cut), 'the note counts within the limit');
        $this->assertSame(Artifacts::asText($mine->find($table['artifact_id']), 1000), $byId, 'whole when it fits');
    }

    /**
     * Each mode of change: replace, append, and patch — a title, a find-and-replace in a text, ids
     * off a list, rows off a table. Every change is checked whole, and a change that cannot be
     * made says why and leaves the artifact as it was.
     */
    #[DataProvider('databases')]
    public function testChangingAnArtifact(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $a     = new Artifacts(new Scope(7, null));
        $table = $a->create(['kind' => 'table', 'title' => 'T', 'columns' => ['A'], 'rows' => [['1'], ['2'], ['3']]])['artifact_id'];
        $list  = $a->create(['kind' => 'list', 'title' => 'L', 'entity' => 'user', 'ids' => [1, 2, 3]])['artifact_id'];
        $text  = $a->create(['kind' => 'text', 'title' => 'D', 'text' => 'Dear Ann, hello.'])['artifact_id'];
        $chart = $a->create(['kind' => 'chart', 'title' => 'C', 'labels' => ['a'], 'series' => [['label' => 's', 'data' => [1]]]])['artifact_id'];
        $proposal = $a->keep('proposal', 'P', null, ['status' => 'waiting'])['artifact_id'];

        // Act / Assert — append
        $this->assertSame('Changed artifact ' . $table . '.', self::call($a, 'update_artifact', ['id' => $table, 'mode' => 'append', 'rows' => [['4']]]));
        $this->assertSame([['1'], ['2'], ['3'], ['4']], $a->find($table)['content']['rows']);
        $a->update($list, 'append', ['ids' => [4]]);
        $this->assertSame([1, 2, 3, 4], $a->find($list)['content']['ids']);
        $a->update($text, 'append', ['text' => 'Regards.']);
        $this->assertSame("Dear Ann, hello.\n\nRegards.", $a->find($text)['content']['text']);
        $this->assertSame('A chart is changed whole: use mode replace.', $a->update($chart, 'append', []));
        $this->assertSame('A list names each id once.', $a->update($list, 'append', ['ids' => [1]]), 'checked whole');

        // patch
        $a->update($text, 'patch', ['find' => 'Ann', 'replace' => 'Bob']);
        $this->assertSame("Dear Bob, hello.\n\nRegards.", $a->find($text)['content']['text']);
        $this->assertSame('What to find must be in the text exactly once.', $a->update($text, 'patch', ['find' => 'e']));
        $a->update($list, 'patch', ['remove' => [2, '4']]);
        $this->assertSame([1, 3], $a->find($list)['content']['ids']);
        $a->update($table, 'patch', ['remove_rows' => [1, 4]]);
        $this->assertSame([['2'], ['3']], $a->find($table)['content']['rows']);
        $a->update($chart, 'patch', ['title' => 'Renamed']);
        $this->assertSame(['Renamed', 'bar'], [$a->find($chart)['title'], $a->find($chart)['content']['chart']], 'a title alone keeps the content');
        $a->update($list, 'patch', ['title' => 'Kept']);
        $this->assertSame([1, 3], $a->find($list)['content']['ids']);
        $this->assertSame('Nothing to change: give a new title, or what to change in it.', $a->update($table, 'patch', []));

        // replace
        $a->update($chart, 'replace', ['chart_type' => 'pie', 'labels' => ['x', 'y'], 'series' => [['label' => 's', 'data' => [1, 2]]]]);
        $this->assertSame(['pie', ['x', 'y']], [$a->find($chart)['content']['chart'], $a->find($chart)['content']['labels']]);
        $this->assertSame('user', $a->find($list)['entity']);
        $a->update($list, 'replace', ['entity' => 'device', 'ids' => [7]]);
        $this->assertSame(['user', [7]], [$a->find($list)['entity'], $a->find($list)['content']['ids']], 'the entity stays what it was');

        // refused
        $this->assertSame('Error: There is no such artifact.', self::call($a, 'update_artifact', ['id' => 99999, 'mode' => 'replace']));
        $this->assertSame('A proposal cannot be changed here.', $a->update($proposal, 'replace', []));
        $this->assertSame('Change it with one of: replace, append, patch.', $a->update($text, 'rewrite', []));
        $this->assertSame('There is no such artifact.', (new Artifacts(new Scope(8, null)))->update($text, 'append', ['text' => 'x']), 'only one\'s own');
    }

    /** Found by kind, entity, a tag or words in the title, newest change first, within a limit. */
    #[DataProvider('databases')]
    public function testFindingArtifacts(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $a     = new Artifacts(new Scope(7, null));
        $old   = $a->create(['kind' => 'list', 'title' => 'Old buyers', 'entity' => 'user', 'ids' => [1]])['artifact_id'];
        $new   = $a->create(['kind' => 'text', 'title' => 'New notes', 'text' => 'x'])['artifact_id'];
        $a->link(55, 66, ['google']);
        $this->db->queryBuilder()->table('#PREFIX#chat_artifacts')->where('artifact_id', $new)->update(['updated_at' => \Pramnos\Llm\CallLog::instant(time() - 3600)]);

        // Act
        $all     = self::call($a, 'list_artifacts', []);
        $byKind  = $a->search(['kind' => 'list']);
        $byQuery = $a->search(['query' => 'notes']);
        $byTag   = $a->search(['tag' => 'google']);
        $noTag   = $a->search(['tag' => 'other']);
        $one     = $a->search([], 1);

        // Assert
        $this->assertStringStartsWith('#' . $old . ' list: Old buyers (user) [google]', $all, 'the newest change first');
        $this->assertSame([$old], array_column($byKind, 'artifact_id'));
        $this->assertSame([$new], array_column($byQuery, 'artifact_id'));
        $this->assertCount(2, $byTag);
        $this->assertSame([], $noTag);
        $this->assertCount(1, $one);
        $this->assertSame('No artifacts found.', self::call(new Artifacts(new Scope(8, null)), 'list_artifacts', ['entity' => 'user']));
    }

    /**
     * Pinned by the model, an artifact is in the context of the next question — as it stands
     * then — in its own space only; taken off, it is gone from there.
     */
    #[DataProvider('databases')]
    public function testAPinnedArtifactIsInEveryQuestion(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $userId = $this->user();
        $scope  = new Scope($userId, null);
        $a      = new Artifacts($scope);
        $plan   = $a->create(['kind' => 'text', 'title' => 'Plan', 'text' => 'Ship on Friday.'])['artifact_id'];
        $admin  = (new Artifacts(new Scope($userId, null, Scope::ADMIN)))->create(['kind' => 'text', 'title' => 'Admin only', 'text' => 'x'])['artifact_id'];
        $this->answer([[200, self::says('Noted.')]]);

        // Act
        $pinned = self::call($a, 'pin_artifact', ['id' => $plan]);
        (new Artifacts(new Scope($userId, null, Scope::ADMIN)))->pin($admin);
        $a->update($plan, 'patch', ['find' => 'Friday', 'replace' => 'Monday']);
        (new Conversations(new BaseAssistant()))->as($scope)->ask(null, 'What is the plan?');
        $system = self::systemOf($this->sent[0]);
        $off    = self::call($a, 'pin_artifact', ['id' => $plan, 'pinned' => false]);
        $after  = $a->pinnedContext();

        // Assert
        $this->assertSame('Pinned.', $pinned);
        $this->assertStringContainsString("#{$plan} text: Plan\nShip on Monday.", $system, 'as it stands at the question');
        $this->assertStringNotContainsString('Admin only', $system, 'another space\'s pin');
        $this->assertSame('Taken off.', $off);
        $this->assertSame('', $after);
        $this->assertSame('Error: There is no such artifact.', self::call($a, 'pin_artifact', ['id' => 999999]));
        $this->assertSame('', (new Artifacts(new Scope(999999, null)))->pinnedContext(), 'nobody to have pins');
    }
}
