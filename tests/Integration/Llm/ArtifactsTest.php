<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Api\ChatController;
use Pramnos\Llm\Chat\Artifacts;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Web\ArtifactView;
use Pramnos\Llm\Llm;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

// RecordingAssistant and AcceptsEverything are declared there, under names the autoloader
// cannot map to a file.
require_once __DIR__ . '/ConversationsTest.php';

/**
 * Artifacts: results of a conversation kept as objects of their own — on MySQL and PostgreSQL.
 *
 * What holds: the model's `save_artifact` call keeps a table, a list or a text, linked to the
 * conversation and the answer it came with, and the answer comes back carrying it; what does not
 * fit a kind is refused with the reason, so the model can correct itself; a person reads and
 * removes only their own; deleting the conversation takes its artifacts with it; and each kind is
 * drawn escaped.
 */
#[CoversClass(Artifacts::class)]
#[CoversClass(ArtifactView::class)]
#[CoversClass(ChatController::class)]
#[CoversClass(\Pramnos\Llm\Chat\Web\BulkView::class)]
class ArtifactsTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
    }

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /**
     * A question whose answer saves a list: the artifact is linked to the conversation and the
     * answer, carries the conversation's tags, comes back on the answer and on the conversation,
     * and goes when the conversation is deleted.
     */
    #[DataProvider('databases')]
    public function testTheModelKeepsAnArtifactWithItsAnswer(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([
            // `lookup` reads data tagged `google`, which the artifact has to carry
            [200, self::calls([['lookup', []], ['save_artifact', ['kind' => 'list', 'title' => 'Dormant buyers', 'entity' => 'user', 'ids' => [4, '9'], 'labels' => ['Maria', 'Nikos']]]])],
            [200, self::says('Two buyers have not ordered since spring.')],
        ]);
        Llm::use('rules', [new AcceptsEverything()]);
        $chat = (new Conversations(new RecordingAssistant()))->as(new Scope(7, 3));

        // Act
        $answer = $chat->ask(null, 'Who stopped buying?');
        $stored = $chat->find((int) $answer['conversation_id']);

        // Assert — kept, linked, and on the answer
        $this->assertTrue($answer['ok'], $answer['says']);
        $made = $answer['message']['artifacts'];
        $this->assertCount(1, $made);
        $this->assertSame(['list', 'Dormant buyers', 'user'], [$made[0]['kind'], $made[0]['title'], $made[0]['entity']]);
        $this->assertSame(['ids' => [4, 9], 'labels' => ['Maria', 'Nikos']], $made[0]['content'], 'numeric ids are integers');
        $this->assertSame((int) $answer['conversation_id'], $made[0]['conversation_id']);
        $this->assertSame($answer['message']['message_id'], $made[0]['message_id']);
        $this->assertSame(['google'], $made[0]['tags'], 'the data tags the conversation held');
        $this->assertSame($made, $stored['messages'][1]['artifacts'], 'read back with the conversation');
        $this->assertSame([], $stored['messages'][0]['artifacts'], 'the question made none');
        // The model was offered the tool
        $names = array_column(array_column($this->sent[0]['body']['tools'], 'function'), 'name');
        $this->assertContains('save_artifact', $names);

        // Act — the conversation goes
        $chat->delete((int) $answer['conversation_id']);

        // Assert — and its artifacts with it
        $this->assertSame([], $this->rows('chat_artifacts'));
    }

    /**
     * What does not fit a kind is refused with a reason the model can act on, and nothing is
     * kept; a table's short rows are padded and an object cell is kept as JSON.
     */
    #[DataProvider('databases')]
    public function testEachKindIsCheckedBeforeItIsKept(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $artifacts = new Artifacts(new Scope(7, 3));
        $tool      = $artifacts->tools()['save_artifact']['run'];

        // Act
        $refusals = [
            $artifacts->create(['kind' => 'chart', 'title' => 'x']),
            $artifacts->create(['kind' => 'text', 'title' => '  ']),
            $artifacts->create(['kind' => 'table', 'title' => 'T', 'columns' => [], 'rows' => [[1]]]),
            $artifacts->create(['kind' => 'table', 'title' => 'T', 'columns' => ['a'], 'rows' => []]),
            $artifacts->create(['kind' => 'table', 'title' => 'T', 'columns' => ['a'], 'rows' => [[1, 2]]]),
            $artifacts->create(['kind' => 'list', 'title' => 'L', 'entity' => 'Users!', 'ids' => [1]]),
            $artifacts->create(['kind' => 'list', 'title' => 'L', 'entity' => 'user', 'ids' => []]),
            $artifacts->create(['kind' => 'list', 'title' => 'L', 'entity' => 'user', 'ids' => [1, 2], 'labels' => ['one']]),
            $artifacts->create(['kind' => 'list', 'title' => 'L', 'entity' => 'user', 'ids' => [1, '1']]),
            $artifacts->create(['kind' => 'text', 'title' => 'X', 'text' => '']),
        ];
        $byTool = $tool(['kind' => 'text', 'title' => 'X']);
        $table  = $artifacts->create(['kind' => 'table', 'title' => 'T', 'columns' => ['a', 'b'], 'rows' => [[1], [['x' => 1], true]]]);
        $codes  = $artifacts->create(['kind' => 'list', 'title' => 'Meters', 'entity' => 'device', 'ids' => ['A-17', 'B-2']]);

        // Assert
        foreach ($refusals as $i => $refusal) {
            $this->assertIsString($refusal, 'case ' . $i . ' should have been refused');
        }
        $this->assertStringStartsWith('Error: A text needs', $byTool, 'the tool hands the reason to the model');
        $this->assertSame([[1, null], ['{"x":1}', true]], $table['content']['rows']);
        $this->assertSame(['A-17', 'B-2'], $codes['content']['ids'], 'ids that are not numbers stay strings');
        $this->assertCount(2, $this->rows('chat_artifacts'), 'only the two that fit were kept');
    }

    /**
     * A person lists, reads and removes their own; somebody else's — another person, another
     * organisation — is not there for them.
     */
    #[DataProvider('databases')]
    public function testAPersonReachesOnlyTheirOwn(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $mine   = new Artifacts(new Scope(7, 3));
        $theirs = new Artifacts(new Scope(8, 3));
        $other  = new Artifacts(new Scope(7, 4));
        $made   = $mine->create(['kind' => 'text', 'title' => 'Memo', 'text' => 'Hello']);

        // Act
        $list = $mine->list();

        // Assert
        unset($list[0]['created_at']);
        $this->assertSame([['artifact_id' => $made['artifact_id'], 'kind' => 'text', 'title' => 'Memo', 'entity' => null, 'conversation_id' => null]], $list);
        $this->assertSame('Hello', $mine->find($made['artifact_id'])['content']['text']);
        $this->assertNull($theirs->find($made['artifact_id']));
        $this->assertNull($other->find($made['artifact_id']), 'another organisation');
        $this->assertSame([], $theirs->list());
        $this->assertFalse($theirs->delete($made['artifact_id']));
        $this->assertTrue($mine->delete($made['artifact_id']));
        $this->assertNull($mine->find($made['artifact_id']));
    }

    /**
     * The API: list, read and delete, a 404 for what is not the caller's.
     */
    #[DataProvider('databases')]
    public function testTheApi(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $made = (new Artifacts(new Scope(7, 3)))->create(['kind' => 'text', 'title' => 'Memo', 'text' => 'Hi']);
        $api  = new ArtifactsApi(new Scope(7, 3));
        $none = new ArtifactsApi(new Scope(8, 3));

        // Act / Assert
        $this->assertSame('Memo', $api->artifacts()['data']['artifacts'][0]['title']);
        $this->assertSame('Hi', $api->readArtifact($made['artifact_id'])['data']['content']['text']);
        $this->assertSame(404, $none->readArtifact($made['artifact_id'])['status']);
        $this->assertSame(404, $none->deleteArtifact($made['artifact_id'])['status']);
        $this->assertTrue($api->deleteArtifact($made['artifact_id'])['data']['deleted']);
        $this->assertSame(404, $api->readArtifact($made['artifact_id'])['status']);
        $this->assertSame([], (new ArtifactsApi(null))->artifacts()['data']['artifacts'], 'nobody signed in, nothing listed');
        // Refused by the guard: each endpoint answers with the refusal, not with data
        $denied = new ArtifactsApi(new Scope(7, 3), true);
        foreach ([$denied->artifacts(), $denied->readArtifact(1), $denied->deleteArtifact(1)] as $refused) {
            $this->assertSame(403, $refused['status']);
        }
        $this->assertSame([], (new Artifacts(new Scope(7, 3)))->link(1, 1), 'nothing made this turn, nothing linked');

        // A list's bulk actions through the API: what it offers, running one, a refusal said
        \Pramnos\Bulk\BulkActions::reset();
        \Pramnos\Bulk\BulkActions::forget('user', 'group_add');
        \Pramnos\Bulk\BulkActions::define('user', 'count', 'Count', ['why' => ['label' => 'Why', 'required' => true]],
            static fn (array $ids): array => ['done' => count($ids)], allowed: static fn (): bool => true);
        $list = (new Artifacts(new Scope(7, 3)))->create(['kind' => 'list', 'title' => 'L', 'entity' => 'user', 'ids' => [4, 5]]);
        $text = (new Artifacts(new Scope(7, 3)))->create(['kind' => 'text', 'title' => 'T', 'text' => 'x']);
        $body = new ArtifactsApi(new Scope(7, 3), false, ['params' => ['why' => 'Because']]);
        $this->assertSame(['count'], array_keys($api->artifactActions($list['artifact_id'])['data']['actions']));
        $this->assertSame(404, $api->artifactActions($text['artifact_id'])['status'], 'a text has no records to act on');
        $this->assertSame(404, $none->artifactActions($list['artifact_id'])['status']);
        $this->assertSame(['done' => 2, 'queued' => 0, 'says' => ''], $body->runArtifactAction($list['artifact_id'], 'count')['data']);
        $this->assertSame(422, $api->runArtifactAction($list['artifact_id'], 'count')['status'], 'its required field');
        $this->assertSame(404, $none->runArtifactAction($list['artifact_id'], 'count')['status']);
        $this->assertSame(403, $denied->artifactActions($list['artifact_id'])['status']);
        $this->assertSame(403, $denied->runArtifactAction($list['artifact_id'], 'count')['status']);
        \Pramnos\Bulk\BulkActions::reset();
    }

    /**
     * Each kind drawn, escaped: a table's cells, a list's labels and how many more, a text's
     * Markdown without anything executable.
     */
    public function testEachKindIsDrawnEscaped(): void
    {
        // Arrange
        $ids = range(1, 25);

        // Act
        $table = ArtifactView::html(['artifact_id' => 1, 'kind' => 'table', 'title' => '<T>', 'content' => ['columns' => ['<a>'], 'rows' => [['<b>'], [false]]]], 'card', 'table', 'muted');
        $list  = ArtifactView::html(['artifact_id' => 2, 'kind' => 'list', 'title' => 'L', 'entity' => 'user', 'content' => ['ids' => $ids, 'labels' => ['<Maria>']]]);
        $text  = ArtifactView::html(['artifact_id' => 3, 'kind' => 'text', 'title' => 'X', 'content' => ['text' => '**Kept**<script>x</script>']]);

        // Assert
        $this->assertStringContainsString('data-pf-artifact="1"', $table);
        $this->assertStringContainsString('<strong>&lt;T&gt;</strong>', $table);
        $this->assertStringContainsString('<th>&lt;a&gt;</th>', $table);
        $this->assertStringContainsString('<td>&lt;b&gt;</td>', $table);
        $this->assertStringContainsString('<td>✗</td>', $table);
        $this->assertStringContainsString('Table · 2 rows', $table);
        $this->assertSame(20, substr_count($list, '<li>'), 'only the first twenty');
        $this->assertStringContainsString('&lt;Maria&gt; (#1)', $list);
        $this->assertStringContainsString('and 5 more', $list);
        $this->assertStringContainsString('List · 25 user', $list);
        $this->assertStringContainsString('<strong>Kept</strong>', $text);
        $this->assertStringNotContainsString('<script>', $text);
    }

    /** A list's bulk actions as forms: each kind of field, the count on the button, nothing when there are none. */
    public function testTheBulkForms(): void
    {
        // Act
        $html = \Pramnos\Llm\Chat\Web\BulkView::forms(['go' => ['label' => '<Go>', 'params' => [
            'group' => ['label' => 'Group', 'type' => 'select', 'options' => [3 => '<Press>'], 'required' => true],
            'times' => ['label' => 'Times', 'type' => 'number'],
            'name'  => ['label' => 'Name'],
        ]]], '/chat/act/', 5, 12, 9, '<input name="t">', ['btn' => 'b', 'input' => 'i']);

        // Assert
        $this->assertStringContainsString('<summary>&lt;Go&gt;</summary>', $html);
        $this->assertStringContainsString('action="/chat/act/5"', $html);
        $this->assertStringContainsString('<select name="params[group]" class="i" required>', $html);
        $this->assertStringContainsString('<option value="3">&lt;Press&gt;</option>', $html);
        $this->assertStringContainsString('<input type="number" name="params[times]"', $html);
        $this->assertStringContainsString('<input type="text" name="params[name]"', $html);
        $this->assertStringContainsString('Do it for 12', $html);
        $this->assertSame('', \Pramnos\Llm\Chat\Web\BulkView::forms([], '/chat/act/', 5, 12, 9, ''));
    }

    /** Without the table, there is no tool, and reading finds nothing. */
    public function testWithoutTheTableThereIsNoTool(): void
    {
        // Arrange — the default connection has no LLM tables
        $artifacts = new Artifacts(new Scope(7, 3));

        // Act / Assert
        $this->assertFalse(Artifacts::available());
        $this->assertSame([], $artifacts->tools());
        $this->assertSame([], $artifacts->list());
        $this->assertNull($artifacts->find(1));
        $this->assertSame([], $artifacts->forConversation(1));
    }
}

/** The chat API as a given person, without a request. */
final class ArtifactsApi extends ChatController
{
    /** @param array<string, mixed> $body */
    public function __construct(private readonly ?Scope $as, private readonly bool $deny = false, private readonly array $body = [])
    {
    }

    protected function input(): array
    {
        return $this->body;
    }

    protected function scope(): ?Scope
    {
        return $this->as;
    }

    protected function refusal(string $action): ?array
    {
        return $this->deny ? ['status' => 403, 'error' => 'refused'] : null;
    }
}
