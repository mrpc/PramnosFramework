<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Segments as SegmentsScreen;
use Pramnos\Audience\Experiments;
use Pramnos\Audience\Rules\Rules;
use Pramnos\Audience\Segments;
use Pramnos\Llm\Analyst\Analyst;
use Pramnos\Llm\Analyst\Tools\AudienceDraftTool;
use Pramnos\Llm\Analyst\Tools\AudienceExperimentsTool;
use Pramnos\Llm\Analyst\Tools\AudienceRulesTool;
use Pramnos\Llm\Analyst\Tools\AudienceSegmentsTool;
use Pramnos\Llm\Analyst\Tools\AudienceTool;
use Pramnos\Llm\Analyst\Tools\AudienceWhyTool;

/**
 * Ask's audience tools on MySQL, prefixed MySQL and PostgreSQL: they read segments, rules, A/B
 * tests and why a person is in or out — counts, never a person's details — and write only drafts:
 * a segment as a link to the builder filled in, nothing saved; a rule saved as a draft that does
 * nothing. Each asks the screen's ability of the administrator asking, and none is offered where
 * its tables are not installed.
 */
#[CoversClass(AudienceTool::class)]
#[CoversClass(AudienceSegmentsTool::class)]
#[CoversClass(AudienceRulesTool::class)]
#[CoversClass(AudienceExperimentsTool::class)]
#[CoversClass(AudienceWhyTool::class)]
#[CoversClass(AudienceDraftTool::class)]
#[CoversClass(SegmentsScreen::class)]
#[CoversClass(Analyst::class)]
class AudienceAnalystToolsTest extends TestCase
{
    use AudienceProbe {
        tearDown as private probeTearDown;
    }
    use AdminScreen;

    /** Clear the request, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        $this->clearRequest();
        $this->probeTearDown();
    }

    /** Dan, raised to the administration's floor: the administrator asking. */
    private function admin(): int
    {
        $this->db->queryBuilder()->table('users')->where('userid', $this->ids['dan'])->update(['usertype' => \Pramnos\Auth\AdminAccess::defaultUsertype()]);

        return $this->ids['dan'];
    }

    /** A rule that puts whoever pays in the Press group. */
    private function rule(): int
    {
        return Rules::create('Paid to press', ['trigger' => ['type' => 'event', 'event' => 'payment.made'], 'steps' => [
            'a' => ['type' => 'action', 'action' => 'group_add', 'params' => ['group' => $this->groups['Press']], 'next' => null],
        ]], 2);
    }

    /**
     * The reading tools answer the library, one item with its definition, and the A/B results —
     * to an administrator with the ability, or with nobody asking; to somebody below it, a refusal
     * that names the ability.
     */
    #[DataProvider('databases')]
    public function testTheReadingToolsAnswerWithTheScreensAbility(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek = Segments::create('Greek', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]], 2, 'Speak Greek');
        Segments::recompute($greek);
        $rule = $this->rule();
        $test = Experiments::create('hero', 'Hero', [['key' => 'A', 'share' => 50], ['key' => 'B', 'share' => 50]], 'payment.made');
        $admin = $this->admin();

        // Act
        $library  = (new AudienceSegmentsTool($admin))->execute([]);
        $one      = (new AudienceSegmentsTool())->execute(['segment' => $greek]);
        $missing  = (new AudienceSegmentsTool($admin))->execute(['segment' => 424242]);
        $rules    = (new AudienceRulesTool($admin))->execute([]);
        $oneRule  = (new AudienceRulesTool($admin))->execute(['rule' => $rule]);
        $tests    = (new AudienceExperimentsTool($admin))->execute([]);
        $oneTest  = (new AudienceExperimentsTool($admin))->execute(['test' => $test]);
        $refused  = (new AudienceRulesTool($this->ids['anna']))->execute([]);
        $schema   = (new AudienceRulesTool())->inputSchema();

        // Assert
        $this->assertSame([['id' => $greek, 'name' => 'Greek', 'description' => 'Speak Greek', 'members' => 2]],
            array_map(static fn (array $s): array => array_intersect_key($s, array_flip(['id', 'name', 'description', 'members'])), $library['segments']));
        $this->assertSame(['entered' => 0, 'left' => 0], $library['segments'][0]['last7days'], 'made in the test: its members are its start');
        $this->assertSame('el', $one['filter']['conditions'][0]['value']);
        $this->assertSame(['found' => false], $missing);
        $this->assertSame(['Paid to press', 'draft', 0, 0, 0], [$rules['rules'][0]['name'], $rules['rules'][0]['status'],
            $rules['rules'][0]['entered'], $rules['rules'][0]['running'], $rules['rules'][0]['goals']]);
        $this->assertSame('group_add', $oneRule['definition']['steps']['a']['action']);
        $this->assertSame([], $oneRule['steps'], 'nobody went through it yet');
        $this->assertSame('Hero', $tests['tests'][0]['name']);
        $this->assertSame(['A', 'B'], array_column($oneTest['results']['variants'], 'key'));
        $this->assertSame(['available' => false, 'says' => 'The administrator asking may not use this screen (admin.rules).'], $refused);
        $this->assertSame(['rule'], array_keys($schema['properties']), 'no date window: these are not series');
    }

    /**
     * Why one person is in a segment, or would come into a rule: each condition in words with the
     * person's value; without a person, an error; an unknown segment, not found.
     */
    #[DataProvider('databases')]
    public function testWhyExplainsOnePersonInWords(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $greek = Segments::create('Greek', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
        Segments::recompute($greek);
        $rule = Rules::create('Greek only', ['trigger' => ['type' => 'event', 'event' => 'payment.made'],
            'who' => ['match' => 'all', 'conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]],
            'steps' => ['a' => ['type' => 'action', 'action' => 'group_add', 'params' => ['group' => $this->groups['Press']], 'next' => null]]], 2);
        $tool = new AudienceWhyTool($this->admin());

        // Act
        $anna    = $tool->execute(['person' => $this->ids['anna'], 'segment' => $greek]);
        $bob     = $tool->execute(['person' => $this->ids['bob'], 'rule' => $rule]);
        $nobody  = $tool->execute(['segment' => $greek]);
        $unknown = $tool->execute(['person' => $this->ids['anna'], 'segment' => 424242]);

        // Assert
        $this->assertTrue($anna['matches']);
        $this->assertTrue($anna['inSegment']);
        $this->assertSame(['met' => true, 'what' => 'Language', 'how' => 'is'], array_intersect_key($anna['conditions'][0], array_flip(['met', 'what', 'how'])));
        $this->assertFalse($bob['wouldComeIn']);
        $this->assertFalse($bob['conditions'][0]['met']);
        $this->assertSame('Error: person is the account id to explain.', $nobody);
        $this->assertSame(['found' => false], $unknown);
    }

    /**
     * A segment draft is checked and counted and handed back as a link — nothing saved — and the
     * link opens the builder filled in; a broken filter is refused, and a broken draft link opens
     * an empty form. A rule draft is saved as a draft and says how many would have come in. With
     * nobody asking, no drafts.
     */
    #[DataProvider('databases')]
    public function testDraftsAreDraftsOnly(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->signIn(98, $this->admin());
        $tool   = new AudienceDraftTool($this->ids['dan']);
        $screen = $this->screen(SegmentsScreen::class);

        // Act
        $segment = $tool->execute(['kind' => 'segment', 'name' => 'Greek', 'description' => 'For the Greek newsletter',
            'filter' => ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]]);
        parse_str((string) parse_url($segment['link'], PHP_URL_QUERY), $query);
        $this->request('GET', null, ['draft' => $query['draft']]);
        $screen->edit();
        $filled = $screen->view->draft;
        $this->request('GET', null, ['draft' => '{"filter": {"conditions": [{"attribute": "nothing"}]}}']);
        $screen->edit();
        $broken = $screen->view->draft;
        $bad = $tool->execute(['kind' => 'segment', 'filter' => ['conditions' => [['attribute' => 'nothing', 'op' => 'is']]]]);
        $rule = $tool->execute(['kind' => 'rule', 'name' => 'From Ask', 'definition' => ['trigger' => ['type' => 'event', 'event' => 'payment.made'],
            'steps' => ['a' => ['type' => 'action', 'action' => 'group_add', 'params' => ['group' => $this->groups['Press']], 'next' => null]]]]);
        $anonymous = (new AudienceDraftTool())->execute(['kind' => 'rule']);

        // Assert
        $this->assertSame([false, 2], [$segment['saved'], $segment['count']]);
        $this->assertSame([], Segments::all(), 'nothing saved');
        $this->assertSame(['name' => 'Greek', 'description' => 'For the Greek newsletter'], array_intersect_key($filled, array_flip(['name', 'description'])));
        $this->assertSame('el', $filled['filter']['conditions'][0]['value']);
        $this->assertNull($broken);
        $this->assertStringStartsWith('Error: ', $bad);
        $this->assertSame('draft', $rule['saved']);
        $this->assertSame('draft', Rules::get($rule['rule'])['status'], 'it does nothing until switched on');
        $this->assertSame(adminUrl('Rules/edit/') . $rule['rule'], $rule['link']);
        $this->assertIsInt($rule['wouldHaveComeIn7Days']);
        $this->assertFalse($anonymous['available']);
    }

    /**
     * Ask is offered the audience tools where their tables are, each knowing who asks, and why's
     * answer is marked as a person's data.
     */
    #[DataProvider('databases')]
    public function testAskIsOfferedThemWhereTheyAreInstalled(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);

        // Act
        $names = array_map(static fn ($tool): string => $tool->name(), Analyst::mcpTools($this->admin()));
        $tools = Analyst::tools($this->ids['dan']);

        // Assert
        foreach (['audience_segments', 'audience_why', 'audience_draft', 'audience_rules', 'audience_experiments'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertArrayHasKey('personal', (array) ($tools['audience_why']['tags'] ?? []), 'why names a person\'s values');
    }

    /**
     * Where a table is missing a tool says so instead of failing: the rules' tools without the rules
     * tables, why and drafts of a rule likewise; a nested group is explained as a group.
     */
    #[DataProvider('databases')]
    public function testMissingTablesAreSaidAndGroupsExplained(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $admin = $this->admin();
        $either = Segments::create('Greek or Bob', ['conditions' => [['match' => 'any', 'conditions' => [
            ['attribute' => 'language', 'op' => 'is', 'value' => 'el'], ['attribute' => 'usertype', 'op' => 'is', 'value' => 2]]]]]);
        $grouped = (new AudienceWhyTool($admin))->execute(['person' => $this->ids['bob'], 'segment' => $either]);
        foreach (['pramnos.rule_log', 'pramnos.rule_runs', 'rule_versions', 'rules'] as $table) {
            $this->db->schema()->dropTableIfExists($table);
        }
        $this->probeChanged($this->db->type);
        \Pramnos\Audience\Installed::reset();

        // Act
        $rules = (new AudienceRulesTool($admin))->execute([]);
        $why   = (new AudienceWhyTool($admin))->execute(['person' => $this->ids['bob'], 'rule' => 1]);
        $draft = (new AudienceDraftTool($admin))->execute(['kind' => 'rule', 'name' => 'x']);
        $names = array_map(static fn ($tool): string => $tool->name(), Analyst::mcpTools($admin));

        // Assert
        $this->assertSame(['any', true], [$grouped['conditions'][0]['match'], $grouped['conditions'][0]['met']]);
        $this->assertCount(2, $grouped['conditions'][0]['conditions']);
        $this->assertFalse($rules['available']);
        $this->assertFalse($why['available']);
        $this->assertFalse($draft['available']);
        $this->assertNotContains('audience_rules', $names, 'not offered where it has nothing to read');
        $this->assertContains('audience_segments', $names);
    }
}
