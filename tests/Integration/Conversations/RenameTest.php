<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Conversations as Chat;
use Pramnos\Broadcasting\Testing\FakeDriver;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Http\Request;

/**
 * A group conversation has a name of its own, which any member gives or takes away: its title is
 * the name, or its members without one; a line in it says who renamed it, seen at once but notifying
 * nobody; its members' sidebars are told; the changelog keeps it. A direct conversation has no name.
 */
#[CoversClass(Conversations::class)]
#[CoversClass(Messages::class)]
#[CoversClass(\Pramnos\Conversations\Realtime::class)]
#[CoversClass(Chat::class)]
class RenameTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** Put the real broadcasting back, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        FakeDriver::restore();
        unset(Application::getInstance()->applicationInfo['features']);
        $this->probeTearDown();
    }

    /**
     * Named at its making (trimmed, up to 80), or not; renamed by a member, its name taken away by
     * an empty one; a non-member refused (403) and a direct conversation refused; the title the
     * sidebar shows; the line, the telling and the changelog.
     */
    #[DataProvider('databases')]
    public function testAGroupIsNamedAndRenamedByItsMembers(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $named   = Conversations::create('group', '  Ομάδα πωλήσεων  ' . str_repeat('x', 100), [$maria, $nikos], $eleni);
        $unnamed = Conversations::create('group', null, [$maria, $nikos], $eleni);
        $alone   = Conversations::create('channel', 'general', [$maria], $eleni);
        $dm      = Conversations::create('dm', 'not a name', [$maria], $eleni);
        $fake    = FakeDriver::swap();

        // Act
        $renamed = $this->ask('maria', 'rename', $unnamed, ['name' => 'Βάρδια']);
        $titles  = array_column($this->ask('eleni', 'conversations')['body']['conversations'], null, 'id');
        $this->ask('nikos', 'rename', $unnamed, ['name' => '']);
        $back    = array_column($this->ask('eleni', 'conversations')['body']['conversations'], null, 'id')[$unnamed]['title'];
        $outsider = Conversations::create('group', null, [$eleni, $nikos], $maria);
        $refused  = $this->ask('maria', 'rename', $outsider, ['name' => 'x']);
        $notMine  = Conversations::create('group', null, [$maria, $nikos], $this->ids['eleni']);
        $stranger = $this->ask('maria', 'rename', $notMine, ['name' => 'same']);
        $dmRefused = $this->ask('eleni', 'rename', $dm, ['name' => 'x']);
        $events   = \Pramnos\Changelog\ChangelogReader::history('conversation', $unnamed, ['events'], 10);
        $lines    = array_column(Messages::list($unnamed), 'body');
        $told     = array_map(static fn (array $r): array => [$r['channel'], $r['event'], $r['payload']['name'] ?? null], $fake->recorded());

        // Assert
        $this->assertSame('Ομάδα πωλήσεων  ' . str_repeat('x', 64), Conversations::get($named)['name'], 'trimmed at its ends, up to 80');
        $this->assertNull(Conversations::get($dm)['name'], 'a direct conversation has no name');
        $this->assertSame(['ok' => true], $renamed['body']);
        $this->assertSame('Βάρδια', $titles[$unnamed]['title'], 'its name is its title');
        $this->assertStringContainsString('Μαρία', $titles[$unnamed]['membersTitle'], 'its members too, to be found by');
        $this->assertSame($titles[$unnamed]['membersTitle'], $back, 'no name: its members again');
        $this->assertSame('#general', $titles[$alone]['title']);
        $this->assertSame(200, $refused['status'], 'maria made it, so she is in it');
        $this->assertSame(200, $stranger['status']);
        $this->assertSame(404, $dmRefused['status'], 'only a group is named');
        $this->assertCount(2, $lines, 'a line for each rename');
        $this->assertStringContainsString('Βάρδια', $lines[0]);
        $this->assertSame(['conversation.renamed', 'conversation.renamed'], array_column($events, 'event'));
        $this->assertContains(['private-users.' . $nikos, 'conversation.changed', 'Βάρδια'], $told, 'the sidebar told the new title');
        $this->assertContains(['private-conversation.' . $unnamed, 'message.posted', null], $told, 'the line shown at once');
        $this->assertNotContains('conversation.activity', array_column($told, 1), 'nobody notified of a rename');
    }

    /** Somebody not in a group may not rename it: 403. */
    #[DataProvider('databases')]
    public function testSomebodyNotInItMayNotRenameIt(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        $group  = Conversations::create('group', 'Πρώτη', [$this->ids['maria'], $this->ids['nikos']], $this->ids['eleni']);

        // Act — root, who is not in it
        $answer = $this->ask('root', 'rename', $group, ['name' => 'Δική μου']);

        // Assert
        $this->assertSame(403, $answer['status']);
        $this->assertSame('Πρώτη', Conversations::get($group)['name'], 'unchanged');
    }

    /** One of the controller's actions, as `$name`, with a JSON body; its status and answer. */
    private function ask(string $name, string $action, ?int $id = null, array $body = []): array
    {
        $_GET  = $id === null ? [] : ['_option' => (string) $id];
        $_POST = [];
        Request::setRawInput((string) json_encode($body));
        $chat = new class (Application::getInstance()) extends Chat {
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
