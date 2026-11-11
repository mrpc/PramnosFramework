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
 * A reminder promised is a reminder scheduled (FW-431), on MySQL and PostgreSQL.
 *
 * What went wrong: "remind me at ten, I have a meeting" — the model called only set_busy, which
 * sends nothing, and answered "I'll ring you at ten". What has to hold now: on the conversation
 * page schedule_task is offered without opening the tasks skill; an answer that promises a
 * reminder with nothing scheduled is corrected once, and the reminder is then written; an answer
 * that schedules it, or promises nothing, is left alone.
 */
#[CoversClass(Routines::class)]
#[CoversClass(Conversations::class)]
class ReminderPromiseTest extends LlmTestCase
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

    /** The names of the tools a request offered the model. */
    private static function offered(array $sent): array
    {
        return array_map(static fn (array $t): string => (string) $t['function']['name'], (array) ($sent['body']['tools'] ?? []));
    }

    /**
     * The promise is read in Greek and English; a reply that promises nothing, or one whose calls
     * scheduled it, is not corrected.
     */
    public function testAPromiseWithNothingScheduled(): void
    {
        // Act / Assert
        $this->assertNotNull(Routines::unkept('Έγινε — στις 10:00 θα σου χτυπήσω', [['name' => 'set_busy', 'arguments' => []]]), 'set_busy sends nothing');
        $this->assertNotNull(Routines::unkept("Sure, I'll remind you at ten.", []));
        $this->assertNotNull(Routines::unkept('Εντάξει, θα σε ειδοποιήσω πριν τη συνάντηση.', []));
        $this->assertNull(Routines::unkept('Θα σου θυμίσω στις 10.', [['name' => 'schedule_task', 'arguments' => []]]), 'scheduled');
        $this->assertNull(Routines::unkept('Θα σου πω τα νούμερα αμέσως.', []), 'no promise of a reminder');
        $this->assertStringContainsString('kind "reminder"', (string) Routines::unkept('I will remind you', []));
    }

    /**
     * On the conversation page: schedule_task is offered from the first request, without the
     * tasks skill; the promise without a call is corrected, the model schedules it, and the
     * reminder is in the person's routines. In the administration's space the tool stays in its
     * skill.
     */
    #[DataProvider('databases')]
    public function testThePromiseIsCorrectedAndTheReminderWritten(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $this->answer([
            [200, self::says('Έγινε — στις 10:00 θα σου χτυπήσω.')],
            [200, self::calls([['schedule_task', ['kind' => 'reminder', 'title' => 'Συνάντηση', 'prompt' => 'Η συνάντηση', 'cadence' => 'once', 'in_minutes' => 60]]])],
            [200, self::says('Έγινε, θα σου θυμίσω σε μία ώρα.')],
        ]);

        // Act
        $answer = (new Conversations(new BaseAssistant()))->as(new Scope($person, null))->ask(null, 'Μπορείς να με ειδοποιήσεις σε μία ώρα; Έχω συνάντηση.');
        $tagged = static function (string $space) use ($person): array {
            $scope = new Scope($person, null, $space);
            $chat  = (new Conversations(new BaseAssistant()))->as($scope);

            return (array) ($chat->toolsFor(new \Pramnos\Llm\Chat\Turn($scope, null, false, [], [], [], $chat))['schedule_task']['skills'] ?? []);
        };
        $routines = $this->db->queryBuilder()->table('pramnos.ai_routines')->where('userid', $person)->get();
        $count = 0;
        while ($routines->fetch() !== null) {
            $count++;
        }

        // Assert
        $this->assertContains('schedule_task', self::offered($this->sent[0]), 'offered without the tasks skill');
        $this->assertStringContainsString('nothing was scheduled', json_encode($this->sent[1]['body']['messages'], JSON_UNESCAPED_UNICODE), 'corrected once');
        $this->assertSame('Έγινε, θα σου θυμίσω σε μία ώρα.', $answer['message']['content']);
        $this->assertSame(1, $count, 'the reminder is written');
        $this->assertSame([], $tagged(Scope::CHAT), 'in no skill on the conversation page');
        $this->assertSame(['tasks'], $tagged(Scope::ADMIN), 'the administration\'s space keeps it in its skill');
        $this->db->queryBuilder()->table('pramnos.ai_routines')->where('userid', $person)->delete();
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }
}
