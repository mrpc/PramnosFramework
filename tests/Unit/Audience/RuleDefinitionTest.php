<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\Rules\Actions;
use Pramnos\Audience\Rules\Definition;

/**
 * A rule's definition is checked when it is saved, so a saved rule can run: every refusal says
 * where, the shape is filled in, and what it reads — segments, events — can be asked.
 */
#[CoversClass(Definition::class)]
#[CoversClass(Actions::class)]
class RuleDefinitionTest extends TestCase
{
    /** An action step. */
    private static function act(?string $next = null): array
    {
        return ['type' => 'action', 'action' => 'group_add', 'params' => ['group' => 1], 'next' => $next];
    }

    /** @return array<string, array{mixed, string}> */
    public static function refused(): array
    {
        $event = ['type' => 'event', 'event' => 'x'];

        return [
            'not json'          => ['{nope', 'The rule is not valid JSON.'],
            'no trigger'        => [['steps' => []], 'trigger: one of event, segment_entered, segment_left, schedule, once.'],
            'unnamed event'     => [['trigger' => ['type' => 'event', 'event' => ' ']], 'trigger: an event is named, in at most 100 characters.'],
            'segment id'        => [['trigger' => ['type' => 'segment_entered', 'segment' => 'vip']], 'trigger: a segment, by id.'],
            'bad cron'          => [['trigger' => ['type' => 'schedule', 'cron' => 'often']], 'trigger: a schedule is a cron expression'],
            'step name'         => [['trigger' => $event, 'steps' => ['a b' => self::act()]], 'steps: a step is named with letters, digits, . _ - (a b).'],
            'step type'         => [['trigger' => $event, 'steps' => ['a' => ['type' => 'jump']]], 'steps.a: a step is one of action, wait, wait_event, branch, split.'],
            'no action'         => [['trigger' => $event, 'steps' => ['a' => ['type' => 'action', 'action' => 'teleport']]], 'steps.a: no action teleport.'],
            'short wait'        => [['trigger' => $event, 'steps' => ['a' => ['type' => 'wait', 'seconds' => 5]]], 'steps.a: a wait is a minute or more, in seconds.'],
            'no start'          => [['trigger' => $event, 'start' => 'z', 'steps' => ['a' => self::act()]], 'start: no step z.'],
            'no next'           => [['trigger' => $event, 'steps' => ['a' => self::act('z')]], 'steps.a: no step z.'],
            'loop'              => [['trigger' => $event, 'steps' => ['a' => self::act('b'), 'b' => self::act('a')]], 'a step cannot lead back to itself.'],
            'branch status'     => [['trigger' => $event, 'steps' => ['a' => ['type' => 'branch', 'message' => ['step' => 'a', 'status' => 'read']]]], 'steps.a: a message was opened, clicked, delivered.'],
            'branch on wait'    => [['trigger' => $event, 'steps' => ['w' => ['type' => 'wait', 'seconds' => 60], 'b' => ['type' => 'branch', 'message' => ['step' => 'w', 'status' => 'opened']]]], 'steps.b: a branch on a message names an action step.'],
            'branch filter'     => [['trigger' => $event, 'steps' => ['a' => ['type' => 'branch', 'filter' => ['conditions' => [['attribute' => 'nope', 'op' => 'is']]]]]], 'steps.a: filter.0: no attribute nope.'],
            'split one'         => [['trigger' => $event, 'steps' => ['s' => ['type' => 'split', 'variants' => [['key' => 'A', 'share' => 100]]]]], 'steps.s: a split has two variants or more, their shares adding up to 100.'],
            'split share'       => [['trigger' => $event, 'steps' => ['s' => ['type' => 'split', 'variants' => [['key' => 'A', 'share' => 0], ['share' => 100]]]]], 'steps.s.variants.0: a variant has a key and a share of at least 1.'],
            'entry'             => [['trigger' => $event, 'entry' => 'sometimes'], 'entry: first or every.'],
            'who'               => [['trigger' => $event, 'who' => ['conditions' => [['attribute' => 'nope', 'op' => 'is']]]], 'who: filter.0: no attribute nope.'],
            'goal'              => [['trigger' => $event, 'goal' => ['event' => 'paid', 'days' => 0]], 'goal: an event within a number of days.'],
            'hours'             => [['trigger' => $event, 'hours' => ['from' => '9', 'to' => '17:00']], 'hours: from and to as HH:MM.'],
            'caps'              => [['trigger' => $event, 'caps' => ['email' => ['max' => 0, 'days' => 1]]], 'caps.email: at most a number of messages in a number of days.'],
        ];
    }

    /** Each malformed definition is refused with where it is wrong. */
    #[DataProvider('refused')]
    public function testAMalformedDefinitionIsRefused(mixed $definition, string $message): void
    {
        // Arrange
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        // Act
        Definition::check($definition);

        // Assert — the expectation above.
    }

    /**
     * A definition is filled in — the first step starts, entry defaults to first, empty filters,
     * no goal — and says what it reads and listens for.
     */
    public function testADefinitionIsShapedAndReadable(): void
    {
        // Arrange
        $definition = Definition::check((string) json_encode([
            'trigger' => ['type' => 'segment_left', 'segment' => '4'],
            'who'     => ['conditions' => [['segment' => 5]]],
            'exit'    => ['conditions' => [['segment' => 6]]],
            'steps'   => [
                'w'  => ['type' => 'wait_event', 'event' => 'paid', 'seconds' => '3600', 'next' => 'b', 'timeout' => ''],
                'b'  => ['type' => 'branch', 'filter' => ['conditions' => [['segment' => 7]]], 'yes' => 's', 'no' => null],
                's'  => ['type' => 'split', 'variants' => [['share' => 30, 'next' => 'a'], ['share' => 70]]],
                'a'  => self::act(),
            ],
            'goal'    => ['event' => 'subscribed', 'days' => 10],
            'consent' => '3',
            'hours'   => ['from' => '22:00', 'to' => '06:00'],
            'caps'    => ['email' => ['max' => '2', 'days' => '7']],
        ]));

        // Act
        $segments = Definition::segments($definition);
        $events   = Definition::events($definition);
        $once     = Definition::check(['trigger' => ['type' => 'once']]);

        // Assert
        $this->assertSame('w', $definition['start']);
        $this->assertSame('first', $definition['entry']);
        $this->assertNull($definition['steps']['w']['timeout']);
        $this->assertSame(['A', 'B'], array_column($definition['steps']['s']['variants'], 'key'));
        $this->assertSame([5, 6, 7, 4], $segments);
        $this->assertSame(['segment.left', 'paid', 'subscribed'], $events);
        $this->assertSame(3, $definition['consent']);
        $this->assertSame(['email' => ['max' => 2, 'days' => 7]], $definition['caps']);
        $this->assertSame(['a', null], Definition::exits($definition['steps']['s']) + [1 => null]);
        $this->assertNull($once['start']);
        $this->assertSame([], Definition::events($once));
        $this->assertSame(['match' => 'all', 'conditions' => []], $once['who']);
        $this->assertNull($once['goal']);
    }

    /**
     * The action registry: the framework's actions with labels and channels, an application's
     * action declared — or replacing one — and forgotten.
     */
    public function testActionsAreDeclared(): void
    {
        // Arrange
        Actions::define('sms', 'SMS', ['text' => ['label' => 'Text', 'type' => 'textarea']], static fn (): array => ['status' => 'sent'], 'sms');

        // Act
        $all = Actions::all();
        Actions::reset();

        // Assert
        $this->assertSame(['email', 'bell', 'push', 'site_message', 'group_add', 'group_remove', 'attribute', 'notify_admin', 'webhook', 'sms'], array_keys($all));
        $this->assertSame('sms', $all['sms']['channel']);
        $this->assertSame('email', $all['email']['channel']);
        $this->assertNull($all['webhook']['channel']);
        $this->assertNull(Actions::get('sms'));
    }
}
