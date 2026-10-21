<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Rules as Screen;
use Pramnos\Audience\Rules\Engine;
use Pramnos\Audience\Rules\Rules;
use Pramnos\Html\RuleCanvas;
use Pramnos\Html\RuleEditor;

/**
 * `/admin/Rules` on MySQL, prefixed MySQL and PostgreSQL: the list with how each rule is doing,
 * a rule's boxes with their counts and the tools to try it, the editor, and the writes — in every
 * theme.
 */
#[CoversClass(Screen::class)]
#[CoversClass(RuleCanvas::class)]
#[CoversClass(RuleEditor::class)]
class RulesScreenTest extends TestCase
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

    /** A rule with every kind of step, run once for bob and cara. */
    private function ruleWithRuns(): int
    {
        $this->fakeQueue();
        $id = Rules::create('Welcome', [
            'trigger' => ['type' => 'event', 'event' => 'signed.up'],
            'who'     => ['conditions' => [['attribute' => 'usertype', 'op' => 'is', 'value' => 2]]],
            'start'   => 'tag',
            'steps'   => [
                'tag'   => ['type' => 'action', 'action' => 'group_add', 'params' => ['group' => $this->groups['Press']], 'next' => 'greek'],
                'greek' => ['type' => 'branch', 'filter' => ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]], 'yes' => 'ab', 'no' => 'wait'],
                'ab'    => ['type' => 'split', 'variants' => [['key' => 'A', 'share' => 50, 'next' => 'card'], ['key' => 'B', 'share' => 50, 'next' => 'card']]],
                'card'  => ['type' => 'action', 'action' => 'site_message', 'params' => ['kind' => 'card', 'title' => 'Hi'], 'next' => null],
                'wait'  => ['type' => 'wait_event', 'event' => 'profile.done', 'seconds' => 86400, 'next' => null, 'timeout' => null],
            ],
            'goal' => ['event' => 'payment.made', 'days' => 10],
            'exit' => ['conditions' => [['attribute' => 'active', 'op' => 'is', 'value' => 5]]],
        ], 2);
        Rules::setStatus($id, 'active', 2);
        Engine::onEvent('signed.up', $this->ids['bob']);
        Engine::onEvent('signed.up', $this->ids['cara']);
        $this->work(Engine::$queue, static fn (array $t): bool => $t['delay'] > 0);

        return $id;
    }

    /** Every theme's rendering of one view. @return array<string, string> */
    private function everyTheme(string $file, array $vars): array
    {
        $html = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $html[$theme] = $this->render($theme, $file, $vars);
        }

        return $html;
    }

    /**
     * The list says each rule's status and trigger in words, how many came in this week, how many
     * are on their way and how many reached the goal.
     */
    #[DataProvider('databases')]
    public function testTheListSaysHowEachRuleIsDoing(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->ruleWithRuns();
        $this->signIn();
        $screen = $this->screen(Screen::class);
        $this->request('GET');

        // Act
        $screen->display();
        $vars = (array) $screen->view;
        $html = $this->everyTheme('rules/rules.html.php', $vars);

        // Assert
        $row = $vars['rules'][0];
        $this->assertSame(['Welcome', 'active', 'signed.up happens', 2, 1, 0], [$row['name'], $row['status'], $row['trigger'], $row['entered'], $row['running'], $row['goals']]);
        foreach ($html as $theme => $page) {
            $this->assertStringContainsString('Welcome', $page, $theme);
            $this->assertStringContainsString('signed.up happens', $page, $theme);
        }
    }

    /**
     * A rule's page draws its boxes with how many went through each — a branch's ways side by side,
     * a step two ways reach drawn once — and answers the testing tools; in every theme.
     */
    #[DataProvider('databases')]
    public function testARulesPageDrawsItAndAnswersTheTools(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = $this->ruleWithRuns();
        $definition = Rules::get($id)['definition'];
        Rules::update($id, ['definition' => ['who' => ['conditions' => []]] + $definition], 2);
        $this->signIn();
        $screen = $this->screen(Screen::class);

        // Act
        $this->request('GET', $id, ['person' => (string) $this->ids['anna'], 'simulate' => '7', 'diff' => '1,2']);
        $screen->view();
        $vars = (array) $screen->view;
        $html = $this->everyTheme('rules/view.html.php', $vars);
        $this->request('GET', $id, ['diff' => '1,9']);
        $screen->view();
        $noDiff = $screen->view->diff;
        $this->request('GET', 424242);
        $gone = $screen->view();

        // Assert
        $canvas = $vars['canvas'];
        $this->assertStringContainsString('data-step="tag"', $canvas);
        $this->assertStringContainsString('2 passed', $canvas, 'both went through the first step');
        $this->assertStringContainsString('pf-rule-branches', $canvas);
        $this->assertStringContainsString('go to 4. Show a message on the site', $canvas, 'the second variant leads where the first already drew, named by its place, never its id');
        $this->assertStringContainsString('goal: payment.made within 10 days', $canvas);
        $this->assertStringContainsString('up to 1 day', $canvas);
        $this->assertTrue($vars['why']['enters'], 'version 2 takes everybody in');
        $this->assertSame(7, $vars['simulated']['days']);
        $this->assertSame(['before' => 2, 'after' => 3, 'in' => 1, 'out' => 0, 'from' => 1, 'to' => 2], $vars['diff']);
        $this->assertSame(['card'], $vars['actionSteps'], 'only messages can be tried on oneself');
        $this->assertNotEmpty($vars['log']);
        $this->assertNull($noDiff);
        $this->assertNull($gone);
        foreach ($html as $theme => $page) {
            $this->assertStringContainsString('Would come in.', $page, $theme);
            $this->assertStringContainsString('would have come in, in 7 days', $page, $theme);
            $this->assertStringContainsString('v1 → v2: 3 now instead of 2; +1 in, −0 out.', $page, $theme);
            $this->assertStringContainsString('Rules/testsend/', $page, $theme);
            $this->assertStringContainsString('compare with v1', $page, $theme);
        }
    }

    /**
     * The editor draws the definition with who and the exit in the audience builder; saving puts
     * the three back together — a new rule as a draft, a change as a new version — and a definition
     * that does not check goes back to the editor with what is wrong.
     */
    #[DataProvider('databases')]
    public function testTheEditorSavesDraftsAndVersions(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->signIn();
        $screen = $this->screen(Screen::class);
        $definition = json_encode(['trigger' => ['type' => 'once'], 'steps' => ['b' => ['type' => 'action', 'action' => 'bell', 'params' => ['subject' => 'Hi'], 'next' => null]]]);
        $who = json_encode(['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);

        // Act
        $this->request('POST', null, [], ['name' => 'Hello', 'definition' => $definition, 'who' => $who, 'exit' => '']);
        $screen->save();
        $id = Rules::all()[0]['id'];
        $this->request('POST', null, [], ['ruleid' => (string) $id, 'name' => 'Hello', 'definition' => $definition, 'who' => '{"conditions":[]}']);
        $screen->save();
        $this->request('POST', null, [], ['ruleid' => (string) $id, 'name' => 'Hello', 'definition' => '{"trigger":{"type":"never"}}']);
        $screen->save();
        $this->request('POST', null, [], ['ruleid' => '424242', 'name' => 'x']);
        $screen->save();
        $this->request('GET', $id);
        $screen->edit();
        $forms = $this->everyTheme('rules/edit.html.php', (array) $screen->view);
        $this->request('GET');
        $screen->edit();
        $blank = $this->render('tailwind', 'rules/edit.html.php', (array) $screen->view);
        $this->request('GET', 424242);
        $missing = $screen->edit();

        // Assert
        $this->assertSame([
            ['message', 'Saved as a draft.'],
            ['message', 'Saved as version 2.'],
            ['error', 'trigger: one of event, segment_entered, segment_left, schedule, once.'],
            ['error', 'That rule no longer exists.'],
            ['error', 'That rule no longer exists.'],
        ], $screen->flash);
        $this->assertSame([], Rules::get($id)['definition']['who']['conditions']);
        $this->assertSame('language', Rules::version($id, 1)['who']['conditions'][0]['attribute']);
        $this->assertNull($missing);
        foreach ($forms as $theme => $page) {
            $this->assertStringContainsString('data-pf-rule-editor', $page, $theme);
            $this->assertStringContainsString('name="ruleid"', $page, $theme);
            $this->assertStringContainsString('name="who"', $page, $theme);
            $this->assertStringContainsString('pf-rules.js', $page, $theme);
        }
        $this->assertStringNotContainsString('name="ruleid"', $blank);
        $this->assertSame('{"x":1}', RuleEditor::combine(['definition' => '{"x":1}', 'who' => 'nope']));
        $this->assertSame('nope', RuleEditor::combine(['definition' => 'nope']));
    }

    /**
     * Switching, a test send and deleting: each with what happened in the flash; a running rule is
     * not deleted; a test send of a step that is not an action is refused.
     */
    #[DataProvider('databases')]
    public function testStatusTestSendAndDelete(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = $this->ruleWithRuns();
        $this->signIn(98, $this->ids['anna']);
        $screen = $this->screen(Screen::class);

        // Act
        $this->request('POST', $id, [], ['step' => 'card', 'person' => (string) $this->ids['cara']]);
        $screen->testsend();
        $this->request('POST', $id, [], ['step' => 'greek', 'person' => (string) $this->ids['cara']]);
        $screen->testsend();
        $this->request('POST', $id, [], ['step' => 'tag', 'person' => '424242']);
        $screen->testsend();
        $this->request('POST', $id);
        $screen->delete();
        $this->request('POST', $id, [], ['status' => 'on']);
        $screen->status();
        $this->request('POST', $id, [], ['status' => 'paused']);
        $screen->status();
        $this->request('POST', $id);
        $screen->delete();

        // Assert
        $this->assertSame(['message', 'Test sent to you.'], $screen->flash[0]);
        $this->assertSame(['error', "Rule {$id} has no action step greek."], $screen->flash[1]);
        $this->assertSame(['error', 'Only a message step can be sent as a test.'], $screen->flash[2], 'a group change would be real');
        $this->assertSame(['error', 'Pause the rule before deleting it.'], $screen->flash[3]);
        $this->assertSame(['error', 'A rule is draft, shadow, active, paused.'], $screen->flash[4]);
        $this->assertSame(['message', 'The rule is paused.'], $screen->flash[5]);
        $this->assertSame(['message', 'Rule deleted.'], $screen->flash[6]);
        $this->assertSame('Hi', \Pramnos\Audience\SiteMessages::pending($this->ids['anna'])[0]['title'] ?? null, 'the test reached the one who asked');
        $this->assertNull(Rules::get($id));
    }

    /**
     * A test send whose action skips says why; below the floor nothing is read or written.
     */
    #[DataProvider('databases')]
    public function testASkippedTestAndTheFloor(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $id = Rules::create('Mail', ['trigger' => ['type' => 'once'], 'steps' => ['m' => ['type' => 'action', 'action' => 'email', 'params' => ['template' => 'none'], 'next' => null]]], 2);
        $this->signIn();
        $screen = $this->screen(Screen::class);
        $this->request('POST', $id, [], ['step' => 'm', 'person' => (string) $this->ids['cara']]);

        // Act
        $screen->testsend();
        $this->signIn(1);
        $below = $this->screen(Screen::class);
        $answers = [$below->display(), $below->view(), $below->edit()];
        $below->save();
        $below->status();
        $below->testsend();
        $below->delete();

        // Assert
        $this->assertSame('error', $screen->flash[0][0]);
        $this->assertStringContainsString('no template none', $screen->flash[0][1]);
        $this->assertSame([null, null, null], $answers);
        $this->assertCount(7, $below->redirects);
        $this->assertNotNull(Rules::get($id));
    }

    /**
     * Every trigger and step reads in words on the canvas: leaving a segment, a schedule, once, a
     * plain wait, a branch on a message; durations as days, hours or minutes; a segment that is
     * gone by its id.
     */
    #[DataProvider('databases')]
    public function testTheCanvasSaysEveryKindInWords(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $segment = \Pramnos\Audience\Segments::create('Dormant', ['conditions' => []]);
        $steps = [
            'm' => ['type' => 'action', 'action' => 'bell', 'params' => ['subject' => 'Hi'], 'next' => 'w'],
            'w' => ['type' => 'wait', 'seconds' => 7200, 'next' => 'b'],
            'b' => ['type' => 'branch', 'message' => ['step' => 'm', 'status' => 'opened'], 'yes' => 'x', 'no' => null],
            'x' => ['type' => 'wait', 'seconds' => 300, 'next' => null],
        ];
        $check = static fn (array $trigger): array => \Pramnos\Audience\Rules\Definition::check(['trigger' => $trigger, 'steps' => $steps]);

        // Act
        $left     = RuleCanvas::render($check(['type' => 'segment_left', 'segment' => $segment]));
        $gone     = RuleCanvas::trigger(['type' => 'segment_entered', 'segment' => 424242]);
        $schedule = RuleCanvas::trigger(['type' => 'schedule', 'cron' => '0 10 * * 1']);
        $once     = RuleCanvas::trigger(['type' => 'once']);

        // Assert
        $this->assertStringContainsString('leaves the segment Dormant', $left);
        $this->assertStringContainsString('2 hours', $left);
        $this->assertStringContainsString('5 minutes', $left);
        $this->assertStringContainsString('the message of 1. Notify on the site was opened', $left, 'a step named as the administrator reads it');
        $this->assertSame('1 hour', RuleCanvas::duration(3600), 'one in the singular');
        $this->assertStringContainsString('Wait', $left);
        $this->assertSame('enters the segment #424242', $gone);
        $this->assertStringStartsWith('on schedule: ', $schedule);
        $this->assertSame('once, when switched on', $once);
        $this->assertSame('3 days', RuleCanvas::duration(259200));
    }
}
