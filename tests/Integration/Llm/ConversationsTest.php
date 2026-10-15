<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Memory;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Turn;
use Pramnos\Llm\Connection;
use Pramnos\Llm\Llm;
use Pramnos\Llm\Policy;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * Conversations, on MySQL and PostgreSQL, against a faked provider.
 *
 * What has to hold: a turn is stored question and answer together, with the tools and tags it
 * used; an assistant's hooks are called with what they need; and one person's conversations,
 * memories and answers never show to another — nor a row without an organisation inside one.
 */
#[CoversClass(Conversations::class)]
#[CoversClass(Memory::class)]
#[CoversClass(Scope::class)]
#[CoversClass(Turn::class)]
#[CoversClass(BaseAssistant::class)]
class ConversationsTest extends LlmTestCase
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

    /** Conversations with a recording assistant, as a scope. */
    private function chat(Scope $scope, ?BaseAssistant $assistant = null, bool $scheduled = false): Conversations
    {
        return (new Conversations($assistant ?? new RecordingAssistant()))->as($scope, $scheduled);
    }

    /**
     * A first question makes a conversation of two messages, in the asker's scope, with the
     * tools the answer called and the tags its data carried; the assistant is asked for its
     * prompt, context, tools and question, and decorates the stored answer.
     */
    #[DataProvider('databases')]
    public function testAFirstQuestionMakesAConversation(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([
            [200, self::calls([['lookup', []]])],
            [200, self::says('Answer ⟦Tools that wrote in this answer: lookup ⟧')],
        ]);
        Llm::use('rules', [new AcceptsEverything()]);
        $assistant = new RecordingAssistant();
        $chat      = $this->chat(new Scope(7, 3), $assistant);

        // Act
        $answer = $chat->ask(null, '  How did   it go?  ', null, ['draft' => 9]);

        // Assert — the answer, stored without the note it copied
        $this->assertTrue($answer['ok'], $answer['says']);
        $this->assertSame('Answer', $answer['message']['content']);
        $this->assertSame('decorated', $answer['message']['by_app']);
        $this->assertSame([['name' => 'lookup', 'arguments' => []]], $answer['message']['tools']);
        $conversations = $chat->list();
        $this->assertSame('How did it go?', $conversations[0]['title']);
        $stored = $chat->find($answer['conversation_id']);
        $this->assertSame(['user', 'assistant'], array_column($stored['messages'], 'role'));
        $this->assertSame(['google'], $stored['tags'], 'the tags the answer carried');
        $this->assertSame('presented', $stored['messages'][1]['by_app']);
        $row = $this->rows('chat_conversations')[0];
        $this->assertSame([7, 3], [(int) $row['userid'], (int) $row['organization_id']]);

        // The hooks saw the turn
        $this->assertSame(['draft' => 9], $assistant->turns[0]->extra);
        $this->assertSame((int) $answer['conversation_id'], $assistant->answeredIn);
        $this->assertStringStartsWith('Asked: ', end($this->sent[0]['body']['messages'])['content'], 'question() rewrote it');
        $this->assertStringContainsString('SYSTEM' . "\n\n" . 'CONTEXT', $this->sent[0]['body']['messages'][0]['content']);
        $this->assertStringContainsString('You remember nothing about this person yet.', $this->sent[0]['body']['messages'][0]['content']);
        $names = array_column(array_column($this->sent[0]['body']['tools'], 'function'), 'name');
        $this->assertContains('lookup', $names);
        $this->assertContains('remember', $names, 'the memory tools are always there');
        $this->assertContains('schedule_task', $names, 'and the schedule tools for a person asking');
    }

    /**
     * What question() keeps in the turn's bag reaches the tools and answered(), and the schedule
     * tool is told the assistant's clock.
     */
    #[DataProvider('databases')]
    public function testTheBagAndTheClockReachTheAnswer(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([
            [200, self::calls([['schedule_task', ['title' => 'Daily', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']]])],
            [200, self::says('done')],
        ]);
        $assistant = new BaggingAssistant();

        // Act
        $answer = $this->chat(new Scope(7, null), $assistant)->ask(null, 'Every morning');

        // Assert
        $this->assertSame(['in tools' => 'draft 9', 'in answered' => 'draft 9'], $assistant->seen);
        $this->assertSame('Pacific/Auckland', $this->rows('ai_routines')[0]['timezone']);
        $this->assertSame('draft 9', $answer['message']['bagged']);
    }

    /**
     * A continued conversation sends the history back, an earlier answer's writes noted after
     * it, and keeps the tags it held; a scheduled run is told so and gets no schedule tools.
     */
    #[DataProvider('databases')]
    public function testAConversationContinues(string $type, string $host, int $port): void
    {
        // Arrange — a first turn whose answer wrote with `remember`
        $this->connectDatabase($type, $host, $port);
        $this->answer([
            [200, self::calls([['remember', ['kind' => 'fact', 'content' => 'Likes tables']]])],
            [200, self::says('Noted.')],
        ]);
        $scope = new Scope(7, null);
        $first = $this->chat($scope)->ask(null, 'Remember I like tables');

        // Act — a scheduled follow-up
        $this->sent = [];
        $this->answer([[200, self::says('Here is a table.')]]);
        $assistant = new RecordingAssistant();
        $second    = $this->chat($scope, $assistant, true)->ask($first['conversation_id'], 'And now?');

        // Assert
        $this->assertTrue($second['ok']);
        $sent = $this->sent[0]['body'];
        $history = array_column(array_slice($sent['messages'], 1), 'content');
        $this->assertSame('Remember I like tables', $history[0]);
        $this->assertSame("Noted.\n\n" . Conversations::MARK . 'remember ⟧', $history[1]);
        $this->assertTrue($assistant->turns[0]->scheduled);
        $this->assertNotContains('schedule_task', array_column(array_column($sent['tools'], 'function'), 'name'));
        $this->assertStringContainsString('Likes tables', $sent['messages'][0]['content'], 'the memory is in the context');
        $this->assertCount(4, $this->chat($scope)->find($first['conversation_id'])['messages']);
    }

    /**
     * What the model said while it called tools is kept apart from the answer: the message's
     * content is the answer alone, the steps are in `extra`, and the next question's history
     * carries the answer without them. A Greek question was answered after ten lines of
     * "Let me check…" in English, copied and replayed with it.
     */
    #[DataProvider('databases')]
    public function testStepsAreKeptApartFromTheAnswer(string $type, string $host, int $port): void
    {
        // Arrange — a round that says something and calls a tool, then the answer
        $this->connectDatabase($type, $host, $port);
        $this->answer([
            [200, self::calls([['remember', ['kind' => 'fact', 'content' => 'x']]], ['content' => 'Let me check your notes.'])],
            [200, self::says('Έχετε μία σημείωση.')],
        ]);
        $scope = new Scope(7, null);

        // Act
        $first   = $this->chat($scope)->ask(null, 'Τι σημειώσεις έχω;');
        $stored  = $this->chat($scope)->find($first['conversation_id'])['messages'][1];
        $this->sent = [];
        $this->answer([[200, self::says('Τίποτε άλλο.')]]);
        $this->chat($scope)->ask($first['conversation_id'], 'Κάτι άλλο;');
        $history = array_column(array_slice($this->sent[0]['body']['messages'], 1), 'content');

        // Assert
        $this->assertStringStartsWith('Έχετε μία σημείωση.', $stored['content'], 'the answer alone');
        $this->assertStringNotContainsString('Let me check', $stored['content']);
        $this->assertSame(['Let me check your notes.'], $stored['extra']['steps']);
        $this->assertStringNotContainsString('Let me check', $history[1], 'not replayed with the answer');
    }

    /**
     * One person's conversations never show to another, nor one organisation's in another, nor
     * a row without an organisation inside one — and deleting is only of one's own.
     */
    #[DataProvider('databases')]
    public function testScopesNeverMeet(string $type, string $host, int $port): void
    {
        // Arrange — the same person with no organisation, in organisation 3, and another person
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('ok')]]);
        $alone = $this->chat(new Scope(7, null))->ask(null, 'alone')['conversation_id'];
        $inOrg = $this->chat(new Scope(7, 3))->ask(null, 'in org')['conversation_id'];
        $other = $this->chat(new Scope(8, 3))->ask(null, 'other')['conversation_id'];

        // Act / Assert
        $this->assertSame(['alone'], array_column($this->chat(new Scope(7, null))->list(), 'title'));
        $this->assertSame(['in org'], array_column($this->chat(new Scope(7, 3))->list(), 'title'), 'no null-org row inside an organisation');
        $this->assertSame([], $this->chat(new Scope(7, 4))->list());
        $this->assertNull($this->chat(new Scope(7, 3))->find($alone));
        $this->assertNull($this->chat(new Scope(7, 3))->find($other));
        $this->assertFalse($this->chat(new Scope(7, 3))->delete($other));
        $this->assertSame('missing', $this->chat(new Scope(7, 3))->ask($other, 'hijack')['why']);
        $this->assertTrue($this->chat(new Scope(7, 3))->delete($inOrg));
        $this->assertSame([], $this->chat(new Scope(7, 3))->list());
        $this->assertCount(4, $this->rows('chat_messages'), 'the deleted conversation\'s messages went with it');
        $this->assertSame([], (new Conversations(new RecordingAssistant()))->list(), 'no scope, nothing');
    }

    /**
     * A conversation holding data of a tag is asked only where that data may go, or refused
     * with the assistant's sentence.
     */
    #[DataProvider('databases')]
    public function testHeldDataDecidesWhereItIsAsked(string $type, string $host, int $port): void
    {
        // Arrange — a conversation that took `google` data
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('ok')]]);
        Llm::use('rules', [new AcceptsEverything()]);
        $scope = new Scope(7, 3);
        $id    = $this->chat($scope)->ask(null, 'q', null, [])['conversation_id'];
        $this->db->queryBuilder()->table('chat_conversations')->where('conversation_id', $id)->update(['tags' => '["google"]']);

        // Act — a rule that accepts nothing and offers nowhere
        Llm::use('rules', [new AcceptsNothing()]);
        $refused = $this->chat($scope)->ask($id, 'again');

        // Assert
        $this->assertSame('tags', $refused['why']);
        $this->assertSame('Held: google', $refused['says']);
    }

    /**
     * Each refusal names itself: nobody, nothing asked, a provider error, consent, limits.
     */
    #[DataProvider('databases')]
    public function testRefusalsNameThemselves(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $scope = new Scope(7, null);

        // Act / Assert
        $this->assertSame('scope', (new Conversations(new RecordingAssistant()))->ask(null, 'x')['why']);
        $this->assertSame('empty', $this->chat($scope)->ask(null, '   ')['why']);
        $this->answer([[500, ['error' => ['message' => 'down']]]]);
        $this->assertSame('provider', $this->chat($scope)->ask(null, 'x')['why']);
        Llm::use('consent', new class implements Policy\Consent {
            public function refusal(array $context, string $provider): ?string
            {
                return 'No consent.';
            }
        });
        $this->assertSame('consent', $this->chat($scope)->ask(null, 'x')['why']);
        Llm::reset();
        Llm::use('limits', new class implements Policy\Limits {
            public function refusal(array $context, Connection $connection): ?string
            {
                return 'Over.';
            }

            public function record(array $context, Connection $connection, array $usage, ?float $cost): void
            {
            }
        });
        $this->assertSame('limit', $this->chat($scope)->ask(null, 'x')['why']);
        $this->assertSame([], $this->rows('chat_conversations'), 'nothing is stored for a refusal');
    }

    /**
     * An attached document travels with the question, a document alone is a question, and the
     * assistant sees the conversation's documents by name.
     */
    #[DataProvider('databases')]
    public function testAnAttachmentIsPartOfTheQuestion(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('read it')]]);
        $assistant = new RecordingAssistant();

        // Act
        $answer = $this->chat(new Scope(7, null), $assistant)->ask(null, '', ['name' => '/tmp/x/release.docx', 'text' => 'The words']);

        // Assert
        $this->assertTrue($answer['ok']);
        $this->assertSame(['release.docx' => 'The words'], $assistant->turns[0]->documents);
        $this->assertStringEndsWith("---\n📎 release.docx\n\nThe words", end($this->sent[0]['body']['messages'])['content']);
    }

    /**
     * The memory: remembered, bounded, forgotten, searched across conversations in either case,
     * and read whole — the person's own only.
     */
    #[DataProvider('databases')]
    public function testTheMemory(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['memory' => ['max' => 2, 'chars' => 10]]]);
        $scope  = new Scope(7, 3);
        $memory = new Memory($scope);
        $chat   = $this->chat($scope);
        $this->answer([[200, self::says('The Sun shines')], [200, self::says('nothing here')]]);
        $first  = $chat->ask(null, 'Tell me about the SUN')['conversation_id'];
        $second = $chat->ask(null, 'something else')['conversation_id'];

        // Act / Assert — remembered, cut to its length, refused when full
        $this->assertStringStartsWith('Remembered as memory ', $memory->remember('opinion', "  a   very long sentence  "));
        $this->assertSame('Error: there is nothing to remember.', $memory->remember('fact', '  '));
        $memory->remember('preference', 'second');
        $this->assertStringStartsWith('Error: the memory is full (2 entries)', $memory->remember('fact', 'third'));
        $all = $memory->all();
        $this->assertSame([['fact', 'a very lon'], ['preference', 'second']], array_map(static fn (array $m): array => [$m['kind'], $m['content']], $all));
        $this->assertSame([], (new Memory(new Scope(7, null)))->all(), 'another scope remembers nothing');
        $this->assertFalse((new Memory(new Scope(8, 3)))->forget($all[0]['memory_id']));
        $this->assertTrue($memory->forget($all[0]['memory_id']));

        // Searched in other conversations, whatever the case, never the current one
        $hits = $memory->search($chat, 'sun', 90, $second);
        $this->assertSame([$first, $first], array_column($hits, 'conversation_id'));
        $this->assertSame([], $memory->search($chat, 'sun', 90, $first), 'the conversation being asked in is left out');
        $this->assertSame([], $memory->search($chat, 'a b'), 'words under three letters are not searched');

        // Its tools
        $tools = $memory->tools($chat, $second);
        $this->assertSame('Forgotten.', ($tools['forget']['run'])(['memory_id' => $all[1]['memory_id']]));
        $this->assertSame('Error: there is no such memory.', ($tools['forget']['run'])(['memory_id' => 999]));
        $this->assertStringStartsWith('Remembered', ($tools['remember']['run'])(['kind' => 'fact', 'content' => 'x']));
        $this->assertStringContainsString('"conversation_id":' . $first, ($tools['search_conversations']['run'])(['query' => 'Sun']));
        $this->assertStringContainsString('Tell me about the SUN', ($tools['read_conversation']['run'])(['conversation_id' => $first]));
        $this->assertSame('Error: there is no such conversation.', ($tools['read_conversation']['run'])(['conversation_id' => 999]));
    }

    /**
     * A long message is searched for a snippet around the word; without the table, the memory is
     * simply empty.
     */
    #[DataProvider('databases')]
    public function testASnippetAroundTheWordAndNoTable(string $type, string $host, int $port): void
    {
        // Arrange — a long answer with the word deep inside it
        $this->connectDatabase($type, $host, $port);
        $scope = new Scope(7, null);
        $chat  = $this->chat($scope);
        $this->answer([[200, self::says(str_repeat('filler ', 200) . 'the needle sits here ' . str_repeat('more ', 200))], [200, self::says('x')]]);
        $chat->ask(null, 'first');
        $other = $chat->ask(null, 'second')['conversation_id'];

        // Act
        $hit = (new Memory($scope))->search($chat, 'needle', 90, $other)[0]['said'];

        // Assert
        $this->assertStringStartsWith('…', $hit);
        $this->assertStringEndsWith('…', $hit);
        $this->assertStringContainsString('the needle sits here', $hit);
        $this->db->schema()->dropTableIfExists('chat_memory');
        $this->assertSame([], (new Memory($scope))->all());
    }

    /**
     * The limits the screen shows, and the base assistant's defaults.
     */
    #[DataProvider('databases')]
    public function testLimitsAndDefaults(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $chat = (new Conversations(new BaseAssistant()))->as(new Scope(7, null));

        // Act
        $limits = $chat->limits();

        // Assert
        $this->assertSame(['OpenAI', 16, 30, Conversations::CHARS], [$limits['provider'], $limits['rounds'], $limits['turns'], $limits['question_chars']]);
        $this->assertInstanceOf(Memory::class, $chat->memory());
        $this->assertNull((new Conversations(new BaseAssistant()))->memory());
        $base = new BaseAssistant();
        $this->assertStringContainsString('Start a new conversation', $base->heldRefusal('nothing-governs-this'));
        $this->assertNull($base->routineRefusal(new Scope(1)));
        $this->assertStringEndsWith('chat?c=5', $base->conversationUrl(5));
        $this->assertSame(42, $base->acting(new Scope(1), static fn (): int => 42));
    }
}

/** An assistant that records what it was asked, and marks what it decorated. */
final class RecordingAssistant extends BaseAssistant
{
    /** @var list<Turn> */
    public array $turns = [];

    public ?int $answeredIn = null;

    public function systemPrompt(Turn $turn): string
    {
        $this->turns[] = $turn;

        return 'SYSTEM';
    }

    public function context(Turn $turn): string
    {
        return 'CONTEXT';
    }

    public function tools(Turn $turn): array
    {
        return ['lookup' => ['description' => 'Look up', 'parameters' => [], 'run' => static fn (): string => 'found',
            'tags' => ['google' => true]]];
    }

    public function writes(): array
    {
        return [...parent::writes(), 'lookup'];
    }

    public function question(Turn $turn, string $question): string
    {
        return $turn->extra === [] ? $question : 'Asked: ' . $question;
    }

    public function answered(Turn $turn, array $message): array
    {
        $this->answeredIn = $turn->conversationId;

        return $message + ['by_app' => 'decorated'];
    }

    public function present(Scope $scope, array $messages): array
    {
        return array_map(static fn (array $m): array => $m + ['by_app' => 'presented'], $messages);
    }

    public function heldRefusal(string $tag): string
    {
        return 'Held: ' . $tag;
    }
}

/** A rule for `google` that takes any connection. */
final class AcceptsEverything implements Policy\DataRule
{
    public function tag(): string
    {
        return 'google';
    }

    public function accepts(Connection $connection): bool
    {
        return true;
    }

    public function alternative(array $context, Connection $current): ?Connection
    {
        return null;
    }

    public function refusalResult(): string
    {
        return 'no';
    }
}

/** A rule for `google` that takes nothing and offers nowhere. */
final class AcceptsNothing implements Policy\DataRule
{
    public function tag(): string
    {
        return 'google';
    }

    public function accepts(Connection $connection): bool
    {
        return false;
    }

    public function alternative(array $context, Connection $current): ?Connection
    {
        return null;
    }

    public function refusalResult(): string
    {
        return 'no';
    }
}

/** An assistant that keeps something in the bag at question() and reads it later, on its own clock. */
final class BaggingAssistant extends BaseAssistant
{
    /** @var array<string, mixed> */
    public array $seen = [];

    public function question(Turn $turn, string $question): string
    {
        $turn->bag['draft'] = 'draft 9';

        return $question;
    }

    public function tools(Turn $turn): array
    {
        $this->seen['in tools'] = $turn->bag['draft'] ?? null;

        return [];
    }

    public function answered(Turn $turn, array $message): array
    {
        $this->seen['in answered'] = $turn->bag['draft'] ?? null;

        return $message + ['bagged' => $turn->bag['draft'] ?? null];
    }

    public function timezone(Scope $scope): string
    {
        return 'Pacific/Auckland';
    }
}
