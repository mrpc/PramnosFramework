<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\ModelChoices;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Web\ChatPageController;
use Pramnos\Llm\Policy\ModelCascade;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The models a person may choose for their Ask questions, behind a floor per choice, on MySQL and
 * PostgreSQL: DeepSeek's flash model the cheapest, OpenAI's gpt-4o dearer, a reasoning gpt-4o
 * for the highest usertype alone.
 *
 * What has to hold: a person sees and gets only what their usertype reaches; their choice is
 * checked again on every question, so one they no longer reach is Automatic; Automatic picks among
 * the choices they may have, decided on the cheapest, never above their floor; and a pick that
 * fails is answered on the cheapest, told so.
 */
#[CoversClass(ModelChoices::class)]
#[CoversClass(ModelCascade::class)]
#[CoversClass(Conversations::class)]
#[CoversClass(\Pramnos\Llm\Chat\Web\ChoosesModel::class)]
class ModelChoicesTest extends LlmTestCase
{
    use ChatDatabase;

    /** Users this test made, removed on tear-down. */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-openai');
        $this->setting('llm_deepseek_api_key', 'sk-deepseek');
        $this->setting('llm_provider', 'openai');
        $this->setting(ModelChoices::SETTING, json_encode([
            ['key' => 'deep', 'name' => 'Deep thought', 'provider' => 'openai', 'model' => 'gpt-4o', 'reasoning' => true, 'usertype' => 98],
            ['key' => 'strong', 'name' => 'Strong', 'provider' => 'openai', 'model' => 'gpt-4o', 'usertype' => 97],
            ['key' => 'quick', 'name' => 'Quick', 'provider' => 'deepseek', 'model' => 'deepseek-flash', 'usertype' => 0],
            ['key' => 'ghost', 'provider' => 'nowhere', 'model' => 'x'],
        ]));
        $_POST = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->users as $id) {
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $id)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $id)->delete();
        }
        \Pramnos\Http\RequestIdentity::reset();
        $_POST = [];
        http_response_code(200);
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A saved user of a usertype, with a choice kept, or none. */
    private function user(int $usertype, string $choice = ''): \Pramnos\User\User
    {
        $user = new \Pramnos\User\User();
        $user->username = 'pick' . random_int(100000, 999999);
        $user->email    = $user->username . '@example.test';
        $user->usertype = $usertype;
        if ($choice !== '') {
            $user->{ModelChoices::USER_DETAIL} = $choice;
        }
        $user->save();
        $this->users[] = (int) $user->userid;

        return $user;
    }

    /** The host each request went to. */
    private function hosts(): array
    {
        return array_map(static fn (array $r): string => (string) parse_url($r['url'], PHP_URL_HOST), $this->sent);
    }

    /**
     * What a person may choose follows their usertype, cheapest first — reasoning after the same
     * model without — and a row with an unknown provider is no choice at all. A key they do not
     * reach, or no key, is Automatic.
     */
    public function testWhatEachPersonMayChoose(): void
    {
        // Arrange
        $admin = (object) ['userid' => 5, 'usertype' => 97, ModelChoices::USER_DETAIL => 'deep'];
        $super = (object) ['userid' => 6, 'usertype' => 98, ModelChoices::USER_DETAIL => 'deep'];

        // Act
        $forAdmin = array_column(ModelChoices::allowed($admin), 'key');
        $forSuper = array_column(ModelChoices::allowed($super), 'key');

        // Assert
        $this->assertSame(['deep', 'strong', 'quick'], array_column(ModelChoices::all(), 'key'), 'the unknown provider dropped');
        $this->assertSame(['quick', 'strong'], $forAdmin);
        $this->assertSame(['quick', 'strong', 'deep'], $forSuper, 'cheapest first; the same model with reasoning after');
        $this->assertNull(ModelChoices::chosen($admin), 'a choice above the floor is Automatic');
        $this->assertSame('deep', ModelChoices::chosen($super)['key']);
        $this->assertSame([], ModelChoices::allowed(null));
        $this->assertNull(ModelChoices::chosen((object) ['userid' => 5, 'usertype' => 97]), 'no choice kept: Automatic');
    }

    /** A model nobody priced is ordered last, as if dearest: Automatic reaches it last. */
    public function testAnUnpricedModelIsLast(): void
    {
        // Act
        $ordered = ModelChoices::byCost([
            ['key' => 'mystery', 'model' => 'no-such-model', 'reasoning' => false],
            ['key' => 'flash', 'model' => 'deepseek-flash', 'reasoning' => false],
        ]);

        // Assert
        $this->assertSame(['flash', 'mystery'], array_column($ordered, 'key'));
    }

    /**
     * A chosen model answers the question on its provider, model and reasoning; once the person no
     * longer reaches it — their usertype lowered — the same question goes to the assistant's own
     * connection, with no decision asked.
     */
    #[DataProvider('databases')]
    public function testAChosenModelIsCheckedOnEveryQuestion(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $user = $this->user(97, 'quick');
        $chat = (new Conversations(new BaseAssistant()))->as(new Scope((int) $user->userid, null));
        $this->answer([[200, self::says('Fast.')], [200, self::says('Default.')]]);

        // Act
        $chosen = $chat->ask(null, 'How many?');
        $first  = $this->sent[0];
        $before = $chat->connection()->provider();
        $user->{ModelChoices::USER_DETAIL} = 'deep';
        $user->save();
        $above  = $chat->ask(null, 'And now?');

        // Assert
        $this->assertTrue($chosen['ok'] && $above['ok']);
        $this->assertSame('api.deepseek.com', parse_url($first['url'], PHP_URL_HOST), 'answered where the person chose');
        $this->assertSame('deepseek-flash', $first['body']['model']);
        $this->assertSame(['deepseek', 'openai'], [$before, $chat->connection()->provider()], 'what is checked before asking follows the choice too');
        $this->assertSame(['api.deepseek.com', 'api.openai.com'], $this->hosts(), 'above the floor: the assistant\'s own, undecided');
    }

    /**
     * Automatic, with more than one choice and the model cascade on: the cheapest the person may
     * have reads the question and names one of them — listed cheapest first, and only those — and
     * the question is answered there.
     */
    #[DataProvider('databases')]
    public function testAutomaticPicksAmongWhatThePersonMayHave(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['cascade' => ModelCascade::class, 'cascade_hold' => 1]);
        $user = $this->user(97);
        $this->answer([[200, self::calls([['choose_model', ['model' => 'strong']]])], [200, self::says('Chosen.')], [200, self::says('Thought through.')]]);

        // Act
        $answer = (new Conversations(new BaseAssistant()))->as(new Scope((int) $user->userid, null))->ask(null, 'Plan the quarter.');

        // Assert
        $this->assertTrue($answer['ok'], $answer['says']);
        $this->assertSame(['api.deepseek.com', 'api.deepseek.com', 'api.openai.com'], $this->hosts(), 'decided on the cheapest, answered on the pick');
        $this->assertSame(['quick', 'strong'], $this->sent[0]['body']['tools'][0]['function']['parameters']['properties']['model']['enum'], 'nothing above the floor offered');
        $this->assertSame('gpt-4o', $this->sent[2]['body']['model']);
        $this->assertSame('Thought through.', $answer['message']['content']);
    }

    /** Automatic's pick down: the question is answered on the cheapest choice, told so. */
    #[DataProvider('databases')]
    public function testAPickThatFailsFallsToTheCheapest(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['cascade' => ModelCascade::class, 'cascade_hold' => 1]);
        $user = $this->user(98);
        $this->answer([[200, self::calls([['choose_model', ['model' => 'deep']]])], [200, self::says('Chosen.')],
            [400, ['error' => ['message' => 'model gone']]], [200, self::says('My best.')]]);

        // Act
        $answer = (new Conversations(new BaseAssistant()))->as(new Scope((int) $user->userid, null))->ask(null, 'Prove it.');

        // Assert
        $this->assertTrue($answer['ok'], $answer['says']);
        $last = end($this->sent);
        $this->assertSame('api.deepseek.com', parse_url($last['url'], PHP_URL_HOST));
        $this->assertStringEndsWith(Conversations::DEGRADED, $last['body']['messages'][count($last['body']['messages']) - 2]['content']);
    }

    /**
     * Kept from the composer: a key the person may have, or '' for Automatic; one above their
     * floor is refused and nothing kept; nobody signed in keeps nothing. The page draws the
     * choices they may have, the kept one selected, in every theme.
     */
    #[DataProvider('databases')]
    public function testTheComposerKeepsTheChoice(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $user = $this->user(97);
        \Pramnos\Http\RequestIdentity::seal($user, 'test');

        // Act
        $_POST   = ['choice' => 'strong'];
        $kept    = (new ChatPageController())->modelchoice();
        $_POST   = ['choice' => 'deep'];
        $refused = (new ChatPageController())->modelchoice();
        $stored  = (string) (new \Pramnos\User\User((int) $user->userid))->{ModelChoices::USER_DETAIL};
        $pages   = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $pages[$theme] = (string) (new PageFixture(new Scope((int) $user->userid, null), $theme))->display();
        }
        \Pramnos\Http\RequestIdentity::reset();
        \Pramnos\Http\RequestIdentity::seal(null, 'test');
        $nobody = (new ChatPageController())->modelchoice();

        // Assert
        $this->assertSame('{"status":200,"data":{"choice":"strong"}}', $kept);
        $this->assertStringContainsString('"status":422', (string) $refused);
        $this->assertSame('strong', $stored, 'the refused key kept nothing');
        foreach ($pages as $theme => $page) {
            $this->assertMatchesRegularExpression('~data-pf-chat-model data-pf-chat-model-url="[^"]*chat/modelchoice"~', $page, $theme);
            $this->assertStringContainsString('<option value="strong" selected>Strong</option>', $page, $theme);
            $this->assertStringNotContainsString('value="deep"', $page, $theme . ': above the floor, not offered');
        }
        $this->assertStringContainsString('"status":401', (string) $nobody);
    }

    /**
     * The settings card: a row per choice and one to add; saving drops a row without a model and
     * refuses an unknown provider.
     */
    #[DataProvider('databases')]
    public function testTheSettingsCard(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $card = new ModelChoices();

        // Act
        $html    = $card->render();
        $refused = $card->save(['choices' => [['provider' => 'nowhere', 'model' => 'x']]]);
        $saved   = $card->save(['choices' => [
            ['key' => 'quick', 'name' => 'Quick', 'provider' => 'deepseek', 'model' => 'deepseek-flash', 'usertype' => '0'],
            ['name' => 'New', 'provider' => 'openai', 'model' => 'gpt-4o', 'reasoning' => '1', 'ability' => 'admin.ai', 'usertype' => '99'],
            ['name' => 'Removed', 'provider' => 'openai', 'model' => ''],
        ]]);
        $all = ModelChoices::all();

        // Assert
        $this->assertSame('modelchoices', $card->key());
        $this->assertStringContainsString('name="choices[3][model]" value=""', $html, 'three choices and an empty row');
        $this->assertSame('There is no provider called nowhere.', $refused);
        $this->assertNull($saved);
        $this->assertSame(['Quick', 'New'], array_column($all, 'name'));
        $this->assertSame(10, strlen($all[1]['key']), 'a new row is given a key');
        $this->assertSame(['admin.ai', 99, true], [$all[1]['ability'], $all[1]['usertype'], $all[1]['reasoning']]);
        // An ability decides through the access rules: in usertype mode, the usertype floor.
        $this->assertSame(['quick'], array_column(ModelChoices::allowed((object) ['userid' => 5, 'usertype' => 98]), 'key'));
        $this->assertSame(['quick', $all[1]['key']], array_column(ModelChoices::allowed((object) ['userid' => 5, 'usertype' => 99]), 'key'));
    }
}
