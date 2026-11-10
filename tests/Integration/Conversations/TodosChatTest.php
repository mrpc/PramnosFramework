<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Broadcasting\Testing\FakeDriver;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Inbox;
use Pramnos\Conversations\Messages;
use Pramnos\Framework\Migrations\Todos\CreateTodosTable;
use Pramnos\Todos\Chat;
use Pramnos\Todos\Todos;

/**
 * The to-dos in the conversations (FW-363, step 3), against MySQL with and without a prefix and
 * PostgreSQL: "Add to-do" and "Remind me about this" on a message, a to-do written in the chat,
 * the chat's list with where each came from, the message's mark, "move to saved", and
 * `todo.changed` told to the people an item concerns.
 */
#[CoversClass(Chat::class)]
#[CoversClass(\Pramnos\Application\Controllers\Conversations::class)]
#[CoversClass(\Pramnos\Conversations\Realtime::class)]
class TodosChatTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The application's info before the test. */
    private array $info = [];

    /** The probe with the to-do table, and the settings the test changes. */
    private function ready(string $type, string $host, int $port, string $user): void
    {
        $this->boot($type, $host, $port, $user);
        require_once ROOT . '/database/migrations/framework/todos/2026_11_09_000011_create_todos_table.php';
        \Pramnos\Framework\Testing\Schema::ensure([CreateTodosTable::class], $this->db);
        $this->db->queryBuilder()->table(Todos::TABLE)->delete();
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'UTC'];
        Application::getInstance()->applicationInfo['todos'] = [];
        Todos::forgetSources();
    }

    /** The settings back, the fake broadcaster gone, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        if ($this->info !== []) {
            Application::getInstance()->applicationInfo = $this->info;
        }
        FakeDriver::restore();
        Todos::forgetSources();
        $_GET = $_POST = [];
        $this->probeTearDown();
    }

    /**
     * From a message: "Add to-do" keeps the whole message — its address too — private and the
     * reader's; "Remind me" names the writer and is reminded at the preset; somebody who cannot read
     * the message gets nothing. Written in the chat: the text as written, its address the link.
     * The list says where each came from, filters and orders; the mark lists the messages with an
     * open to-do; "move to saved" saves the message and the item goes; every change is told.
     */
    #[DataProvider('databases')]
    public function testToDosFromMessagesAndTheChatsList(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $direct  = Conversations::create('dm', null, [$maria], $eleni);
        $said    = Messages::post($general, $eleni, 'Send the contract https://example.test/c/7');
        $asked   = Messages::post($direct, $eleni, 'Can you call me?');
        $fake    = FakeDriver::swap();
        Todos::source('ticket', static fn (): array => ['ticket:1' => ['title' => 'Answer the ticket', 'assigned_to' => $maria, 'due' => '2020-01-01']]);
        Todos::touch(0);                     // the list's first read syncs it
        $now     = time();

        // Act
        $kept    = Chat::fromMessage($said, $this->user('maria'));
        $remind  = Chat::fromMessage($asked, $this->user('maria'), '1h');
        $written = Chat::write($this->user('maria'), "Book the room https://example.test/r\n- for **ten**", 'tomorrow');
        try {
            Chat::fromMessage($asked, $this->user('nikos'));   // a direct conversation not his
            $outsider = 'added';
        } catch (\DomainException) {
            $outsider = 'refused';
        }
        $all       = Chat::list($this->user('maria'));
        $messages  = array_column(Chat::list($this->user('maria'), 'messages'), 'id');
        $automatic = array_column(Chat::list($this->user('maria'), 'automatic'), 'title');
        $overdue   = array_column(Chat::list($this->user('maria'), 'overdue'), 'title');
        $newest    = array_column(Chat::list($this->user('maria'), 'all', 'newest'), 'id');
        $marks     = Chat::messageIds($maria, [$said, $asked, 999999]);
        Chat::toSaved($kept['id'], $this->user('maria'));

        // Assert
        $this->assertSame('refused', $outsider, 'not a reader of the conversation');
        $this->assertSame(['Send the contract https://example.test/c/7', null, true, $maria, $said],
            [$kept['title'], $kept['link'], $kept['private'], $kept['assigned_to'], $kept['messageid']], 'the whole message, private, the reader\'s');
        $this->assertSame('Message from Ελένη Σ.: Can you call me?', $remind['title']);
        $this->assertEqualsWithDelta($now + 3600, $remind['remind_at'], 5);
        $this->assertSame("Book the room https://example.test/r\n- for **ten**", $written['title'], 'as written, its address kept');
        $this->assertSame('https://example.test/r', $written['link']);
        $this->assertSame(Chat::at('tomorrow'), $written['remind_at']);
        $from = array_column($all, 'from', 'id');
        $this->assertSame('Ελένη Σ. in #general', $from[$kept['id']]);
        $this->assertSame('Direct with Ελένη Σ.', $from[$remind['id']]);
        $this->assertSame(['Answer the ticket'], $automatic);
        $this->assertSame(['Answer the ticket'], $overdue, 'its day passed');
        $this->assertEqualsCanonicalizing([$kept['id'], $remind['id']], $messages);
        $this->assertSame('Answer the ticket', $all[0]['title'], 'soonest first: the overdue one');
        $this->assertSame(array_column($all, 'id', 'title')['Answer the ticket'], $newest[0], 'newest first: the ticket, opened at the first read');
        $this->assertEqualsCanonicalizing([$said, $asked], $marks);
        $this->assertNull(Todos::get($kept['id']), 'moved to saved: gone');
        $this->assertContains($said, Inbox::savedIds($maria, [$said]), 'and the message saved');
        $this->assertTrue($fake->hasBroadcast(null, 'todo.changed', static fn (array $p): bool => $p['id'] === $kept['id'] && $p['deleted'] === true));
        $this->assertSame(['20m', '1h', '3h', 'tomorrow', 'monday'], array_column(Chat::presets(), 'key'));
    }

    /**
     * Where the installation has no to-do list, the messages' marks ask nothing of the database
     * (FW-388).
     *
     * `messageIds()` is asked on every conversation opened. Without the table its query failed and
     * the failure was caught — the page stood, but the server and the application each logged an
     * error every time. It answers no marks, and no statement on the table is sent.
     */
    #[DataProvider('databases')]
    public function testNoToDoListAsksNothing(string $type, string $host, int $port, string $user): void
    {
        // Arrange — the table gone, and the "it is there" this process remembered forgotten
        $this->ready($type, $host, $port, $user);
        $this->db->schema()->dropIfExists(Todos::TABLE);
        (new \ReflectionProperty(Todos::class, 'installed'))->setValue(null, null);
        $this->db->error_text = '';

        // Act
        $marks = Chat::messageIds(1, [1, 2, 3]);

        // Assert
        $this->assertSame([], $marks);
        // No statement failed on this connection: the query on the missing table was not sent.
        $this->assertSame('', (string) $this->db->error_text);
    }

    /**
     * The presets: minutes and hours from now; tomorrow and next Monday at 9:00 in the zone; a
     * preset there is not refused.
     */
    public function testThePresets(): void
    {
        // Arrange — a Wednesday afternoon
        $now  = (int) strtotime('2026-11-11 15:30 UTC');
        $zone = new \DateTimeZone('UTC');

        // Act
        $at = array_map(static fn (string $p): string => gmdate('Y-m-d H:i', Chat::at($p, $now, $zone)), Chat::PRESETS);

        // Assert
        $this->assertSame(['2026-11-11 15:50', '2026-11-11 16:30', '2026-11-11 18:30', '2026-11-12 09:00', '2026-11-16 09:00'], $at);
        $this->expectException(\InvalidArgumentException::class);
        Chat::at('someday', $now, $zone);
    }

    /**
     * Through the screen: `todo` from a message and written, `todos` with its presets (and the
     * bell's to-do notices settled), `todoupdate` with a preset, `todosave`, `tododelete`; the
     * `messages` answer carries the reader's marks; a message the reader cannot read is a 404, a
     * bad preset a 422.
     */
    #[DataProvider('databases')]
    public function testThroughTheScreen(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $said    = Messages::post($general, $eleni, 'Check the numbers');
        $private = Conversations::create('dm', null, [$this->ids['nikos']], $eleni);   // not hers to read

        // Act
        $fromMessage = $this->ask('maria', 'addtodo', 0, ['message' => $said, 'preset' => '20m']);
        $written     = $this->ask('maria', 'addtodo', 0, ['text' => 'Water the plants']);
        $refused     = $this->ask('maria', 'addtodo', 0, ['message' => Messages::post($private, $eleni, 'Secret')]);
        $badPreset   = $this->ask('maria', 'addtodo', 0, ['text' => 'x', 'preset' => 'someday']);
        $listed      = $this->ask('maria', 'todolist', 0, ['filter' => 'messages']);
        $id          = (int) $written['body']['todo']['id'];
        $updated     = $this->ask('maria', 'changetodo', $id, ['status' => Todos::IN_PROGRESS, 'preset' => '3h', 'title' => 'Water the plants twice']);
        $item        = Todos::get($id);
        $marks       = $this->ask('maria', 'messages', $general, []);
        $saved       = $this->ask('maria', 'savetodo', (int) $fromMessage['body']['todo']['id'], []);
        $deleted     = $this->ask('maria', 'deletetodo', $id, []);

        // Assert
        $this->assertSame([200, 200, 422], [$fromMessage['status'], $written['status'], $badPreset['status']]);
        $this->assertContains($refused['status'], [404], 'a message the reader cannot read');
        $this->assertSame(200, $listed['status']);
        $this->assertSame([$said], array_column($listed['body']['todos'], 'messageid'));
        $this->assertCount(5, $listed['body']['presets']);
        $this->assertSame(200, $updated['status']);
        $this->assertSame([Todos::IN_PROGRESS, 'Water the plants twice'], [$item['status'], $item['title']]);
        $this->assertEqualsWithDelta(time() + 10800, $item['remind_at'], 5);
        $this->assertSame([$said], $marks['body']['todos'], 'the "✓ to-do added" mark');
        $this->assertSame([200, 200], [$saved['status'], $deleted['status']]);
        $this->assertNull(Todos::get($id));
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
        $_GET  = ['_option' => (string) $id] + ($action === 'todolist' ? array_map('strval', $values) : []);
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
