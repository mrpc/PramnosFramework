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
     * A channel's member is notified by default of what concerns them, a direct conversation's of
     * everything. In a channel each member's setting decides: `all` everything, `mentions` only what names them,
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
        $default = Conversations::member($ops, $maria)['notify'];
        $dmDefault = Conversations::member(Conversations::create('dm', null, [$maria], $eleni), $maria)['notify'];
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
        $this->assertSame(['mentions', 'all'], [$default, $dmDefault], 'by default a channel what concerns one, a dm everything — as Slack');
        $this->assertSame([], $this->recipients($plain), 'maria only mentions, nikos muted, root none');
        $this->assertSame([$maria => 'mention', $nikos => 'mention'], $this->recipients($named), 'a mute still lets <@id> through; none is none');
        $this->assertSame([$maria => 'mention'], $this->recipients($channel), 'a muted channel keeps its @channel to itself');
        $this->assertSame([$eleni => 'thread'], $this->recipients($reply), 'the thread\'s first writer');
        $this->assertSame([$maria => 'thread'], $this->recipients($again), 'who replied is in the thread; nikos is muted');
        $this->assertSame([$eleni => 'thread', $maria => 'thread'], $this->recipients($loud), 'also sent to the channel');
        Conversations::notify($ops, $maria, 'all');
        $this->assertSame([$maria => 'message'], $this->recipients(Messages::post($ops, $eleni, 'Νέα')), 'all is everything');
        Conversations::notify($ops, $root, 'all');
        $this->assertSame([$eleni => 'thread', $maria => 'thread'], $this->recipients(Messages::post($ops, $nikos, 'Πάλι', ['thread' => $named])),
            'everything is not a thread one is not in');
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
        $this->assertSame([], Notify::paused(999999), 'no such message, nobody paused');
    }

    /**
     * What fails is logged and the rest goes on: somebody with no account is not notified, an application's own notification class that throws fails in its channel and not in
     * the conversation, and a bell that cannot be written or settled is no error of the conversation's.
     */
    #[DataProvider('databases')]
    public function testFailuresAreLoggedNotThrown(string $type, string $host, int $port, string $user): void
    {
        // Arrange — maria paused; nikos's account gone; an own class that cannot be built
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'quiet' => fn (array $ids): array => [$maria]];
        $ops = Conversations::create('channel', 'ops', [$maria, $nikos], $eleni);
        $id  = Messages::post($ops, $eleni, '<@' . $maria . '> <@' . $nikos . '>');
        $this->db->queryBuilder()->table('users')->where('userid', $nikos)->delete();

        // Act
        $told = Notify::run($id, $this->mentioned($id));
        // An own class that fails is the channel's to log.
        Application::getInstance()->applicationInfo['conversations']['notification'] = FailingNotification::class;
        $ownFails = Notify::run(Messages::post(Conversations::create('dm', null, [$eleni], $this->ids['root']), $this->ids['root'], 'Γεια'), []);
        $this->db->schema()->dropTableIfExists('notifications');
        $this->probeChanged($this->db->type);
        Notify::settle($ops, $maria);

        // Assert
        $this->assertSame([], $told, 'maria paused, nikos no longer an account');
        $this->assertSame([$eleni], $ownFails, 'told, though the class failed in its channel');
        $this->assertSame([], Notify::run($id, []), 'nobody, and no error, with no bell to write to');
    }

    /**
     * A queue worker runs as another application — the site's — whose configuration has no
     * `conversations` block: the settings are the administration's (`app/admin.php`), so its
     * `quiet` hook still pauses, and a notification opens where the request that wrote the message
     * said, carried in the task — not at this application's address.
     */
    #[DataProvider('databases')]
    public function testAWorkerRunAsAnotherApplication(string $type, string $host, int $port, string $user): void
    {
        // Arrange — the area's settings pause maria; the worker's application has none
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        Conversations::useArea(['admin' => ['prefix' => 'admin'], 'conversations' => ['usertype' => 30, 'quiet' => fn (array $ids): array => [$maria]]]);
        unset(Application::getInstance()->applicationInfo['conversations']);
        $ops = Conversations::create('channel', 'ops', [$maria, $nikos], $eleni);
        $id  = Messages::post($ops, $eleni, '<@' . $maria . '> <@' . $nikos . '> δείτε το');
        $controller = new \Pramnos\Application\Controller();
        $item       = new \Pramnos\Queue\QueueItem($controller);
        $item->payload = (string) json_encode(['message' => $id, 'mentioned' => $this->mentioned($id), 'page' => '/admin/Conversations']);

        // Act
        (new NotifyTask($controller))->execute($item);
        $page = Notify::page();
        Conversations::useArea(null);

        // Assert
        $this->assertSame([], $this->bell($maria), 'paused by the area\'s hook, in a worker with none of its own');
        $this->assertSame(['/admin/Conversations#/c/' . $ops . '/m/' . $id], array_column($this->bell($nikos), 'url'), 'where the writing request said');
        $this->assertStringEndsWith('admin/Conversations', $page, 'from a console process, the area\'s prefix');
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
        // FW-395: the client she is in, in front — it tells of the message itself, no push; behind,
        // or in front but not seen for two minutes — pushed.
        \Pramnos\Conversations\Clients::active($maria, 'tab-a', null, true);
        $inFront  = $notification->via($mariaUser);
        \Pramnos\Conversations\Clients::active($maria, 'tab-a', null, false);
        $behind   = $notification->via($mariaUser);
        \Pramnos\Conversations\Clients::active($maria, 'tab-a', null, true, false, time() - 120);
        $stale    = $notification->via($mariaUser);
        Application::getInstance()->applicationInfo['conversations']['notify_via'] = ['database'];
        $bellOnly = $notification->via($mariaUser);

        // Assert
        $this->assertSame($push, $notification->toPush($mariaUser), 'worked out once');
        $this->assertSame('#ops', $push['title']);
        $this->assertSame('Ελένη Σ.: Προσοχή @Μαρία Κ., ο server', $push['body'], 'marks out, mentions by name');
        $this->assertSame('conversation-' . $ops, $push['tag'], 'a conversation\'s pushes replace one another');
        $this->assertSame(['conversationId' => $ops, 'messageId' => $id], $push['data']);
        $this->assertSame('ifOpen', $push['silent'], 'quiet while a page is open to play its own sound');
        // Never kept from showing: a push the worker shows nothing for is counted against the
        // subscription, and another page in front is not the one told of the message.
        $this->assertArrayNotHasKey('unlessFocused', $push);
        $this->assertSame(['database'], $inFront, 'the client in front tells of it itself');
        $this->assertSame(['database', 'push'], $behind);
        $this->assertSame(['database', 'push'], $stale, 'in front, but not seen for two minutes');
        Application::getInstance()->applicationInfo['conversations']['push_silent'] = 'always';
        $always = (new ConversationNotification($id, 'mention'))->toPush($mariaUser)['silent'];
        Application::getInstance()->applicationInfo['conversations']['push_silent'] = 'never';
        $never = (new ConversationNotification($id, 'mention'))->toPush($mariaUser)['silent'];
        $this->assertSame([true, false], [$always, $never], 'conversations.push_silent');
        $this->assertSame(['database', 'push'], $via);
        $this->assertSame(['database'], $noSub, 'no browser subscribed');
        $this->assertSame(['database'], $bellOnly);
        $this->assertSame('Ελένη Σ.: Sent a file', (new ConversationNotification($file, 'message'))->toPush($mariaUser)['body'], 'nothing written: what it is');
    }

    /**
     * FW-495: with `push.hold_unopened` on, a device that still has an earlier conversation push
     * sitting there unopened is held off a second one — and let through again once it is opened,
     * once the person does anything on any client, or once they catch up on that conversation.
     * Off, or on a browser it does not apply to (`'mobile'` on a desktop user agent), nothing is
     * ever held — the state a `TaskReminder` or anything else that never implements `heldFor()`
     * is already in, by never being asked.
     */
    #[DataProvider('databases')]
    public function testTheHoldUnopenedRule(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $first   = Messages::post($general, $eleni, 'πρώτο');
        $notification = new ConversationNotification($first, 'message');
        $phone  = ['endpoint' => 'https://push.test/phone', 'endpoint_hash' => hash('sha256', 'https://push.test/phone'), 'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome Mobile'];
        $desktop = ['endpoint' => 'https://push.test/desktop', 'endpoint_hash' => hash('sha256', 'https://push.test/desktop'), 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome'];

        // Act & Assert — off by default: never held, on either device
        unset(Application::getInstance()->applicationInfo['push']);
        $this->assertFalse($notification->heldFor($phone, $maria), 'off by default');

        // A push to the phone, delivered, unopened
        Application::getInstance()->applicationInfo['push'] = ['hold_unopened' => 'mobile'];
        $pushId = \Pramnos\Push\Log::reserve($maria, $phone['endpoint_hash'], ['tag' => 'conversation-' . $general], ConversationNotification::class);
        \Pramnos\Push\Log::complete($pushId, 201);

        // Assert — the phone is held: the mode is 'mobile', and nothing of its own sits on the desktop yet
        $this->assertTrue($notification->heldFor($phone, $maria), 'an unopened conversation push sits on this phone');
        $this->assertFalse($notification->heldFor($desktop, $maria), 'nothing unresolved on this device yet');

        // An unopened push of the desktop's own: 'mobile' leaves it alone, 'all' holds it too
        $desktopPush = \Pramnos\Push\Log::reserve($maria, $desktop['endpoint_hash'], ['tag' => 'conversation-' . $general], ConversationNotification::class);
        \Pramnos\Push\Log::complete($desktopPush, 201);
        $this->assertFalse($notification->heldFor($desktop, $maria), '"mobile": a desktop user agent is never held');
        Application::getInstance()->applicationInfo['push']['hold_unopened'] = 'all';
        $this->assertTrue($notification->heldFor($desktop, $maria), '"all": every kind of device');
        Application::getInstance()->applicationInfo['push']['hold_unopened'] = 'mobile';
        \Pramnos\Push\Log::opened($desktopPush);

        // Unlocked by opening it
        \Pramnos\Push\Log::opened($pushId);
        $this->assertFalse($notification->heldFor($phone, $maria), 'opened: no longer held');

        // A second, unlocked instead by catching up on its own conversation — before any client
        // activity is recorded, so that unlock cannot be the one doing the clearing here.
        $pushId2 = \Pramnos\Push\Log::reserve($maria, $phone['endpoint_hash'], ['tag' => 'conversation-' . $general], ConversationNotification::class);
        \Pramnos\Push\Log::complete($pushId2, 201);
        $this->assertTrue($notification->heldFor($phone, $maria));
        Conversations::markRead($general, $maria, $first);
        $this->assertFalse($notification->heldFor($phone, $maria), 'caught up on the conversation it was about');

        // A third, in a second conversation: not cleared by having read the first one
        $other  = Conversations::create('channel', 'other', [$maria], $eleni);
        $second = Messages::post($other, $eleni, 'δεύτερο');
        $pushId3 = \Pramnos\Push\Log::reserve($maria, $phone['endpoint_hash'], ['tag' => 'conversation-' . $other], ConversationNotification::class);
        \Pramnos\Push\Log::complete($pushId3, 201);
        $this->assertTrue((new ConversationNotification($second, 'message'))->heldFor($phone, $maria), 'a different conversation: still held');

        // Last: unlocked by activity on any client — a future moment, so it cannot be mistaken
        // for coincidental same-second timing against the push just reserved above.
        \Pramnos\Conversations\Clients::active($maria, 'tab-a', now: time() + 3600);
        $this->assertFalse(
            (new ConversationNotification($second, 'message'))->heldFor($phone, $maria),
            'activity on any client since it was sent'
        );
        unset(Application::getInstance()->applicationInfo['push']);
    }
}

/** An application's own notification class whose message cannot be told. */
final class FailingNotification extends ConversationNotification
{
    /** @return array<string, mixed> Never: it fails */
    public function toDatabase(mixed $notifiable): array
    {
        throw new \RuntimeException('cannot be told');
    }
}
