<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Ratings;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Web\RatingView;
use Pramnos\Llm\Chat\Web\ReviewQueueView;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ConversationsTest.php';
require_once __DIR__ . '/ArtifactsTest.php';
require_once __DIR__ . '/AnalystTest.php';

/**
 * Rating answers and the queue of bad ones to review — on MySQL and PostgreSQL.
 *
 * What holds: a person rates only answers in their own conversations, and rating again replaces
 * it; a bad answer waits in the queue with the question it answered and the note, until it is
 * marked reviewed, which the audit log records; a new verdict puts it back; the summary counts
 * them; and the page, the API and the analyst's review screen do the same.
 */
#[CoversClass(Ratings::class)]
#[CoversClass(RatingView::class)]
#[CoversClass(ReviewQueueView::class)]
class RatingsTest extends LlmTestCase
{
    use ChatDatabase;

    protected function tearDown(): void
    {
        \Pramnos\Auth\AuditLog::resetTableCache();
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A conversation of this scope's: a question and its answer. Answers [conversation, answer id]. */
    private function exchange(Scope $scope, string $question = 'How many?', string $answer = 'Forty.'): array
    {
        $chat = (new Conversations(new RecordingAssistant()))->as($scope);
        $id   = (int) $chat->seed('Counts', [['role' => 'user', 'content' => $question], ['role' => 'assistant', 'content' => $answer]]);

        return [$id, (int) $chat->find($id)['messages'][1]['message_id']];
    }

    /**
     * Rating: only one's own answers; good, bad with a note, taken back; the queue holds the bad
     * one with its question until it is reviewed; rating again puts it back; the summary counts.
     */
    #[DataProvider('databases')]
    public function testRatingAndTheQueue(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $mine   = new Scope(7, 3);
        $ratings = new Ratings();
        [$id, $answer] = $this->exchange($mine, 'How many bookings in May?', 'There were **40**.');
        $question = (int) (new Conversations(new RecordingAssistant()))->as($mine)->find($id)['messages'][0]['message_id'];
        [, $other]     = $this->exchange(new Scope(8, 3));

        // Act — refusals
        $refusals = [
            $ratings->rate($mine, $answer, 5),
            $ratings->rate($mine, $question, 1),
            $ratings->rate($mine, $other, -1),
            $ratings->rate($mine, 999999, -1),
        ];
        // Act — good, then bad with a note, then queue and review
        $ratings->rate($mine, $answer, 1);
        $good    = (new Conversations(new RecordingAssistant()))->as($mine)->find($id)['messages'][1]['rating'];
        $ratings->rate($mine, $answer, -1, str_repeat('x', 600));
        $queue   = $ratings->queue();
        $summary = $ratings->summary();
        $done    = $ratings->reviewed($answer, 11);
        $twice   = $ratings->reviewed($answer, 11);
        $empty   = $ratings->queue();
        $all     = $ratings->queue(100, true);
        $ratings->rate($mine, $answer, -1, 'Still wrong');
        $back    = $ratings->queue();
        $ratings->rate($mine, $answer, 0);

        // Assert
        $this->assertSame(['A rating is good (1), bad (-1), or none (0).', 'There is no such answer.', 'There is no such answer.', 'There is no such answer.'], $refusals);
        $this->assertSame(1, $good);
        $this->assertCount(1, $queue);
        $this->assertSame([$answer, $id, 'Counts', 7, 'chat', 'How many bookings in May?', 'There were **40**.', 500],
            [$queue[0]['message_id'], $queue[0]['conversation_id'], $queue[0]['title'], $queue[0]['owner'], $queue[0]['space'], $queue[0]['question'], $queue[0]['answer'], mb_strlen($queue[0]['note'])]);
        $this->assertSame(['good' => 0, 'bad' => 1, 'waiting' => 1], $summary, 'the good one was replaced by the bad');
        $this->assertTrue($done);
        $this->assertFalse($twice);
        $this->assertSame([], $empty);
        $this->assertTrue($all[0]['reviewed']);
        $this->assertSame('Still wrong', $back[0]['note'], 'a new verdict is in the queue again');
        $this->assertSame([], $ratings->queue(), 'taken back, out of the queue');
        $this->assertNull((new Conversations(new RecordingAssistant()))->as($mine)->find($id)['messages'][1]['rating']);
    }

    /** The page's action, the API, and the analyst's review screen with its button. */
    #[DataProvider('databases')]
    public function testThePageTheApiAndTheReviewScreen(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $mine = new Scope(7, null);
        [$id, $answer] = $this->exchange($mine);

        // Act — the page
        $_POST = ['rating' => '-1', 'note' => 'Wrong year', 'conversation_id' => (string) $id];
        $page  = new PageFixture($mine, 'plain-css');
        $page->rate($answer);
        $other = new PageFixture(new Scope(8, null), 'plain-css');
        $other->rate($answer);
        $_POST = [];

        // Assert
        $this->assertSame(['Thank you — noted.'], $page->messages);
        $this->assertStringEndsWith('chat?c=' . $id, $page->redirectedTo[0]);
        $this->assertSame(['There is no such answer.'], $other->errors);

        // Act / Assert — the API
        $this->assertTrue((new ArtifactsApi($mine, false, ['rating' => 1]))->rate($answer)['data']['rated']);
        $this->assertSame(422, (new ArtifactsApi($mine, false, ['rating' => 7]))->rate($answer)['status']);
        $this->assertSame(404, (new ArtifactsApi(new Scope(8, null), false, ['rating' => 1]))->rate($answer)['status']);
        $this->assertSame(404, (new ArtifactsApi(null))->rate($answer)['status']);
        $this->assertSame(403, (new ArtifactsApi($mine, true))->rate($answer)['status']);
        (new ArtifactsApi($mine, false, ['rating' => -1, 'note' => 'Wrong year']))->rate($answer);

        // Act — the review screen, in every theme; marked reviewed; somebody who may not
        $pages = [];
        foreach (['plain-css', 'tailwind', 'bootstrap'] as $theme) {
            $pages[$theme] = (string) (new AnalystScreen($theme))->reviews();
        }
        $marking = new AnalystScreen();
        $marking->reviewed($answer);
        $again   = new AnalystScreen();
        $again->reviewed($answer);
        $refused = new AnalystScreen();
        $refused->reading = (object) ['userid' => 9, 'usertype' => 1, 'active' => 1];
        $closed  = 0;
        foreach ([static fn () => $refused->reviews(), static fn () => $refused->reviewed($answer)] as $call) {
            try {
                $call();
            } catch (\Pramnos\Application\ApplicationClosedException) {
                // The 404 page, as for any address that is not there.
                $closed++;
            }
        }

        // Assert
        foreach ($pages as $theme => $html) {
            $this->assertStringContainsString('data-pf-review="' . $answer . '"', $html, $theme);
            $this->assertStringContainsString('Wrong year', $html, $theme);
            $this->assertStringContainsString('Ai/reviewed/' . $answer, $html, $theme);
            $this->assertStringContainsString('1 bad; 1 waiting', $html, $theme);
        }
        $this->assertSame(['Marked reviewed.'], $marking->messages);
        $this->assertSame(['That answer is not waiting to be reviewed.'], $again->errors);
        $this->assertSame(2, $closed, 'a 404 for whoever does not read everybody\'s');
        $this->assertSame([], $refused->messages);
    }

    /** The markup: the pressed button, the note beside the bad one; an empty queue says so. */
    public function testTheMarkup(): void
    {
        // Act
        $buttons = RatingView::buttons(['message_id' => 4, 'rating' => -1], '/chat/rate/', 9, '', ['btnGhost' => 'g']);
        $empty   = ReviewQueueView::html([], ['good' => 2, 'bad' => 0, 'waiting' => 0], [], '');
        $admin   = ReviewQueueView::html([['message_id' => 5, 'conversation_id' => 6, 'title' => 'T', 'owner_name' => 'N', 'rated_at' => 'now', 'space' => 'admin',
            'question' => '<q>', 'answer' => 'a', 'note' => '']], ['good' => 0, 'bad' => 1, 'waiting' => 1], ['open' => '/admin/Ai/all?c=', 'reviewed' => '/r/', 'back' => '/admin/Ai'], '');

        // Assert
        $this->assertStringContainsString('action="/chat/rate/4"', $buttons);
        $this->assertStringContainsString('name="rating" value="0"', $buttons, 'pressing the chosen one again takes it back');
        $this->assertStringContainsString('aria-pressed="true" aria-label="A bad answer"', $buttons);
        $this->assertStringContainsString('name="note"', $buttons);
        $this->assertStringContainsString('Nothing waiting', $empty);
        $this->assertStringContainsString('href="/admin/Ai/all?c=6"', $admin, 'an analyst conversation opens read only');
        $this->assertStringContainsString('&lt;q&gt;', $admin);
        $this->assertStringContainsString('href="/admin/Ai"', $admin);
    }

    /** Without the columns, nothing is rated and nothing waits. */
    public function testWithoutTheColumns(): void
    {
        // Arrange
        $ratings = new Ratings();

        // Act / Assert
        $this->assertFalse(Ratings::available());
        $this->assertSame('Rating is not set up here.', $ratings->rate(new Scope(7, 3), 1, 1));
        $this->assertSame([], $ratings->queue());
        $this->assertFalse($ratings->reviewed(1, 1));
        $this->assertSame(['good' => 0, 'bad' => 0, 'waiting' => 0], $ratings->summary());
    }
}
