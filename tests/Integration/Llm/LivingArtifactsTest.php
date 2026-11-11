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

require_once __DIR__ . '/ChatApiTest.php';

/**
 * Artifacts as a workspace across conversations — read, changed, found and pinned by the model —
 * on MySQL and PostgreSQL.
 *
 * What holds: a change in any mode is checked whole, as a new artifact would be, and touches only
 * this person's own; a title finds the exact one before a partial one; and what is pinned is in
 * front of the model at the start of every question, as it stands then.
 */
#[CoversClass(Artifacts::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
#[CoversClass(\Pramnos\Broadcasting\BroadcastingManager::class)]
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
        $this->db->queryBuilder()->table('assist.chat_artifacts')->where('artifact_id', $new)->update(['updated_at' => \Pramnos\Llm\CallLog::instant(time() - 3600)]);

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
     * A change and a pin are told to the person's open pages at once, as `artifact.changed` on
     * their private channel — a refused change is not; without broadcasting set up nothing is told
     * and nothing fails.
     */
    #[DataProvider('databases')]
    public function testAChangeIsToldToTheOpenPage(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $userId = $this->user();
        $a      = new Artifacts(new Scope($userId, null));
        $plan   = $a->create(['kind' => 'text', 'title' => 'Plan', 'text' => 'Ship on Friday.'])['artifact_id'];
        $quiet  = $a->update($plan, 'append', ['text' => 'Nobody listening.']);
        $fake   = \Pramnos\Broadcasting\Testing\FakeDriver::swap();

        try {
            // Act
            $a->update($plan, 'append', ['text' => 'Or Monday.']);
            $a->update($plan, 'patch', ['find' => 'nothing like this', 'replace' => 'x']);
            $a->pin($plan);
            $events = array_map(static fn (array $r): array => [$r['event'], $r['payload']], $fake->recorded());
            $channels = array_unique(array_column($fake->recorded(), 'channel'));
        } finally {
            \Pramnos\Broadcasting\Testing\FakeDriver::restore();
        }

        // Assert
        $this->assertIsArray($quiet, 'without broadcasting the change is made all the same');
        $this->assertSame([
            [Artifacts::CHANGED, ['id' => $plan]],
            [Artifacts::CHANGED, ['id' => $plan, 'pinned' => true]],
        ], $events, 'the refused patch is not told');
        $this->assertCount(1, $channels);
        $this->assertStringContainsString((string) $userId, (string) reset($channels), 'their own channel');
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

    /**
     * The pinned index (FW-469): nothing is left out without a word. A table that does not fit is
     * a line a row of its key columns, saying how many more; a long text is cut, saying so; once
     * the whole share is spent, the rest are named in a line each. The limits are the
     * application's, and read_artifact reads up to its own.
     */
    #[DataProvider('databases')]
    public function testPinnedArtifactsAreAnIndexWithNothingLeftOutSilently(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['artifacts' => ['pinned_each' => 400, 'pinned_all' => 700, 'read' => 50]]]);
        $userId = $this->user();
        $a      = new Artifacts(new Scope($userId, null));
        $rows   = array_map(static fn (int $i): array => ['Project ' . $i, 'active', 'focus ' . $i, str_repeat('detail ', 20)], range(1, 30));
        $table  = $a->create(['kind' => 'table', 'title' => 'Plan', 'columns' => ['Project', 'Phase', 'Focus', 'Notes'], 'rows' => $rows])['artifact_id'];
        $text   = $a->create(['kind' => 'text', 'title' => 'Notes', 'text' => str_repeat('word ', 200)])['artifact_id'];
        $third  = $a->create(['kind' => 'text', 'title' => 'Late', 'text' => 'never shown whole'])['artifact_id'];
        foreach ([$table, $text, $third] as $id) {
            $a->pin($id);
        }

        // Act
        $context = $a->pinnedContext();
        $read    = self::call($a, 'read_artifact', ['id' => $text]);

        // Assert
        $this->assertStringContainsString("#{$table} table: Plan (30 rows, its first 3 columns; read_artifact {$table} for the whole)\nProject\tPhase\tFocus\nProject 1\tactive\tfocus 1", $context, 'one line a row, its key columns');
        $this->assertStringNotContainsString('detail', $context, 'not the long column');
        $this->assertMatchesRegularExpression('/\[… \d+ more rows; read_artifact ' . $table . '\]/', $context, 'how many more, and how to read them');
        $this->assertStringContainsString("[… cut here; read_artifact {$text} for the whole]", $context, 'a text cut, saying so');
        $this->assertStringContainsString("#{$third} text: Late (", $context, 'past the share: still named');
        $this->assertStringContainsString("not shown here: read_artifact {$third})", $context);
        $this->assertLessThanOrEqual(50, mb_strlen($read), 'read_artifact reads up to the application\'s own limit');
    }

    /** A user's username, for sharing by name. */
    private function nameOf(int $userId): string
    {
        return (string) $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $userId)->value('username');
    }

    /**
     * Shared live (FW-472): the same artifact, not a copy. A reader reads every change and cannot
     * make one; an editor's change is the owner's artifact itself; a whole organisation shares it;
     * a member is listed, taken off, or leaves; only the owner shares or deletes it, and deleting
     * it takes its members with it; what others share is listed for a member and the model.
     */
    #[DataProvider('databases')]
    public function testAnArtifactSharedLiveIsTheSameForEveryMember(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        [$owner, $reader, $editor, $colleague, $stranger] = [$this->user(), $this->user(), $this->user(), $this->user(), $this->user()];
        $mine   = new Artifacts(new Scope($owner, 7));
        $id     = $mine->create(['kind' => 'table', 'title' => 'Shopping', 'columns' => ['Item'], 'rows' => [['milk']]])['artifact_id'];
        $asRead = new Artifacts(new Scope($reader, null));
        $asEdit = new Artifacts(new Scope($editor, null));

        // Act
        $before  = $asRead->find($id);
        $shared  = $mine->shareLive($id, [$this->nameOf($reader), 'nobody-' . $owner], 'read');
        $mine->shareLive($id, [$this->nameOf($editor)], 'edit');
        $org     = $mine->shareLive($id, [], 'read', true);
        $refused = $asRead->update($id, 'append', ['rows' => [['bread']]]);
        $edited  = $asEdit->update($id, 'append', ['rows' => [['eggs']]]);
        $seen    = $asRead->find($id);
        $ownView = $mine->find($id);
        $inOrg   = (new Artifacts(new Scope($colleague, 7)))->find($id);
        $outside = (new Artifacts(new Scope($stranger, 8)))->find($id);
        $notHers = $asEdit->shareLive($id, [$this->nameOf($stranger)], 'edit');
        $list    = $asRead->sharedWithMe();
        $tool    = self::call($asRead, 'list_artifacts', []);
        $members = array_map(static fn (array $m): array => [$m['userid'], $m['role']], $mine->membersOf($id));
        $badRole = $mine->shareLive($id, [$this->nameOf($reader)], 'owner');
        $left    = $asRead->unshareLive($id, $reader);
        $after   = $asRead->find($id);
        $noDel   = $asEdit->delete($id);
        $deleted = $mine->delete($id);
        $rows    = $this->db->queryBuilder()->table(Artifacts::MEMBERS)->where('artifact_id', $id)->count();

        // Assert
        $this->assertNull($before, 'nobody else\'s before it is shared');
        $this->assertSame([[$this->nameOf($reader)], ['nobody-' . $owner]], [$shared['shared'], $shared['unknown']]);
        $this->assertSame(['shared' => [], 'unknown' => []], $org, 'the whole organisation');
        $this->assertSame('It is shared with you to read; only its owner or an editor changes it.', $refused);
        $this->assertSame([['milk'], ['eggs']], $edited['content']['rows'], 'an editor\'s change');
        $this->assertSame([['milk'], ['eggs']], $seen['content']['rows'], 'the reader reads it: the same artifact, not a copy');
        $this->assertSame([['milk'], ['eggs']], $ownView['content']['rows'], 'and it is the owner\'s');
        $this->assertSame(['read', $owner], [$seen['shared'], $seen['owner']]);
        $this->assertArrayNotHasKey('shared', $ownView, 'the owner\'s own');
        $this->assertSame('read', $inOrg['shared'] ?? null, 'a colleague in its organisation');
        $this->assertNull($outside, 'nobody outside it');
        $this->assertSame('Only its owner shares an artifact.', $notHers);
        $this->assertSame([['artifact_id' => $id, 'kind' => 'table', 'title' => 'Shopping', 'owner' => $owner, 'role' => 'read']], $list);
        $this->assertStringContainsString('#' . $id . ' table: Shopping (shared with them by ', $tool, 'the model finds it too');
        $this->assertSame([[$reader, 'read'], [$editor, 'edit'], [null, 'read']], $members);
        $this->assertStringStartsWith('A member reads it', $badRole);
        $this->assertTrue($left, 'a member leaves');
        $this->assertNull($after);
        $this->assertFalse($noDel, 'only its owner deletes it');
        $this->assertTrue($deleted);
        $this->assertSame(0, $rows, 'its members go with it');
    }

    /** A request through the chat's routes, as the given person. */
    private function route(int $userId, string $method, string $uri, array $input = []): mixed
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        ApiFixture::$scope = new Scope($userId, null);
        ApiFixture::$stop  = null;
        ApiFixture::$input = $input;
        $router = new \Pramnos\Routing\Router(null);
        \Pramnos\Llm\Chat\Api\Routes::register($router, ApiFixture::class);

        try {
            return $router->dispatch(\Pramnos\Http\Request::create($uri, $method));
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
            ApiFixture::$scope = null;
            ApiFixture::$input = [];
        }
    }

    /**
     * The same through the API (FW-472): shared by its owner, listed for its members, what is
     * shared with a person, a member taken off; refused to one who is not its owner.
     */
    #[DataProvider('databases')]
    public function testSharingLiveThroughTheApi(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        [$owner, $member] = [$this->user(), $this->user()];
        $id = (new Artifacts(new Scope($owner, null)))->create(['kind' => 'text', 'title' => 'Notes', 'text' => 'one'])['artifact_id'];

        // Act
        $shared  = $this->route($owner, 'POST', '/chat/artifacts/' . $id . '/members', ['names' => $this->nameOf($member), 'role' => 'edit']);
        $members = $this->route($member, 'GET', '/chat/artifacts/' . $id . '/members');
        $mine    = $this->route($member, 'GET', '/chat/artifacts/shared');
        $notHis  = $this->route($member, 'POST', '/chat/artifacts/' . $id . '/members', ['names' => 'x']);
        $removed = $this->route($owner, 'DELETE', '/chat/artifacts/' . $id . '/members/' . $member);
        $gone    = $this->route($member, 'GET', '/chat/artifacts/' . $id . '/members');
        $again   = $this->route($member, 'DELETE', '/chat/artifacts/' . $id . '/members/' . $owner);

        // Assert
        $this->assertSame([200, [$this->nameOf($member)]], [$shared['status'], $shared['data']['shared']]);
        $this->assertSame([[$member, 'edit']], array_map(static fn (array $m): array => [$m['userid'], $m['role']], $members['data']['members']), 'a member sees who shares it');
        $this->assertSame([$id], array_column($mine['data']['artifacts'], 'artifact_id'));
        $this->assertSame(404, $notHis['status'], 'only its owner shares it');
        $this->assertSame(200, $removed['status']);
        $this->assertSame(404, $gone['status'], 'taken off: not theirs to see');
        $this->assertSame(404, $again['status'], 'nobody else takes a member off');
    }
}
