<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Access;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Events\ConversationCreated;
use Pramnos\Conversations\Events\MentionReceived;
use Pramnos\Conversations\Events\MessagePosted;
use Pramnos\Conversations\Files;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\People;

/**
 * The conversations engine on MySQL, prefixed MySQL and PostgreSQL with TimescaleDB: who may use
 * them, channels, direct and group conversations and who is in them; messages, threads, mentions,
 * edits, deletions, reactions, pins, reading and what is unread; an import written once.
 */
#[CoversClass(Access::class)]
#[CoversClass(Conversations::class)]
#[CoversClass(Messages::class)]
#[CoversClass(People::class)]
#[CoversClass(Files::class)]
#[CoversClass(MessagePosted::class)]
#[CoversClass(MentionReceived::class)]
#[CoversClass(ConversationCreated::class)]
class ConversationsTest extends TestCase
{
    use ConversationsProbe;

    /** Sales and the administrators may use them; a translator not; root reads them all. */
    private function team(): void
    {
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
    }

    /**
     * The conversations are an ability with a floor of their own: sales in, a translator out,
     * root reading everything; a deactivated account out at once.
     */
    #[DataProvider('databases')]
    public function testWhoMayUseThem(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();

        // Act
        $use = array_map(fn (string $n): bool => Access::canUse($this->user($n)), ['eleni' => 'eleni', 'nikos' => 'nikos', 'tasos' => 'tasos', 'root' => 'root']);
        $all = array_map(fn (string $n): bool => Access::readsAll($this->user($n)), ['eleni' => 'eleni', 'root' => 'root']);
        $off = $this->user('nikos');
        $off->active = 0;

        // Assert
        $this->assertSame(['eleni' => true, 'nikos' => true, 'tasos' => false, 'root' => true], $use);
        $this->assertSame(['eleni' => false, 'root' => true], $all);
        $this->assertFalse(Access::canUse($off), 'deactivated: out at once');
        $this->assertFalse(Access::canUse(null));
        unset(Application::getInstance()->applicationInfo['conversations']);
        $this->assertFalse(Access::canUse($this->user('nikos')), 'without a floor of its own, the administration\'s');
    }

    /**
     * Channels have a unique name; a dm is two people and the same two have one; a group is three
     * to nine; open ones are joined, closed ones added to by a member; a dm cannot be left; who
     * reads and who writes follows.
     */
    #[DataProvider('databases')]
    public function testConversationsAndWhoIsInThem(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        [$eleni, $maria, $nikos, $root] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos'], $this->ids['root']];

        // Act
        $general = Conversations::create('channel', 'Γενικά Θέματα', [], $eleni, 'Για όλους');
        $secret  = Conversations::create('private', 'finance', [$maria], $eleni);
        $dm      = Conversations::create('dm', null, [$maria], $eleni);
        $again   = Conversations::create('dm', null, [$eleni], $maria);
        $group   = Conversations::create('group', null, [$maria, $nikos], $eleni);
        Conversations::join($general, $nikos);
        Conversations::add($secret, $nikos, $maria);
        $refusals = [];
        foreach ([
            fn () => Conversations::create('channel', 'γενικά-θέματα', [], $eleni),
            fn () => Conversations::create('channel', ' # ', [], $eleni),
            fn () => Conversations::create('dm', null, [], $eleni),
            fn () => Conversations::create('group', null, [$maria], $eleni),
            fn () => Conversations::create('room', null),
            fn () => Conversations::join($secret, $root),
            fn () => Conversations::add($secret, $root, $root),
            fn () => Conversations::add($dm, $nikos, $eleni),
            fn () => Conversations::leave($dm, $eleni),
        ] as $refused) {
            try {
                $refused();
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }
        Conversations::leave($secret, $nikos);
        Conversations::notify($general, $nikos, 'mentions');
        Conversations::mute($general, $nikos);

        // Assert
        $this->assertSame('γενικά-θέματα', Conversations::get($general)['slug']);
        $this->assertSame($dm, $again, 'the same two have one');
        $this->assertSame([$eleni, $nikos], Conversations::members($general));
        $this->assertSame([$eleni, $maria], Conversations::members($secret), 'nikos left');
        $this->assertSame('owner', Conversations::member($general, $eleni)['role']);
        $this->assertSame(['mentions', true], [Conversations::member($general, $nikos)['notify'], Conversations::member($general, $nikos)['muted']]);
        $this->assertCount(9, $refusals);
        $this->assertSame('There is a channel called #γενικά-θέματα already.', $refusals[0]);
        $this->assertTrue(Conversations::canRead($general, $this->user('tasos')) === false, 'a translator reads nothing');
        $this->assertTrue(Conversations::canRead($general, $this->user('maria')), 'an open channel is read by the team');
        $this->assertFalse(Conversations::canRead($secret, $this->user('nikos')), 'a closed one by its members');
        $this->assertTrue(Conversations::canRead($secret, $this->user('root')), 'and root');
        $this->assertFalse(Conversations::canWrite($general, $this->user('maria')), 'writing takes joining');
        $this->assertTrue(Conversations::canWrite($group, $this->user('nikos')));
        Conversations::archive($group);
        $this->assertFalse(Conversations::canWrite($group, $this->user('nikos')), 'archived: read, not written');
        $this->assertSame([[$general, false, 2]], array_map(static fn (array $c): array => [$c['id'], $c['joined'], $c['memberCount']], Conversations::browse($maria)),
            'the open ones only, and whether she is in them');
        $this->assertSame(ConversationCreated::EVENT, $this->dispatched[0][0]);
        $this->expectExceptionMessage('Told of all of it, of mentions, or of nothing.');
        Conversations::notify($general, $nikos, 'sometimes');
    }

    /**
     * A message: its writer must be in the conversation; a reply counts on its root; the
     * conversation's newest moves; mentions are rows and events, `@all` for everybody but the
     * writer; an edit keeps what it said; a deletion keeps the place; reactions and pins; an import
     * is written once; references are kept for cards.
     */
    #[DataProvider('databases')]
    public function testMessagesThreadsMentionsAndTheRest(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $general = Conversations::create('channel', 'general', [$maria, $nikos], $eleni);

        // Act
        $first  = Messages::post($general, $eleni, 'Ο <@' . $this->ids['root'] . '> ζητά επιστροφή για #user:48211 — το βλέπει η <@' . $maria . '>;');
        $reply  = Messages::post($general, $maria, 'Το κοιτάζω **τώρα**', ['thread' => $first]);
        $all    = Messages::post($general, $nikos, '@all συνάντηση στις 3');
        $system = Messages::post($general, null, '', ['card' => ['title' => 'Πληρωμή', 'fields' => ['Ποσό' => '29,90 €']]]);
        $import = Messages::post($general, $eleni, 'από αλλού', ['source' => 'legacy', 'externalid' => '1700000000.000100', 'at' => 1700000000]);
        $twice  = Messages::post($general, $eleni, 'από αλλού', ['source' => 'legacy', 'externalid' => '1700000000.000100']);
        Messages::edit($reply, $maria, 'Το κοιτάζω **τώρα**, <@' . $eleni . '>');
        Messages::react($first, $nikos, ':eyes:');
        Messages::react($first, $maria, ':eyes:');
        Messages::react($first, $maria, '👍');
        Messages::react($first, $maria, '👍', false);
        Messages::pin($first, $eleni);
        Messages::delete($all, $this->user('nikos'));
        $refusals = [];
        foreach ([
            fn () => Messages::post($general, $this->ids['tasos'], 'μπαίνω;'),
            fn () => Messages::post($general, $eleni, '   '),
            fn () => Messages::post($general, $eleni, str_repeat('x', Messages::MAX_LENGTH + 1)),
            fn () => Messages::post($general, $eleni, 'απάντηση σε απάντηση', ['thread' => $reply]),
            fn () => Messages::edit($first, $maria, 'δεν είναι δικό μου'),
            fn () => Messages::delete($first, $this->user('maria')),
            fn () => Messages::react($first, $maria, ''),
            fn () => Messages::post(424242, $eleni, 'πουθενά'),
        ] as $refused) {
            try {
                $refused();
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }
        $call   = Messages::post($general, $maria, '<@' . $nikos . '> πάρε με τηλέφωνο');
        $top    = Messages::list($general);
        $thread = Messages::list($general, ['thread' => $first]);
        $after  = Messages::list($general, ['after' => $first]);
        $before = Messages::list($general, ['before' => $all]);
        $nikosSide = Conversations::forPerson($nikos);

        // Assert
        $this->assertSame($import, $twice, 'an import is written once');
        $this->assertSame([$first, $all, $system, $import, $call], array_column($top, 'id'), 'top level only, oldest first');
        $this->assertSame([$first, $reply], array_column($thread, 'id'));
        $this->assertSame([$reply, $all, $system, $import, $call], array_column($after, 'id'), 'what a reconnect missed, replies too');
        $this->assertSame([$first], array_column($before, 'id'));
        $byId = array_column($top, null, 'id');
        $this->assertSame([1, 'Μαρία Κ.'], [$byId[$first]['replyCount'], Messages::list($general, ['thread' => $first])[1]['author']]);
        $this->assertStringContainsString('@Μαρία Κ.', $byId[$first]['html']);
        $this->assertSame(['user:48211'], $byId[$first]['refs']);
        $this->assertSame([['emoji' => ':eyes:', 'count' => 2, 'people' => [$nikos, $maria]]], $byId[$first]['reactions']);
        $this->assertTrue($byId[$first]['pinned']);
        $this->assertSame([$first], array_column(Messages::pinned($general), 'id'));
        $this->assertSame(['', ''], [$byId[$all]['body'], $byId[$all]['html']], 'deleted: its place, nothing else');
        $this->assertNotNull($byId[$all]['deletedAt']);
        $this->assertSame(['system', null, 'Πληρωμή'], [$byId[$system]['authorType'], $byId[$system]['author'], $byId[$system]['card']['title']]);
        $this->assertSame(1700000000, $byId[$import]['at']);
        $this->assertSame(['Το κοιτάζω **τώρα**'], array_column(Messages::edits($reply), 'body'), 'what it said before');
        $this->assertNotNull(Messages::get($reply)['editedAt']);
        $mentions = array_values(array_filter($this->dispatched, static fn (array $d): bool => $d[0] === MentionReceived::EVENT));
        $this->assertSame([[$maria, 'person'], [$eleni, 'all'], [$maria, 'all'], [$nikos, 'person']], array_map(static fn (array $d): array => [$d[1]->personId, $d[1]->kind], $mentions),
            'root is no member and is not mentioned; @all is everybody but its writer');
        $posted = array_values(array_filter($this->dispatched, static fn (array $d): bool => $d[0] === MessagePosted::EVENT));
        $this->assertSame([$first, $reply], [$posted[0][1]->messageId, $posted[1][1]->messageId]);
        $this->assertSame($first, $posted[1][1]->threadRootId);
        $this->assertSame(MessagePosted::class, $posted[0][1]::class);
        $this->assertSame(['messageId' => $first, 'conversationId' => $general, 'authorId' => $eleni, 'threadRootId' => null], $posted[0][1]->jsonSerialize());
        $this->assertEquals($posted[0][1], MessagePosted::fromQueue($posted[0][1]->jsonSerialize()), 'it travels through the queue');
        $this->assertEquals($mentions[0][1], MentionReceived::fromQueue($mentions[0][1]->jsonSerialize()));
        $created = $this->dispatched[0][1];
        $this->assertEquals($created, ConversationCreated::fromQueue($created->jsonSerialize()));
        $this->assertCount(8, $refusals);
        $this->assertSame('Only its members write in a conversation.', $refusals[0]);
        $this->assertSame(['unread' => 3, 'mentions' => 1], ['unread' => $nikosSide[0]['unread'], 'mentions' => $nikosSide[0]['mentions']],
            'writing @all read what came before it; after it, the system\'s, the import and maria\'s call, which mentions him');
        Conversations::markRead($general, $nikos, $call);
        Conversations::markRead($general, $nikos, $first);
        $this->assertSame(0, Conversations::forPerson($nikos)[0]['unread'], 'read, never back');
        $this->assertSame([], Files::forMessages([]));
        $this->assertSame([], People::names([0]));
    }

    /**
     * History brought in from another system is written as it was, through the public API, with no
     * table touched by the application: a message found again by where it came from, never written
     * twice; when it was written and last changed; a writer with no account here shown by name as
     * somebody who left; a reaction dated when it was put, and nobody told of any of it.
     */
    #[DataProvider('databases')]
    public function testHistoryIsWrittenAsItWas(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $events  = count($this->dispatched);

        // Act
        $before = Messages::find('legacy', 'C1:100');
        $first  = Messages::post($general, $eleni, 'από παλιά', ['quiet' => true, 'source' => 'legacy', 'externalid' => 'C1:100', 'at' => 1700000000, 'editedAt' => 1700000600]);
        $again  = Messages::post($general, $eleni, 'από παλιά', ['quiet' => true, 'source' => 'legacy', 'externalid' => 'C1:100']);
        $gone   = Messages::post($general, null, 'έφυγε πια', ['quiet' => true, 'source' => 'legacy', 'externalid' => 'C1:200', 'authorName' => 'Κώστας Λ.']);
        Messages::react($first, $maria, ':tada:', true, 1700000300, true);
        Messages::react($first, $maria, ':tada:', true, 1700000400, true);
        $reaction = $this->db->queryBuilder()->table('conversation_reactions')->where('messageid', $first)->getAllOrFail();

        // Assert
        $this->assertNull($before);
        $this->assertSame([$first, $first, $gone], [Messages::find('legacy', 'C1:100'), $again, Messages::find('legacy', 'C1:200')], 'found by where it came from, written once');
        $this->assertNull(Messages::find('other', 'C1:100'), 'the source is part of it');
        $this->assertSame([1700000000, 1700000600], [Messages::get($first)['at'], Messages::get($first)['editedAt']]);
        $this->assertSame([null, ['from' => 'Κώστας Λ.', 'former' => true]], [Messages::get($gone)['authorId'], Messages::get($gone)['card']], 'by name, as somebody who left');
        $this->assertCount(1, $reaction, 'a reaction once');
        $this->assertSame([1700000300, ':tada:'], [(int) $reaction[0]['createdat'], $reaction[0]['emoji']]);
        $this->assertSame([], array_slice($this->dispatched, $events), 'nobody told');
    }

    /**
     * A conversation with somebody who has no account here — a colleague who left, brought in by
     * an import — keeps them by name: a dm of one account and them, found again by their id where
     * they came from; a group of accounts and them, three or more in all. They are in its title, they
     * never read or count, and a message of theirs carries their name. Nothing else keeps them.
     */
    #[DataProvider('databases')]
    public function testAConversationKeepsSomebodyWithNoAccount(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $kostas = ['name' => 'Κώστας Λ.', 'externalid' => 'slack:U123'];

        // Act
        $dm      = Conversations::create('dm', null, [], $eleni, '', [$kostas]);
        $again   = Conversations::create('dm', null, [], $eleni, '', [['name' => 'άλλο όνομα', 'externalid' => 'slack:U123']]);
        $byName  = Conversations::create('dm', null, [], $eleni, '', [['name' => 'Λένα']]);
        $group   = Conversations::create('group', null, [$maria], $eleni, '', [$kostas]);
        $said    = Messages::post($dm, null, 'γεια', ['quiet' => true, 'former' => 'slack:U123']);
        Messages::post($dm, $eleni, 'σημείωση');
        $refused = [];
        foreach ([fn () => Conversations::create('dm', null, [], null, '', [$kostas, ['name' => 'Λένα']]),
            fn () => Conversations::create('group', null, [], null, '', [$kostas, ['name' => 'Λένα'], ['name' => 'Νίκη']]),
            fn () => Conversations::create('group', null, [], $eleni, '', [$kostas]),
            fn () => Conversations::create('channel', 'gone', [], $eleni, '', [$kostas])] as $try) {
            try {
                $try();
            } catch (\InvalidArgumentException) {
                $refused[] = true;
            }
        }

        // Assert
        $this->assertSame($dm, $again, 'the same person by where they came from: the same dm');
        $this->assertSame($byName, Conversations::dmWithFormer($eleni, 'Λένα'), 'by name where there is no id');
        $this->assertNotSame($dm, $byName);
        $this->assertSame([$eleni], Conversations::members($dm), 'only accounts are members');
        $this->assertSame([[ 'former' => true, 'name' => 'Κώστας Λ.']], Conversations::formers([$dm])[$dm]);
        $this->assertSame('Ελένη Σ., Κώστας Λ.', Conversations::names([$dm])[$dm], 'named by everybody in it, as any dm');
        $this->assertStringContainsString('Κώστας Λ.', Conversations::names([$group])[$group], 'in a group\'s title too');
        $this->assertSame(['from' => 'Κώστας Λ.', 'former' => true], Messages::get($said)['card'], 'their message carries their name');
        $mine = array_values(array_filter(Conversations::forPerson($eleni), static fn (array $c): bool => $c['id'] === $dm))[0];
        $this->assertSame(0, $mine['unread'], 'the account reads and writes it, as notes');
        $this->assertCount(4, $refused, 'a dm of formers, a group with no account or too few people, a channel: refused');
    }

    /**
     * Somebody kept with no account turns out to have one: in a dm they become its second account,
     * read up to its newest message, their messages the account's — and the dm is merged into the
     * older one the two accounts already had; in a group they become a member and it stays a group;
     * where the account is in it already the former member just goes. A second call does nothing.
     */
    #[DataProvider('databases')]
    public function testAnAccountClaimsSomebodyKeptWithNoAccount(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $kostas  = ['name' => 'Κώστας Λ.', 'externalid' => 'slack:U123'];
        $before  = Conversations::create('dm', null, [$nikos], $eleni);
        Messages::post($before, $eleni, 'εδώ πρώτα');
        $dm      = Conversations::create('dm', null, [], $eleni, '', [$kostas]);
        $said    = Messages::post($dm, null, 'από το slack', ['quiet' => true, 'former' => 'slack:U123']);
        $mine    = Messages::post($dm, $eleni, 'απάντηση');
        $group   = Conversations::create('group', null, [], $maria, '', [$kostas, ['name' => 'Λένα']]);
        $inThere = Conversations::create('group', null, [$nikos, $eleni], $maria, '', [$kostas]);
        Messages::post($group, null, 'στην ομάδα', ['quiet' => true, 'former' => 'slack:U123']);

        // Act
        $done  = Conversations::claimFormer('slack:U123', $nikos, $eleni);
        $again = Conversations::claimFormer('slack:U123', $nikos, $eleni);

        // Assert
        $this->assertSame(['conversations' => 3, 'messages' => 2, 'merged' => 1], $done);
        $this->assertSame(['conversations' => 0, 'messages' => 0, 'merged' => 0], $again, 'nothing left to claim');
        $this->assertNull(Conversations::get($dm), 'the newer dm is merged into the older');
        $this->assertSame([$nikos, null], [Messages::get($said)['authorId'], Messages::get($said)['card']], 'their message is the account\'s');
        $this->assertSame([$before, $before], [Messages::get($said)['conversationId'], Messages::get($mine)['conversationId']]);
        $this->assertSame($before, Conversations::dmBetween($eleni, $nikos));
        $this->assertSame(0, array_values(array_filter(Conversations::forPerson($nikos), static fn (array $c): bool => $c['id'] === $before))[0]['unread'] ?? null,
            'read up to the newest: history is not unread');
        $this->assertSame('group', Conversations::get($group)['kind'], 'a group stays a group');
        $this->assertSame([$maria, $nikos], Conversations::members($group));
        $this->assertSame([['former' => true, 'name' => 'Λένα']], Conversations::formers([$group])[$group]);
        $this->assertArrayNotHasKey($inThere, Conversations::formers([$inThere]), 'already in it: the former member just goes');
        $this->assertSame([$eleni, $maria, $nikos], Conversations::members($inThere));
    }

    /**
     * A name's card and profile: who they are, when last signed in, their local time where their
     * account names a zone (and none for a zone that is no zone), the direct conversation the two
     * have and whether the reader muted it, the channels both are in — not one only the reader is
     * in — and the application's fields and links for this reader, a script address dropped.
     * Somebody who may not use the conversations is shown only to a reader who sees them anyway — in a
     * conversation the reader reads, or to root — and never as somebody to write to; and with a person's profile come
     * what they wrote, what mentions them and what they sent, in what the reader reads.
     */
    #[DataProvider('databases')]
    public function testAPersonAsTheirCardShowsThem(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $this->db->queryBuilder()->table('users')->where('userid', $maria)->update(['timezone' => 'EET', 'lastlogin' => 1700000000]);
        $this->db->queryBuilder()->table('users')->where('userid', $nikos)->update(['timezone' => 'XYZ']);
        Application::getInstance()->applicationInfo['conversations']['profile'] = fn (int $person, object $reader): array => [
            'fields' => ['Ρόλος' => 'Υποστήριξη #' . $person, 'Κενό' => ''],
            'links'  => [['label' => 'Καρτέλα', 'url' => '/admin/Users/' . $person, 'icon' => '👤'], ['label' => 'Κακό', 'url' => 'javascript:alert(1)']],
        ];
        $general = Conversations::create('channel', 'general', [$maria, $nikos], $eleni);
        Conversations::create('private', 'board', [], $eleni);
        $dm = Conversations::create('dm', null, [$eleni, $maria]);
        Conversations::mute($dm, $eleni);
        $said = Messages::post($general, $maria, 'το τιμολόγιο στάλθηκε');
        Messages::post($general, $maria, 'καλημέρα');
        $named = Messages::post($general, $nikos, 'ρώτα την <@' . $maria . '>');
        $board = Conversations::create('private', 'secret', [$maria], $nikos);
        foreach ([[$general, 'report.txt'], [$board, 'hidden.txt']] as [$where, $name]) {
            // A file's row as it is once sent; the disk is FilesTest's.
            $this->db->queryBuilder()->table('conversation_files')->insert(['conversationid' => $where, 'messageid' => $said, 'uploadedby' => $maria,
                'name' => $name, 'path' => 'x/' . $name, 'mime' => 'text/plain', 'size' => 5, 'createdat' => 1700000000]);
        }

        // Act
        $profile = People::profile($maria, $this->user('eleni'), 1700000000);
        $other   = People::profile($nikos, $this->user('eleni'));
        $none    = People::profile($this->ids['tasos'], $this->user('eleni'));
        Conversations::enroll($board, $this->ids['tasos']);
        $seen    = People::profile($this->ids['tasos'], $this->user('nikos'));
        $byRoot  = People::profile($this->ids['tasos'], $this->user('root'));
        $hidden  = People::profile($this->ids['tasos'], $this->user('eleni'));
        $self    = People::profile($maria, $this->user('maria'));
        $hers    = Messages::search($this->user('eleni'), 'τιμολ', null, 50, $maria);
        $all     = Messages::search($this->user('eleni'), '', null, 50, $maria);
        $about   = Messages::search($this->user('eleni'), '', null, 50, null, $maria);
        $files   = Files::sentBy($maria, $this->user('eleni'));
        $rootAll = Files::sentBy($maria, $this->user('root'));
        $refused = [Files::sentBy($maria, $this->user('tasos')), Messages::search($this->user('eleni'), '', null, 50, null, $this->ids['tasos'])];

        // Assert
        $this->assertSame(['Μαρία Κ.', 1700000000, 7200, $dm, true], [$profile['name'], $profile['lastSignIn'], $profile['utcOffset'], $profile['dm'], $profile['muted']]);
        $this->assertSame([['id' => $general, 'title' => '#general']], $profile['shared'], 'not the channel only the reader is in, nor the direct one');
        $this->assertSame(['Ρόλος' => 'Υποστήριξη #' . $maria], $profile['fields']);
        $this->assertSame([['label' => 'Καρτέλα', 'url' => '/admin/Users/' . $maria, 'icon' => '👤']], $profile['links'], 'a script address is no link');
        $this->assertSame([null, null, false], [$other['utcOffset'], $other['dm'], $other['muted']]);
        $this->assertNull($none, 'a translator who may not use the conversations, in none the reader reads: a stranger');
        $this->assertSame(['Τάσος', false, null], [$seen['name'], $seen['canMessage'], $seen['dm']], 'in a conversation the reader reads: shown, but not to be written to');
        $this->assertNotNull($byRoot, 'root reads every conversation');
        $this->assertNull($hidden, 'in a closed one the reader does not read: still a stranger');
        $this->assertTrue($profile['canMessage']);
        $this->assertFalse($self['canMessage'], 'nobody writes to themselves');
        $this->assertSame([$said], array_column($hers, 'id'));
        $this->assertCount(2, $all, 'hers alone, without words');
        $this->assertSame([$named], array_column($about, 'id'));
        $this->assertSame(['report.txt'], array_column($files, 'name'), 'not one in a conversation the reader does not read');
        $this->assertSame(['hidden.txt', 'report.txt'], array_column($rootAll, 'name'), 'root reads them all');
        $this->assertSame([[], []], $refused, 'a translator reads none; nobody mentions tasos');
    }

    /**
     * People's faces are the application's: `conversations.avatars` is asked once for the ids, with
     * the reader, and what it answers is kept only for ids asked about and for an address of the site
     * or http(s) — a script is no picture. Without the hook nobody has one; a profile carries its own.
     */
    #[DataProvider('databases')]
    public function testFacesAreTheApplicationsPictures(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $none  = People::avatars([$eleni, $maria], $this->user('eleni'));
        $asked = [];
        Application::getInstance()->applicationInfo['conversations']['avatars'] = function (array $ids, object $reader) use (&$asked, $maria, $nikos): array {
            $asked[] = [$ids, (int) $reader->userid];

            return [$maria => '/photos/' . $maria . '.jpg', $nikos => 'javascript:alert(1)', 4242 => '/photos/stranger.jpg'];
        };

        // Act
        $faces   = People::avatars([$eleni, $maria, $nikos, $maria, 0], $this->user('eleni'));
        $profile = People::profile($maria, $this->user('eleni'));
        $empty   = People::avatars([], $this->user('eleni'));

        // Assert
        $this->assertSame([], $none, 'no hook: the initials');
        $this->assertSame([$maria => '/photos/' . $maria . '.jpg'], $faces, 'not a script, not somebody not asked about');
        $this->assertSame([[$eleni, $maria, $nikos], $eleni], $asked[0], 'each id once, with the reader');
        $this->assertSame('/photos/' . $maria . '.jpg', $profile['avatar']);
        $this->assertSame([], $empty);
    }

    /**
     * A reply also sent to the conversation stays in its thread and is listed with the conversation's
     * own messages, in order, showing what it answers; it counts as unread like them and moves the
     * conversation's newest; `broadcast` without a thread is refused. Told as any message is, marked.
     */
    #[DataProvider('databases')]
    public function testAReplyAlsoSentToTheConversation(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $root    = Messages::post($general, $eleni, '**Ποιος** κοιτάζει το <@' . $maria . '> θέμα;');
        Conversations::markRead($general, $maria, $root);

        // Act
        $quiet = Messages::post($general, $maria, 'μόνο στο νήμα', ['thread' => $root]);
        $loud  = Messages::post($general, $maria, 'και στο κανάλι', ['thread' => $root, 'broadcast' => true]);
        $after = Messages::post($general, $eleni, 'ευχαριστώ');
        $top   = Messages::list($general);
        $inThread = Messages::list($general, ['thread' => $root]);
        $unread = Conversations::counts($general, $eleni, $root);
        try {
            Messages::post($general, $eleni, 'χωρίς νήμα', ['broadcast' => true]);
            $refused = null;
        } catch (\InvalidArgumentException $e) {
            $refused = $e->getMessage();
        }
        $activity = array_values(array_filter($this->dispatched, static fn (array $d): bool => $d[0] === 'conversation.message.posted'));

        // Assert
        $this->assertSame([$root, $loud, $after], array_column($top, 'id'), 'the broadcast reply among the conversation\'s own, the other not');
        $this->assertSame([$root, $quiet, $loud], array_column($inThread, 'id'), 'and in its thread');
        $this->assertSame(['id' => $root, 'author' => 'Ελένη Σ.', 'text' => 'Ποιος κοιτάζει το @Μαρία Κ. θέμα;'], $top[1]['rootPreview']);
        $this->assertSame([true, $root], [$top[1]['broadcast'], $top[1]['threadRootId']]);
        $this->assertNull($top[0]['rootPreview']);
        $this->assertSame(2, $top[0]['replyCount']);
        $this->assertSame(1, $unread['unread'], 'eleni: maria\'s broadcast reply, not the quiet one nor her own');
        $this->assertSame($after, Conversations::get($general)['lastMessageId']);
        $this->assertNotNull($refused);
        $this->assertCount(4, $activity, 'every message is told, the broadcast one too');
    }

    /**
     * Two different emoji from one person on one message are two reactions, and the same emoji
     * twice is one. MySQL's `utf8mb4_unicode_ci` compares every emoji equal (😍 = 💝), so under
     * the unique key the second was dropped; the migration makes the column binary and keeps
     * every row it had. PostgreSQL tells them apart under either, and the migration skips it.
     */
    #[DataProvider('databases')]
    public function testEveryEmojiIsItsOwnReaction(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general     = Conversations::create('channel', 'general', [$maria], $eleni);
        $first       = Messages::post($general, $eleni, 'καλημέρα');
        $application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $application->database = $this->db;
        $migration   = new \Pramnos\Framework\Migrations\Conversations\MakeConversationReactionsEmojiBinary($application);
        $count       = fn (): int => count($this->db->queryBuilder()->table('conversation_reactions')->where('messageid', $first)->getAll());

        // Act
        $migration->down();
        Messages::react($first, $maria, '😍');
        Messages::react($first, $maria, '💝');
        $before = $count();
        $migration->up();
        Messages::react($first, $maria, '💝');
        Messages::react($first, $maria, '😍');
        $emoji = array_column($this->db->queryBuilder()->table('conversation_reactions')->where('messageid', $first)->orderBy('id')->getAll(), 'emoji');

        // Assert
        // The old collation on MySQL: 💝 taken for the 😍 already there.
        $this->assertSame($type === 'postgresql' ? 2 : 1, $before);
        $this->assertSame(['😍', '💝'], $emoji, 'both, the row already there kept, and 😍 again written once');
    }

    /**
     * On TimescaleDB the messages are a hypertable, compressed after a quarter and never dropped.
     */
    public function testOnTimescaleTheMessagesAreAHypertable(): void
    {
        // Arrange
        $this->boot('postgresql', 'timescaledb', 5432, 'postgres');

        // Act
        $rows = $this->db->queryBuilder()->table('timescaledb_information.hypertables')->where('hypertable_name', 'conversation_messages')->getAll();
        $jobs = $this->db->queryBuilder()->table('timescaledb_information.jobs')->where('hypertable_name', 'conversation_messages')->getAll();

        // Assert
        $this->assertCount(1, $rows);
        $this->assertSame(['policy_compression'], array_column($jobs, 'proc_name'), 'compressed, and no retention');
    }
}
