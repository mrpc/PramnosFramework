<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Automations;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Details;
use Pramnos\Conversations\Messages;
use Pramnos\Framework\Migrations\Conversations\CreateConversationAutomationsTable;

/**
 * A conversation's automations (`Automations`), on MySQL with and without a prefix and
 * PostgreSQL: a schedule on the installation's clock across a change of summer time, weekdays,
 * days of the week and a month's last day; a message repeated as the Automation, paused and
 * resumed; a welcome once per entry, in the conversation or to the newcomer alone; only its
 * managers manage them, a question only by somebody who may ask; the conversation's deletion
 * takes them; the screen's actions answer them.
 */
#[CoversClass(Automations::class)]
#[CoversClass(\Pramnos\Conversations\Events\MemberJoined::class)]
class AutomationsTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The request cleared, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        \Pramnos\Http\Request::setRawInput('');
        \Pramnos\Broadcasting\Testing\FakeDriver::restore();
        $this->probeTearDown();
    }

    /** The conversations on, for usertype 30 up, with the automations' table. */
    private function setUpConversations(string $type, string $host, int $port, string $user): void
    {
        $this->boot($type, $host, $port, $user);
        foreach (['2026_11_09_000013_add_image_to_conversations', '2026_11_09_000014_add_details_to_conversations', '2026_11_10_000001_create_conversation_automations_table'] as $file) {
            require_once ROOT . '/database/migrations/framework/conversations/' . $file . '.php';
        }
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Conversations\AddImageToConversations::class,
            \Pramnos\Framework\Migrations\Conversations\AddDetailsToConversations::class, CreateConversationAutomationsTable::class], $this->db);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features']      = ['conversations'];
        \Pramnos\Broadcasting\Testing\FakeDriver::swap();
    }

    /** The newest message of a conversation. @return array<string, mixed>|null */
    private function last(int $conversation): ?array
    {
        $list = Messages::list($conversation, ['limit' => 1]);

        return $list === [] ? null : $list[0];
    }

    /**
     * Every day at 09:00 in Athens stays 09:00 when summer time ends — 25 hours between the two
     * runs; weekdays skip the weekend; a week's days are kept; the 31st of a month is February's
     * last day.
     */
    public function testTheScheduleKeepsTheClock(): void
    {
        // Arrange
        $athens   = new \DateTimeZone('Europe/Athens');
        $saturday = (new \DateTimeImmutable('2026-10-24 10:00', $athens))->getTimestamp();
        $local    = static fn (int $t): string => (new \DateTimeImmutable('@' . $t))->setTimezone($athens)->format('Y-m-d D H:i');

        // Act
        $sat     = Automations::next(['every' => 'day', 'time' => '09:00'], $saturday - 3 * 3600, $athens);
        $sunday  = Automations::next(['every' => 'day', 'time' => '09:00'], $saturday, $athens);
        $monday  = Automations::next(['every' => 'day', 'time' => '09:00'], $sunday, $athens);
        $weekday = Automations::next(['every' => 'weekdays', 'time' => '09:30'], $saturday, $athens);
        $thu     = Automations::next(['every' => 'week', 'days' => [4, 2], 'time' => '17:00'], $saturday, $athens);
        $feb     = Automations::next(['every' => 'month', 'day' => 31, 'time' => '10:00'], (new \DateTimeImmutable('2027-02-01', $athens))->getTimestamp(), $athens);
        $later   = Automations::next(['every' => 'day', 'time' => '11:00'], $saturday, $athens);

        // Assert
        $this->assertSame('2026-10-25 Sun 09:00', $local($sunday), 'the day summer time ends');
        $this->assertSame(25 * 3600, $sunday - $sat, 'Saturday to Sunday: a day of 25 hours, the clock still 09:00');
        $this->assertSame(24 * 3600, $monday - $sunday);
        $this->assertSame('2026-10-26 Mon 09:30', $local($weekday), 'not on the weekend');
        $this->assertSame('2026-10-27 Tue 17:00', $local($thu), 'the first of its days');
        $this->assertSame('2027-02-28 Sun 10:00', $local($feb), 'the 31st is the month\'s last day');
        $this->assertSame('2026-10-24 Sat 11:00', $local($later), 'later the same day');
    }

    /**
     * A repeated message is sent as the Automation when due and moves on to its next time; paused
     * it sends nothing; resumed it goes on from after now; one from its creator who left keeps
     * why; somebody who does not manage the conversation is refused; a schedule that is wrong says
     * what.
     */
    #[DataProvider('databases')]
    public function testARepeatedMessage(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->setUpConversations($type, $host, $port, $user);
        [$maria, $nikos] = [$this->ids['maria'], $this->ids['nikos']];
        $room = Conversations::create('channel', 'standup', [$nikos], $maria);
        Details::settings($room, ['tabsBy' => 'admins'], $maria);

        // Act
        $id    = Automations::add($room, $maria, ['kind' => 'repeat', 'body' => 'Stand-up!', 'every' => 'day', 'time' => '09:30']);
        $first = Automations::get($id)['nextAt'];
        $ran   = Automations::due($first);
        $sent  = $this->last($room);
        $moved = Automations::get($id)['nextAt'];
        Automations::pause($id, $maria);
        $paused = Automations::due($moved + 1);
        Automations::pause($id, $maria, false);
        $resumed = Automations::get($id);
        $mine = Automations::add($room, $maria, ['kind' => 'repeat', 'body' => 'From me', 'every' => 'weekdays', 'time' => '10:00', 'sender' => 'creator']);
        Conversations::leave($room, $maria);
        Automations::due(Automations::get($mine)['nextAt']);
        $refusals = [];
        foreach ([
            fn () => Automations::add($room, $nikos, ['kind' => 'repeat', 'body' => 'x', 'every' => 'day', 'time' => '09:00']),
            fn () => Automations::change($id, $nikos, ['body' => 'mine now']),
            fn () => Automations::add($room, $this->ids['root'], ['kind' => 'repeat', 'body' => 'x', 'every' => 'fortnight', 'time' => '09:00']),
            fn () => Automations::add($room, $this->ids['root'], ['kind' => 'repeat', 'body' => 'x', 'every' => 'day', 'time' => '9.30']),
            fn () => Automations::add($room, $this->ids['root'], ['kind' => 'repeat', 'body' => 'x', 'every' => 'week', 'time' => '09:00']),
            fn () => Automations::add($room, $this->ids['root'], ['kind' => 'repeat', 'body' => '', 'every' => 'day', 'time' => '09:00']),
            fn () => Automations::add($room, $this->ids['root'], ['kind' => 'poll', 'body' => 'x']),
        ] as $try) {
            try {
                $try();
                $refusals[] = 'none';
            } catch (\DomainException) {
                $refusals[] = 'domain';
            } catch (\InvalidArgumentException) {
                $refusals[] = 'invalid';
            }
        }

        // Assert
        $this->assertSame(1, $ran);
        $this->assertSame(['Stand-up!', 'Automation', null], [$sent['body'], $sent['card']['from'], $sent['authorId']]);
        $this->assertGreaterThan($first, $moved, 'moved on to its next time');
        $this->assertSame('09:30', date('H:i', $moved), 'at the same time of day');
        $this->assertSame(0, $paused, 'paused, nothing sent');
        $this->assertFalse($resumed['paused']);
        $this->assertGreaterThan(time(), $resumed['nextAt'], 'resumed from after now');
        $this->assertSame('Its creator is no longer in the conversation.', Automations::get($mine)['error']);
        $this->assertSame(['domain', 'domain', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid'], $refusals, 'root moderates everything; nikos manages nothing here');
    }

    /**
     * A welcome is sent each time somebody comes in — in the conversation mentioning them, or to
     * them alone — not again while they stay, again when they come back; the conversation's
     * deletion takes its automations; a question needs somebody who may ask the analyst.
     */
    #[DataProvider('databases')]
    public function testAWelcomeOncePerEntry(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->setUpConversations($type, $host, $port, $user);
        [$maria, $nikos] = [$this->ids['maria'], $this->ids['nikos']];
        $room = Conversations::create('channel', 'lobby', [], $maria);
        Automations::add($room, $maria, ['kind' => 'welcome', 'body' => 'Καλώς ήρθες!', 'to' => 'channel']);
        $alone = Automations::add($room, $maria, ['kind' => 'welcome', 'body' => 'Read the rules', 'to' => 'person']);
        Automations::add($room, $maria, ['kind' => 'welcome', 'body' => 'Ask me anything', 'to' => 'person', 'sender' => 'creator']);
        $joined = [];
        \Pramnos\Event\Event::listen(\Pramnos\Conversations\Events\MemberJoined::EVENT, static function (object $e) use (&$joined): void {
            $joined[] = $e->person;
        });
        $count = fn (): int => count(array_filter(Messages::list($room, ['limit' => 50]), static fn (array $m): bool => str_contains($m['body'], 'Καλώς ήρθες')));

        // Act
        Conversations::join($room, $nikos);
        $once = $this->last($room);
        Conversations::join($room, $nikos);
        $stayed = $count();
        Conversations::leave($room, $nikos);
        Conversations::join($room, $nikos);
        $back = $count();
        $own = \Pramnos\Conversations\SystemPost::conversation($nikos);
        $private = $own === null ? null : $this->last($own);
        $dm = Conversations::dmBetween($maria, $nikos);
        $direct = $dm === null ? [] : array_column(Messages::list($dm, ['limit' => 10]), 'body');
        Automations::pause($alone, $maria);
        $asks = null;
        try {
            Automations::add($room, $maria, ['kind' => 'ask', 'body' => 'Πόσες εγγραφές;', 'every' => 'day', 'time' => '08:00']);
        } catch (\InvalidArgumentException $e) {
            $asks = $e->getMessage();
        }
        $listed = Automations::of($room);
        Details::destroy($room, $this->user('root'));
        $left = Automations::of($room);

        // Assert
        $this->assertSame('<@' . $nikos . '> Καλώς ήρθες!', $once['body']);
        $this->assertSame([1, 2], [$stayed, $back], 'not again while in it, again when back');
        $this->assertSame([$nikos, $nikos], $joined, 'each entry told, not the join of somebody in it');
        $this->assertNotNull($private, 'to the newcomer alone, in their conversation with the system');
        $this->assertSame(['Read the rules', '#lobby'], [$private['body'], $private['card']['from']]);
        if (!\Pramnos\Conversations\Ask::canAsk($this->user('maria'))) {
            $this->assertSame('Only somebody who may ask the analyst sets it a question.', $asks);
        }
        $this->assertSame(['Ask me anything', 'Ask me anything'], $direct, 'from its creator, in a direct conversation, once per entry');
        $this->assertSame(['welcome', 'welcome', 'welcome'], array_column($listed, 'kind'));
        $this->assertSame([false, true, false], array_column($listed, 'paused'));
        $this->assertSame('channel', $listed[0]['to']);
        $this->assertSame([], $left, 'deleted with the conversation');
    }

    /**
     * The screen's actions: a manager adds, changes, pauses and deletes, each answering the list
     * and whether the reader manages it; a member who does not is refused with a 404.
     */
    #[DataProvider('databases')]
    public function testTheScreensActions(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->setUpConversations($type, $host, $port, $user);
        [$maria, $nikos] = [$this->ids['maria'], $this->ids['nikos']];
        $room = Conversations::create('channel', 'ops', [$nikos], $maria);
        Details::settings($room, ['tabsBy' => 'admins'], $maria);
        $api  = function (string $name): \Pramnos\Conversations\ConversationsApi {
            $api = new class extends \Pramnos\Conversations\ConversationsApi {
                /** Who asks, set by the test. */
                public ?object $asking = null;

                /** The test's account, not the API's credentials. */
                protected function user(): ?object
                {
                    return $this->asking;
                }
            };
            $api->asking = $this->user($name);

            return $api;
        };
        $body = static fn (array $b) => \Pramnos\Http\Request::setRawInput((string) json_encode($b));

        // Act
        $body(['kind' => 'repeat', 'body' => 'Weekly', 'every' => 'week', 'days' => '1,5', 'time' => '08:15']);
        $added = $api('maria')->call('POST', 'addautomation', $room);
        $id    = $added['data']['automations'][0]['id'];
        $body(['time' => '08:45']);
        $changed = $api('maria')->call('POST', 'changeautomation', $id);
        $body(['paused' => true]);
        $paused = $api('maria')->call('POST', 'pauseautomation', $id);
        $body([]);
        $read    = $api('nikos')->call('GET', 'automations', $room);
        $refused = $api('nikos')->call('POST', 'deleteautomation', $id);
        $deleted = $api('maria')->call('POST', 'deleteautomation', $id);

        // Assert
        $this->assertSame(200, $added['status']);
        $this->assertSame(['every' => 'week', 'time' => '08:15', 'days' => [1, 5]], $added['data']['automations'][0]['schedule']);
        $this->assertSame('08:45', $changed['data']['automations'][0]['schedule']['time']);
        $this->assertTrue($paused['data']['automations'][0]['paused']);
        $this->assertSame([200, false, 1], [$read['status'], $read['data']['manages'], count($read['data']['automations'])], 'a member reads them');
        $this->assertSame(404, $refused['status']);
        $this->assertSame([200, []], [$deleted['status'], $deleted['data']['automations']]);
    }
}
