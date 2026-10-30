<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Policy\ModelCascade;
use Pramnos\Llm\Policy\NoCascade;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * A question answered on the tier it needs — the fast one for routine questions, the frontier one
 * for the rest — on MySQL and PostgreSQL, with DeepSeek as the fast tier and OpenAI as the
 * frontier one.
 *
 * What has to hold: the decision is one short call on the fast tier; the answer goes to the
 * provider of the tier chosen; a frontier tier that is down hands the question to the fast one,
 * which is told so; and a decision that cannot be had changes nothing.
 */
#[CoversClass(ModelCascade::class)]
#[CoversClass(NoCascade::class)]
#[CoversClass(Conversations::class)]
class CascadeTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-openai');
        $this->setting('llm_deepseek_api_key', 'sk-deepseek');
        $this->setting('llm_provider', 'openai');
        $this->setting('llm_feature_quick_provider', 'deepseek');
        $this->setting('llm_feature_deep_provider', 'openai');
    }

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Tiers on two features, decided by the fast one. */
    private function tiers(): void
    {
        $this->configure([
            'features' => ['quick' => 'Quick answers', 'deep' => 'Hard questions'],
            'tiers'    => ['fast' => 'quick', 'frontier' => 'deep'],
            'cascade'  => ModelCascade::class,
        ]);
    }

    /** The fast tier's decision, through its tool: a call, then its closing words. */
    private static function decides(string $tier): array
    {
        return [[200, self::calls([['choose_tier', ['tier' => $tier]]])], [200, self::says('Chosen.')]];
    }

    private function ask(string $question): array
    {
        return (new Conversations(new BaseAssistant()))->as(new Scope(7, null))->ask(null, $question);
    }

    /**
     * A routine question is decided on the fast tier, from the question and what came before,
     * and answered there; a hard one is answered on the frontier tier.
     */
    #[DataProvider('databases')]
    public function testEachQuestionOnTheTierItNeeds(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->tiers();
        $this->answer([...self::decides('fast'), [200, self::says('12 orders.')], ...self::decides('frontier'), [200, self::says('A long plan.')]]);

        // Act
        $quick = $this->ask('How many orders today?');
        $deep  = $this->ask('Plan the next quarter with me.');

        // Assert
        $this->assertTrue($quick['ok'] && $deep['ok']);
        $this->assertSame(
            ['api.deepseek.com', 'api.deepseek.com', 'api.deepseek.com', 'api.deepseek.com', 'api.deepseek.com', 'api.openai.com'],
            array_map(static fn (array $r): string => (string) parse_url($r['url'], PHP_URL_HOST), $this->sent),
            'decided on the fast tier each time; answered where it was decided'
        );
        $this->assertStringContainsString('The question: How many orders today?', end($this->sent[0]['body']['messages'])['content']);
        $this->assertSame('choose_tier', $this->sent[0]['body']['tools'][0]['function']['name']);
        $this->assertSame('A long plan.', $deep['message']['content']);
    }

    /**
     * In one conversation the tier is decided once and held for `Conversations::CASCADE_HOLD`
     * questions — the decision is a call of its own — then decided again; with
     * `'cascade_hold' => 1`, every question is decided.
     */
    #[DataProvider('databases')]
    public function testTheTierIsHeldForAConversation(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->tiers();
        $chat  = (new Conversations(new BaseAssistant()))->as(new Scope(7, null));
        $this->answer([...self::decides('fast'), [200, self::says('Hello')]]);
        $first = $chat->ask(null, 'Hi');   // a new conversation decides, as it has no id yet
        $id    = (int) $first['conversation_id'];
        // An id an earlier run used may still have a decision held: this one starts clean.
        \Pramnos\Cache\Cache::getInstance('llm')->delete('tier-' . $id);
        $this->sent = [];
        $replies = [...self::decides('frontier')];
        for ($i = 0; $i < 4; $i++) {
            $replies[] = [200, self::says('Answer ' . $i)];
        }
        $this->answer([...$replies, ...self::decides('fast'), [200, self::says('Again')]]);

        // Act
        for ($i = 0; $i < 4; $i++) {
            $chat->ask($id, 'Question ' . $i);
        }
        $held = $this->sent;
        $chat->ask($id, 'Question 4');
        $hosts = array_map(static fn (array $r): string => (string) parse_url($r['url'], PHP_URL_HOST), $this->sent);

        // Assert
        $this->assertSame(['api.deepseek.com', 'api.deepseek.com', 'api.openai.com', 'api.openai.com', 'api.openai.com', 'api.openai.com'],
            array_map(static fn (array $r): string => (string) parse_url($r['url'], PHP_URL_HOST), $held), 'one decision, four answers on the frontier');
        $this->assertSame(['api.deepseek.com', 'api.deepseek.com', 'api.deepseek.com'], array_slice($hosts, 6), 'decided again on the fifth');
    }

    /**
     * The frontier tier down: the question is answered on the fast tier, told that it answers in
     * the stronger model's place.
     */
    #[DataProvider('databases')]
    public function testTheFastTierAnswersWhenTheFrontierIsDown(string $type, string $host, int $port): void
    {
        // Arrange — the frontier refuses the request outright
        $this->connectDatabase($type, $host, $port);
        $this->tiers();
        $this->answer([...self::decides('frontier'), [400, ['error' => ['message' => 'model gone']]], [200, self::says('My best answer.')]]);

        // Act
        $answer = $this->ask('Help me think this through.');

        // Assert
        $this->assertTrue($answer['ok'], $answer['says']);
        $this->assertSame('My best answer.', $answer['message']['content']);
        $last = end($this->sent);
        $this->assertSame('api.deepseek.com', parse_url($last['url'], PHP_URL_HOST));
        $tail = $last['body']['messages'][count($last['body']['messages']) - 2]['content'];
        $this->assertStringEndsWith(Conversations::DEGRADED, $tail, 'told it answers in the stronger model\'s place');
    }

    /**
     * No decision changes nothing: without a cascade, with tiers not named, or with the fast tier
     * unable to decide, the question goes to the assistant's own connection.
     */
    #[DataProvider('databases')]
    public function testNoDecisionChangesNothing(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('Plain.')]]);

        // Act — no cascade; a cascade without tiers; a fast tier that cannot answer
        $none = $this->ask('Hi');
        $this->configure(['cascade' => ModelCascade::class]);
        $noTiers = (new ModelCascade())->tier([], 'Hi', []);
        $this->tiers();
        $this->answer([[400, ['error' => ['message' => 'down']]], [200, self::says('Plain.')]]);
        $undecided = $this->ask('Hi again');

        // Assert
        $this->assertTrue($none['ok']);
        $this->assertSame('api.openai.com', parse_url($this->sent[0]['url'], PHP_URL_HOST), 'the assistant\'s own connection');
        $this->assertNull($noTiers);
        $this->assertTrue($undecided['ok']);
        $this->assertSame('api.openai.com', parse_url(end($this->sent)['url'], PHP_URL_HOST), 'undecided: its own connection again');
        $this->assertNull((new NoCascade())->tier([], 'x', []));
    }
}
