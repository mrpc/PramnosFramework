<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * Three ways a companion said what was not so, from one real conversation (FW-447, FW-448,
 * FW-449), on MySQL and PostgreSQL.
 *
 * What has to hold: a dictated sentence that may be a question is not kept as a fact, and nothing
 * contradicting what is known is kept without asking; "is my alarm set?" is answered from what is
 * scheduled — the next day's tasks are in the model's view, the list is offered without a skill,
 * and "no" without looking is corrected; an answer written before a tool call and never again is
 * the answer, not an empty message.
 */
#[CoversClass(Routines::class)]
#[CoversClass(Conversations::class)]
#[CoversClass(\Pramnos\Llm\Chat\Memory::class)]
class ChatTruthTest extends LlmTestCase
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

    /** The tail of what was sent: the line just before the question. */
    private static function tail(array $sent): string
    {
        return (string) $sent['body']['messages'][count($sent['body']['messages']) - 2]['content'];
    }

    /** The names of the tools a request offered. */
    private static function offered(array $sent): array
    {
        return array_map(static fn (array $t): string => (string) $t['function']['name'], (array) ($sent['body']['tools'] ?? []));
    }

    /**
     * FW-447: remembering is told that a dictated sentence may be a question and that a fact
     * contradicting what is known waits for the person; a question asked aloud is told it may
     * have no question mark.
     */
    public function testAQuestionIsNotAFact(): void
    {
        // Arrange
        $tools = (new \Pramnos\Llm\Chat\Memory(new Scope(1)))->tools(new Conversations(new BaseAssistant()), null);

        // Act
        $remember = (string) $tools['remember']['description'];

        // Assert
        $this->assertStringContainsString('Never from a sentence that may be a question', $remember);
        $this->assertStringContainsString('contradicts', $remember);
        $this->assertStringContainsString('may be a question though it has no question mark', \Pramnos\Llm\Chat\ContextAssembler::SPOKEN);
    }

    /** FW-448: a denial is read in Greek and English, and is left alone once the list was read. */
    public function testANoWithoutLooking(): void
    {
        // Act / Assert
        $this->assertNotNull(Routines::unchecked('Όχι, δεν έχουμε ορίσει κάποιο ξυπνητήρι.', []));
        $this->assertNotNull(Routines::unchecked('No, there are no reminders.', []));
        $this->assertNull(Routines::unchecked('Όχι, δεν έχουμε ορίσει κάποιο ξυπνητήρι.', [['name' => 'list_scheduled_tasks', 'arguments' => []]]));
        $this->assertNull(Routines::unchecked('Δεν έχω πρόσβαση στα analytics.', []), 'not about what is scheduled');
    }

    /**
     * FW-448: the next day's reminder is in the model's view; the list is offered without the
     * skill; a "no" without looking is sent back and answered from the list.
     */
    #[DataProvider('databases')]
    public function testIsMyAlarmSet(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $scope  = new Scope($person);
        $chat   = (new Conversations(new BaseAssistant()))->as($scope);
        $made   = $chat->toolsFor(new \Pramnos\Llm\Chat\Turn($scope, null, false, [], [], [], $chat))['schedule_task']['run'](
            ['kind' => 'reminder', 'title' => 'Meeting', 'prompt' => 'The meeting', 'cadence' => 'once', 'in_minutes' => 90]);
        $this->answer([
            [200, self::says('Όχι, δεν έχουμε ορίσει κάποιο ξυπνητήρι.')],
            [200, self::calls([['list_scheduled_tasks', []]])],
            [200, self::says('Ναι: σε μιάμιση ώρα, για τη συνάντηση.')],
        ]);

        // Act
        $answer = $chat->ask(null, 'έχουμε ορίσει ξυπνητήρι');

        // Assert
        $this->assertStringNotContainsString('Error', $made);
        $this->assertStringContainsString("Scheduled for this person in the next 24 hours:\n- ", self::tail($this->sent[0]));
        $this->assertStringContainsString('Meeting (reminder)', self::tail($this->sent[0]));
        $this->assertContains('list_scheduled_tasks', self::offered($this->sent[0]), 'without the tasks skill');
        $this->assertStringContainsString('without looking', json_encode($this->sent[1]['body']['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('Ναι: σε μιάμιση ώρα, για τη συνάντηση.', $answer['message']['content']);
        $this->db->queryBuilder()->table('pramnos.ai_routines')->where('userid', $person)->delete();
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }

    /** With nothing scheduled the model is told so, rather than nothing. */
    #[DataProvider('databases')]
    public function testNothingScheduledIsSaid(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $this->answer([[200, self::says('Hi.')]]);

        // Act
        (new Conversations(new BaseAssistant()))->as(new Scope($person))->ask(null, 'Hello');

        // Assert
        $this->assertStringContainsString('Nothing is scheduled for this person in the next 24 hours.', self::tail($this->sent[0]));
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }

    /**
     * FW-449: the model wrote its answer, called a tool and wrote nothing more — the answer is
     * what it wrote, not an empty message with the words folded away as a step.
     */
    #[DataProvider('databases')]
    public function testAnAnswerWrittenBeforeATool(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $this->answer([
            [200, self::calls([['list_scheduled_tasks', []]], ['content' => 'Το κοιτάζω και σου λέω αμέσως.'])],
            [200, self::says('')],
        ]);

        // Act
        $answer = (new Conversations(new BaseAssistant()))->as(new Scope($person))->ask(null, 'Τι έχω αύριο;');

        // Assert
        $this->assertTrue($answer['ok'], json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Το κοιτάζω και σου λέω αμέσως.', $answer['message']['content'], 'what it wrote is the answer');
        $this->assertArrayNotHasKey('steps', $answer['message']['extra'], 'not folded away as a step too');
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }
}
