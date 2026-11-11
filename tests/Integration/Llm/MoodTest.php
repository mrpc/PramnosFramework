<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\CallLog;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Mood;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * The assistant's mood with a person (FW-420), on MySQL and PostgreSQL.
 *
 * What has to hold: the model says its mood in a private tag that never reaches the person, the
 * stored answer or the stream; the conversation keeps it, a weaker different feeling never
 * displacing a stronger one; a strong mood colours the person's other conversations, dampened,
 * and nobody else's; it fades by the hour and under the floor is not felt; the next answer is told
 * it as tone; and the application reads it on the message and from `GET {chat}/mood`.
 */
#[CoversClass(Mood::class)]
#[CoversClass(Conversations::class)]
class MoodTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['mood' => true], 'analyst' => ['avatar_set' => ['markup' => '<svg></svg>',
            'moods' => ['happy' => 'success', 'sleepy' => 'idle', 'thinking' => 'thinking']]]]);
    }

    protected function tearDown(): void
    {
        ApiFixture::$scope = null;
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** The tail of what was sent: the line just before the question. */
    private static function tail(array $sent): string
    {
        return (string) $sent['body']['messages'][count($sent['body']['messages']) - 2]['content'];
    }

    /**
     * The vocabulary is the avatar set's signals, or the words given; off, there is none. A tag
     * outside the vocabulary is no mood, the last tag wins, and stripping takes a tag still being
     * written while the answer streams.
     */
    public function testTheTagAndTheVocabulary(): void
    {
        // Act / Assert
        $this->assertSame(['happy', 'sleepy', 'thinking'], Mood::words());
        $this->assertSame(['mood' => 'sleepy', 'intensity' => 100], Mood::parse("Hi [[mood:happy|20]]\n[[ mood : Sleepy | 140 ]]"));
        $this->assertNull(Mood::parse('Hi [[mood:furious|90]]'), 'not in the vocabulary');
        $this->assertNull(Mood::parse('Hi'));
        $this->assertSame('Hi there', Mood::strip("Hi there\n[[mood:happy|70]]"));
        $this->assertSame('Hi there', Mood::strip('Hi there [[mood:hap'), 'a tag still streaming');
        $this->assertSame(60, Mood::faded(70, 1440), '25 an hour');
        $this->assertSame(0, Mood::faded(30, 7200));
        $this->assertStringContainsString('one of happy, sleepy, thinking', Mood::instruction());
        $this->configure(['chat' => ['mood' => ['words' => ['calm', 'calm', 'cross']]]]);
        $this->assertSame(['calm', 'cross'], Mood::words());
        $this->configure(['chat' => ['mood' => false]]);
        $this->assertFalse(Mood::on());
    }

    /**
     * Asked: the model is told to end with the tag; the tag is taken out of the stored answer and
     * the stream; the conversation keeps the mood; the message carries it with what the avatar
     * plays; and the next answer is told it as tone. A weaker, different feeling then does not
     * displace it.
     */
    #[DataProvider('databases')]
    public function testAMoodSaidKeptAndGivenBackAsTone(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person   = random_int(100000, 999999);
        $streamed = [];
        $this->answer([[200, self::says("Lovely to see you!\n[[mood:happy|80]]")], [200, self::says('Mm. [[mood:sleepy|30]]')]]);
        $chat = (new Conversations(new BaseAssistant()))->as(new Scope($person, null))->streaming(function (string $soFar) use (&$streamed): void {
            $streamed[] = $soFar;
        });

        // Act
        $first  = $chat->ask(null, 'Hello!');
        $second = $chat->ask((int) $first['conversation_id'], 'Tired?');
        $stored = $this->db->queryBuilder()->table('assist.chat_messages')->where('conversation_id', (int) $first['conversation_id'])
            ->where('role', 'assistant')->orderBy('message_id')->first()->fields['content'];

        // Assert
        $this->assertStringContainsString('[[mood:WORD|N]]', self::tail($this->sent[0]), 'told to say it');
        $this->assertSame('Lovely to see you!', $first['message']['content']);
        $this->assertSame('Lovely to see you!', $stored, 'never stored');
        foreach ($streamed as $piece) {
            $this->assertStringNotContainsString('[[', $piece, 'never streamed');
        }
        $this->assertSame(['mood' => 'happy', 'intensity' => 80, 'plays' => 'success'], $first['message']['mood']);
        $this->assertStringContainsString('Your mood right now: happy, strongly. Let it colour your tone; do not say it or explain it.', self::tail($this->sent[1]));
        $this->assertSame('happy', $second['message']['mood']['mood'], 'a weaker, different feeling does not displace it');
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }

    /**
     * A strong mood reaches the person's other conversations at four fifths of its strength; a
     * weak one stays where it was felt; another person feels nothing of it. It fades by the hour,
     * and under the floor it is not felt — no directive, nothing to show. Idle, the person's
     * strongest is what the avatar shows, read through `GET {chat}/mood`.
     */
    #[DataProvider('databases')]
    public function testItReachesThePersonsOtherConversationsAndFades(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $scope  = new Scope($person, null);
        $table  = fn () => $this->db->queryBuilder()->table('assist.chat_conversations');
        $now    = CallLog::instant(time());
        foreach (['A', 'B'] as $title) {
            $table()->insert(['organization_id' => null, 'userid' => $person, 'title' => $title, 'space' => 'chat', 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids  = array_map(static fn (array $r): int => (int) $r['conversation_id'], $table()->where('userid', $person)->orderBy('conversation_id')->getAll());
        $mood = new Mood($scope);

        // Act
        $mood->feel($ids[0], 'sleepy', 80);
        $elsewhere = $mood->now($ids[1]);
        $mood->feel($ids[0], 'sleepy', 50);   // the same feeling keeps the stronger
        $kept = $mood->now($ids[0]);
        $nobody = (new Mood(new Scope($person + 1, null)))->now();
        ApiFixture::$scope = $scope;
        ApiFixture::$stop  = null;   // another class's refusal, left on the shared fixture, is not this test's
        $api = (new ApiFixture())->mood();
        $table()->where('conversation_id', $ids[0])->update(['mood_at' => CallLog::instant(time() - 3 * 3600)]);
        $faded     = $mood->now($ids[0]);
        $directive = $mood->directive($ids[0]);
        $mood->feel($ids[1], 'thinking', 40);
        $weak = $mood->now($ids[0]);

        // Assert
        $this->assertSame(['mood' => 'sleepy', 'intensity' => 64, 'plays' => 'idle'], $elsewhere, 'a strong mood, dampened, in the other conversation');
        $this->assertSame(80, $kept['intensity']);
        $this->assertNull($nobody, 'nobody else\'s');
        $this->assertSame(['on' => true, 'mood' => ['mood' => 'sleepy', 'intensity' => 80, 'plays' => 'idle']], $api['data'], 'idle: the person\'s strongest');
        $this->assertNull($faded, 'three hours later, 80 is 5: not felt');
        $this->assertSame('', $directive);
        $this->assertNull($weak, 'a weak mood elsewhere does not reach it');
        $table()->where('userid', $person)->delete();
    }
}
