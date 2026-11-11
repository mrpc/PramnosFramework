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
        ApiFixture::$input = [];
        ApiFixture::$queue = null;
        Mood::senseWith(null);
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
     * Sensed before the answer: off without a sensor; a word, a pair or a map from the sensor is
     * the mood with what it plays — a bare word strong — while a word outside the vocabulary, an
     * empty message, the analyst's space or a sensor that throws is none.
     */
    public function testAMoodSensedFromTheMessage(): void
    {
        // Arrange
        $chat  = new Scope(7, null);
        $admin = new Scope(7, null, Scope::ADMIN);
        $off   = Mood::sense($chat, 'I got the job!!');

        // Act
        Mood::senseWith(static fn (string $m): string|array|null => match (true) {
            str_contains($m, '!!') => 'happy', str_contains($m, 'zz') => ['sleepy', 30],
            str_contains($m, 'hmm') => ['mood' => 'thinking', 'intensity' => 500], str_contains($m, 'boom') => throw new \RuntimeException('down'),
            default => 'furious'});

        // Assert
        $this->assertNull($off, 'no sensor, nothing sensed');
        $this->assertFalse(Mood::senses($admin), 'the analyst\'s space');
        $this->assertSame(['mood' => 'happy', 'intensity' => Mood::STRONG, 'plays' => 'success'], Mood::sense($chat, 'I got the job!!'));
        $this->assertSame(['mood' => 'sleepy', 'intensity' => 30, 'plays' => 'idle'], Mood::sense($chat, 'zzz'));
        $this->assertSame(100, Mood::sense($chat, 'hmm')['intensity'], 'held to 100');
        $this->assertNull(Mood::sense($chat, 'ok'), 'not in the vocabulary');
        $this->assertNull(Mood::sense($chat, '   '));
        $this->assertNull(Mood::sense($chat, 'boom'), 'a failing sensor is no mood, not a failed question');
        $this->assertNull(Mood::sense($admin, 'I got the job!!'));
    }

    /**
     * A question queued through the routes answers at once with the mood it calls for, so the
     * avatar reacts while the worker writes the answer; the key is there, null, when nothing is
     * sensed, and absent without a sensor.
     */
    #[DataProvider('databases')]
    public function testTheQueuedQuestionAnswersWithTheMoodSensed(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        ApiFixture::$scope = new Scope($person, null);
        ApiFixture::$stop  = null;
        $post = function (string $content): array {
            ApiFixture::$input = ['content' => $content];
            $router = new \Pramnos\Routing\Router(null);
            \Pramnos\Llm\Chat\Api\Routes::register($router, ApiFixture::class);

            return $router->dispatch(\Pramnos\Http\Request::create('/chat', 'POST'));
        };

        // Act
        $plain = $post('Hello');
        Mood::senseWith(static fn (string $m): ?string => str_contains($m, '!!') ? 'happy' : null);
        $glad  = $post('I got the job!!');
        $flat  = $post('Hello');

        // Assert
        $this->assertSame(202, $glad['status']);
        $this->assertArrayNotHasKey('sensed', $plain['data'], 'no sensor: no key');
        $this->assertSame(['mood' => 'happy', 'intensity' => Mood::STRONG, 'plays' => 'success'], $glad['data']['sensed']);
        $this->assertArrayHasKey('sensed', $flat['data']);
        $this->assertNull($flat['data']['sensed']);
        $this->db->queryBuilder()->table('assist.chat_asks')->where('userid', $person)->delete();
    }

    /**
     * Mood and the assistant's word about itself live in the conversation page's space unless the
     * application names others: the administrators' analyst (space `admin`) is asked for no tag and
     * keeps no `self`, while `'spaces'` can let it in.
     */
    #[DataProvider('databases')]
    public function testOnlyInTheSpacesNamed(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $chat  = new Scope(7, null);
        $admin = new Scope(7, null, Scope::ADMIN);
        $this->configure(['chat' => ['mood' => true, 'memory' => ['self' => true]], 'analyst' => ['avatar_set' => ['markup' => '<svg></svg>', 'moods' => ['happy' => 'success']]]]);
        $this->answer([[200, self::says('Figures. [[mood:happy|90]]')]]);

        // Act
        $byDefault = [Mood::on($chat), Mood::on($admin), \Pramnos\Llm\Chat\Memory::selfOn($chat), \Pramnos\Llm\Chat\Memory::selfOn($admin)];
        $answer    = (new Conversations(new BaseAssistant()))->as($admin)->ask(null, 'Sales today?');
        $this->configure(['chat' => ['mood' => ['words' => ['happy'], 'spaces' => ['chat', 'admin']], 'memory' => ['self' => ['spaces' => ['admin']]]]]);
        $named = [Mood::on($admin), \Pramnos\Llm\Chat\Memory::selfOn($chat), \Pramnos\Llm\Chat\Memory::selfOn($admin)];

        // Assert
        $this->assertSame([true, false, true, false], $byDefault, 'the conversation page only');
        $this->assertStringNotContainsString('[[mood:', self::tail($this->sent[0]), 'the analyst is not asked for a tag');
        $this->assertArrayNotHasKey('mood', $answer['message']);
        $this->assertSame([true, false, true], $named);
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('conversation_id', (int) $answer['conversation_id'])->delete();
    }

    /**
     * Asked: the model is told to end with the tag; the tag is taken out of the stored answer and
     * the stream; the conversation keeps the mood; the message carries it with what the avatar
     * plays; and the next answer is told it as tone. FW-427: the model's latest word is the
     * conversation's mood — "good night" after good news is sleepy — and each answer keeps the mood
     * it was given in, read back with the conversation's history.
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
        $this->assertSame(['mood' => 'sleepy', 'intensity' => 30, 'plays' => 'idle'], $second['message']['mood'], 'the latest word is the mood');
        // An answer that says no mood still says so: the key, null — not a backend without moods.
        $this->answer([[200, self::says('Fine.')]]);
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('conversation_id', (int) $first['conversation_id'])->update(['mood' => null]);
        $third = $chat->ask((int) $first['conversation_id'], 'And?');
        $this->assertArrayHasKey('mood', $third['message']);
        $this->assertNull($third['message']['mood']);
        $this->assertArrayHasKey('mood', $third['message']['extra']);
        $history = array_values(array_filter($chat->find((int) $first['conversation_id'])['messages'], static fn (array $m): bool => $m['role'] === 'assistant'));
        $this->assertSame(['happy', 'sleepy', null], array_map(static fn (array $m): ?string => $m['mood']['mood'] ?? null, $history), 'each answer keeps its mood, in the history; none, null');
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }

    /**
     * FW-435: a mood from another conversation is told as the past, and optional, and a model that
     * only says it back does not make it the new conversation's — or it would reach the next one in
     * turn and never fade. "I passed my exams!!" made it happy; a new conversation is told so as
     * the past, says happy back and keeps no mood of its own; a third, about a lost job, says it
     * is sleepy and keeps that. With `bleed` off, a new conversation is told nothing.
     */
    #[DataProvider('databases')]
    public function testAMoodFromElsewhereIsThePastAndIsNotSaidBack(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person = random_int(100000, 999999);
        $this->answer([[200, self::says('Congratulations! [[mood:happy|85]]')], [200, self::says('Sunny today. [[mood:happy|70]]')],
            [200, self::says('I am so sorry. [[mood:sleepy|60]]')], [200, self::says('Hello.')]]);
        $chat = (new Conversations(new BaseAssistant()))->as(new Scope($person, null));
        $rowOf = fn (int $id): array => (array) $this->db->queryBuilder()->table('assist.chat_conversations')->where('conversation_id', $id)->first()->fields;

        // Act
        $exams   = $chat->ask(null, 'I passed my exams!!');
        $weather = $chat->ask(null, 'What is the weather?');
        $job     = $chat->ask(null, 'I am angry, the nanny did not come and I lost my job');
        $this->configure(['chat' => ['mood' => ['words' => ['happy', 'sleepy'], 'bleed' => false]], 'analyst' => ['avatar_set' => ['markup' => '<svg></svg>']]]);
        $alone   = $chat->ask(null, 'Hi');

        // Assert
        $this->assertStringContainsString('Earlier, in another conversation with this person, you felt happy, noticeably. It need not carry over', self::tail($this->sent[1]));
        $this->assertStringNotContainsString('Your mood right now', self::tail($this->sent[1]), 'not told as the present');
        $this->assertStringContainsString('how THIS conversation makes you feel', self::tail($this->sent[1]));
        $this->assertNull($rowOf((int) $weather['conversation_id'])['mood'], 'said back: not kept as its own');
        $this->assertSame('sleepy', $rowOf((int) $job['conversation_id'])['mood'], 'a mood of its own is kept');
        $this->assertStringNotContainsString('you felt', self::tail($this->sent[3]), 'bleed off: nothing from elsewhere');
        $this->assertSame('happy', $rowOf((int) $exams['conversation_id'])['mood']);
        $this->assertNotNull($alone['message']);
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
        $mood->feel($ids[1], 'thinking', 20);   // its own, felt, wins over a stronger one from elsewhere
        $own = $mood->now($ids[1]);
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('conversation_id', $ids[1])->update(['mood' => null]);
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
        $this->assertSame(['mood' => 'thinking', 'intensity' => 20, 'plays' => 'thinking'], $own);
        $this->assertNull($nobody, 'nobody else\'s');
        $this->assertSame(['on' => true, 'mood' => ['mood' => 'sleepy', 'intensity' => 80, 'plays' => 'idle']], $api['data'], 'idle: the person\'s strongest');
        $this->assertNull($faded, 'three hours later, 80 is 5: not felt');
        $this->assertSame('', $directive);
        $this->assertNull($weak, 'a weak mood elsewhere does not reach it');
        $table()->where('userid', $person)->delete();
    }
}
