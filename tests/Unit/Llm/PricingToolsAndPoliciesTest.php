<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use Pramnos\Llm\Billing\OpenAiCosts;
use Pramnos\Llm\Llm;
use Pramnos\Llm\Policy;
use Pramnos\Llm\Pricing;
use Pramnos\Llm\Tools;
use Pramnos\Mcp\McpToolInterface;

/**
 * Costs, tools, and how an application's policies are found.
 */
class PricingToolsAndPoliciesTest extends LlmTestCase
{
    /**
     * A call is costed in three buckets, at the hour it was made, however the provider counts.
     */
    public function testACallIsCostedByBucketAndHour(): void
    {
        // Arrange
        $pricing = new Pricing();
        $deepseek = ['prompt_tokens' => 1_000_000, 'prompt_cache_hit_tokens' => 400_000, 'prompt_cache_miss_tokens' => 600_000,
            'completion_tokens' => 1_000_000, 'total_tokens' => 2_000_000];
        $peak    = (int) strtotime('2026-10-12 07:00:00 UTC');   // a Monday, 07:00 UTC
        $offPeak = (int) strtotime('2026-10-11 07:00:00 UTC');   // a Sunday

        // Act / Assert — 0.4 × 0.006 + 0.6 × 0.30 + 1 × 1.20
        $this->assertEqualsWithDelta(1.3824, $pricing->cost('deepseek-chat', $deepseek, $peak), 1e-9);
        $this->assertEqualsWithDelta(0.6912, $pricing->cost('deepseek-chat', $deepseek, $offPeak), 1e-9);

        // OpenAI's cached count, and Gemini's thinking outside completion_tokens
        $this->assertSame(['hit' => 30, 'miss' => 70], Pricing::splitPromptTokens(['prompt_tokens' => 100, 'prompt_tokens_details' => ['cached_tokens' => 30]]));
        $this->assertSame(['hit' => 5, 'miss' => 95], Pricing::splitPromptTokens(['prompt_tokens' => 100, 'prompt_cache_hit_tokens' => 5]));
        $this->assertSame(158, Pricing::outputTokens(['prompt_tokens' => 0, 'completion_tokens' => 3, 'total_tokens' => 158]));
        $this->assertSame(161, Pricing::tokensIn(['prompt_tokens' => 3, 'completion_tokens' => 3, 'total_tokens' => 161]));

        // A model nobody priced costs null, not nothing
        $this->assertNull($pricing->cost('unpriced-model', $deepseek));
    }

    /**
     * The prices are a setting; a broken setting falls back to the seed, and an incomplete
     * entry is dropped rather than billed at nought.
     */
    public function testThePricesAreASetting(): void
    {
        // Arrange / Act / Assert — the setting wins
        $this->setting('llm_prices', json_encode(['my-model' => ['cache_hit' => 1, 'cache_miss' => 2, 'output' => 3], 'half' => ['output' => 1], 'junk' => 'x']));
        $prices = (new Pricing())->all();
        $this->assertSame(['my-model'], array_keys($prices));
        $this->assertSame(['cache_hit' => 1.0, 'cache_miss' => 2.0, 'output' => 3.0], (new Pricing())->forModel('my-model'));

        // Broken JSON: the seed
        $this->setting('llm_prices', '{not json');
        $this->assertArrayHasKey('gpt-4o', (new Pricing())->all());
        $this->assertJson(Pricing::seedJson());
    }

    /**
     * What an administrator types is validated with a reason.
     */
    public function testTypedPricesAreValidated(): void
    {
        // Act
        $good    = Pricing::validate('{"m": {"cache_hit": 1, "cache_miss": 2, "output": "3"}}');
        $missing = Pricing::validate('{"m": {"cache_hit": 1}}');
        $empty   = Pricing::validate('[]');

        // Assert
        $this->assertTrue($good['ok']);
        $this->assertEquals(3, json_decode($good['json'], true)['m']['output'], 'the string "3" stored as a number');
        $this->assertSame('These need cache_hit, cache_miss and output as numbers: m.', $missing['says']);
        $this->assertSame('The prices are not a JSON object of models.', $empty['says']);
    }

    /**
     * MCP tools become tool entries; a result that is not text is encoded; tags are attached.
     */
    public function testMcpToolsBecomeToolEntries(): void
    {
        // Arrange
        $tool = new class implements McpToolInterface {
            public function name(): string
            {
                return 'stats';
            }

            public function description(): string
            {
                return 'Site statistics';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): mixed
            {
                return ['users' => 3, 'name' => 'Αθήνα'];
            }
        };

        // Act
        $tools = Tools::fromMcp([$tool, new \stdClass()], skip: ['whoami']);
        Tools::tag($tools, 'stats', 'google', static fn (string $result): bool => str_contains($result, 'users'));
        Tools::tag($tools, 'missing', 'google');
        $result = ($tools['stats']['run'])([]);

        // Assert
        $this->assertSame(['stats'], array_keys($tools));
        $this->assertSame('Site statistics', $tools['stats']['description']);
        $this->assertInstanceOf(\stdClass::class, $tools['stats']['parameters']['properties']);
        $this->assertSame('{"users":3,"name":"Αθήνα"}', $result);
        $this->assertSame(['google'], Tools::tagsOf($tools['stats'], $result, []));
        $this->assertSame([], Tools::tagsOf($tools['stats'], 'nothing', []));
        $this->assertSame([], Tools::fromMcp([$tool], skip: ['stats']));
        $this->assertSame('text', Tools::text('text'));
    }

    /**
     * Policies are found in app.php, fall back to the defaults, and a runtime one wins.
     */
    public function testPoliciesComeFromConfiguration(): void
    {
        // Arrange / Act / Assert — the defaults
        $this->assertInstanceOf(Policy\NoLimits::class, Llm::limits());
        $this->assertInstanceOf(Policy\NoConsent::class, Llm::consent());
        $this->assertInstanceOf(Policy\DefaultRouter::class, Llm::router());
        $this->assertSame([], Llm::rules());
        $this->assertSame([], Llm::adminSections());
        $this->assertNull((new Policy\NoConsent())->refusal([], 'openai'));
        $this->assertNull((new Policy\NoLimits())->refusal([], new \Pramnos\Llm\Connection()));

        // Configured by class name; a class that does not exist is ignored
        $this->configure([
            'consent' => RefuseEverything::class,
            'limits'  => 'No\\Such\\Class',
            'rules'   => [GoogleToOpenAi::class, \stdClass::class, 'No\\Such\\Class'],
            'admin_sections' => [Section::class, \stdClass::class],
        ]);
        $this->assertInstanceOf(RefuseEverything::class, Llm::consent());
        $this->assertSame(Llm::consent(), Llm::consent(), 'built once');
        $this->assertInstanceOf(Policy\NoLimits::class, Llm::limits());
        $this->assertSame(['google'], array_keys(Llm::rules()));
        $this->assertCount(1, Llm::adminSections());

        // Set at runtime: wins, one object or a list
        Llm::use('consent', new Policy\NoConsent());
        Llm::use('rules', new GoogleToOpenAi());
        $this->assertInstanceOf(Policy\NoConsent::class, Llm::consent());
        $this->assertSame(['google'], array_keys(Llm::rules()));
        $this->assertSame('fallback', Llm::config('missing', 'fallback'));
    }

    /**
     * The default router fills in the person signed in, and only when the caller did not.
     */
    public function testTheDefaultRouterNamesThePerson(): void
    {
        // Act
        $named = (new Policy\DefaultRouter())->context(['userid' => 5]);
        $none  = (new Policy\DefaultRouter())->context([]);

        // Assert
        $this->assertSame(5, $named['userid']);
        $this->assertArrayHasKey('userid', $none);
    }

    /**
     * OpenAI's billed costs, by project, with the balance worked out from the top-ups.
     */
    public function testOpenAiCostsAndBalance(): void
    {
        // Arrange
        $this->setting(OpenAiCosts::ADMIN_KEY, \Pramnos\Security\Encrypter::encrypt('sk-admin'));
        $this->setting(OpenAiCosts::TOPUPS, "2026-10-01 50\nnot a line\n2026-09-15 $10");
        $this->setting(OpenAiCosts::PROJECT, 'proj_ours');
        $this->setting('llm_openai_shared_project', 'proj_shared');
        $now   = (int) strtotime('2026-10-12 12:00:00 UTC');
        $day   = static fn (string $date, string $project, float $usd): array => ['start_time' => (int) strtotime($date . ' UTC'),
            'results' => [['project_id' => $project, 'amount' => ['value' => $usd]]]];
        $pages = [
            [200, ['data' => [$day('2026-09-20', 'proj_ours', 4.0)], 'has_more' => true, 'next_page' => 'p2']],
            [200, ['data' => [$day('2026-10-02', 'proj_ours', 1.5), $day('2026-10-03', 'proj_shared', 0.25)], 'has_more' => false]],
        ];
        $asked = [];
        $fetch = static function (string $url) use (&$pages, &$asked): array {
            $asked[] = $url;

            return array_shift($pages);
        };

        // Act
        $summary = (new OpenAiCosts($fetch, ['llm_openai_shared_project']))->summary($now, true);

        // Assert
        $this->assertTrue($summary['ok']);
        $this->assertSame(1.75, $summary['month_usd']);
        $this->assertSame(1.5, $summary['project_month_usd']);
        $this->assertSame(['llm_openai_shared_project' => 0.25], $summary['projects']);
        $this->assertSame('2026-09-15', $summary['since']);
        $this->assertSame(60.0, $summary['topped_up_usd']);
        $this->assertSame(54.25, $summary['balance_usd']);
        $this->assertStringContainsString('group_by=project_id', $asked[0]);
        $this->assertStringContainsString('page=p2', $asked[1]);
        $this->assertSame([['date' => '2026-09-15', 'usd' => 10.0], ['date' => '2026-10-01', 'usd' => 50.0]], OpenAiCosts::topups());

        // Kept, then refused, then not configured
        $this->assertSame($summary, (new OpenAiCosts(static fn (): array => [500, null], ['llm_openai_shared_project']))->summary($now));
        OpenAiCosts::forget();
        $this->assertFalse((new OpenAiCosts(static fn (): array => [500, null]))->summary($now)['ok']);
        $this->setting(OpenAiCosts::ADMIN_KEY, '');
        $this->assertSame('No Admin key is saved.', (new OpenAiCosts())->summary($now)['says']);
    }

    /**
     * The transport turns a failure to connect into status 0 with the reason, never a throw.
     */
    public function testTheTransportNeverThrows(): void
    {
        // Arrange
        \Pramnos\Http\Client::fake(['*' => static fn () => throw new \RuntimeException('connection refused')]);
        $transport  = new \Pramnos\Llm\Transport\OpenAiCompatible();
        $connection = new \Pramnos\Llm\Connection('openai');

        // Act
        $chat = $transport->chat($connection, ['model' => 'x']);
        $get  = $transport->get($connection, '/models');

        // Assert
        $this->assertSame([0, ['error' => ['message' => 'connection refused']]], $chat);
        $this->assertSame(0, $get[0]);
    }

    /**
     * OpenAI's costs are read with the Admin key over HTTP; a key that cannot be read is none.
     */
    public function testOpenAiCostsAreReadOverHttp(): void
    {
        // Arrange
        $this->setting(OpenAiCosts::ADMIN_KEY, \Pramnos\Security\Encrypter::encrypt('sk-admin'));
        $this->answer([[200, ['data' => [], 'has_more' => false]]]);
        OpenAiCosts::forget();

        // Act
        $summary = (new OpenAiCosts())->summary(null, true);
        \Pramnos\Http\Client::fake(['*' => static fn () => throw new \RuntimeException('down')]);
        $down = (new OpenAiCosts())->summary(null, true);

        // Assert
        $this->assertTrue($summary['ok']);
        $this->assertStringStartsWith('https://api.openai.com/v1/organization/costs?', $this->sent[0]['url']);
        $this->assertFalse($down['ok']);
        $this->setting(OpenAiCosts::ADMIN_KEY, 'enc:v1:unreadable');
        $this->assertFalse(OpenAiCosts::configured());
    }

    /**
     * A limits policy that throws while recording does not end the answer.
     */
    public function testAFailingMeterDoesNotEndTheAnswer(): void
    {
        // Arrange
        $this->setting('llm_openai_api_key', 'sk-o');
        $this->answer([[200, self::says('Hi')]]);
        Llm::use('limits', new class implements Policy\Limits {
            public function refusal(array $context, \Pramnos\Llm\Connection $connection): ?string
            {
                return null;
            }

            public function record(array $context, \Pramnos\Llm\Connection $connection, array $usage, ?float $cost): void
            {
                throw new \RuntimeException('meter down');
            }
        });

        // Act
        $answer = (new \Pramnos\Llm\Client(null, self::memoryLog()))->ask('', [['role' => 'user', 'content' => 'x']]);

        // Assert
        $this->assertTrue($answer['ok']);
    }
}

/** A consent policy that refuses everything. */
final class RefuseEverything implements Policy\Consent
{
    public function refusal(array $context, string $provider): ?string
    {
        return 'No.';
    }
}

/** The `google` tag, for OpenAI only. */
final class GoogleToOpenAi implements Policy\DataRule
{
    public function tag(): string
    {
        return 'google';
    }

    public function accepts(\Pramnos\Llm\Connection $connection): bool
    {
        return $connection->provider() === 'openai';
    }

    public function alternative(array $context, \Pramnos\Llm\Connection $current): ?\Pramnos\Llm\Connection
    {
        return null;
    }

    public function refusalResult(): string
    {
        return 'No.';
    }
}

/** An application's section of the administration screen. */
final class Section implements \Pramnos\Llm\Admin\Section
{
    public function key(): string
    {
        return 'plans';
    }

    public function title(): string
    {
        return 'Plans';
    }

    public function render(): string
    {
        return '<p>Plans</p>';
    }

    public function save(array $input): ?string
    {
        return null;
    }
}
