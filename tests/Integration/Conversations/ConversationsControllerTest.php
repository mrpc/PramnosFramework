<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Conversations as Chat;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Files;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * `/admin/Conversations/…`, the conversations' JSON, on each backend: what a browser asks for and writes, as
 * the account asking — refused to somebody who may not use them, or when the application does not
 * have them; a mistake 422, something not theirs to read 404; every write a token-checked POST.
 */
#[CoversClass(Chat::class)]
class ConversationsControllerTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The tree files are kept in for this test. */
    private string $tree = '';

    /** Clear the request, the tree, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        $_GET = $_POST = $_FILES = [];
        Request::setRawInput(null);
        if ($this->tree !== '' && is_dir($this->tree)) {
            exec('rm -rf ' . escapeshellarg($this->tree));
        }
        unset(Application::getInstance()->applicationInfo['features']);
        $this->probeTearDown();
    }

    /** The controller as `$name` asks it, for `$id` in the path, with this body. */
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
        $chat->asking = $name === '' ? null : $this->user($name);
        $response = $chat->{$action}();
        $this->assertInstanceOf(Response::class, $response);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)];
    }

    /**
     * A browser's whole round: the sidebar, a new channel, a message with a mention, a reply,
     * reading, reacting, pinning, editing, searching, notifications, a star, deleting, leaving.
     */
    #[DataProvider('databases')]
    public function testABrowsersRound(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];

        // Act
        $made    = $this->ask('eleni', 'create', null, ['kind' => 'channel', 'name' => 'Πωλήσεις', 'topic' => 'Οι πωλήσεις', 'members' => [$maria]]);
        $id      = $made['body']['id'];
        $posted  = $this->ask('eleni', 'post', $id, ['body' => 'Καλημέρα <@' . $maria . '>']);
        $side    = $this->ask('maria', 'conversations');
        $replied = $this->ask('maria', 'post', $id, ['body' => 'Καλημέρα', 'thread' => $posted['body']['id']]);
        $loud    = $this->ask('maria', 'post', $id, ['body' => 'Και στο κανάλι', 'thread' => $posted['body']['id'], 'broadcast' => true]);
        $this->ask('maria', 'read', $id, ['message' => $posted['body']['id']]);
        $this->ask('maria', 'react', $posted['body']['id'], ['emoji' => ':wave:']);
        $this->ask('eleni', 'pin', $posted['body']['id'], []);
        $edited  = $this->ask('eleni', 'edit', $posted['body']['id'], ['body' => 'Καλημέρα σε όλους']);
        $list    = $this->ask('maria', 'messages', $id);
        $thread  = $this->ask('maria', 'messages', $id, ['thread' => $posted['body']['id']]);
        $found   = $this->ask('maria', 'search', null, ['q' => 'καλημέρα']);
        $unread  = $this->ask('maria', 'unread', $id, ['message' => $posted['body']['id']]);
        $unreadSide = $this->ask('maria', 'conversations');
        $stranger = $this->ask('nikos', 'unread', $id, ['message' => $posted['body']['id']]);
        $this->ask('maria', 'notify', $id, ['notify' => 'mentions', 'muted' => true]);
        $starred = $this->ask('maria', 'star', $id, ['on' => true]);
        $starSide = $this->ask('maria', 'conversations');
        $this->ask('eleni', 'star', $id, ['on' => false]);
        $this->ask('nikos', 'join', $id);
        $this->ask('nikos', 'leave', $id);
        $this->ask('eleni', 'add', $id, ['person' => $this->ids['nikos']]);
        $this->ask('eleni', 'pin', $posted['body']['id'], ['on' => false]);
        $deleted = $this->ask('maria', 'delete', $replied['body']['id']);

        // Assert
        $this->assertSame(200, $made['status']);
        $this->assertSame(['channel', 'Πωλήσεις', 'owner'], [$made['body']['conversation']['kind'], $made['body']['conversation']['name'],
            $made['body']['conversation']['member']['role']], 'the new conversation itself, as messages gives it — no second request');
        $this->assertSame('πωλήσεις', Conversations::get($id)['slug']);
        $this->assertStringContainsString('@Μαρία Κ.', $posted['body']['message']['html'], 'the message as a reader sees it');
        $this->assertSame([1, 1], [$side['body']['conversations'][0]['unread'], $side['body']['conversations'][0]['mentions']]);
        $this->assertSame($maria, $side['body']['me']);
        $this->assertSame([false, true], [$replied['body']['message']['broadcast'], $loud['body']['message']['broadcast']], 'a reply also sent to the conversation');
        $this->assertSame([['ok' => true], 1, 404], [$unread['body'], $unreadSide['body']['conversations'][0]['unread'], $stranger['status']],
            'unread from the message, so it counts again; somebody not in it is refused');
        $this->assertSame('Καλημέρα σε όλους', $edited['body']['message']['body']);
        $this->assertSame([$posted['body']['id'], $loud['body']['id']], array_column($list['body']['messages'], 'id'), 'the reply sent to the conversation too');
        $this->assertSame([$posted['body']['id']], $list['body']['pinned'], 'pinned before it was unpinned');
        $this->assertSame(':wave:', $list['body']['messages'][0]['reactions'][0]['emoji']);
        $this->assertSame(['member', 'Οι πωλήσεις'], [$list['body']['conversation']['member']['role'], $list['body']['conversation']['topic']]);
        $this->assertSame([$posted['body']['id'], $replied['body']['id'], $loud['body']['id']], array_column($thread['body']['messages'], 'id'));
        $this->assertCount(2, $found['body']['messages']);
        $this->assertSame(['mentions', true], [Conversations::member($id, $maria)['notify'], Conversations::member($id, $maria)['muted']]);
        $this->assertSame([$eleni, $maria, $this->ids['nikos']], Conversations::members($id), 'nikos joined, left, was added');
        $this->assertSame(1, Conversations::forPerson($eleni)[0]['unread'], 'what eleni wrote is read; maria\'s reply sent to the conversation is not');
        $this->assertSame(['ok' => true], $starred['body']);
        $this->assertTrue($starSide['body']['conversations'][0]['starred'], 'her sidebar shows it starred');
        $this->assertFalse(Conversations::forPerson($eleni)[0]['starred'], 'a star is the member\'s own');
        $this->assertTrue(Conversations::forPerson($maria)[0]['starred']);
        $this->assertSame(['ok' => true], $deleted['body']);
    }

    /**
     * The views above the conversations through the controller: threads, activity, every unread
     * message, what was sent, saving a message and the saved ones, a conversation's files — and a
     * message or conversation the asker cannot read is 404, never saved, never listed.
     */
    #[DataProvider('databases')]
    public function testTheViewsAboveTheConversations(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $secret  = Conversations::create('private', 'secret', [$maria], $eleni);
        $root    = \Pramnos\Conversations\Messages::post($general, $eleni, 'Ρίζα');
        \Pramnos\Conversations\Messages::post($general, $maria, 'Απάντηση <@' . $eleni . '>', ['thread' => $root]);
        $hidden  = \Pramnos\Conversations\Messages::post($secret, $maria, 'μυστικό');

        // Act
        $threads  = $this->ask('eleni', 'threads');
        $activity = $this->ask('eleni', 'activity');
        $unreads  = $this->ask('eleni', 'unreads');
        $sent     = $this->ask('eleni', 'sent');
        $save     = $this->ask('eleni', 'save', $root, ['on' => true]);
        $saved    = $this->ask('eleni', 'saved');
        $list     = $this->ask('eleni', 'messages', $general);
        $refused  = $this->ask('nikos', 'save', $hidden, ['on' => true]);
        $missing  = $this->ask('eleni', 'save', 999999, ['on' => true]);
        $files    = $this->ask('eleni', 'files', $general);
        $closed   = $this->ask('nikos', 'files', $secret);

        // Assert
        $this->assertSame([$root], array_map(static fn (array $t): int => $t['root']['id'], $threads['body']['threads']));
        $this->assertSame('mention', $activity['body']['activity'][0]['kind']);
        $this->assertSame([$secret], array_column($unreads['body']['unreads'], 'id'), 'the thread reply is not unread in the channel');
        $this->assertSame([$root], array_column($sent['body']['messages'], 'id'));
        $this->assertSame(['ok' => true], $save['body']);
        $this->assertSame([$root], array_column($saved['body']['messages'], 'id'));
        $this->assertSame([$root], $list['body']['saved'], 'the conversation says which of its messages are kept');
        $this->assertSame(404, $refused['status'], 'nikos cannot read the closed one');
        $this->assertSame(404, $missing['status']);
        $this->assertSame(['files' => []], $files['body']);
        $this->assertSame(404, $closed['status']);
    }

    /**
     * A name's card and profile through the controller — and the faces the list, a conversation's
     * members and a card carry: the person, and with `show` what they wrote,
     * what mentions them and what they sent; somebody who may not use the conversations is 404 unless
     * they are in a conversation the reader reads, and then not somebody to write to.
     */
    #[DataProvider('databases')]
    public function testAPersonsCardAndProfile(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $hers    = \Pramnos\Conversations\Messages::post($general, $maria, 'Καλημέρα <@' . $eleni . '>');

        Application::getInstance()->applicationInfo['conversations']['avatars'] = fn (array $ids): array => array_fill_keys($ids, '/face.png');

        // Act
        $side     = $this->ask('maria', 'conversations');
        $inside   = $this->ask('maria', 'messages', $general);
        $card     = $this->ask('maria', 'person', $eleni);
        $messages = $this->ask('eleni', 'person', $maria, ['show' => 'messages']);
        $mentions = $this->ask('maria', 'person', $eleni, ['show' => 'mentions']);
        $files    = $this->ask('eleni', 'person', $maria, ['show' => 'files']);
        $nobody   = $this->ask('eleni', 'person', $this->ids['tasos']);
        Conversations::enroll($general, $this->ids['tasos']);
        $member   = $this->ask('eleni', 'person', $this->ids['tasos']);

        // Assert
        $this->assertSame(['Ελένη Σ.', [['id' => $general, 'title' => '#general']]], [$card['body']['person']['name'], $card['body']['person']['shared']]);
        $this->assertArrayNotHasKey('messages', $card['body'], 'the card alone, without show');
        $this->assertSame([$hers], array_column($messages['body']['messages'], 'id'));
        $this->assertSame([$hers], array_column($mentions['body']['messages'], 'id'));
        $this->assertSame([], $files['body']['files']);
        $this->assertSame(404, $nobody['status'], 'a stranger who may not use the conversations');
        $this->assertSame('/face.png', $side['body']['avatars'][$maria], 'the faces of the sidebar\'s people, the reader\'s own among them');
        $this->assertSame(['/face.png', '/face.png'], array_column($inside['body']['members'], 'avatar'), 'each member\'s face');
        $this->assertSame('/face.png', $card['body']['person']['avatar']);
        $this->assertSame([200, false], [$member['status'], $member['body']['person']['canMessage']], 'in a channel the reader reads: shown, not to be written to');
    }

    /**
     * Refused: somebody who may not use the conversations, an application without them; a mistake
     * is 422 with its reason, a conversation or message not theirs 404; writes need the token.
     */
    #[DataProvider('databases')]
    public function testRefusalsAndMistakes(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        $secret  = Conversations::create('private', 'finance', [$this->ids['maria']], $this->ids['eleni']);
        $message = \Pramnos\Conversations\Messages::post($secret, $this->ids['eleni'], 'μυστικό');

        // Act
        $tasos   = $this->ask('tasos', 'conversations');
        $nobody  = $this->ask('', 'conversations');
        $outside = $this->ask('nikos', 'messages', $secret);
        $react   = $this->ask('nikos', 'react', $message, ['emoji' => ':eyes:']);
        $empty   = $this->ask('eleni', 'post', $secret, ['body' => '  ']);
        $twice   = $this->ask('eleni', 'create', null, ['kind' => 'private', 'name' => 'finance']);
        Application::getInstance()->applicationInfo['features'] = [];
        $off     = $this->ask('eleni', 'conversations');
        $writes  = (new \ReflectionProperty(\Pramnos\Application\Controller::class, 'writeActions'))->getValue(new Chat(Application::getInstance()));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);
        $unverified = (new Chat(Application::getInstance()))->exec('create');
        $refusal    = (string) \Pramnos\Framework\Factory::getDocument('raw')->getContent();
        $code       = http_response_code();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Assert
        $this->assertNull($unverified);
        $this->assertSame(['error' => 'That request could not be verified, so nothing was changed. Try again from the page.'], json_decode($refusal, true),
            'a write without the token is told so in JSON — though the request says nothing of what it accepts — never redirected');
        $this->assertSame(403, $code);
        $this->assertSame([403, 403], [$tasos['status'], $nobody['status']]);
        $this->assertSame([404, 'That conversation no longer exists.'], [$outside['status'], $outside['body']['error']]);
        $this->assertSame(404, $react['status'], 'a message in a closed conversation is not his to react to');
        $this->assertSame([422, 'A message needs something in it.'], [$empty['status'], $empty['body']['error']]);
        $this->assertSame([422, 'There is a channel called #finance already.'], [$twice['status'], $twice['body']['error']]);
        $this->assertSame(403, $off['status'], 'an application without the conversations');
        $this->assertSame(['create', 'join', 'leave', 'add', 'post', 'edit', 'delete', 'react', 'pin', 'read', 'notify', 'star', 'save', 'upload', 'command', 'withask', 'unread'], $writes,
            'every write checks the session\'s token');
    }

    /**
     * `withask` opens one's conversation with Ask — the same one each time, with one's membership —
     * for whoever may ask it, and is a 404 to a person below the analyst's floor; a message posted
     * there is put to the analyst without `@Ask`.
     */
    #[DataProvider('databases')]
    public function testTheConversationWithAsk(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        \Pramnos\Application\Settings::setSetting('llm_openai_api_key', 'sk-test', false);
        $asked = [];
        \Pramnos\Conversations\Ask::queueWith(function (int $userId, string $question) use (&$asked): int {
            $asked[] = $question;

            return count($asked);
        });

        try {
            // Act
            $first  = $this->ask('eleni', 'withask');
            $again  = $this->ask('eleni', 'withask');
            $sales  = $this->ask('nikos', 'withask');
            $posted = $this->ask('eleni', 'post', $first['body']['id'], ['body' => 'πόσοι σήμερα;']);
        } finally {
            \Pramnos\Conversations\Ask::queueWith(null);
            \Pramnos\Application\Settings::setSetting('llm_openai_api_key', '', false);
        }

        // Assert
        $this->assertSame(200, $first['status']);
        $this->assertSame(['bot', 'Ask', 'member'], [$first['body']['conversation']['kind'], $first['body']['conversation']['name'], $first['body']['conversation']['member']['role']], 'a member of it, as of any bot conversation');
        $this->assertSame($first['body']['id'], $again['body']['id'], 'one conversation with Ask');
        $this->assertSame([404, 'Ask is not here for you.'], [$sales['status'], $sales['body']['error']]);
        $this->assertSame(200, $posted['status']);
        $this->assertSame(['πόσοι σήμερα;'], $asked, 'a question without @Ask');
    }

    /**
     * Uploading: a file kept for the message about to be written, a failed upload explained; a file
     * sent to a reader and a 404 to anybody else.
     */
    #[DataProvider('databases')]
    public function testUploadingAndFetchingAFile(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfchat-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->tree];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        $secret = Conversations::create('private', 'finance', [$this->ids['maria']], $this->ids['eleni']);
        $tmp    = (string) tempnam(sys_get_temp_dir(), 'pfchat');
        file_put_contents($tmp, 'ένα κείμενο');

        // Act
        $_FILES = ['file' => ['tmp_name' => $tmp, 'name' => 'notes.txt', 'error' => UPLOAD_ERR_OK]];
        $kept   = $this->ask('eleni', 'upload', $secret);
        $_FILES = ['file' => ['tmp_name' => '', 'name' => 'big.pdf', 'error' => UPLOAD_ERR_INI_SIZE]];
        $big    = $this->ask('eleni', 'upload', $secret);
        $_FILES = [];
        $none   = $this->ask('eleni', 'upload', $secret);
        $posted = $this->ask('eleni', 'post', $secret, ['files' => [$kept['body']['id']]]);
        $denied = $this->ask('nikos', 'file', $kept['body']['id']);
        $_GET   = ['_option' => (string) $kept['body']['id']];
        $reader = new class (Application::getInstance()) extends Chat {
            /** Maria, a member. */
            public ?object $asking = null;

            /** The test's account. */
            protected function me(): ?object
            {
                return $this->asking;
            }
        };
        $reader->asking = $this->user('maria');
        ob_start();
        try {
            $reader->file();
            $closed = false;
        } catch (\Pramnos\Application\ApplicationClosedException) {
            $closed = true;
        }
        $body = (string) ob_get_clean();

        // Assert
        $this->assertSame(['name' => 'notes.txt', 'size' => strlen('ένα κείμενο'), 'mime' => 'text/plain'], array_diff_key($kept['body'], ['id' => 0]));
        $this->assertSame([422, 'A file is at most 25 MB.'], [$big['status'], $big['body']['error']]);
        $this->assertSame([422, 'The file did not arrive.'], [$none['status'], $none['body']['error']]);
        $this->assertSame([$kept['body']['id']], array_column($posted['body']['message']['files'], 'id'));
        $this->assertSame(404, $denied['status']);
        $this->assertSame([true, 'ένα κείμενο'], [$closed, $body], 'a member is sent the file itself, and the request ends there');
        $this->assertSame($kept['body']['id'], Files::get($kept['body']['id'])['id']);
    }
}
