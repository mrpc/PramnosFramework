<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Turn;
use Pramnos\Llm\Client;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * A follow-up held to the figures of the answer before it, on MySQL and PostgreSQL.
 *
 * What went wrong: a report answered, a number nothing in it made ("41") was said, and three
 * follow-ups — the person repeating it — were answered from that number without a tool, so no
 * check saw them. What has to hold now: what a numeric tool returned is kept on the answer; the
 * next answer that calls none is held to it once it gives one of its figures; a number the person
 * brings that the figures do not bear out is pointed out before the model answers.
 */
#[CoversClass(Conversations::class)]
#[CoversClass(Client::class)]
class FiguresFollowUpTest extends LlmTestCase
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
     * The report's figures kept on its answer; a follow-up that invents a number beside one of
     * them answered again; the person's "41" pointed out; small talk with a number left alone.
     */
    #[DataProvider('databases')]
    public function testAFollowUpIsHeldToTheFiguresBeforeIt(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $this->answer([
            [200, self::calls([['retention', ['from' => '2026-11-01']]])],
            [200, self::says('84 ανανεώσεις και 107 checkout απέτυχαν.')],
            [200, self::says('Από τις 84 ανανεώσεις, οι 41 ήταν κάρτα.')],          // follow-up: 41 invented
            [200, self::says('Από τις 84 ανανεώσεις, οι 75 ήταν κάρτα.')],          // written again
            [200, self::says('Το 41 δεν βγαίνει από τα στοιχεία: 75 ήταν κάρτα.')], // the person's 41
            [200, self::says('Τα λέμε στις 5!')],                                     // small talk
        ]);
        $assistant = new class extends BaseAssistant {
            /** A reporting tool: figures, as the incident's. */
            public function tools(Turn $turn): array
            {
                return ['retention' => ['description' => 'Failed payments', 'parameters' => [], 'numeric' => true,
                    'run' => static fn (): string => '{"renewals":[75,8,1],"checkouts":[75,29,2,1]}']];
            }
        };
        $chat = (new Conversations($assistant))->as(new Scope($person, null));

        // Act
        $first   = $chat->ask(null, 'Πόσοι απέτυχαν να πληρώσουν τον Νοέμβριο;');
        $id      = (int) $first['conversation_id'];
        $second  = $chat->ask($id, 'Και πόσες ήταν κάρτα;');
        $third   = $chat->ask($id, 'Από τις 41, πόσες ήταν κάρτα;');
        $small   = $chat->ask($id, 'Ευχαριστώ!');
        $stored  = $this->db->queryBuilder()->table('assist.chat_messages')->where('message_id', (int) $second['message']['message_id'])->getAllOrFail()[0];
        $kept    = $this->db->queryBuilder()->table('assist.chat_messages')->where('message_id', (int) $first['message']['message_id'])->getAllOrFail()[0];

        // Assert
        $this->assertStringContainsString('figures_read', (string) $kept['extra'], 'the report kept on its answer');
        $this->assertSame('Από τις 84 ανανεώσεις, οι 75 ήταν κάρτα.', $second['message']['content'], 'no tool, still held to the figures before it');
        $this->assertSame(sprintf(Client::FIGURES, '41'), end($this->sent[3]['body']['messages'])['content']);
        $this->assertStringContainsString('"corrected":"figures"', (string) $stored['extra']);
        $this->assertStringContainsString(sprintf(Client::DOUBTED, '41'), json_encode($this->sent[4]['body']['messages'], JSON_UNESCAPED_UNICODE) ?: '', 'the person\'s 41 pointed out before the answer');
        $this->assertSame('Το 41 δεν βγαίνει από τα στοιχεία: 75 ήταν κάρτα.', $third['message']['content']);
        $this->assertSame('Τα λέμε στις 5!', $small['message']['content'], 'no figure in it: not a follow-up about them');
        $this->assertCount(6, $this->sent, 'nothing asked again beyond the one correction');
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }
}
