<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Acts;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * What the assistant does and wears in an answer, and the person's style (FW-430), on MySQL and
 * PostgreSQL.
 *
 * What has to hold: the model is offered the set's acts and wear with when each fits, in the
 * conversation page only; the tags it ends with never reach the stream or the stored answer; the
 * answer keeps `act` and `wear` — null when it chose none or a word the set lacks — on the message
 * and in the history; and a person's style is a pose of the set's, kept with their account and
 * read and set through `{chat}/style`.
 */
#[CoversClass(Acts::class)]
#[CoversClass(Conversations::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
class ActsTest extends LlmTestCase
{
    use ChatDatabase;

    /** Users this test made, removed on tear-down. */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['analyst' => ['avatar_set' => ['markup' => '<svg></svg>', 'poses' => ['headphones', 'party', 'nightcap'],
            'acts' => ['explain' => ['plays' => 'teaching', 'when' => 'explaining something'], 'Wave' => 'waving', 'bad word!' => 'x'],
            'wear' => ['party' => ['pose' => 'party', 'when' => 'celebrating good news']]]]]);
    }

    protected function tearDown(): void
    {
        foreach ($this->users as $id) {
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $id)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $id)->delete();
        }
        ApiFixture::$scope = null;
        ApiFixture::$input = [];
        unset($_SERVER['REQUEST_METHOD']);
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** The tail of what was sent: the line just before the question. */
    private static function tail(array $sent): string
    {
        return (string) $sent['body']['messages'][count($sent['body']['messages']) - 2]['content'];
    }

    /**
     * The vocabulary is the set's — a bare value is what the word plays, a word that is not one is
     * dropped — the last tag wins, a word the set lacks is none, and stripping takes the tags and
     * one still being written. Off without acts or wear, and outside the conversation page.
     */
    public function testTheTagsAndTheVocabulary(): void
    {
        // Act
        $acts = Acts::acts();

        // Assert
        $this->assertSame(['explain' => ['plays' => 'teaching', 'when' => 'explaining something'], 'wave' => ['plays' => 'waving', 'when' => '']], $acts);
        $this->assertSame(['act' => 'wave', 'plays' => 'waving'], Acts::act("Hi [[act:explain]]\n[[ act : Wave ]]"), 'the last tag');
        $this->assertNull(Acts::act('Hi [[act:dance]]'), 'not the set\'s');
        $this->assertSame(['wear' => 'party', 'pose' => 'party'], Acts::worn('Yay [[wear:party]]'));
        $this->assertNull(Acts::worn('Yay'));
        $this->assertSame('Hi there', Acts::strip("Hi there\n[[act:explain]]\n[[wear:party]]"));
        $this->assertSame('Hi there', Acts::strip('Hi there [[wea'), 'a tag still streaming');
        $this->assertStringContainsString('explain (explaining something); wave', Acts::instruction());
        $this->assertStringContainsString('[[wear:WORD]], one of: party (celebrating good news)', Acts::instruction());
        $this->assertTrue(Acts::on(new Scope(1, null)));
        $this->assertFalse(Acts::on(new Scope(1, null, Scope::ADMIN)), 'the conversation page only');
        $this->configure(['analyst' => ['avatar_set' => ['markup' => '<svg></svg>']]]);
        $this->assertFalse(Acts::on());
        $this->assertSame('', Acts::instruction());
    }

    /**
     * Asked: the model is offered the acts and wear; the tags — with a mood's beside them — are
     * out of the stream and the stored answer; the message and the history keep what it did and
     * wore, and null for an answer that chose neither.
     */
    #[DataProvider('databases')]
    public function testAnAnswerKeepsWhatItDidAndWore(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $person   = random_int(100000, 999999);
        $streamed = [];
        $this->answer([[200, self::says("Here is how it works.\n[[act:explain]]\n[[wear:party]]")], [200, self::says('Fine.')]]);
        $chat = (new Conversations(new BaseAssistant()))->as(new Scope($person, null))->streaming(function (string $soFar) use (&$streamed): void {
            $streamed[] = $soFar;
        });

        // Act
        $first  = $chat->ask(null, 'How does it work?');
        $second = $chat->ask((int) $first['conversation_id'], 'Ok');
        $stored = $this->db->queryBuilder()->table('assist.chat_messages')->where('conversation_id', (int) $first['conversation_id'])
            ->where('role', 'assistant')->orderBy('message_id')->first()->fields['content'];
        $history = array_values(array_filter($chat->find((int) $first['conversation_id'])['messages'], static fn (array $m): bool => $m['role'] === 'assistant'));

        // Assert
        $this->assertStringContainsString('[[act:WORD]]', self::tail($this->sent[0]), 'offered in the last part');
        $this->assertSame('Here is how it works.', $first['message']['content']);
        $this->assertSame('Here is how it works.', $stored, 'never stored');
        foreach ($streamed as $piece) {
            $this->assertStringNotContainsString('[[', $piece, 'never streamed');
        }
        $this->assertSame(['act' => 'explain', 'plays' => 'teaching'], $first['message']['act']);
        $this->assertSame(['wear' => 'party', 'pose' => 'party'], $first['message']['wear']);
        $this->assertArrayHasKey('act', $second['message'], 'chose none: the key, null');
        $this->assertNull($second['message']['act']);
        $this->assertNull($second['message']['wear']);
        $this->assertSame(['teaching', null], array_map(static fn (array $m): ?string => $m['act']['plays'] ?? null, $history), 'in the history too');
        $this->db->queryBuilder()->table('assist.chat_conversations')->where('userid', $person)->delete();
    }

    /**
     * The person's style: none at first; a pose of the set's is kept with their account and read
     * back; one the set lacks is refused with 422 and keeps the old one; '' clears it. Nobody
     * signed in is 401.
     */
    #[DataProvider('databases')]
    public function testThePersonsStyle(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $user = new \Pramnos\User\User();
        $user->username = 'style' . random_int(100000, 999999);
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->users[] = (int) $user->userid;
        ApiFixture::$scope = new Scope((int) $user->userid, null);
        ApiFixture::$stop  = null;

        // Act
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $before = (new ApiFixture())->style();
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        ApiFixture::$input = ['pose' => 'headphones'];
        $set = (new ApiFixture())->style();
        ApiFixture::$input = ['pose' => 'tiara'];
        $refused = (new ApiFixture())->style();
        $kept = Acts::style(new \Pramnos\User\User((int) $user->userid));
        ApiFixture::$input = ['pose' => ''];
        $cleared = (new ApiFixture())->style();
        ApiFixture::$scope = null;
        $nobody = (new ApiFixture())->style();

        // Assert
        $this->assertNull($before['data']['pose']);
        $this->assertSame(['headphones', 'party', 'nightcap'], $before['data']['poses']);
        $this->assertSame('headphones', $set['data']['pose']);
        $this->assertSame(422, $refused['status']);
        $this->assertSame('headphones', $kept, 'kept with the account, the refused one not');
        $this->assertNull($cleared['data']['pose']);
        $this->assertSame(401, $nobody['status']);
    }
}
