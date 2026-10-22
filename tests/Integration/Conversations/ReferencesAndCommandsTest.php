<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Audience\Rules\Actions;
use Pramnos\Audience\Rules\Context;
use Pramnos\Conversations\Client;
use Pramnos\Conversations\Commands;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\References;
use Pramnos\Http\Request;

/**
 * What the application adds to the conversations, on each backend: references drawn as cards for
 * the reader — `#type:id` and the application's own patterns, null for what the reader may not
 * see, nothing for a resolver that fails; `/commands` answered to their writer alone; and a rule
 * telling the team in a conversation.
 */
#[CoversClass(References::class)]
#[CoversClass(Commands::class)]
#[CoversClass(Actions::class)]
#[CoversClass(\Pramnos\Application\Controllers\Conversations::class)]
class ReferencesAndCommandsTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** No resolvers, patterns or commands left for the next test. */
    protected function tearDown(): void
    {
        References::reset();
        Commands::reset();
        $_GET = $_POST = [];
        Request::setRawInput(null);
        unset(Application::getInstance()->applicationInfo['features']);
        $this->probeTearDown();
    }

    /** The team, and the conversations feature. */
    private function team(): void
    {
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
    }

    /**
     * A reference becomes the card its resolver draws for the reader — cleaned to a card's parts,
     * its links only http(s) or this site's paths; null where the reader may not see it, and where
     * the resolver fails; an unknown type is left out. The application's patterns find references
     * in any message, older ones too, never in a deleted one.
     */
    #[DataProvider('databases')]
    public function testReferencesAsCards(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $older   = Messages::post($general, $eleni, 'Δες το NAN-6212 και https://acme.atlassian.net/browse/NAN-7 και #user:48211');
        References::register('user', static fn (string $id, object $viewer): ?array => (int) $viewer->usertype >= 98
            ? ['title' => 'Ελένη Δ.', 'text' => 'Γονέας · Αθήνα', 'url' => '/admin/users/view/' . $id, 'image' => 'javascript:x',
                'fields' => ['Συνδρομή' => 'Premium', 'nested' => ['x']], 'color' => 'info', 'buttons' => [['label' => 'x']]]
            : null);
        References::register('jira', static fn (string $id): ?array => $id === 'NAN-7' ? throw new \RuntimeException('Jira is down') : ['title' => $id, 'color' => 'purple']);
        References::match('jira', '/\b(NAN-\d+)\b/');
        References::match('jira', '#https://[a-z0-9-]+\.atlassian\.net/browse/([A-Z]+-\d+)#');
        $gone = Messages::post($general, $eleni, 'NAN-1');
        Messages::delete($gone, $this->user('eleni'));

        // Act
        $listed  = array_column(Messages::list($general), 'refs', 'id');
        $forEleni = References::resolve(['user:48211', 'jira:NAN-6212', 'jira:NAN-7', 'invoice:9', 'user:'], $this->user('eleni'));
        $forMaria = References::resolve(['user:48211'], $this->user('maria'));
        $refused  = null;
        try {
            References::match('x', 'not a pattern');
        } catch (\InvalidArgumentException $e) {
            $refused = $e->getMessage();
        }

        // Assert
        $this->assertSame(['user:48211', 'jira:NAN-6212', 'jira:NAN-7'], $listed[$older], 'its own #user, and the patterns');
        $this->assertSame([], $listed[$gone], 'a deleted message refers to nothing');
        $this->assertSame(['title' => 'Ελένη Δ.', 'url' => '/admin/users/view/48211', 'text' => 'Γονέας · Αθήνα', 'fields' => ['Συνδρομή' => 'Premium'], 'color' => 'info'],
            $forEleni['user:48211'], 'a card\'s parts only; no script for an image');
        $this->assertSame(['title' => 'NAN-6212'], $forEleni['jira:NAN-6212'], 'an unknown tone is none');
        $this->assertNull($forEleni['jira:NAN-7'], 'a resolver that fails shows nothing');
        $this->assertArrayNotHasKey('invoice:9', $forEleni, 'nobody draws invoices');
        $this->assertSame(['user:48211' => null], $forMaria, 'what she may not see is the plain text');
        $this->assertSame(['user', 'jira'], References::types());
        $this->assertStringStartsWith('Not a pattern', (string) $refused);
        $this->assertCount(References::MAX, References::resolve(array_map(static fn (int $i): string => 'jira:NAN-' . $i, range(1, 80)), $this->user('eleni')));
    }

    /**
     * A command: named plainly; listed for the composer; its answer — a line, a card, nothing —
     * for its writer alone; a mistake it explains is its answer, a failure is logged and said; an
     * unknown command, or one in a conversation the person may not write in, is refused.
     */
    #[DataProvider('databases')]
    public function testCommands(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $heard   = [];
        Commands::register('/Jira', 'Νέο ζήτημα', function (string $args, int $conversation, object $person, ?int $thread) use (&$heard): array {
            $heard = [$args, $conversation, (int) $person->userid, $thread];

            return ['text' => 'Έγινε το NAN-9', 'card' => ['title' => 'NAN-9', 'url' => 'https://x.atlassian.net/browse/NAN-9', 'color' => 'info']];
        }, usage: '<τίτλος>');
        Commands::register('quiet', 'Τίποτα', static fn (): ?string => null);
        Commands::register('wrong', 'Λάθος', static fn () => throw new \InvalidArgumentException('Πες μου τίτλο.'));
        Commands::register('broken', 'Χαλασμένο', static fn () => throw new \RuntimeException('down'));
        $refusals = [];

        // Act
        $answer = Commands::run("/jira  Λάθος ΦΠΑ\nστην Κύπρο", $general, $this->user('maria'), 77);
        $quiet  = Commands::run('/quiet', $general, $this->user('maria'));
        $wrong  = Commands::run('/wrong', $general, $this->user('maria'));
        $broken = Commands::run('/broken now', $general, $this->user('maria'));
        foreach ([fn () => Commands::run('/nope', $general, $this->user('maria')), fn () => Commands::run('jira x', $general, $this->user('maria')),
            fn () => Commands::run('/jira x', $general, $this->user('nikos')), fn () => Commands::register('two words', 'x', static fn () => null)] as $refused) {
            try {
                $refused();
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }

        // Assert
        $this->assertSame(["Λάθος ΦΠΑ\nστην Κύπρο", $general, $maria, 77], $heard, 'what follows the name, where, who, which thread');
        $this->assertSame(['text' => 'Έγινε το NAN-9', 'card' => ['title' => 'NAN-9', 'url' => 'https://x.atlassian.net/browse/NAN-9', 'color' => 'info']], $answer);
        $this->assertSame(['text' => '', 'card' => null], $quiet);
        $this->assertSame('Πες μου τίτλο.', $wrong['text'], 'its own explanation');
        $this->assertSame('The command failed. It has been logged.', $broken['text']);
        $this->assertSame(['There is no such command.', 'There is no such command.', 'Only its members write in a conversation.',
            'A command is named by letters, digits, - and _.'], $refusals);
        $this->assertSame([['name' => 'jira', 'description' => 'Νέο ζήτημα', 'usage' => '<τίτλος>'], ['name' => 'quiet', 'description' => 'Τίποτα', 'usage' => ''],
            ['name' => 'wrong', 'description' => 'Λάθος', 'usage' => ''], ['name' => 'broken', 'description' => 'Χαλασμένο', 'usage' => '']], Commands::all());
        $props = Client::props($this->user('maria'));
        $this->assertSame([[], 'jira'], [$props['refTypes'], $props['commands'][0]['name']]);
    }

    /**
     * Over HTTP: the cards of references, and a command run — each as the asking person.
     */
    #[DataProvider('databases')]
    public function testTheirEndpoints(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        $general = Conversations::create('channel', 'general', [], $this->ids['eleni']);
        References::register('user', static fn (string $id): array => ['title' => 'Χρήστης ' . $id]);
        Commands::register('ping', 'Ping', static fn (string $args): string => 'pong ' . $args);
        // One controller per request, as a request has: it reads its body once.
        $page = function (): HeldPage {
            $held = new HeldPage(Application::getInstance());
            $held->asking = $this->user('eleni');

            return $held;
        };

        // Act
        $_GET = ['keys' => 'user:5,user:6,,other:1'];
        Request::setRawInput('');
        $cards = json_decode((string) $page()->refs()->getBody(), true);
        $_GET = ['_option' => (string) $general];
        Request::setRawInput((string) json_encode(['text' => '/ping ναι', 'thread' => '']));
        $reply = json_decode((string) $page()->command()->getBody(), true);
        $_GET = [];
        Request::setRawInput('');
        $none = json_decode((string) $page()->refs()->getBody(), true);

        // Assert
        $this->assertSame(['user:5' => ['title' => 'Χρήστης 5'], 'user:6' => ['title' => 'Χρήστης 6']], $cards['cards']);
        $this->assertSame(['text' => 'pong ναι', 'card' => null], $reply['reply']);
        $this->assertSame([], $none['cards'], 'nothing asked, an empty object');
    }

    /**
     * A rule tells the team: in a channel by name, or to an administrator by id — the text filled
     * for the person, the person a card linked to their page, signed by the rule; once per run; a
     * test send to whoever asked; nobody to tell is an error; the action exists only with the
     * conversations.
     */
    #[DataProvider('databases')]
    public function testARuleTellsTheTeam(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        $action  = Actions::get('conversation_post');
        $context = new Context(4, 'Ακυρώσεις', 2, 'tell', 812, $this->ids['nikos']);
        $test    = new Context(4, 'Ακυρώσεις', 2, 'tell', 813, $this->ids['nikos'], recipient: $this->ids['eleni']);

        // Act
        $sent    = ($action['run'])($this->ids['nikos'], ['to' => '#ακυρώσεις', 'text' => 'Ακύρωσε ο {firstname}'], $context);
        $again   = ($action['run'])($this->ids['nikos'], ['to' => '#ακυρώσεις', 'text' => 'Ακύρωσε ο {firstname}'], $context);
        \Pramnos\Conversations\SystemPost::reset();
        $toAdmin = ($action['run'])($this->ids['nikos'], ['to' => (string) $this->ids['maria'], 'text' => 'Για σένα'], $context);
        $tested  = ($action['run'])($this->ids['nikos'], ['to' => '#ακυρώσεις', 'text' => 'Δοκιμή'], $test);
        $nobody  = ($action['run'])($this->ids['nikos'], ['to' => '', 'text' => 'x'], $context);
        $message = Messages::get((int) $sent['detail']['message']);
        unset(Application::getInstance()->applicationInfo['features']);
        $without = Actions::get('conversation_post');

        // Assert
        $this->assertSame('done', $sent['status']);
        $this->assertSame($sent['detail']['message'], $again['detail']['message'], 'once per run');
        $this->assertSame('Ακύρωσε ο Νίκος', $message['body'], 'filled for the person');
        $this->assertSame(['Νίκος Π.', 'Ακυρώσεις', 'info'], [$message['card']['title'], $message['card']['from'], $message['card']['color']]);
        $this->assertStringContainsString((string) $this->ids['nikos'], $message['card']['url'], 'linked to their page');
        $this->assertSame('bot', Conversations::get((int) Messages::get((int) $toAdmin['detail']['message'])['conversationId'])['kind']);
        $this->assertSame([$this->ids['eleni']], Conversations::members((int) Messages::get((int) $tested['detail']['message'])['conversationId']), 'a test, to whoever asked');
        $this->assertSame('error', $nobody['status']);
        $this->assertNull($without, 'no conversations, no such action');
    }
}
