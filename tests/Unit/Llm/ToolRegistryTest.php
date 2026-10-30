<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Llm\Client;
use Pramnos\Llm\ToolRegistry;
use Pramnos\Mcp\McpToolInterface;

/**
 * Tools declared once with what the runtime needs to know, handed to the client per person and
 * per skill; and skills turned on during an answer.
 *
 * What has to hold: the flags the client reads (`writes`, `parallel`, `untrusted`, `gated`,
 * `tags`) come out of the metadata as the table in ToolRegistry says; a tool needing an ability
 * the person lacks is never offered, not even through a skill; and a skill turned on adds its
 * tools from the next round only.
 */
#[CoversClass(ToolRegistry::class)]
class ToolRegistryTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_deepseek_api_key', 'sk-deepseek');
        $this->setting('llm_provider', 'deepseek');
    }

    /** A registry whose abilities are a fixed list for person 7, and none for anybody else. */
    private static function registry(array $abilities = ['admin.reports']): ToolRegistry
    {
        $can = static fn (string $ability, array $context): bool => ($context['userid'] ?? 0) === 7 && in_array($ability, $abilities, true);

        return (new ToolRegistry($can))
            ->registerMcp(new EchoTool(), ['idempotent' => true, 'permissions' => ['admin.reports'], 'skills' => ['reporting'], 'latency_tier' => 'slow'])
            ->register('send', static fn (array $a): string => 'sent', ['writes' => true, 'idempotent' => true, 'skills' => ['mail']], 'Send', ['properties' => ['to' => ['type' => 'string']]])
            ->register('read_mail', static fn (): string => 'mail', ['untrusted' => true, 'skills' => ['mail'], 'tags' => ['personal' => true]], 'Read mail')
            ->register('clock', static fn (): string => 'noon', ['parallel' => true], 'The time')
            ->describeSkill('reporting', 'Reports of the site\'s figures');
    }

    /**
     * The client's flags come from the metadata: a read that gives the same answer each time runs
     * in parallel, a write never does even when idempotent, and an explicit `parallel` stands.
     */
    public function testTheClientsFlagsComeFromTheMetadata(): void
    {
        // Arrange
        $registry = self::registry();

        // Act
        $tools = $registry->tools(['userid' => 7]);

        // Assert
        $this->assertSame(['echo', 'send', 'read_mail', 'clock'], array_keys($tools));
        $this->assertTrue($tools['echo']['parallel'], 'idempotent and not a write');
        $this->assertSame('{"said":"hi"}', \Pramnos\Llm\Tools::text(($tools['echo']['run'])(['say' => 'hi'])));
        $this->assertTrue($tools['send']['writes']);
        $this->assertArrayNotHasKey('parallel', $tools['send'], 'a write never runs at once with others');
        $this->assertTrue($tools['read_mail']['untrusted']);
        $this->assertSame(['personal' => true], $tools['read_mail']['tags']);
        $this->assertTrue($tools['clock']['parallel'], 'said outright');
        $this->assertSame('slow', $registry->metadata('echo')['latency_tier']);
        $this->assertSame(['low', 'fast'], [$registry->metadata('clock')['cost_weight'], $registry->metadata('clock')['latency_tier']]);
        $this->assertSame('object', $tools['clock']['parameters']['type'], 'a schema the provider takes');
        $this->assertTrue($registry->has('echo'));
        $this->assertFalse($registry->has('nothing'));
    }

    /**
     * A tool is offered only to a person holding every ability it needs; and when skills are
     * given, only the skills' tools beside the ones of no skill.
     */
    public function testPermissionsAndSkillsNarrowTheTools(): void
    {
        // Arrange
        $registry = self::registry();

        // Act / Assert
        $this->assertSame(['send', 'read_mail', 'clock'], array_keys($registry->tools(['userid' => 8])), 'no echo without admin.reports');
        $this->assertSame(['send', 'read_mail', 'clock'], array_keys(self::registry([])->tools(['userid' => 7])));
        $this->assertSame(['clock'], array_keys($registry->tools(['userid' => 7], skills: [])), 'no skill in use: only the tools of none');
        $this->assertSame(['echo', 'clock'], array_keys($registry->tools(['userid' => 7], skills: ['reporting'])));
        $this->assertSame(['mail' => ['send', 'read_mail'], 'reporting' => ['echo']], $registry->skills());
        $this->assertSame([], (new ToolRegistry())->register('x', static fn () => '', ['permissions' => ['admin.x']])->tools([]), 'nobody signed in holds nothing');
    }

    /**
     * The prompt's list of skills names what this person can turn on and what each is for; the
     * `use_skill` tool turns one on, and says so — and nothing it may not have.
     */
    public function testTheSkillToolAndTheManifest(): void
    {
        // Arrange
        $registry = self::registry();

        // Act
        $mine    = $registry->manifest(['userid' => 7]);
        $theirs  = $registry->manifest(['userid' => 8]);
        $tool    = $registry->skillTool(['userid' => 8])['use_skill'];
        $said    = ($tool['run'])(['skill' => 'mail']);
        $refused = ($tool['run'])(['skill' => 'reporting']);

        // Assert
        $this->assertStringContainsString("- reporting: Reports of the site's figures (echo)", $mine);
        $this->assertStringContainsString('- mail (send, read_mail)', $mine);
        $this->assertStringNotContainsString('reporting', $theirs, 'a skill whose every tool they lack is not offered');
        $this->assertSame(['mail'], $tool['parameters']['properties']['skill']['enum']);
        $this->assertSame('Turned on mail: send, read_mail.', $said);
        $this->assertSame('Error: there is no skill called reporting.', $refused);
        $this->assertSame(['send', 'read_mail'], array_keys(($tool['expands'])(['skill' => 'mail'])));
        $this->assertSame([], ($tool['expands'])(['skill' => 'reporting']));
        $this->assertSame([], (new ToolRegistry())->skillTool(), 'no skills, no tool');
        $this->assertSame('', (new ToolRegistry())->manifest());
    }

    /**
     * Tools already in the client's shape keep their own flags, and take the registry's metadata
     * for what they do not say; an entry with nothing to run is left out.
     */
    public function testToolsInTheClientsShapeAreAdded(): void
    {
        // Arrange
        $registry = (new ToolRegistry(static fn (): bool => true))->add([
            'mine'   => ['description' => 'Mine', 'parameters' => [], 'run' => static fn (): string => 'x', 'writes' => true],
            'broken' => ['description' => 'Nothing to run'],
        ], ['skills' => ['own'], 'writes' => false]);

        // Act
        $tools = $registry->tools(['userid' => 7], skills: ['own']);

        // Assert
        $this->assertSame(['mine'], array_keys($tools));
        $this->assertTrue($tools['mine']['writes'], 'its own flag wins');
        $this->assertSame(['own'], $registry->metadata('mine')['skills']);
    }

    /**
     * A skill turned on during an answer: its tools are offered from the next request — and run
     * in the answer, never handed to the workers, which do not know them.
     */
    public function testASkillTurnedOnAddsItsToolsFromTheNextRound(): void
    {
        // Arrange — the model turns on mail, then calls send and clock together
        $this->answer([
            [200, self::calls([['use_skill', ['skill' => 'mail']]])],
            [200, self::calls([['send', ['to' => 'a@x.test']], ['clock', []]])],
            [200, self::says('Done')],
        ]);
        $registry = self::registry();
        $context  = ['userid' => 7];
        $tools    = $registry->tools($context, skills: []) + $registry->skillTool($context);
        $handed   = null;

        // Act
        $answer = (new Client(null, self::memoryLog()))
            ->inParallel(static function (array $calls, callable $runHere) use (&$handed): array {
                $handed = $calls;

                return array_map(static fn (array $c): string => $runHere($c['name'], $c['arguments']), $calls);
            })
            ->askWithTools('', [['role' => 'user', 'content' => 'Mail it']], $tools, 4);

        // Assert
        $this->assertTrue($answer['ok']);
        $offered = fn (int $request): array => array_column(array_column($this->sent[$request]['body']['tools'], 'function'), 'name');
        $this->assertSame(['clock', 'use_skill'], $offered(0));
        $this->assertSame(['clock', 'use_skill', 'send', 'read_mail'], $offered(1), 'mail\'s tools from the next round');
        $this->assertSame(['use_skill', 'send', 'clock'], array_column($answer['calls'], 'name'));
        $this->assertNull($handed, 'a write in the round: nothing handed over');
        $this->assertStringContainsString(Client::DATA_NOTICE, $this->sent[1]['body']['messages'][0]['content'], 'read_mail is untrusted: the notice comes with it');
    }
}

/** An MCP tool that says back what it was told. */
final class EchoTool implements McpToolInterface
{
    public function name(): string
    {
        return 'echo';
    }

    public function description(): string
    {
        return 'Says it back';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['say' => ['type' => 'string']]];
    }

    public function execute(array $input): mixed
    {
        return ['said' => $input['say'] ?? ''];
    }

    /**
     * A tool added in the client's shape goes back out with what the registry does not read
     * itself — `expands`, an application's own key — so putting tools through a registry to group
     * them loses nothing the client needs.
     */
    public function testAddKeepsWhatItDoesNotRead(): void
    {
        // Arrange
        $expands  = static fn (): array => [];
        $registry = (new ToolRegistry(static fn (): bool => true))->add([
            'grow' => ['description' => 'd', 'parameters' => [], 'run' => static fn (): string => 'ok', 'expands' => $expands, 'x_app' => 7, 'skills' => ['s']],
        ]);

        // Act
        $tool = $registry->tools([], ['s'])['grow'];

        // Assert
        $this->assertSame($expands, $tool['expands']);
        $this->assertSame(7, $tool['x_app']);
        $this->assertSame(['s'], $tool['meta']['skills']);
    }
}
