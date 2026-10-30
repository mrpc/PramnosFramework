<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Bulk\BulkActions;
use Pramnos\Llm\Chat\Artifacts;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Proposals;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Web\ArtifactView;
use Pramnos\Llm\Chat\Web\BulkView;
use Pramnos\Llm\Llm;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ConversationsTest.php';
require_once __DIR__ . '/ArtifactsTest.php';

/**
 * Changes the model proposes and a person approves — on MySQL and PostgreSQL.
 *
 * What holds: off unless the application turns it on, and then offered only when there is
 * something this person may do; the model's proposal is kept under its answer and changes nothing;
 * a proposal that is not one this person could approve is refused with the reason; approving runs
 * it as the approver — once — and rejecting leaves it marked; nobody else can settle it.
 */
#[CoversClass(Proposals::class)]
#[CoversClass(ArtifactView::class)]
#[CoversClass(BulkView::class)]
class ProposalsTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<array{0: list<int|string>, 1: array<string, mixed>, 2: int}> */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        BulkActions::reset();
        BulkActions::forget('user', 'group_add');
        $this->ran = [];
        BulkActions::define('device', 'disable', 'Turn off', ['reason' => ['label' => 'Why', 'required' => true]],
            function (array $ids, array $params, int $by): array {
                $this->ran[] = [$ids, $params, $by];

                return ['done' => count($ids)];
            }, allowed: static fn (int $user): bool => $user === 7);
    }

    protected function tearDown(): void
    {
        // A bulk action writes to the audit log, which remembers that this database had no table for it.
        \Pramnos\Auth\AuditLog::resetTableCache();
        BulkActions::reset();
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /**
     * Off by default: no tools, even with actions declared. On: the model reads what it may do,
     * proposes, and the proposal waits under its answer with nothing changed.
     */
    #[DataProvider('databases')]
    public function testTheModelProposesAndNothingChanges(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $scope = new Scope(7, 3);

        // Act / Assert — off unless turned on
        $this->assertSame([], (new Proposals($scope))->tools(new Artifacts($scope)));

        // Arrange — turned on
        $this->configure(['chat' => ['propose_actions' => true]]);
        Llm::use('rules', [new AcceptsEverything()]);
        $this->answer([
            [200, self::calls([['list_actions', []], ['propose_action', ['entity' => 'device', 'action' => 'disable', 'ids' => ['A-1', 'B-2'], 'params' => ['reason' => 'Leaking', 'extra' => 'x'], 'reason' => 'Both report leaks.']]])],
            [200, self::says('I proposed turning off the two leaking meters.')],
        ]);

        // Act
        $answer = (new Conversations(new RecordingAssistant()))->as($scope)->ask(null, 'Turn off the leaking ones');

        // Assert
        $proposal = $answer['message']['artifacts'][0];
        $this->assertSame(['proposal', 'device', 'Turn off'], [$proposal['kind'], $proposal['entity'], $proposal['title']]);
        $this->assertSame(['action' => 'disable', 'label' => 'Turn off', 'ids' => ['A-1', 'B-2'], 'params' => ['reason' => 'Leaking'], 'reason' => 'Both report leaks.', 'status' => 'pending'],
            $proposal['content'], 'only the action\'s own fields are kept');
        $this->assertSame([], $this->ran, 'nothing ran');
        $names = array_column(array_column($this->sent[0]['body']['tools'], 'function'), 'name');
        $this->assertContains('propose_action', $names);
        $this->assertStringContainsString('"device"', (string) self::spoken($this->sent[1])[2]['content'], 'list_actions answered with what may be done');
        // Nobody may do anything: no tools
        $this->assertSame([], (new Proposals(new Scope(8, 3)))->tools(new Artifacts(new Scope(8, 3))));
    }

    /**
     * What cannot be proposed says why; approving runs it once as the approver; rejecting leaves
     * it marked; another person cannot settle it.
     */
    #[DataProvider('databases')]
    public function testApprovingAndRejecting(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $scope     = new Scope(7, 3);
        $artifacts = new Artifacts($scope);
        $proposals = new Proposals($scope);

        // Act
        $unknown = $proposals->propose($artifacts, ['entity' => 'device', 'action' => 'explode', 'ids' => [1]]);
        $missing = $proposals->propose($artifacts, ['entity' => 'device', 'action' => 'disable', 'ids' => [1]]);
        $noIds   = $proposals->propose($artifacts, ['entity' => 'device', 'action' => 'disable', 'ids' => [], 'params' => ['reason' => 'x']]);
        $one     = $proposals->propose($artifacts, ['entity' => 'device', 'action' => 'disable', 'ids' => ['A-1'], 'params' => ['reason' => 'Leak'], 'reason' => 'r']);
        $two     = $proposals->propose($artifacts, ['entity' => 'device', 'action' => 'disable', 'ids' => ['B-2'], 'params' => ['reason' => 'Leak']]);
        $stranger = (new Proposals(new Scope(8, 3)))->approve($one['artifact_id']);
        $approved = $proposals->approve($one['artifact_id']);
        $again    = $proposals->approve($one['artifact_id']);
        $rejected = $proposals->reject($two['artifact_id']);
        $after    = $proposals->approve($two['artifact_id']);
        $notOne   = $proposals->approve($artifacts->create(['kind' => 'text', 'title' => 'T', 'text' => 'x'])['artifact_id']);

        // Assert
        $this->assertSame('There is no such action this person may take. Read list_actions.', $unknown);
        $this->assertSame('Why is required.', $missing);
        $this->assertSame('Name between 1 and ' . BulkActions::MAX . ' records.', $noIds);
        $this->assertSame('There is no such proposal.', $stranger, 'only its owner settles it');
        $this->assertSame(['done' => 1, 'queued' => 0, 'says' => ''], $approved);
        $this->assertSame([[['A-1'], ['reason' => 'Leak'], 7]], $this->ran, 'run once, as the approver');
        $this->assertSame('That proposal was already approved.', $again);
        $this->assertNull($rejected);
        $this->assertSame('That proposal was already rejected.', $after);
        $this->assertSame('There is no such proposal.', $notOne);
        $settled = $artifacts->find($one['artifact_id'])['content'];
        $this->assertSame(['approved', 1], [$settled['status'], $settled['result']['done']]);
        $this->assertArrayHasKey('settled_at', $settled);
        $this->assertSame('rejected', $artifacts->find($two['artifact_id'])['content']['status']);

        // The markup: what it would do, and the decision only while it waits
        $pending = ArtifactView::html(['artifact_id' => 9, 'kind' => 'proposal', 'title' => 'Turn off', 'entity' => 'device',
            'content' => ['label' => 'Turn off', 'ids' => ['A-1'], 'params' => ['reason' => '<Leak>'], 'reason' => 'Both leak.', 'status' => 'pending']]);
        $done    = ArtifactView::html(['artifact_id' => 9, 'kind' => 'proposal', 'title' => 'Turn off', 'entity' => 'device', 'content' => $settled]);
        $queued  = ArtifactView::html(['artifact_id' => 9, 'kind' => 'proposal', 'title' => 'Turn off', 'entity' => 'device', 'content' => ['result' => ['queued' => 500], 'status' => 'approved']]);
        $this->assertStringContainsString('Proposed change · waiting for you', $pending);
        $this->assertStringContainsString('Turn off, on 1 device', $pending);
        $this->assertStringContainsString('reason: &lt;Leak&gt;', $pending);
        $this->assertStringContainsString('Proposed change · approved', $done);
        $this->assertStringContainsString('Done for 1.', $done);
        $this->assertStringContainsString('500 queued', $queued);
        $decision = BulkView::decision(['artifact_id' => 9, 'kind' => 'proposal', 'content' => ['status' => 'pending']], '/chat/approve/', '/chat/reject/', 4, '', ['btn' => 'b']);
        $this->assertStringContainsString('action="/chat/approve/9"', $decision);
        $this->assertStringContainsString('action="/chat/reject/9"', $decision);
        $this->assertSame('', BulkView::decision(['artifact_id' => 9, 'kind' => 'proposal', 'content' => $settled], '/a/', '/r/', 4, ''));
    }

    /** The API and the page: approve and reject, refusals said. */
    #[DataProvider('databases')]
    public function testTheApiAndThePage(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $scope     = new Scope(7, null);
        $artifacts = new Artifacts($scope);
        $proposals = new Proposals($scope);
        $make      = fn (): array => $proposals->propose($artifacts, ['entity' => 'device', 'action' => 'disable', 'ids' => ['A-1'], 'params' => ['reason' => 'x']]);
        [$a, $b, $c, $d] = [$make(), $make(), $make(), $make()];
        $api = new ArtifactsApi($scope);

        // Act / Assert — the API
        $this->assertSame(1, $api->approveProposal($a['artifact_id'])['data']['done']);
        $this->assertSame(422, $api->approveProposal($a['artifact_id'])['status'], 'already settled');
        $this->assertSame(404, $api->approveProposal(999999)['status']);
        $this->assertTrue($api->rejectProposal($b['artifact_id'])['data']['rejected']);
        $this->assertSame(422, $api->rejectProposal($b['artifact_id'])['status']);
        $this->assertSame(404, (new ArtifactsApi(null))->rejectProposal($b['artifact_id'])['status']);
        $denied = new ArtifactsApi($scope, true);
        $this->assertSame(403, $denied->approveProposal($c['artifact_id'])['status']);
        $this->assertSame(403, $denied->rejectProposal($c['artifact_id'])['status']);

        // Act / Assert — the page
        $_POST = ['conversation_id' => '0'];
        $page  = new PageFixture($scope, 'plain-css');
        $page->approve($c['artifact_id']);
        $page->reject($d['artifact_id']);
        $page->approve($d['artifact_id']);
        $nobody = new PageFixture(null, 'plain-css');
        $nobody->approve($c['artifact_id']);
        $nobody->reject($c['artifact_id']);
        $_POST = [];
        $this->assertSame(['Done for 1.', 'Rejected; nothing was changed.'], $page->messages);
        $this->assertSame(['That proposal was already rejected.'], $page->errors);
        $this->assertSame(['There is no such proposal.', 'There is no such proposal.'], $nobody->errors);

        // A long list approved is queued
        BulkActions::useQueue(new RecordingQueue());
        $long = $proposals->propose($artifacts, ['entity' => 'device', 'action' => 'disable', 'ids' => range(1, BulkActions::INLINE + 1), 'params' => ['reason' => 'x']]);
        $_POST = ['conversation_id' => '0'];
        $queued = new PageFixture($scope, 'plain-css');
        $queued->approve($long['artifact_id']);
        $_POST = [];
        $this->assertSame([(BulkActions::INLINE + 1) . ' queued; they will be done shortly.'], $queued->messages);
    }
}
