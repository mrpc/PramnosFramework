<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Details;
use Pramnos\Conversations\Files;
use Pramnos\Conversations\Messages;
use Pramnos\Framework\Migrations\Conversations\AddDetailsToConversations;
use Pramnos\Framework\Migrations\Conversations\AddImageToConversations;

/**
 * A channel's details, as Slack's "Channel details" (FW-369), against MySQL with and without a
 * prefix and PostgreSQL: renamed (a system channel keeping its `#name`), its topic and description,
 * private and public, archived for everyone and brought back, members removed and given roles,
 * its settings, the mention of somebody not in it, its links and files a page at a time, and
 * deleted for good — who may do each, and who is refused.
 */
#[CoversClass(Details::class)]
#[CoversClass(Conversations::class)]
#[CoversClass(Files::class)]
#[CoversClass(\Pramnos\Application\Controllers\Conversations::class)]
#[CoversClass(AddDetailsToConversations::class)]
class ChannelDetailsTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** Where the test's files went. */
    private string $files = '';

    /** The probe with the details' columns, the files under a directory of the test's own. */
    private function ready(string $type, string $host, int $port, string $user): void
    {
        $this->boot($type, $host, $port, $user);
        require_once ROOT . '/database/migrations/framework/conversations/2026_11_09_000013_add_image_to_conversations.php';
        require_once ROOT . '/database/migrations/framework/conversations/2026_11_09_000014_add_details_to_conversations.php';
        \Pramnos\Framework\Testing\Schema::ensure([AddImageToConversations::class, AddDetailsToConversations::class], $this->db);
        $this->files = sys_get_temp_dir() . '/pf-details-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->files];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
    }

    /** The test's files gone, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        if ($this->files !== '' && is_dir($this->files)) {
            exec('rm -rf ' . escapeshellarg($this->files));
        }
        $_GET = $_POST = [];
        \Pramnos\Http\Request::setRawInput('');
        $this->probeTearDown();
    }

    /** What a refusal says, or 'ok' when there was none. */
    private static function refused(callable $act): string
    {
        try {
            $act();

            return 'ok';
        } catch (\DomainException|\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Name, topic, description, privacy, archive: its owner and a moderator may, a plain member may
     * not; a `#name` another channel has is refused; a system channel keeps its `#name` and only
     * its name shown changes, and is never made private; a group is renamed by any member; each
     * change is a line in it. An archived channel leaves the lists and is found among the archived.
     */
    #[DataProvider('databases')]
    public function testNameTopicPrivacyAndArchive(string $type, string $host, int $port, string $user): void
    {
        // Arrange — maria owns #launch; nikos is a member; eleni moderates every one
        $this->ready($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $launch = Conversations::create('channel', 'launch', [$nikos], $maria);
        Conversations::create('channel', 'taken', [], $maria);
        $jira   = Conversations::create('system', 'jira', [$maria]);
        $dm     = Conversations::create('dm', null, [$maria], $nikos);

        // Act
        $byMember = self::refused(fn () => Conversations::rename($launch, 'Mine now', $nikos));
        $taken    = self::refused(fn () => Conversations::rename($launch, 'Taken', $maria));
        Conversations::rename($launch, 'Launch Q4', $maria);
        Conversations::rename($jira, 'Jira tickets', $eleni);
        Details::describe($launch, ['topic' => 'Ships on the 20th', 'description' => str_repeat('δ', 300)], $maria);
        $described = Conversations::get($launch);
        Details::describe($launch, ['topic' => ''], $maria);
        $systemPrivate = self::refused(fn () => Details::setPrivate($jira, true, $eleni));
        $dmDetails     = self::refused(fn () => Details::describe($dm, ['topic' => 'x'], $nikos));
        Details::setPrivate($launch, true, $maria);
        $private = Conversations::get($launch)['kind'];
        Details::archive($launch, true, $eleni);
        $listed   = array_column(Conversations::forPerson($nikos), 'id');
        $archived = array_column(Conversations::browse($nikos, true), 'id');
        $writes   = Conversations::canWrite($launch, $this->user('nikos'));
        Details::archive($launch, false, $maria);
        $lines = array_column(Messages::list($launch), 'body');

        // Assert
        $this->assertSame('Only its owner, an admin of it or a moderator changes that.', $byMember);
        $this->assertSame('There is a channel called #taken already.', $taken);
        $this->assertSame(['launch-q4', 'Launch Q4'], [Conversations::get($launch)['slug'], Conversations::get($launch)['name']]);
        $this->assertSame(['jira', 'Jira tickets'], [Conversations::get($jira)['slug'], Conversations::get($jira)['name']], 'a system channel keeps its #name');
        $this->assertNotNull(Conversations::bySlug('jira'), 'and the application still finds it');
        $this->assertSame('Ships on the 20th', $described['topic']);
        $this->assertSame(250, mb_strlen((string) $described['description']), 'a description is at most 250');
        $this->assertNull(Conversations::get($launch)['topic'], 'cleared');
        $this->assertSame('Only a channel is made private or public.', $systemPrivate);
        $this->assertSame('A direct conversation has no details to change.', $dmDetails);
        $this->assertSame('private', $private);
        $this->assertNotContains($launch, $listed, 'archived: off the lists');
        $this->assertContains($launch, $archived, 'found among the archived');
        $this->assertFalse($writes, 'read, not written');
        $this->assertNull(Conversations::get($launch)['archivedAt'], 'brought back');
        foreach (['renamed the channel to #launch-q4', 'set the topic: Ships on the 20th', 'changed the description', 'cleared the topic',
            'made the channel private', 'archived the channel', 'brought the channel back'] as $said) {
            $this->assertNotEmpty(array_filter($lines, static fn (string $l): bool => str_contains($l, $said)), $said);
        }
    }

    /**
     * Members: roles given and taken by the owner, an admin acting as one; the last owner neither
     * removed nor demoted; somebody not in it neither; the roster owners first, with when each
     * joined. The settings: `tabsBy` one of two, `suggestMembers` off leaving no one to suggest; a
     * mention of somebody not in the channel suggested to its writer.
     */
    #[DataProvider('databases')]
    public function testMembersRolesAndSettings(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$maria, $nikos, $tasos] = [$this->ids['maria'], $this->ids['nikos'], $this->ids['tasos']];
        $launch = Conversations::create('channel', 'launch', [$nikos], $maria);
        $group  = Conversations::create('group', null, [$nikos, $tasos], $maria);

        // Act
        $lastOwner   = self::refused(fn () => Details::remove($launch, $maria, $maria));
        $notIn       = self::refused(fn () => Details::setRole($launch, $tasos, 'admin', $maria));
        $badRole     = self::refused(fn () => Details::setRole($launch, $nikos, 'king', $maria));
        $groupRole   = self::refused(fn () => Details::setRole($group, $nikos, 'admin', $maria));
        Details::setRole($launch, $nikos, 'admin', $maria);
        Conversations::join($launch, $tasos);
        $suggested   = Details::notMembers($launch, [$tasos, $this->ids['eleni'], 0]);
        Details::remove($launch, $tasos, $nikos);                    // an admin removes
        $roster      = Details::roster($launch);
        $demoteOwner = self::refused(fn () => Details::setRole($launch, $maria, 'member', $nikos));
        Details::settings($launch, ['tabsBy' => 'admins', 'suggestMembers' => false], $nikos);
        $badTabs     = self::refused(fn () => Details::settings($launch, ['tabsBy' => 'robots'], $maria));
        $none        = Details::notMembers($launch, [$this->ids['eleni']]);
        Details::remove($group, $tasos, $nikos);                     // any member of a group

        // Assert
        $this->assertSame('Its last owner stays: make somebody else its owner first.', $lastOwner);
        $this->assertSame('They are not in it.', $notIn);
        $this->assertSame('A member is its owner, an admin or a member.', $badRole);
        $this->assertSame('A group\'s members have no roles.', $groupRole);
        $this->assertSame([$this->ids['eleni']], $suggested, 'only who is not in it');
        $this->assertSame([[$maria, 'owner'], [$nikos, 'admin']], array_map(static fn (array $m): array => [$m['id'], $m['role']], $roster));
        $this->assertGreaterThan(0, $roster[0]['joinedAt']);
        $this->assertSame('Μαρία Κ.', $roster[0]['name']);
        $this->assertSame('Its last owner stays: make somebody else its owner first.', $demoteOwner);
        $this->assertSame(['admins', false], [Conversations::get($launch)['tabsBy'], Conversations::get($launch)['suggestMembers']]);
        $this->assertSame('Tabs are managed by everyone or by its admins.', $badTabs);
        $this->assertSame([], $none, 'suggestions off');
        $this->assertNotContains($tasos, Conversations::members($group));
    }

    /**
     * Who deletes a message of somebody else's: whoever moderates the conversation — its owner,
     * one of its admins — and not a plain member; a bot's post, written by nobody, taken down by an
     * admin. The writer deletes their own.
     */
    #[DataProvider('databases')]
    public function testAModeratorDeletesAnybodysMessage(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$maria, $nikos, $tasos] = [$this->ids['maria'], $this->ids['nikos'], $this->ids['tasos']];
        $launch = Conversations::create('channel', 'launch', [$nikos, $tasos], $maria);
        Details::setRole($launch, $nikos, 'admin', $maria);
        $members = Messages::post($launch, $tasos, 'a member\'s');
        $bots    = Messages::post($launch, null, 'a system post');
        $owners  = Messages::post($launch, $maria, 'the owner\'s');

        // Act
        Messages::delete($members, $this->user('nikos'));              // an admin
        Messages::delete($bots, $this->user('nikos'));                 // nobody's
        $refused = self::refused(fn () => Messages::delete($owners, $this->user('tasos')));

        // Assert
        $this->assertNotNull(Messages::get($members)['deletedAt'] ?? null, 'an admin deletes a member\'s');
        $this->assertNotNull(Messages::get($bots)['deletedAt'] ?? null, 'and a system post');
        $this->assertSame('Only its writer deletes a message.', $refused, 'a plain member does not');
    }

    /**
     * Links and files a page at a time: every address said, newest first, a deleted message's
     * left out, found by a word of the address, the next page before a message; files as media or
     * the rest, by name, before a file. Deleted for good: a superuser's alone, never a system
     * channel's, and nothing is left of it — rows nor files on disk.
     */
    #[DataProvider('databases')]
    public function testLinksFilesAndDeletingForGood(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $launch = Conversations::create('channel', 'launch', [], $maria);
        $first  = Messages::post($launch, $maria, 'The plan https://figma.test/file/1 and https://docs.test/a');
        $gone   = Messages::post($launch, $maria, 'Old https://old.test/x');
        Messages::delete($gone, $this->user('maria'));
        $last   = Messages::post($launch, $maria, 'Board https://trello.test/b/2.');
        $keep   = function (string $name, string $bytes): string {
            $tmp = tempnam(sys_get_temp_dir(), 'pf');
            file_put_contents($tmp, $bytes);

            return $tmp;
        };
        $png    = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $image  = Files::store($launch, $maria, $keep('a.png', $png), 'photo.png');
        $note   = Files::store($launch, $maria, $keep('n.txt', 'notes'), 'notes.txt');
        Messages::post($launch, $maria, 'Files', ['files' => [$image, $note]]);
        $system = Conversations::create('system', 'alerts', [$maria]);

        // Act
        $links    = array_column(Details::links($launch), 'url');
        $figma    = array_column(Details::links($launch, 'figma'), 'url');
        $page     = array_column(Details::links($launch, '', $last), 'url');
        $media    = array_column(Files::page($launch, '', 'media'), 'name');
        $others   = array_column(Files::page($launch, '', 'files'), 'name');
        $named    = array_column(Files::page($launch, 'note'), 'name');
        $older    = array_column(Files::page($launch, '', '', $note), 'name');
        $onDisk   = glob($this->files . '/*/*') ?: glob($this->files . '/*');
        $byMaria  = self::refused(fn () => Details::destroy($launch, $this->user('maria')));
        $ofSystem = self::refused(fn () => Details::destroy($system, $this->user('eleni')));
        $deleted  = Details::destroy($launch, $this->user('eleni'));
        $left     = [];
        foreach (['chat.conversation_messages', 'chat.conversation_files', 'chat.conversation_members', 'chat.conversations'] as $table) {
            $left[$table] = $this->db->queryBuilder()->table($table)->where('conversationid', $launch)->count();
        }

        // Assert
        $this->assertSame(['https://trello.test/b/2', 'https://figma.test/file/1', 'https://docs.test/a'], $links, 'newest first; the deleted one\'s not there');
        $this->assertSame(['https://figma.test/file/1'], $figma);
        $this->assertSame(['https://figma.test/file/1', 'https://docs.test/a'], $page, 'before the last');
        $this->assertSame([['photo.png'], ['notes.txt'], ['notes.txt'], ['photo.png']], [$media, $others, $named, $older]);
        $this->assertNotEmpty($onDisk, 'the files were on disk');
        $this->assertSame('Only a superuser deletes a conversation for good.', $byMaria);
        $this->assertSame('A system channel is not deleted: the application writes to it.', $ofSystem);
        $this->assertSame(2, $deleted['files']);
        $this->assertGreaterThanOrEqual(4, $deleted['messages']);
        $this->assertSame(array_fill_keys(array_keys($left), 0), $left, 'nothing left of it');
        $this->assertSame([], array_filter((array) glob($this->files . '/**/*.*'), 'is_file'), 'nor its files on disk');
        $this->assertNull(Conversations::get($launch));
    }

    /**
     * Through the screen: details changed and read, members and links listed, the archived listed,
     * a post naming somebody not in the channel answered with them, a refusal a 404, deleting a
     * superuser's alone.
     */
    #[DataProvider('databases')]
    public function testThroughTheScreen(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$maria, $nikos] = [$this->ids['maria'], $this->ids['nikos']];
        $launch = Conversations::create('channel', 'launch', [$nikos], $maria);
        \Pramnos\Broadcasting\Testing\FakeDriver::swap();

        try {
            // Act
            $described = $this->ask('maria', 'describe', $launch, ['topic' => 'Q4', 'description' => 'All of it']);
            $renamed   = $this->ask('eleni', 'rename', $launch, ['name' => 'Launch Q4']);          // a moderator, not in it
            $slug      = Conversations::get($launch)['slug'];
            $refused   = $this->ask('nikos', 'describe', $launch, ['topic' => 'mine']);
            $settings  = $this->ask('maria', 'settings', $launch, ['tabsBy' => 'admins', 'suggestMembers' => '1']);
            $role      = $this->ask('maria', 'setrole', $launch, ['person' => $nikos, 'role' => 'admin']);
            $members   = $this->ask('nikos', 'members', $launch, []);
            $posted    = $this->ask('maria', 'post', $launch, ['body' => 'Ρωτάμε τον <@' . $this->ids['tasos'] . '> https://plan.test/1']);
            $links     = $this->ask('nikos', 'links', $launch, ['q' => 'plan']);
            $private   = $this->ask('maria', 'setprivate', $launch, ['on' => '1']);
            $archive   = $this->ask('maria', 'archive', $launch, []);
            $archived  = $this->ask('maria', 'archived', 0, []);
            $unarchive = $this->ask('maria', 'unarchive', $launch, []);
            $removed   = $this->ask('maria', 'removemember', $launch, ['person' => $nikos]);
            $files     = $this->ask('maria', 'files', $launch, ['kind' => 'media']);
            $noDestroy = $this->ask('maria', 'destroy', $launch, []);
            $destroyed = $this->ask('eleni', 'destroy', $launch, []);

            // Assert
            $this->assertSame(['Q4', 'All of it'], [$described['body']['conversation']['topic'], $described['body']['conversation']['description']]);
            $this->assertSame(404, $refused['status']);
            $this->assertSame([200, 'launch-q4'], [$renamed['status'], $slug]);
            $this->assertSame('admins', $settings['body']['conversation']['tabsBy']);
            $this->assertSame('admin', array_column($role['body']['members'], 'role', 'id')[$nikos]);
            $this->assertCount(2, $members['body']['members']);
            $this->assertSame([$this->ids['tasos']], $posted['body']['notMembers'], 'offered to add who is not in it');
            $this->assertSame(['https://plan.test/1'], array_column($links['body']['links'], 'url'));
            $this->assertSame('private', $private['body']['conversation']['kind']);
            $this->assertSame([200, 200, 200], [$archive['status'], $unarchive['status'], $removed['status']]);
            $this->assertContains($launch, array_column($archived['body']['conversations'], 'id'));
            $this->assertSame([], $files['body']['files']);
            $this->assertSame(404, $noDestroy['status']);
            $this->assertSame(200, $destroyed['status']);
        } finally {
            \Pramnos\Broadcasting\Testing\FakeDriver::restore();
        }
    }

    /**
     * One of the controller's actions, asked by `$name` with these values (as a JSON body); its
     * status and answer.
     *
     * @param array<string, mixed> $values
     * @return array{status: int, body: mixed}
     */
    private function ask(string $name, string $action, int $id, array $values): array
    {
        $_GET  = ['_option' => (string) $id] + array_map('strval', $values);
        $_POST = [];
        \Pramnos\Http\Request::setRawInput((string) json_encode($values));
        $chat = new class (Application::getInstance()) extends \Pramnos\Application\Controllers\Conversations {
            /** Who asks, set by the test. */
            public ?object $asking = null;

            /** The test's account, not a session. */
            protected function me(): ?object
            {
                return $this->asking;
            }
        };
        $chat->asking = $this->user($name);
        $response     = $chat->{$action}();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)];
    }
}
