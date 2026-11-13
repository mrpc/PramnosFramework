<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Inbox;
use Pramnos\Conversations\Messages;

/**
 * The views above the conversations on each backend: the threads somebody takes part in, what
 * happened to them, every unread message, what they sent and what they kept — each only from the
 * conversations they are in, never their own doing reported back to them.
 */
#[CoversClass(Inbox::class)]
#[CoversClass(Messages::class)]
class InboxTest extends TestCase
{
    use ConversationsProbe;

    /**
     * A thread shows the replies new since the reader's own last word there; activity is mentions
     * (an `@all` in a channel they are in, not one they never joined), reactions to what they wrote
     * by others, and replies in their threads — a reply that mentions them told once, as the mention.
     */
    #[DataProvider('databases')]
    public function testThreadsAndActivity(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $other   = Conversations::create('channel', 'other', [$nikos], $eleni);
        $root    = Messages::post($general, $maria, 'Ποιος κοιτάζει το τιμολόγιο;');
        $mine    = Messages::post($general, $eleni, 'Εγώ', ['thread' => $root]);
        $reply   = Messages::post($general, $maria, 'Ευχαριστώ <@' . $eleni . '>', ['thread' => $root]);
        Messages::post($general, $maria, 'Και κάτι ακόμα', ['thread' => $root]);
        Messages::post($general, $maria, '@all συνάντηση στις 10');
        Messages::post($other, $nikos, '@all μόνο για όσους είναι εδώ');
        Messages::react($mine, $maria, '👍');
        Messages::react($mine, $eleni, '🎉');
        Messages::post($general, $eleni, 'Μια δική μου αναφορά <@' . $eleni . '>');
        $wrong   = Messages::post($general, $maria, 'Λάθος άτομο <@' . $eleni . '>');
        Messages::delete($wrong, $this->user('maria'));
        Conversations::leave($other, $eleni);

        // Act
        $threads  = Inbox::threads($eleni);
        $activity = Inbox::activity($eleni);
        $nobody   = Inbox::activity($this->ids['tasos']);

        // Assert
        $this->assertSame([$root], array_map(static fn (array $t): int => $t['root']['id'], $threads));
        $this->assertSame([3, 2], [$threads[0]['replies'], $threads[0]['newReplies']], 'two from maria since eleni\'s reply');
        $this->assertSame('Και κάτι ακόμα', $threads[0]['latest']['body']);
        $this->assertSame('#general', $threads[0]['root']['conversation']);
        $kinds = array_map(static fn (array $a): string => $a['kind'] . ':' . $a['message']['id'], $activity);
        $this->assertContains('mention:' . $reply, $kinds, 'the reply that mentions her, as the mention');
        $this->assertNotContains('reply:' . $reply, $kinds, 'and not twice');
        $this->assertContains('reaction:' . $mine, $kinds);
        $this->assertSame(1, count(array_filter($activity, static fn (array $a): bool => $a['kind'] === 'reaction')), 'not her own reaction');
        $this->assertSame('Μαρία Κ.', array_values(array_filter($activity, static fn (array $a): bool => $a['kind'] === 'reaction'))[0]['byName']);
        $this->assertCount(1, array_filter($activity, static fn (array $a): bool => str_contains($a['message']['body'], '@all')),
            'the @all where she is, not in the channel she left');
        $this->assertSame([], array_filter($activity, static fn (array $a): bool => $a['message']['authorId'] === $eleni && $a['kind'] !== 'reaction'),
            'what she wrote herself is not activity');
        $this->assertNotContains('mention:' . $wrong, $kinds, 'a deleted message is nothing that happened');
        $this->assertSame([], $nobody, 'somebody in no conversation');
        $this->assertSame([], Messages::many([]));
        $this->assertSame([], Inbox::threads($this->ids['tasos']));
    }

    /**
     * Unread is per conversation, oldest first, never the reader's own, never a muted one; sent is
     * the reader's own newest first; a message kept for later comes back until it is let go, and
     * only while its conversation can still be read.
     */
    #[DataProvider('databases')]
    public function testUnreadsSentAndSaved(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $quiet   = Conversations::create('channel', 'quiet', [$maria], $eleni);
        $secret  = Conversations::create('private', 'secret', [$maria], $eleni);
        // Writing reads up to what one wrote: hers comes first.
        Messages::post($general, $eleni, 'δικό μου');
        $a       = Messages::post($general, $maria, 'πρώτο');
        $b       = Messages::post($general, $maria, 'δεύτερο');
        Messages::post($quiet, $maria, 'σε σίγαση');
        Conversations::mute($quiet, $eleni);
        $kept    = Messages::post($secret, $maria, 'κράτα το');

        // Act
        $unreads = Inbox::unreads($eleni);
        $sent    = Inbox::sent($eleni);
        Inbox::save($a, $eleni);
        Inbox::save($a, $eleni);
        Inbox::save($kept, $eleni);
        $saved   = array_column(Inbox::saved($eleni), 'id');
        $among   = Inbox::savedIds($eleni, [$a, $b]);
        Inbox::save($a, $eleni, false);
        Conversations::leave($secret, $eleni);
        $after   = array_column(Inbox::saved($eleni), 'id');

        // Assert
        $byId = array_column($unreads, null, 'id');
        $this->assertEqualsCanonicalizing([$general, $secret], array_keys($byId), 'not the muted one');
        $this->assertSame([$a, $b], array_column($byId[$general]['messages'], 'id'), 'oldest first, not her own');
        $this->assertSame('#general', $byId[$general]['title']);
        $this->assertSame(['δικό μου'], array_column($sent, 'body'), 'only what she wrote');
        $this->assertSame([$kept, $a], $saved, 'kept once, the newest kept first');
        $this->assertSame([$a], $among);
        $this->assertSame([], $after, 'let go, and a closed one she left is not readable');
        $this->assertSame([], Inbox::savedIds($eleni, []));
        $this->assertSame([], Inbox::sent($this->ids['tasos']));
    }

    /**
     * A quiet source — a reminder a bot posts — is not conversation (FW-456): unread leaves it out
     * and does not count it, a conversation with only it unread is not listed, and activity leaves
     * it out too — it stays in its conversation. Without the setting, it is a message like any other.
     */
    #[DataProvider('databases')]
    public function testQuietSources(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'quiet_sources' => ['todo']];
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general  = Conversations::create('channel', 'general', [$maria], $eleni);
        $bot      = Conversations::create('channel', 'reminders', [$maria], $eleni);
        Messages::post($general, $eleni, 'δικό μου');
        Messages::post($bot, $eleni, 'δικό μου');
        $said     = Messages::post($general, $maria, 'γεια');
        $todo     = Messages::post($general, $maria, 'Υπενθύμιση: γάλα', ['source' => 'todo', 'externalid' => 'todo:1']);
        $only     = Messages::post($bot, $maria, 'Υπενθύμιση: ψωμί', ['source' => 'todo', 'externalid' => 'todo:2']);

        // Act
        $unreads  = array_column(Inbox::unreads($eleni), null, 'id');
        $activity = Inbox::activity($eleni);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $plain    = array_column(Inbox::unreads($eleni), null, 'id');

        // Assert
        $this->assertSame([$general], array_keys($unreads), 'a conversation with only a reminder unread is not listed');
        $this->assertSame([$said], array_column($unreads[$general]['messages'], 'id'));
        $this->assertSame(1, $unreads[$general]['unread'], 'the reminder is not counted');
        $this->assertSame([], array_intersect([$todo, $only], array_map(static fn (array $a): int => $a['message']['id'], $activity)), 'not activity');
        $this->assertSame([], array_filter(array_column($activity, 'kind'), static fn (string $k): bool => $k === 'todo'), 'no kind of its own');
        $this->assertSame(2, $plain[$general]['unread'], 'without the setting, a message like any other');
        $this->assertArrayHasKey($bot, $plain);
        $this->assertSame([], Inbox::quietSources(), 'none set');
    }

    /**
     * When somebody last saw their activity is kept on the server (FW-489), so every device agrees:
     * what came since is unseen, for a badge; seeing it clears the count; a later moment never
     * moves back; nothing seen yet, everything is unseen.
     */
    #[DataProvider('databases')]
    public function testActivitySeenIsKeptOnTheServer(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], Conversations::db());
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        Messages::post($general, $maria, 'Γεια <@' . $eleni . '>');
        Messages::post($general, $maria, 'Και πάλι <@' . $eleni . '>');

        // Act
        $before = Inbox::unseenActivity($eleni);
        Inbox::sawActivity($eleni, time() + 5);
        \Pramnos\User\User::clearUserCache();
        $after  = Inbox::unseenActivity($eleni);
        Inbox::sawActivity($eleni, 1000);
        \Pramnos\User\User::clearUserCache();
        $kept   = Inbox::activitySeen($eleni);

        // Assert
        $this->assertSame(2, $before, 'nothing seen yet: both mentions unseen');
        $this->assertSame(0, $after, 'seen: none');
        $this->assertGreaterThan(1000, $kept, 'never moved back');
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $eleni)->where('fieldname', Inbox::ACTIVITY_SEEN)->delete();
    }

    /**
     * The badge counts what is still unread (FW-493): a mention read in its conversation — the
     * read point past it — is not counted, though the activity was never opened; one still unread
     * is; a reaction arriving after the conversation's read point counts until the activity is
     * seen (FW-497 gives it a read point of its own — {@see testAReactionIsNotCountedOnceTheConversationWasReadAfterIt});
     * seen, nothing is. The activity itself still lists all of them.
     */
    #[DataProvider('databases')]
    public function testTheBadgeLeavesOutWhatWasReadInItsConversation(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], Conversations::db());
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $mine    = Messages::post($general, $eleni, 'Καλημέρα');
        $first   = Messages::post($general, $maria, 'Γεια <@' . $eleni . '>');
        $second  = Messages::post($general, $maria, 'Και πάλι <@' . $eleni . '>');

        // Act
        Conversations::markRead($general, $eleni, $first);
        $oneRead  = Inbox::unseenActivity($eleni);
        Conversations::markRead($general, $eleni, $second);
        $allRead  = Inbox::unseenActivity($eleni);
        Messages::react($mine, $maria, '👍');
        // `readat` is unix-second, and the reaction above can land in the very same second as the
        // markRead() just before it — pushed a second ahead so the two are never a tie.
        Conversations::db()->queryBuilder()->table('chat.conversation_reactions')->where('messageid', $mine)->where('participantid', $maria)
            ->update(['createdat' => time() + 1]);
        $reaction = Inbox::unseenActivity($eleni);
        $listed   = count(Inbox::activity($eleni));
        Inbox::sawActivity($eleni, time() + 5);
        \Pramnos\User\User::clearUserCache();
        $seen     = Inbox::unseenActivity($eleni);

        // Assert
        $this->assertSame(1, $oneRead, 'the mention read in the channel is not counted; the unread one is');
        $this->assertSame(0, $allRead, 'both read in the channel: none, the activity never opened');
        $this->assertSame(1, $reaction, 'a reaction after the conversation was read: still counted until the activity is seen');
        $this->assertSame(3, $listed, 'the activity still lists everything');
        $this->assertSame(0, $seen);
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#userdetails')->where('userid', $eleni)->where('fieldname', Inbox::ACTIVITY_SEEN)->delete();
    }

    /**
     * FW-497: a reaction has a read point too — `conversation_members.readat`, set by
     * `markRead()` every time it runs, whatever it does to `lastreadid`. A reaction that arrives
     * while the reader is already caught up on every message moves `lastreadid` nowhere, and
     * used to count for ever because nothing else marked it read; now `markRead()` — called with
     * the same, unmoved, last message — still bumps `readat`, and the reaction stops counting.
     * One that arrives *after* that read point still counts, same as before.
     */
    #[DataProvider('databases')]
    public function testAReactionIsNotCountedOnceTheConversationWasReadAfterIt(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], Conversations::db());
        $db      = Conversations::db();
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $mine    = Messages::post($general, $eleni, 'Καλημέρα');
        // `time()` alone, a second apart at best, is not reliable ordering for a test that cares
        // which of two events came first — every timestamp below is set by hand instead.
        $readAt = static function (int $at) use ($db, $general, $eleni): void {
            $db->queryBuilder()->table('chat.conversation_members')->where('conversationid', $general)
                ->where('participanttype', 'user')->where('participantid', $eleni)->update(['readat' => $at]);
        };
        $reactAt = static function (int $at) use ($db, $mine, $maria): void {
            $db->queryBuilder()->table('chat.conversation_reactions')->where('messageid', $mine)->where('participantid', $maria)
                ->update(['createdat' => $at]);
        };

        // Act — read first (T1000); the reaction arrives after it (T2000), while eleni is already
        // caught up on every message: lastreadid does not move
        Conversations::markRead($general, $eleni, $mine);
        $readAt(1000);
        Messages::react($mine, $maria, '👍');
        $reactAt(2000);
        $beforeMarkRead = Inbox::unseenActivity($eleni);
        // markRead() again, with the same last message: lastreadid is unchanged, readat moves past
        // the reaction (T3000)
        Conversations::markRead($general, $eleni, $mine);
        $readAt(3000);
        $afterMarkRead = Inbox::unseenActivity($eleni);
        // A second reaction, after that read point (T4000): still counts
        Messages::react($mine, $maria, '🎉');
        $db->queryBuilder()->table('chat.conversation_reactions')->where('messageid', $mine)->where('participantid', $maria)
            ->where('emoji', '🎉')->update(['createdat' => 4000]);
        $afterNewReaction = Inbox::unseenActivity($eleni);

        // Assert
        $this->assertSame(1, $beforeMarkRead, 'arrived after the last real read: counted');
        $this->assertSame(0, $afterMarkRead, 'markRead() moved the read point past it, though lastreadid itself did not move');
        $this->assertSame(1, $afterNewReaction, 'a reaction after the new read point still counts');
    }
}
