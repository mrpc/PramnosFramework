<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\ConversationNotification;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\Notify;
use Pramnos\Conversations\NotifyTask;

/**
 * Who a message notifies — the bell and a push — on each backend.
 *
 * Why it matters: a conversation nobody is told of is read only by whoever happens to have it open.
 * So a direct message tells the other person, a mention tells who it names, a reply tells who is in
 * its thread — and each person's own setting for the conversation, a mute, and the application's
 * pause are kept, or the notifications teach people to switch them off. Its writer is never told,
 * and "Notify anyway" passes a pause once.
 */
#[CoversClass(Notify::class)]
#[CoversClass(ConversationNotification::class)]
#[CoversClass(NotifyTask::class)]
class NotifyTest extends TestCase
{
    use ConversationsProbe;

    /** Who a message mentions, with how, as its event said: what `post()` queues with it. */
    private function mentioned(int $messageId): array
    {
        $out = [];
        foreach ($this->dispatched as [$event, $e]) {
            if ($event === 'conversation.mention.received' && $e->messageId === $messageId) {
                $out[$e->personId] = $e->kind;
            }
        }

        return $out;
    }

    /** Who a message notifies, with why. */
    private function recipients(int $messageId): array
    {
        $out = Notify::recipients($messageId, $this->mentioned($messageId));
        ksort($out);

        return $out;
    }

    /** @return list<array<string, mixed>> A person's bell, by message, its data decoded */
    private function bell(int $person): array
    {
        $rows = $this->db->queryBuilder()->table('notifications')->where('notifiable_id', $person)->getAllOrFail();

        $bell = array_map(static fn (array $r): array => ['type' => $r['type'], 'read' => $r['read_at'] !== null] + (array) json_decode((string) $r['data'], true), $rows);
        // By the message they link to: two written in one second have no order of their own.
        usort($bell, static fn (array $a, array $b): int => strnatcmp((string) $a['url'], (string) $b['url']));

        return $bell;
    }

    /**
     * A direct message notifies the other person, in their bell, with a link to the message and what
     * it is about; never its writer. The queue's task does the same as `run()`; reading the
     * conversation settles the bell.
     */
    #[DataProvider('databases')]
    public function testADirectMessageNotifiesTheOther(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'page' => '/chat'];
        $dm = Conversations::create('dm', null, [$maria], $eleni);

        // Act
        $id    = Messages::post($dm, $eleni, 'Πάρε με τηλέφωνο');
        $told  = Notify::run($id, $this->mentioned($id));
        $task  = Messages::post($dm, $eleni, 'Ή γράψε μου');
        // As the worker runs it.
        $controller = new \Pramnos\Application\Controller();
        $item       = new \Pramnos\Queue\QueueItem($controller);
        $item->payload = (string) json_encode(['message' => $task, 'mentioned' => []]);
        $worker     = new NotifyTask($controller);
        $worker->execute($item);
        $before = $this->bell($maria);
        Notify::settle($dm, $maria);

        // Assert
        $this->assertSame([$maria], $told, 'the other person, not the writer');
        $this->assertSame([], $this->bell($eleni));
        $this->assertCount(2, $before, 'one for each message; the task sends as run() does');
        $this->assertSame(ConversationNotification::class, $before[0]['type']);
        $this->assertSame('Ελένη Σ.: Πάρε με τηλέφωνο', $before[0]['message'], 'a dm is titled with its writer');
        $this->assertSame('/chat#/c/' . $dm . '/m/' . $id, $before[0]['url'], 'opens the conversation at the message');
        $this->assertSame(['conversation:' . $dm], $before[0]['about']);
        $this->assertSame('Notify the people message ' . $task . ' is for', $worker->getDescription($item));
        $this->assertFalse($before[0]['read']);
        $this->assertSame([true, true], array_column($this->bell($maria), 'read'), 'reading the conversation reads its notifications');
    }

    /**
     * In a channel each member's setting decides: `all` everything, `mentions` only what names them,
     * `none` nothing; a muted channel only `<@id>`; a reply only in its thread tells only who is in
     * the thread, and one also sent to the channel tells the channel.
     */
    #[DataProvider('databases')]
    public function testEachMembersSettingDecides(string $type, string $host, int $port, string $user): void
    {
        // Arrange — eleni writes in ops, where maria, nikos and root are
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos, $root] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos'], $this->ids['root']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $ops = Conversations::create('channel', 'ops', [$maria, $nikos, $root], $eleni);
        Conversations::notify($ops, $maria, 'mentions');
        Conversations::mute($ops, $nikos);
        Conversations::notify($ops, $root, 'none');

        // Act
        $plain   = Messages::post($ops, $eleni, 'Καλημέρα');
        $named   = Messages::post($ops, $eleni, '<@' . $maria . '> <@' . $nikos . '> <@' . $root . '> δείτε το');
        $channel = Messages::post($ops, $eleni, '@channel συνάντηση');
        $reply   = Messages::post($ops, $maria, 'Το είδα', ['thread' => $named]);
        $again   = Messages::post($ops, $eleni, 'Ευχαριστώ', ['thread' => $named]);
        $loud    = Messages::post($ops, $nikos, 'Κι εγώ', ['thread' => $named, 'broadcast' => true]);

        // Assert
        $this->assertSame([], $this->recipients($plain), 'maria only mentions, nikos muted, root none');
        $this->assertSame([$maria => 'mention', $nikos => 'mention'], $this->recipients($named), 'a mute still lets <@id> through; none is none');
        $this->assertSame([$maria => 'mention'], $this->recipients($channel), 'a muted channel keeps its @channel to itself');
        $this->assertSame([$eleni => 'thread'], $this->recipients($reply), 'the thread\'s first writer');
        $this->assertSame([$maria => 'thread'], $this->recipients($again), 'who replied is in the thread; nikos is muted');
        $this->assertSame([$eleni => 'thread', $maria => 'thread'], $this->recipients($loud), 'also sent to the channel');
        Conversations::notify($ops, $maria, 'all');
        $this->assertSame([$maria => 'message'], $this->recipients(Messages::post($ops, $eleni, 'Νέα')), 'all is everything');
        $this->assertSame([], Notify::recipients(999999, []), 'no such message, nobody');
    }

    /**
     * The application's `quiet` hook pauses somebody: they are not told, and the message's writer
     * sees them as paused; "Notify anyway" tells them once, past the pause — only by the writer,
     * only somebody it addressed and paused.
     */
    #[DataProvider('databases')]
    public function testAPauseAndNotifyAnyway(string $type, string $host, int $port, string $user): void
    {
        // Arrange — maria's notifications paused
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'quiet' => fn (array $ids): array => [$maria]];
        $dm  = Conversations::create('dm', null, [$maria], $eleni);
        $ops = Conversations::create('channel', 'ops', [$maria, $nikos], $eleni);

        // Act
        $id      = Messages::post($dm, $eleni, 'Επείγον');
        $told    = Notify::run($id, []);
        $paused  = Notify::paused($id);
        $inOps   = Notify::paused(Messages::post($ops, $eleni, '<@' . $maria . '> και <@' . $nikos . '>'));
        Notify::nudge($id, $eleni, $maria);
        $refusals = [];
        foreach ([[$id, $eleni, $maria], [$id, $maria, $maria], [$id, $eleni, $nikos], [999999, $eleni, $maria]] as [$message, $by, $person]) {
            try {
                Notify::nudge($message, $by, $person);
                $refusals[] = 'sent';
            } catch (\InvalidArgumentException $e) {
                $refusals[] = 'refused';
            }
        }

        // Assert
        $this->assertSame([], $told, 'paused: not told');
        $this->assertSame([$maria], $paused, 'the writer sees who is paused');
        $this->assertSame([$maria], $inOps, 'of those it names');
        $bell = $this->bell($maria);
        $this->assertCount(1, $bell, 'the nudge, once');
        $this->assertSame(['conversation:' . $dm, 'nudge:' . $id], $bell[0]['about']);
        $this->assertSame(['refused', 'refused', 'refused', 'refused'], $refusals, 'twice, by another, somebody not paused, no message');
    }

    /**
     * What a push says: a channel's name as its title, its writer and first words — mentions by
     * name — one tag for the conversation; and it goes as a push only where the person has a
     * browser subscribed, push is on and `notify_via` allows it.
     */
    #[DataProvider('databases')]
    public function testWhatAPushSays(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $ops = Conversations::create('channel', 'ops', [$maria, $nikos], $eleni);
        $id  = Messages::post($ops, $eleni, '**Προσοχή** <@' . $maria . '>, ο server');
        $file = Messages::post($ops, $eleni, '', ['card' => ['title' => 'x']]);
        \Pramnos\Push\Subscriptions::store($maria, ['endpoint' => 'https://push.test/1', 'keys' => ['p256dh' => 'k', 'auth' => 'a']]);
        $notification = new ConversationNotification($id, 'mention');
        $mariaUser    = $this->user('maria');

        // Act
        $push     = $notification->toPush($mariaUser);
        $via      = $notification->via($mariaUser);
        $noSub    = $notification->via($this->user('nikos'));
        Application::getInstance()->applicationInfo['conversations']['notify_via'] = ['database'];
        $bellOnly = $notification->via($mariaUser);

        // Assert
        $this->assertSame('#ops', $push['title']);
        $this->assertSame('Ελένη Σ.: Προσοχή @Μαρία Κ., ο server', $push['body'], 'marks out, mentions by name');
        $this->assertSame('conversation-' . $ops, $push['tag'], 'a conversation\'s pushes replace one another');
        $this->assertSame(['conversationId' => $ops, 'messageId' => $id], $push['data']);
        $this->assertSame(['database', 'push'], $via);
        $this->assertSame(['database'], $noSub, 'no browser subscribed');
        $this->assertSame(['database'], $bellOnly);
        $this->assertSame('Ελένη Σ.: Sent a file', (new ConversationNotification($file, 'message'))->toPush($mariaUser)['body'], 'nothing written: what it is');
    }
}
