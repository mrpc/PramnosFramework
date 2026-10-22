<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Broadcasting\Auth\ChannelRegistry;
use Pramnos\Broadcasting\Testing\FakeDriver;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\ConversationsServiceProvider;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\Realtime;

/**
 * What the conversations tell browsers, after the database has it: a message to its conversation
 * as a reader sees it and to each other member's own channel, with whether it mentions them; an
 * edit, a deletion, a reaction, a pin as the message now reads; who may listen to which channel;
 * and nothing told — and nothing failing — where broadcasting is not set up.
 */
#[CoversClass(Realtime::class)]
#[CoversClass(ConversationsServiceProvider::class)]
class RealtimeTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** Put the real broadcasting back, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        FakeDriver::restore();
        $this->probeTearDown();
    }

    /**
     * Each change is told on its conversation's channel; a new message also on each other member's
     * own, saying whether it mentions them.
     */
    #[DataProvider('databases')]
    public function testEachChangeIsTold(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $general = Conversations::create('channel', 'general', [$maria, $nikos], $eleni);
        $fake    = FakeDriver::swap();

        // Act
        $id = Messages::post($general, $eleni, 'γεια <@' . $maria . '>');
        Messages::edit($id, $eleni, 'γεια σας <@' . $maria . '>');
        Messages::react($id, $nikos, ':wave:');
        Messages::pin($id, $eleni);
        Messages::delete($id, $this->user('eleni'));

        // Assert
        $events = array_map(static fn (array $r): array => [$r['channel'], $r['event']], $fake->recorded());
        $this->assertSame([
            ['private-conversation.' . $general, 'message.posted'],
            ['private-users.' . $maria, 'conversation.activity'],
            ['private-users.' . $nikos, 'conversation.activity'],
            ['private-conversation.' . $general, 'message.edited'],
            ['private-conversation.' . $general, 'message.reacted'],
            ['private-conversation.' . $general, 'message.pinned'],
            ['private-conversation.' . $general, 'message.deleted'],
        ], $events);
        $recorded = $fake->recorded();
        $this->assertSame('γεια <span class="pf-conv-mention" data-user="' . $maria . '">@Μαρία Κ.</span>', strip_tags($recorded[0]['payload']['html'], '<span>'),
            'as a reader sees it');
        $this->assertSame([true, false], [$recorded[1]['payload']['mention'], $recorded[2]['payload']['mention']], 'maria is mentioned, nikos not');
        $this->assertSame(':wave:', $recorded[4]['payload']['reactions'][0]['emoji']);
        $this->assertTrue($recorded[5]['payload']['pinned']);
        $this->assertSame('', $recorded[6]['payload']['html']);
    }

    /**
     * A conversation's channel is heard by whoever reads it; the presence channel by whoever may
     * use the conversations, under their name; the provider adds these rules to the application's.
     */
    #[DataProvider('databases')]
    public function testWhoMayListen(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $secret   = Conversations::create('private', 'finance', [$this->ids['maria']], $this->ids['eleni']);
        $container = Application::getInstance()->getContainer();
        if (!$container->has('broadcasting.channels')) {
            $container->singleton('broadcasting.channels', static fn (): ChannelRegistry => new ChannelRegistry());
        }
        (new ConversationsServiceProvider(Application::getInstance()))->register();

        // Act
        (new ConversationsServiceProvider(Application::getInstance()))->boot();
        $channels = $container->get('broadcasting.channels');
        $maria  = $channels->authorize('private-conversation.' . $secret, $this->user('maria'));
        $nikos  = $channels->authorize('private-conversation.' . $secret, $this->user('nikos'));
        $nobody = $channels->authorize('private-conversation.' . $secret, null);
        $team   = $channels->authorize('presence-chat', $this->user('nikos'));
        $tasos  = $channels->authorize('presence-chat', $this->user('tasos'));

        // Assert
        $this->assertTrue($channels->has('private-conversation.' . $secret), 'the provider added the rule to the application\'s');
        $this->assertTrue($maria);
        $this->assertFalse($nikos, 'a closed conversation: its members');
        $this->assertFalse($nobody);
        $this->assertSame(['user_id' => (string) $this->ids['nikos'], 'user_info' => ['name' => 'Νίκος Π.']], $team);
        $this->assertFalse($tasos);
    }

    /**
     * Without broadcasting, nothing is told and writing goes on; a driver that fails is logged,
     * never the writer's failure.
     */
    #[DataProvider('databases')]
    public function testWithoutBroadcastingOrWhenItFails(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $general = Conversations::create('channel', 'general', [], $this->ids['eleni']);
        $previous = \Pramnos\Broadcasting\BroadcastingManager::currentInstance();
        \Pramnos\Broadcasting\BroadcastingManager::setInstance(null);

        // Act
        $quiet = Messages::post($general, $this->ids['eleni'], 'κανείς δεν ακούει');
        $failing = new \Pramnos\Broadcasting\BroadcastingManager();
        $failing->addDriver(new class () implements \Pramnos\Broadcasting\Drivers\DriverInterface {
            /** Fails, as a server that is down. */
            public function broadcast(string $channel, string $event, array $payload): void
            {
                throw new \RuntimeException('connection refused');
            }

            /** Its name. */
            public function name(): string
            {
                return 'down';
            }
        })->setDefault('down');
        \Pramnos\Broadcasting\BroadcastingManager::setInstance($failing);
        $despite = Messages::post($general, $this->ids['eleni'], 'κι όμως γράφεται');
        \Pramnos\Broadcasting\BroadcastingManager::setInstance($previous);

        // Assert
        $this->assertGreaterThan(0, $quiet);
        $this->assertGreaterThan($quiet, $despite, 'written, though not told');
    }
}
