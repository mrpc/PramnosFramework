<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Broadcasting\Testing\FakeDriver;
use Pramnos\Conversations\Ask;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Markdown;
use Pramnos\Conversations\Messages;

/**
 * `@Ask` in a conversation, on each backend: a message that calls on the analyst puts its question
 * to it as its writer — only where the analyst is there and the writer may ask it — and the answer,
 * or why there is none, comes in the message's thread, signed "Ask", once.
 */
#[CoversClass(Ask::class)]
#[CoversClass(Markdown::class)]
class AskTest extends TestCase
{
    /** The conversation the broadcast test answers in. */
    private int $general = 0;

    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The analyst's own queue back, no key, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        Ask::queueWith(null);
        Settings::setSetting('llm_openai_api_key', '', false);
        $this->probeTearDown();
    }

    /**
     * What calls on the analyst: `@Ask` as a word of its own, anywhere, in any case — not inside
     * an address or another word; and it is drawn as a mention.
     */
    public function testWhatCallsOnTheAnalyst(): void
    {
        // Arrange
        $bodies = ['@Ask πόσοι;', 'γεια @ask: πόσοι;', 'mail@ask.gr', '@asking', 'κανένα'];

        // Act
        $called = array_map([Ask::class, 'called'], $bodies);
        $html   = Markdown::render('@ask πόσοι;', static fn (): ?string => null);

        // Assert
        $this->assertSame([true, true, false, false, false], $called);
        $this->assertSame('<p><span class="pf-conv-mention pf-conv-mention-ask">@Ask</span> πόσοι;</p>', $html);
    }

    /**
     * Named in app.php — `llm.analyst.name`, `mentions` — the analyst is called by `@Ask`, its name
     * and its mentions, and drawn by its name; its own conversation is shown by its name and
     * picture, and kept under Ask::NAME.
     */
    public function testANamedAnalyst(): void
    {
        // Arrange
        $app  = \Pramnos\Application\Application::getInstance();
        $kept = $app->applicationInfo['llm'] ?? null;
        $app->applicationInfo['llm'] = ['analyst' => ['name' => 'Naya', 'mentions' => ['ναγια'], 'avatar' => '/img/ai.svg']];

        try {
            // Act
            $called = array_map([Ask::class, 'called'], ['@Naya πόσοι;', '@ναγια πόσοι;', '@Ask πόσοι;', '@nayas', 'naya']);
            $html   = Markdown::render('@NAYA πόσοι;', static fn (): ?string => null);
            $names  = [Ask::displayName(Ask::NAME), Ask::displayName('Jira')];
        } finally {
            $app->applicationInfo['llm'] = $kept;
        }

        // Assert
        $this->assertSame([true, true, true, false, false], $called, 'its name, its mention, and Ask still');
        $this->assertSame('<p><span class="pf-conv-mention pf-conv-mention-ask">@Naya</span> πόσοι;</p>', $html);
        $this->assertSame(['Naya', 'Jira'], $names, 'only the analyst\'s conversation takes its name');
    }

    /**
     * A message with `@Ask` from somebody who may ask is queued — the question without the call,
     * as its writer, to be answered in its thread (a reply's, its root's); nothing without the
     * analyst, for somebody below its floor, or for a message that does not call on it.
     */
    #[DataProvider('databases')]
    public function testAQuestionIsQueuedAsItsWriter(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        [$eleni, $nikos] = [$this->ids['eleni'], $this->ids['nikos']];
        $general = Conversations::create('channel', 'general', [$nikos], $eleni);
        $root    = Messages::post($general, $eleni, 'Αναφορά εγγραφών');
        $reply   = Messages::post($general, $eleni, '@Ask, πόσες εγγραφές χθες;', ['thread' => $root]);
        $plain   = Messages::post($general, $eleni, 'χωρίς ερώτηση');
        $bare    = Messages::post($general, $eleni, '@Ask');
        $asked   = [];
        Ask::queueWith(function (int $userId, string $question, array $extra) use (&$asked): int {
            $asked[] = [$userId, $question, $extra['conversations']];

            return count($asked);
        });

        // Act
        $noKey = Ask::start($reply, $this->user('eleni'));
        Settings::setSetting('llm_openai_api_key', 'sk-test', false);
        $queued   = Ask::start($reply, $this->user('eleni'));
        $sales    = Ask::start($reply, $this->user('nikos'));
        $notAsked = Ask::start($plain, $this->user('eleni'));
        $gone     = Ask::start(424242, $this->user('eleni'));
        $onlyCall = Ask::start($bare, $this->user('eleni'));

        // Assert
        $this->assertNull($noKey, 'no analyst without a key');
        $this->assertSame(1, $queued);
        $this->assertSame([$eleni, 'πόσες εγγραφές χθες;', ['conversation' => $general, 'thread' => $root, 'message' => $reply]], $asked[0],
            'the question alone, as her, into the thread of the reply\'s root');
        $this->assertNull($sales, 'below the analyst\'s floor');
        $this->assertSame([null, null], [$notAsked, $gone]);
        $this->assertSame('@Ask', $asked[1][1], 'a bare call is asked as it is');
        $this->assertTrue(Ask::canAsk($this->user('eleni')));
        $this->assertFalse(Ask::canAsk(null));
    }

    /**
     * The answer comes in the thread signed "Ask", once however often it is told; a failure says
     * why; an ask from elsewhere — the analyst's own page — is not posted.
     */
    #[DataProvider('databases')]
    public function testTheAnswerComesInTheThread(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $general = Conversations::create('channel', 'general', [], $this->ids['eleni']);
        $root    = Messages::post($general, $this->ids['eleni'], '@Ask πόσοι;');
        $where   = ['conversations' => ['conversation' => $general, 'thread' => $root, 'message' => $root]];

        // Act
        Ask::answered(['ask_id' => 7, 'extra' => $where], ['ok' => true, 'message' => ['content' => "**42** χθες.\n- Αθήνα 30"]]);
        Ask::answered(['ask_id' => 7, 'extra' => $where], ['ok' => true, 'message' => ['content' => '**42** χθες.']]);
        Ask::answered(['ask_id' => 8, 'extra' => $where], ['ok' => false, 'says' => 'The AI stopped: timeout']);
        Ask::answered(['ask_id' => 9, 'extra' => []], ['ok' => true, 'message' => ['content' => 'αλλού']]);
        $thread = Messages::list($general, ['thread' => $root]);

        // Assert
        $this->assertCount(3, $thread, 'the question, the answer once, the failure; nothing from elsewhere');
        $this->assertSame(["**42** χθες.\n- Αθήνα 30", 'Ask'], [$thread[1]['body'], $thread[1]['card']['from']]);
        $this->assertSame('⚠ The AI stopped: timeout', $thread[2]['body']);
        $this->assertSame(2, Messages::get($root)['replyCount']);
    }

    /**
     * The people in a conversation are told the analyst is answering — `assistant.thinking`, with
     * the thread, its name and until when — and that it has finished, answered or not:
     * `assistant.done`. A worker cannot whisper, so the server says so.
     */
    #[DataProvider('databases')]
    public function testTheConversationIsToldTheAnalystIsAnswering(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $general = Conversations::create('channel', 'general', [], $this->ids['eleni']);
        $root    = Messages::post($general, $this->ids['eleni'], '@Ask πόσοι;');
        $job     = ['ask_id' => 7, 'extra' => ['conversations' => ['conversation' => $general, 'thread' => $root, 'message' => $root]]];
        $fake    = FakeDriver::swap();
        $before  = time();

        try {
            // Act
            Ask::thinking($job);
            Ask::answered($job, ['ok' => false, 'says' => 'The AI stopped: timeout']);
            Ask::thinking(['extra' => []]);
            $told = array_values(array_filter($fake->recorded(), static fn (array $r): bool => str_starts_with($r['event'], 'assistant.')));
        } finally {
            FakeDriver::restore();
        }

        // Assert
        $this->assertSame(['private-conversation.' . $general, 'assistant.thinking'], [$told[0]['channel'], $told[0]['event']]);
        $this->assertSame([$root, 'Ask'], [$told[0]['payload']['thread'], $told[0]['payload']['name']]);
        $this->assertGreaterThanOrEqual($before + \Pramnos\Conversations\Realtime::ASSISTANT_SECONDS, $told[0]['payload']['until']);
        $this->assertSame(['assistant.done', ['thread' => $root]], [$told[1]['event'], $told[1]['payload']], 'done, though it failed');
        $this->assertCount(2, $told, 'an ask from elsewhere tells nobody');
    }

    /**
     * An answer in a thread is seen in the channel too — FW-258's broadcast — when the question is
     * still the newest message there, nobody having written since (`if_last`, the default); not
     * when somebody has; `always` and `never` as they say.
     */
    #[DataProvider('databases')]
    public function testAnAnswerIsSeenInTheChannelWhenNothingCameSince(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $app = Application::getInstance();
        $app->applicationInfo['conversations'] = ['usertype' => 30];
        $kept    = $app->applicationInfo['llm'] ?? null;
        $general = Conversations::create('channel', 'general', [$this->ids['maria']], $this->ids['eleni']);
        $asks    = 100;
        $answer  = function (int $root) use (&$asks): array {
            // An ask of its own each time: the same ask told twice is posted once.
            Ask::answered(['ask_id' => ++$asks, 'extra' => ['conversations' => ['conversation' => $this->general, 'thread' => $root, 'message' => $root]]],
                ['ok' => true, 'message' => ['content' => 'απάντηση ' . $root]]);
            $replies = Messages::list($this->general, ['thread' => $root]);

            return [end($replies)['broadcast']];
        };
        $this->general = $general;

        try {
            // Act
            $last     = $answer(Messages::post($general, $this->ids['eleni'], '@Ask πρώτη;'));
            $asked    = Messages::post($general, $this->ids['eleni'], '@Ask δεύτερη;');
            Messages::post($general, $this->ids['maria'], 'κάτι άλλο');
            $notLast  = $answer($asked);
            $app->applicationInfo['llm'] = ['analyst' => ['thread_broadcast' => 'always']];
            $always   = $answer($asked);
            $app->applicationInfo['llm'] = ['analyst' => ['thread_broadcast' => 'never']];
            $never    = $answer(Messages::post($general, $this->ids['eleni'], '@Ask τρίτη;'));
        } finally {
            $app->applicationInfo['llm'] = $kept;
        }

        // Assert
        $this->assertSame([true], $last, 'nothing since: seen in the channel too');
        $this->assertSame([false], $notLast, 'somebody wrote since: the thread only');
        $this->assertSame([true], $always);
        $this->assertSame([false], $never, 'never, though it is the newest');
    }

    /**
     * A conversation with Ask, for whoever may ask it — one, however often it is opened; none for
     * a person below the analyst's floor. Every message in it is a question, answered there, not in
     * a thread, with the asker's rights; the answer leads to the same conversation on Ask's page,
     * and the next question goes on in it — the context carried from answer to answer.
     */
    #[DataProvider('databases')]
    public function testAConversationWithAskGoesOnFromAnswerToAnswer(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Settings::setSetting('llm_openai_api_key', 'sk-test', false);
        $eleni = $this->ids['eleni'];
        $asked = [];
        Ask::queueWith(function (int $userId, string $question, array $extra, ?int $goesOn) use (&$asked): int {
            $asked[] = [$userId, $question, $extra, $goesOn];

            return count($asked);
        });
        $dm = Ask::conversationFor($this->user('eleni'));

        // Act
        $again = Ask::conversationFor($this->user('eleni'));
        $first = Ask::start(Messages::post($dm, $eleni, 'πόσες εγγραφές χθες;'), $this->user('eleni'));
        // The analyst answered it in its conversation 55: the ask's row says so.
        $this->db->queryBuilder()->table('chat_asks')->insert(['organization_id' => null, 'userid' => $eleni, 'space' => 'admin',
            'conversation_id' => 55, 'status' => 'answered', 'created_at' => '2026-10-22 10:00:00', 'updated_at' => '2026-10-22 10:00:00']);
        $askId = (int) $this->db->queryBuilder()->table('chat_asks')->orderBy('ask_id', 'desc')->first()->fields['ask_id'];
        Ask::answered(['ask_id' => $askId, 'extra' => $asked[0][2]], ['ok' => true, 'conversation_id' => 55, 'message' => ['content' => '42']]);
        $root   = Messages::post($dm, $eleni, 'και σήμερα;');
        $second = Ask::start($root, $this->user('eleni'));
        $reply  = Ask::start(Messages::post($dm, $eleni, 'σημείωση', ['thread' => $root]), $this->user('eleni'));
        $answer = array_values(array_filter(Messages::list($dm), static fn (array $m): bool => $m['authorId'] === null))[0];

        // Assert
        $this->assertSame($dm, $again, 'one conversation with Ask');
        $this->assertSame(['bot', 'Ask'], [Conversations::get($dm)['kind'], Conversations::get($dm)['name']]);
        $this->assertNull(Ask::conversationFor($this->user('nikos')), 'below the analyst\'s floor, no Ask');
        $this->assertSame([1, 2], [$first, $second]);
        $this->assertSame([$eleni, 'πόσες εγγραφές χθες;', null], [$asked[0][0], $asked[0][1], $asked[0][3]], 'a question without @Ask, a new conversation');
        $this->assertNull($asked[0][2]['conversations']['thread'], 'answered in the conversation, not a thread');
        $this->assertArrayNotHasKey('rights_of', $asked[0][2], 'with the asker\'s own rights');
        $this->assertSame(55, $asked[1][3], 'the next question goes on in the same analyst conversation');
        $this->assertNull($reply, 'a note in a thread is not a question');
        $this->assertSame(['42', null, 'Open in Ask'], [$answer['body'], $answer['threadRootId'], $answer['card']['title']]);
        $this->assertStringEndsWith('Ai?c=55', $answer['card']['url']);
    }

    /**
     * `@Ask` where others read the answer is answered with the rights of its least privileged
     * reader — the members of a closed conversation; everybody who may use the conversations in an
     * open one, a member or not — the lowest usertype, the asker among them.
     */
    #[DataProvider('databases')]
    public function testElsewhereTheAnswerHasItsLeastPrivilegedReadersRights(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Settings::setSetting('llm_openai_api_key', 'sk-test', false);
        [$eleni, $maria, $nikos, $root] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos'], $this->ids['root']];
        $board   = Conversations::create('private', 'board', [$eleni], $root);
        $general = Conversations::create('channel', 'general', [], $root);
        $pair    = Conversations::create('dm', null, [$root, $maria]);
        $rights  = [];
        Ask::queueWith(function (int $userId, string $question, array $extra) use (&$rights): int {
            $rights[] = $extra['rights_of'];

            return count($rights);
        });

        // Act
        foreach ([$board, $general, $pair] as $where) {
            Ask::start(Messages::post($where, $root, '@Ask πόσα έσοδα χθες;'), $this->user('root'));
        }

        // Assert
        $this->assertSame([$eleni, $nikos, $maria], $rights, 'the board\'s 98; the open channel\'s sales, though not in it; the dm\'s 97');
        $this->assertSame($root, Ask::readerOf(424242, $root), 'nobody else reads what does not exist');
    }

    /**
     * The worker posts the answer back itself: an ask from a conversation, run where no provider
     * registered a listener — a CLI application's worker — reaches its conversation, once however
     * often the task runs; why it failed, when it did.
     */
    #[DataProvider('databases')]
    public function testTheWorkerPostsTheAnswerWithNoListener(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        \Pramnos\Llm\Chat\Asks::reset();
        $eleni = $this->ids['eleni'];
        $dm    = Conversations::create('bot', Ask::NAME, [$eleni]);
        $asked = Messages::post($dm, $eleni, 'πόσοι;');
        $queue = new class () extends \Pramnos\Queue\QueueManager {
            /** @var list<array<string, mixed>> */
            public array $tasks = [];

            /** Nothing to connect to. */
            public function __construct()
            {
            }

            /** Keep the task instead of queueing it. */
            public function addTask(string $taskType, mixed $data, int $priority = 10, int $maxAttempts = 3, bool $unique = false): ?int
            {
                $this->tasks[] = $data;

                return count($this->tasks);
            }
        };
        $asks = new \Pramnos\Llm\Chat\Asks(new \Pramnos\Llm\Chat\Conversations(\Pramnos\Llm\Analyst\Analyst::assistant()), $queue);
        // A conversation of the analyst's that is not there: answered without asking a model.
        $asks->start(\Pramnos\Llm\Analyst\Analyst::scope($eleni), 424242, 'πόσοι;', null,
            ['conversations' => ['conversation' => $dm, 'thread' => null, 'message' => $asked]]);

        // Act
        $asks->run($queue->tasks[0]);
        $asks->run($queue->tasks[0]);

        // Assert
        $answers = array_values(array_filter(Messages::list($dm), static fn (array $m): bool => $m['source'] === 'ask'));
        $this->assertCount(1, $answers, 'posted once, with no listener registered');
        $this->assertSame(['⚠ There is no such conversation.', 'Ask', null], [$answers[0]['body'], $answers[0]['card']['from'], $answers[0]['threadRootId']]);
    }
}
