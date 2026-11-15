<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\AnswerTags;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * {@see AnswerTags} wired into a real answer, on MySQL and PostgreSQL (FW-511): a provider's own
 * tool-call protocol leaked as plain content is never shown or kept as the answer, and a
 * capability-gap phrase an application configures is tagged for review without touching what the
 * person reads.
 */
#[CoversClass(Conversations::class)]
#[CoversClass(AnswerTags::class)]
class AnswerTagsIntegrationTest extends LlmTestCase
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
     * A leaked protocol dump never reaches the person, or the stored history, as if it were the
     * answer — a safe, plain refusal instead, `extra.malformed` true for the admins to find
     * without reading every conversation by hand. An ordinary answer carries neither key.
     */
    #[DataProvider('databases')]
    public function testALeakedProtocolDumpIsSanitisedAndTagged(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $leaked = 'oops <｜｜DSML｜｜ calls>[{"name":"search","arguments":{}}]</｜｜DSML｜｜ calls>';
        $this->answer([[200, self::says($leaked)], [200, self::says('A plain answer.')]]);

        // Act
        $broken = (new Conversations(new BaseAssistant()))->as(new Scope(7, null))->ask(null, 'Hi');
        $fine   = (new Conversations(new BaseAssistant()))->as(new Scope(7, null))->ask(null, 'Hi again');

        // Assert
        $this->assertStringNotContainsString('DSML', (string) $broken['message']['content']);
        $this->assertStringNotContainsString('｜', (string) $broken['message']['content']);
        $this->assertTrue($broken['message']['extra']['malformed']);
        $this->assertArrayNotHasKey('capability_gap', $broken['message']['extra']);
        $this->assertArrayNotHasKey('malformed', $fine['message']['extra'], 'an ordinary answer carries neither key');
    }

    /**
     * An application's own `capability_gap_patterns` tag an answer that uses one of them, without
     * changing a word of what the person reads — the whole point is finding these by a query, not
     * hiding them from the person who asked.
     */
    #[DataProvider('databases')]
    public function testACapabilityGapPhraseIsTaggedWithoutChangingTheAnswer(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['capability_gap_patterns' => ['δεν έχω εργαλείο']]]);
        $said = 'Δυστυχώς δεν έχω εργαλείο για αυτό ακόμα.';
        $this->answer([[200, self::says($said)]]);

        // Act
        $answer = (new Conversations(new BaseAssistant()))->as(new Scope(7, null))->ask(null, 'Μπορείς να το κάνεις;');

        // Assert
        $this->assertSame($said, $answer['message']['content']);
        $this->assertTrue($answer['message']['extra']['capability_gap']);
        $this->assertArrayNotHasKey('malformed', $answer['message']['extra']);
    }
}
