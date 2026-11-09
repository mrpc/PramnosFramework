<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Console\Commands\ConversationsSendScheduled;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\Scheduled;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Messages written to be sent later, on each backend.
 *
 * Why it matters: a scheduled message is a promise made to the people it is for. It has to go at
 * its time — not before, which reads as a mistake, and exactly once, which a minute's scheduler
 * running twice must not break — as its writer, and not at all for somebody who has left the
 * conversation, who would be speaking in a room they are no longer in.
 */
#[CoversClass(Scheduled::class)]
#[CoversClass(ConversationsSendScheduled::class)]
class ScheduledTest extends TestCase
{
    use ConversationsProbe;

    /** @return list<string> The bodies of a conversation's messages, oldest first */
    private function bodies(int $conversationId): array
    {
        return array_column(Messages::list($conversationId), 'body');
    }

    /**
     * A scheduled message is sent at its time and not before, once, as its writer — a reply in its
     * thread too — and is gone from its writer's list once sent.
     */
    #[DataProvider('databases')]
    public function testSentAtItsTimeOnce(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $ops  = Conversations::create('channel', 'ops', [$maria], $eleni);
        $root = Messages::post($ops, $maria, 'Ερώτηση');
        $at   = time() + 3600;
        $id   = Scheduled::create($ops, $eleni, 'Καλημέρα σε όλους', $at);
        $reply = Scheduled::create($ops, $eleni, 'Η απάντηση', $at + 60, ['thread' => $root, 'broadcast' => true]);

        // Act
        $early = Scheduled::due($at - 1);
        $mine  = Scheduled::mine($eleni);
        $first = Scheduled::due($at);
        $again = Scheduled::due($at + 30);
        $later = Scheduled::due($at + 60);

        // Assert
        $this->assertSame(0, $early, 'not before its time');
        $this->assertSame([$id, $reply], array_column($mine, 'id'), 'soonest first');
        $this->assertSame(['body' => 'Καλημέρα σε όλους', 'thread' => null, 'at' => $at, 'error' => null],
            array_intersect_key($mine[0], ['body' => 1, 'at' => 1, 'thread' => 1, 'error' => 1]));
        $this->assertSame([1, 0, 1], [$first, $again, $later], 'once each');
        $this->assertSame(['Ερώτηση', 'Καλημέρα σε όλους', 'Η απάντηση'], $this->bodies($ops), 'the reply also sent to the conversation');
        $sent = Messages::list($ops);
        $this->assertSame([$eleni, $eleni], [$sent[1]['authorId'], $sent[2]['authorId']], 'as its writer');
        $this->assertSame($root, $sent[2]['threadRootId']);
        $this->assertSame([], Scheduled::mine($eleni), 'gone once sent');
    }

    /**
     * Its writer left the conversation: it is not sent, and stays with why for them to see. Back in,
     * changing it tries again; the scheduler no longer picks it up while it has an error.
     */
    #[DataProvider('databases')]
    public function testNotSentForSomebodyWhoLeft(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $ops = Conversations::create('channel', 'ops', [$maria], $eleni);
        $at  = time() + 600;
        $id  = Scheduled::create($ops, $maria, 'Θα έρθω αργότερα', $at);
        Conversations::leave($ops, $maria);

        // Act
        $sent    = Scheduled::due($at);
        $waiting = Scheduled::mine($maria)[0];
        $retry   = Scheduled::due($at + 60);
        Conversations::join($ops, $maria);
        Scheduled::change($id, $maria, 'Θα έρθω στις 10', $at + 120);
        $changed = Scheduled::mine($maria)[0];
        $back    = Scheduled::due($at + 120);

        // Assert
        $this->assertSame([0, 0], [$sent, $retry], 'not sent, and not tried every minute after');
        $this->assertSame('You are no longer in that conversation.', $waiting['error']);
        $this->assertSame(['Θα έρθω στις 10', $at + 120, null], [$changed['body'], $changed['at'], $changed['error']], 'changed, and waiting again');
        $this->assertSame(1, $back);
        $this->assertSame(['Θα έρθω στις 10'], $this->bodies($ops));
    }

    /**
     * What is refused: a time not in the future, nothing in it, a conversation one is not in, a reply
     * to another conversation's message; changing or letting go of somebody else's. Letting go of
     * one's own removes it.
     */
    #[DataProvider('databases')]
    public function testRefusalsAndCancelling(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $ops   = Conversations::create('channel', 'ops', [$maria], $eleni);
        $other = Conversations::create('channel', 'other', [$maria], $eleni);
        $root  = Messages::post($other, $eleni, 'Αλλού');
        $at    = time() + 600;
        $id    = Scheduled::create($ops, $eleni, 'Αργότερα', $at);

        // Act
        $refused = [];
        foreach ([
            'past'     => fn () => Scheduled::create($ops, $eleni, 'Χθες', time() - 1),
            'empty'    => fn () => Scheduled::create($ops, $eleni, '  ', $at),
            'long'     => fn () => Scheduled::create($ops, $eleni, str_repeat('α', Messages::MAX_LENGTH + 1), $at),
            'outsider' => fn () => Scheduled::create($ops, $nikos, 'Γεια', $at),
            'thread'   => fn () => Scheduled::create($ops, $eleni, 'Απάντηση', $at, ['thread' => $root]),
            'changeToPast' => fn () => Scheduled::change($id, $eleni, null, time() - 5),
        ] as $case => $attempt) {
            try {
                $attempt();
                $refused[$case] = false;
            } catch (\InvalidArgumentException $e) {
                $refused[$case] = true;
            }
        }
        $notHers = [];
        foreach ([fn () => Scheduled::change($id, $maria, 'Δικό μου'), fn () => Scheduled::cancel($id, $maria)] as $attempt) {
            try {
                $attempt();
                $notHers[] = false;
            } catch (\DomainException $e) {
                $notHers[] = true;
            }
        }
        Scheduled::cancel($id, $eleni);

        // Assert
        $this->assertSame(['past' => true, 'empty' => true, 'long' => true, 'outsider' => true, 'thread' => true, 'changeToPast' => true], $refused);
        $this->assertSame([true, true], $notHers, 'somebody else\'s is as if it were not there');
        $this->assertSame([], Scheduled::mine($eleni), 'let go');
        $this->assertSame(0, Scheduled::due($at), 'and never sent');
    }

    /**
     * `conversations:send-scheduled` sends what is due and says how many — the framework runs it every
     * minute — and says so when there is no table.
     */
    #[DataProvider('databases')]
    public function testTheCommand(string $type, string $host, int $port, string $user): void
    {
        // Arrange — one due a second ago, written straight into the table as the minute after
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $ops = Conversations::create('channel', 'ops', [$maria], $eleni);
        $id  = Scheduled::create($ops, $eleni, 'Τώρα', time() + 60);
        $this->db->queryBuilder()->table('chat.conversation_scheduled')->where('scheduledid', $id)->update(['sendat' => time() - 1]);
        $tester = new CommandTester(new ConversationsSendScheduled());

        // Act
        $tester->execute([]);

        // Assert
        $this->assertSame("1 scheduled message(s) sent.\n0 automation(s) ran.", trim($tester->getDisplay()), 'the automations\' line too, none here');
        $this->assertSame(['Τώρα'], $this->bodies($ops));
        $this->assertContains('conversations:send-scheduled', \Pramnos\Scheduling\FrameworkSchedule::commands(), 'run by the framework');
        $this->db->schema()->dropTableIfExists('chat.conversation_scheduled');
        $this->probeChanged($this->db->type);
        $tester->execute([]);
        $this->assertSame('There is no conversation_scheduled table.', trim($tester->getDisplay()), 'an installation that has not migrated');
    }
}
