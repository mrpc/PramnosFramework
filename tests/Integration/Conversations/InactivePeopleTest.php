<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\People;

/**
 * People who have not signed in for longer than `conversations.inactive_after_days`, on each
 * backend: not offered for a conversation unless asked for, not counted in `@all`, shown with when
 * they last signed in — and nothing of theirs removed, so signing in makes them active again.
 */
#[CoversClass(People::class)]
#[CoversClass(Messages::class)]
class InactivePeopleTest extends TestCase
{
    use ConversationsProbe;

    /**
     * With the setting off nobody is inactive; with it on, somebody neither signed in nor registered
     * within the days is — with when they last signed in, 0 for never — and a recent registration
     * keeps a new account that never signed in active.
     */
    #[DataProvider('databases')]
    public function testWhoIsInactive(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $long = time() - 400 * 86400;
        $this->age('nikos', $long, $long - 10);
        $this->age('maria', 0, $long);
        $this->age('eleni', 0, time());
        $ids = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];

        // Act
        $off = People::inactive($ids);
        Application::getInstance()->applicationInfo['conversations']['inactive_after_days'] = 90;
        $on  = People::inactive($ids);

        // Assert
        $this->assertSame([], $off, 'the setting off: nobody');
        $this->assertSame([$this->ids['maria'] => 0, $this->ids['nikos'] => $long], $on, 'eleni registered today and never signed in: active');
        $this->assertSame([], People::inactive([]));
        $this->assertSame(90, People::inactiveAfterDays());
    }

    /**
     * Somebody inactive is left out of a search unless inactive people are asked for or their whole
     * name or username is typed; `@all` does not count them, while naming them still does; and
     * signing in again makes them active, their memberships untouched.
     */
    #[DataProvider('databases')]
    public function testNotOfferedNotCountedActiveAgain(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'inactive_after_days' => 90];
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $long = time() - 400 * 86400;
        $this->age('nikos', $long, $long);
        $general = Conversations::create('channel', 'general', [$maria, $nikos], $eleni);

        // Act
        $plain   = array_column(People::search('ν'), 'id');
        $asked   = People::search('Νίκος', 20, true);
        $whole   = array_column(People::search('Νίκος Π.'), 'id');
        $byLogin = array_column(People::search('nikos'), 'id');
        $all     = Messages::post($general, $eleni, '@all καλημέρα');
        $named   = Messages::post($general, $eleni, 'Νίκο <@' . $nikos . '>');
        $mentionedAll   = $this->mentioned($all);
        $mentionedNamed = $this->mentioned($named);
        $this->age('nikos', time(), $long);
        $again   = array_column(People::search('Νίκ'), 'id');

        // Assert
        $this->assertNotContains($nikos, $plain, 'not offered');
        $this->assertSame([[$nikos, $long]], array_map(static fn (array $p): array => [$p['id'], $p['inactive']], $asked), 'asked for: with when he last signed in');
        $this->assertSame([$nikos], $whole, 'his whole name');
        $this->assertSame([$nikos], $byLogin, 'his username');
        $this->assertSame([$maria], $mentionedAll, '@all: not him');
        $this->assertSame([$nikos], $mentionedNamed, 'named: him');
        $this->assertSame([$nikos], $again, 'signed in: active again');
        $this->assertSame([$eleni, $maria, $nikos], Conversations::members($general), 'nobody was removed');
    }

    /** Set when somebody last signed in and registered. */
    private function age(string $name, int $lastlogin, int $regdate): void
    {
        $this->db->queryBuilder()->table('users')->where('userid', $this->ids[$name])->update(['lastlogin' => $lastlogin, 'regdate' => $regdate]);
    }

    /** Who a message's mention events told. @return list<int> */
    private function mentioned(int $message): array
    {
        $told = [];
        foreach ($this->dispatched as [$event, $e]) {
            if ($event === 'conversation.mention.received' && $e->messageId === $message) {
                $told[] = $e->personId;
            }
        }
        sort($told);

        return $told;
    }
}
