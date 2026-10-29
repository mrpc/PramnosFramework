<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\CallLog;
use Pramnos\Llm\Client;
use Pramnos\Llm\Idempotency;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * A change made once per answer, against the real `pramnos.llm_tool_idempotency` on MySQL and
 * PostgreSQL: the same write called twice runs once; a named answer run again does not repeat it;
 * a failure gives the key back; a key past its day is taken again.
 */
#[CoversClass(Idempotency::class)]
class IdempotencyTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        Idempotency::reset();
    }

    protected function tearDown(): void
    {
        Idempotency::reset();
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A tool that writes, counting its runs; `fail` makes it throw. */
    private function writer(int &$runs, bool &$fail): array
    {
        return ['send' => ['description' => 'Sends', 'parameters' => [], 'writes' => true, 'run' => static function (array $args) use (&$runs, &$fail): string {
            $runs++;
            if ($fail) {
                throw new \RuntimeException('mail server down');
            }

            return 'sent to ' . $args['to'];
        }]];
    }

    /**
     * Called twice in one answer with the same arguments — in another order — the write runs once,
     * and the model is told the second time that it was done and what it returned. Other
     * arguments are another change.
     *
     * A model that loses track of what it did, or is corrected and does it again, must not send
     * the same email twice.
     */
    #[DataProvider('databases')]
    public function testTheSameChangeIsMadeOncePerAnswer(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([
            [200, self::calls([['send', ['to' => 'a@x.test', 'cc' => 'b@x.test']]])],
            [200, self::calls([['send', ['cc' => 'b@x.test', 'to' => 'a@x.test']], ['send', ['to' => 'c@x.test']]])],
            [200, self::says('Sent')],
        ]);
        [$runs, $fail] = [0, false];

        // Act
        $answer = (new Client(null, self::memoryLog()))->askWithTools('', [['role' => 'user', 'content' => 'Send it']], $this->writer($runs, $fail), 4);

        // Assert
        $this->assertTrue($answer['ok']);
        $this->assertSame(2, $runs, 'a@ once, c@ once');
        $results = array_values(array_filter($this->sent[2]['body']['messages'], static fn (array $m): bool => $m['role'] === 'tool'));
        $this->assertSame(sprintf(Idempotency::DONE, 'sent to a@x.test'), $results[1]['content']);
        $this->assertSame('sent to c@x.test', $results[2]['content']);
    }

    /**
     * A named answer — a queued question — run again does not make the change again; an answer
     * named otherwise does.
     */
    #[DataProvider('databases')]
    public function testANamedAnswerRunAgainDoesNotRepeatIt(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::calls([['send', ['to' => 'a@x.test']]])], [200, self::says('Sent')]]);
        [$runs, $fail] = [0, false];
        $tools = $this->writer($runs, $fail);
        $ask   = static fn (string $key) => (new Client(null, self::memoryLog()))->answering($key)
            ->askWithTools('', [['role' => 'user', 'content' => 'Send it']], $tools, 3);

        // Act
        $ask('ask:1');
        $this->answer([[200, self::calls([['send', ['to' => 'a@x.test']]])], [200, self::says('Sent')]]);
        $ask('ask:1');
        $this->answer([[200, self::calls([['send', ['to' => 'a@x.test']]])], [200, self::says('Sent')]]);
        $ask('ask:2');

        // Assert
        $this->assertSame(2, $runs, 'once for ask 1, once for ask 2');
    }

    /**
     * A change that failed gives its key back, so it can be tried again; a key being run elsewhere
     * says so; and a key past its day is taken again.
     */
    #[DataProvider('databases')]
    public function testAFailureOrAnExpiredKeyCanBeTriedAgain(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $key  = Idempotency::key('answer', 'send', ['to' => 'a@x.test']);
        $runs = 0;

        // Act — fails, then succeeds; another run holds a second key; a third key is a day old
        $failed = Idempotency::once($key, 'send', static function () use (&$runs): string {
            $runs++;

            return 'Error: mail server down';
        });
        $sent = Idempotency::once($key, 'send', static function () use (&$runs): string {
            $runs++;

            return 'sent';
        });
        $this->db->queryBuilder()->table(Idempotency::TABLE)->insert([
            'idempotency_key' => 'busy', 'tool' => 'send', 'created_at' => CallLog::instant(time()), 'expires_at' => CallLog::instant(time() + 60),
        ]);
        $busy = Idempotency::once('busy', 'send', static fn (): string => 'never');
        $this->db->queryBuilder()->table(Idempotency::TABLE)->insert([
            'idempotency_key' => 'old', 'tool' => 'send', 'result' => 'sent long ago', 'created_at' => CallLog::instant(time() - 90000), 'expires_at' => CallLog::instant(time() - 3600),
        ]);
        $again = Idempotency::once('old', 'send', static fn (): string => 'sent today');

        // Assert
        $this->assertSame(['Error: mail server down', 'sent', 2], [$failed, $sent, $runs], 'the failure gave the key back');
        $this->assertSame(Idempotency::BUSY, $busy);
        $this->assertSame('sent today', $again, 'a key past its day is taken again');
        $this->assertSame(Idempotency::key('a', 't', ['x' => 1, 'y' => [2, 3]]), Idempotency::key('a', 't', ['y' => [2, 3], 'x' => 1]));
        $this->assertNotSame(Idempotency::key('a', 't', ['y' => [2, 3]]), Idempotency::key('a', 't', ['y' => [3, 2]]), 'a list keeps its order');
    }

    /** Without the table every call runs, as before the migration. */
    #[DataProvider('databases')]
    public function testWithoutTheTableEveryCallRuns(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->db->schema()->dropTableIfExists(Idempotency::TABLE);
        $this->llmSchemaChanged();
        Idempotency::reset();
        $runs = 0;
        $run  = static function () use (&$runs): string {
            $runs++;

            return 'sent';
        };

        // Act
        Idempotency::once('k', 'send', $run);
        Idempotency::once('k', 'send', $run);

        // Assert
        $this->assertSame(2, $runs);
    }

    /**
     * A database that cannot say whether the table is there is treated as one without it: the
     * change runs, rather than the answer failing over its bookkeeping.
     */
    public function testADatabaseThatCannotAnswerRunsTheChange(): void
    {
        // Arrange — a connection whose schema throws
        $db = $this->getMockBuilder(\Pramnos\Database\Database::class)->disableOriginalConstructor()->getMock();
        $db->type = 'mysql';
        $db->method('schema')->willThrowException(new \RuntimeException('gone'));
        $previous  = \Pramnos\Framework\Factory::getDatabase();
        $singleton = &\Pramnos\Framework\Factory::getDatabase();
        $singleton = $db;

        try {
            // Act
            $result = Idempotency::once('k', 'send', static fn (): string => 'sent');
        } finally {
            $singleton = &\Pramnos\Framework\Factory::getDatabase();
            $singleton = $previous;
        }

        // Assert
        $this->assertSame('sent', $result);
    }
}
