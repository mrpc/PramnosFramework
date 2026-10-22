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
}
