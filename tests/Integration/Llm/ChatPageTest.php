<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Web\ChatPageController;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * `/chat`, the conversation page for an application without a SPA, on MySQL and PostgreSQL.
 *
 * The page is drawn through a real View in every theme — a stand-in `$this` cannot see a view
 * variable that collides with one of View's own properties — and asking is followed both the
 * way a browser without JavaScript does and the way `pf-chat.js` does.
 */
#[CoversClass(ChatPageController::class)]
#[CoversClass(\Pramnos\Llm\Chat\Concerns\TakesQuestions::class)]
#[CoversClass(\Pramnos\Llm\Chat\Attachments::class)]
#[CoversClass(\Pramnos\Llm\Chat\Web\BulkView::class)]
class ChatPageTest extends LlmTestCase
{
    use ChatDatabase;

    private const THEMES = ['tailwind', 'bootstrap', 'plain-css'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $_POST = [];
        $_GET  = [];
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        // A bulk action writes to the audit log, which remembers that this database had no table for it.
        \Pramnos\Auth\AuditLog::resetTableCache();
        $_POST = [];
        $_GET  = [];
        $_SERVER['HTTP_ACCEPT'] = '';
        \Pramnos\Http\Request::resetInstance();
        $this->disconnectDatabase();
        parent::tearDown();
    }

    private function page(?Scope $scope = new Scope(7, null), string $theme = 'plain-css'): PageFixture
    {
        return new PageFixture($scope, $theme);
    }

    /**
     * Asked without JavaScript: queued, then the page follows it and, once answered, opens the
     * conversation — drawn through a real View in every theme.
     */
    #[DataProvider('databases')]
    public function testAskedWithoutJavaScript(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => []]);
        $this->answer([[200, self::says('**Fine**, thanks.')]]);
        $_POST = ['content' => 'How is it?'];

        // Act — asked: queued, and sent to the page following it
        $page = $this->page();
        $page->ask();
        preg_match('/ask=(\d+)/', $page->redirectedTo[0], $m);
        $waiting = $this->page();
        $_GET = ['ask' => $m[1]];
        \Pramnos\Http\Request::resetInstance();
        $drawnWaiting = $waiting->display();

        // The worker answers it
        (new \Pramnos\Llm\Chat\Asks($page->exposed()))->run($page->queue->tasks[0][1]);

        // Assert — while waiting: a refresh for browsers without JavaScript
        $this->assertStringContainsString('<meta http-equiv="refresh"', $drawnWaiting);
        $this->assertStringContainsString('data-pf-chat-waiting="' . $m[1] . '"', $drawnWaiting);

        foreach (self::THEMES as $theme) {
            // Answered: the page opens the conversation it went into
            $answered = $this->page(theme: $theme)->display();
            $this->assertStringContainsString('<strong>Fine</strong>, thanks.', $answered, $theme);
            $this->assertStringContainsString('How is it?', $answered, $theme);
            $this->assertStringContainsString('No data was read for this answer', $answered, $theme);
            $this->assertStringNotContainsString('http-equiv="refresh"', $answered, $theme);
        }
    }

    /**
     * What the page draws for pf-chat.js and for the eye, in every theme: the question with its
     * document folded away, which tools an answer read and with what, the context bar, the ⚙
     * with model, limits and memory, a ✕ per conversation, the file chips, and the script's words
     * and waiting lines.
     */
    #[DataProvider('databases')]
    public function testWhatThePageDraws(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['waiting_lines' => ['Counting the clicks…'], 'suggestions' => ['What did we spend this week?']]]);
        $stamp = \Pramnos\Llm\CallLog::instant(time());
        $qb    = fn (string $t) => $this->db->queryBuilder()->table($t);
        $qb('chat_conversations')->insert(['organization_id' => null, 'userid' => 7, 'title' => 'Spend', 'space' => 'chat', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $id = (int) $qb('chat_conversations')->first()->fields['conversation_id'];
        $qb('chat_messages')->insert(['conversation_id' => $id, 'role' => 'user', 'content' => "Read this\n\n---\n📎 brief.md\n\nThe brief", 'created_at' => $stamp, 'updated_at' => $stamp]);
        $qb('chat_messages')->insert(['conversation_id' => $id, 'role' => 'assistant', 'content' => 'Done.', 'tools' => json_encode([['name' => 'best-posts', 'arguments' => ['days' => 30]]]), 'context_tokens' => 64000, 'extra' => json_encode(['steps' => ['Let me **check** the posts.']]), 'created_at' => $stamp, 'updated_at' => $stamp]);
        $_GET = ['c' => (string) $id];
        \Pramnos\Http\Request::resetInstance();

        foreach (self::THEMES as $theme) {
            // Act
            $page = (string) $this->page(theme: $theme)->display();

            // Assert
            $this->assertStringContainsString('<summary>📎 brief.md</summary>', $page, $theme . ': the document folded away');
            $this->assertStringContainsString('Read 1 times from your data', $page, $theme);
            $this->assertStringContainsString('best-posts (days: 30)', $page, $theme);
            // What the model said while it called tools: folded away beside the answer, not in it
            $this->assertStringContainsString('<summary>What it did on the way</summary>', $page, $theme);
            $this->assertStringContainsString('<div class="pf-chat-step"><p>Let me <strong>check</strong> the posts.</p>', $page, $theme);
            $this->assertStringContainsString('<progress value="50" max="100"', $page, $theme . ': half the context');
            $this->assertStringContainsString('Model, limits and memory', $page, $theme);
            $this->assertStringContainsString('chat/delete/' . $id, $page, $theme . ': a ✕ for the conversation');
            $this->assertStringContainsString('data-pf-chat-chips', $page, $theme);
            $this->assertStringContainsString('data-pf-chat-files', $page, $theme);
            $this->assertStringContainsString('data-say-too-large=', $page, $theme);
            $this->assertStringContainsString('data-waiting-lines="[&quot;Counting the clicks…&quot;]"', $page, $theme);
            $this->assertStringNotContainsString('data-pf-chat-suggestion', $page, $theme . ': no examples over a conversation');
            $this->assertStringContainsString('<strong>You</strong>', $page, $theme . ': the question says whose it is');
            $this->assertStringContainsString('<strong>Assistant</strong>', $page, $theme . ': and so does the answer');
            $this->assertMatchesRegularExpression('/<time datetime="\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ" data-pf-chat-time>\d{1,2} \w{3} \d{4} \d\d:\d\d UTC<\/time>/', $page, $theme . ': a UTC instant, words for a page without script');
            $this->assertStringContainsString('data-pf-chat-copy="Done."', $page, $theme . ': the answer\'s Markdown, to copy');
            $this->assertStringContainsString('data-pf-chat-role="user" style="display:flex;flex-direction:column;align-items:flex-end', $page, $theme . ': the question on the right');
        }

        // A new conversation: the examples, each filling the box from its attribute
        $_GET = [];
        \Pramnos\Http\Request::resetInstance();
        foreach (self::THEMES as $theme) {
            $page = (string) $this->page(theme: $theme)->display();
            $this->assertStringContainsString('data-pf-chat-suggestion="What did we spend this week?"', $page, $theme);
        }
    }

    /**
     * The page is the application's own: an empty one is its greeting over one large composer,
     * with its placeholder, its accent colour and its `/` commands beside the page's, and the
     * examples under the composer; an open conversation has its title instead, and no greeting.
     * In every theme, the same hooks the shared stylesheet styles.
     *
     * Ask is "Naya" in one application and the analyst in another; a page that said "Ask AI" in
     * the framework's blue over a list of buttons would be nobody's.
     */
    #[DataProvider('databases')]
    public function testThePageLooksLikeTheApplications(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => [
            'greeting'    => 'Hi, I am Naya.',
            'placeholder' => 'Ask Naya…',
            'accent'      => '#7c3aed',
            'suggestions' => ['What did we spend this week?'],
            'commands'    => [['name' => 'week', 'says' => 'This week', 'text' => 'How did this week go?']],
        ]]);
        $stamp = \Pramnos\Llm\CallLog::instant(time());
        $this->db->queryBuilder()->table('chat_conversations')->insert(['organization_id' => null, 'userid' => 7, 'title' => 'Spend', 'space' => 'chat', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $id = (int) $this->db->queryBuilder()->table('chat_conversations')->first()->fields['conversation_id'];

        foreach (self::THEMES as $theme) {
            // Act
            $_GET = [];
            \Pramnos\Http\Request::resetInstance();
            $empty = (string) $this->page(theme: $theme)->display();
            $_GET = ['c' => (string) $id];
            \Pramnos\Http\Request::resetInstance();
            $open = (string) $this->page(theme: $theme)->display();

            // Assert — empty: the greeting, the brand, the commands, the examples after the composer
            $this->assertMatchesRegularExpression('/<div class="pf-chat pf-chat-empty[^"]*" style="--pf-chat-accent:#7c3aed"/', $empty, $theme);
            $this->assertStringContainsString('<h1>Hi, I am Naya.</h1>', $empty, $theme);
            $this->assertStringContainsString('placeholder="Ask Naya…"', $empty, $theme);
            $this->assertStringContainsString('&quot;name&quot;:&quot;week&quot;', $empty, $theme . ': the application\'s command');
            $this->assertStringContainsString('&quot;name&quot;:&quot;scheduled&quot;', $empty, $theme . ': beside the page\'s');
            $this->assertGreaterThan(strpos($empty, 'data-pf-chat-form'), strpos($empty, 'data-pf-chat-suggestion'), $theme . ': the examples under the composer');
            $this->assertStringContainsString('rows="3" class="pf-chat-input"', $empty, $theme . ': a large box when empty');
            // Open: its title, no greeting, no examples, a one-line box
            $this->assertStringNotContainsString('pf-chat-empty', $open, $theme);
            $this->assertStringNotContainsString('data-pf-chat-hero', $open, $theme);
            $this->assertStringContainsString('Spend</h1>', $open, $theme);
            $this->assertStringContainsString('rows="1" class="pf-chat-input"', $open, $theme);
        }

        // Without a greeting, the page's heading says who it is; without either, a plain line
        $this->configure(['chat' => []]);
        $_GET = [];
        \Pramnos\Http\Request::resetInstance();
        $plain = (string) $this->page()->display();
        $this->assertStringContainsString('<h1>How can I help?</h1>', $plain);
        $this->assertStringNotContainsString('--pf-chat-accent', $plain);
    }

    /**
     * Sharing from the page, in every theme: the open conversation and each artifact carry a Share
     * form; sharing says who it went to and who was nobody; the owner sees who has it and stops
     * it; the recipient sees it under "Shared with you" and opens it as a conversation of their
     * own; what is not there says so.
     */
    #[DataProvider('databases')]
    public function testSharingFromThePage(string $type, string $host, int $port): void
    {
        // Arrange — an owner and a recipient, a conversation with an artifact
        $this->connectDatabase($type, $host, $port);
        $mark = 'pg' . bin2hex(random_bytes(3));
        $ids  = [];
        foreach (['owner', 'maria'] as $who) {
            $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark . $who, 'email' => $mark . $who . '@example.com', 'password' => 'x', 'regdate' => time()]);
            $ids[$who] = (int) $this->db->getInsertId();
        }
        $owner = new Scope($ids['owner'], null);
        $maria = new Scope($ids['maria'], null);
        $chat  = $this->page($owner)->exposed();
        $id    = (int) $chat->seed('Spend', [['role' => 'user', 'content' => 'How much?'], ['role' => 'assistant', 'content' => 'This much.']]);
        $made  = (new \Pramnos\Llm\Chat\Artifacts($owner))->create(['kind' => 'text', 'title' => 'Memo', 'text' => 'x']);
        $answer = (int) $chat->find($id)['messages'][1]['message_id'];
        $this->db->queryBuilder()->table('chat_artifacts')->where('artifact_id', $made['artifact_id'])->update(['conversation_id' => $id, 'message_id' => $answer]);

        try {
            // Act — shared from the page
            $_POST   = ['kind' => 'conversation', 'names' => $mark . 'maria, nobody-' . $mark, 'conversation_id' => (string) $id];
            $sharing = $this->page($owner);
            $sharing->share($id);
            $shareId = (new \Pramnos\Llm\Chat\Shares())->withMe($ids['maria'])[0]['share_id'];

            // Assert — said, and back to the conversation
            $this->assertSame(['Shared with ' . $mark . 'maria.'], $sharing->messages);
            $this->assertSame(['Nobody here is called nobody-' . $mark . '.'], $sharing->errors);
            $this->assertStringEndsWith('chat?c=' . $id, $sharing->redirectedTo[0]);

            foreach (self::THEMES as $theme) {
                // Act — drawn for the owner and for the recipient
                $_GET = ['c' => (string) $id];
                \Pramnos\Http\Request::resetInstance();
                $mine = (string) $this->page($owner, $theme)->display();
                $_GET = [];
                \Pramnos\Http\Request::resetInstance();
                $hers = (string) $this->page($maria, $theme)->display();

                // Assert
                $this->assertStringContainsString('data-pf-share="conversation-' . $id . '"', $mine, $theme);
                $this->assertStringContainsString('data-pf-share="artifact-' . $made['artifact_id'] . '"', $mine, $theme . ': the artifact can be shared too');
                $this->assertStringContainsString('chat/unshare/' . $shareId, $mine, $theme . ': who has it, with a way to stop');
                $this->assertStringContainsString('chat/shared/' . $shareId, $hers, $theme . ': under Shared with you');
            }

            // Act — the recipient opens it; the owner stops it; what is not there
            $opening = $this->page($maria);
            $opening->shared($shareId);
            $copy    = (int) preg_replace('/^.*c=/', '', $opening->redirectedTo[0]);
            $_POST   = ['conversation_id' => (string) $id];
            $stop    = $this->page($owner);
            $stop->unshare($shareId);
            $again   = $this->page($owner);
            $again->unshare($shareId);
            $gone    = $this->page($maria);
            $gone->shared(999999);
            $_POST   = ['names' => $mark . 'maria'];
            $missing = $this->page($owner);
            $missing->share(999999);
            $signedOut = $this->page(null);
            $signedOut->share($id);

            // Assert
            $this->assertSame(['How much?', 'This much.'], array_column($this->page($maria)->exposed()->find($copy)['messages'], 'content'));
            $this->assertSame(['No longer shared.'], $stop->messages);
            $this->assertSame(['There is no such share.'], $again->errors);
            $this->assertSame(['There is nothing shared with you here.'], $gone->errors);
            $this->assertSame(['There is no such conversation.'], $missing->errors);
            $this->assertSame(['This needs somebody signed in.'], $signedOut->errors);
        } finally {
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', array_values($ids))->delete();
        }
    }

    /**
     * Bulk actions on a list from the page, in every theme: a list artifact offers what
     * BulkActions allows for its kind of record, with a button that says how many; running one
     * says what it did, or that it was queued; a list that is not the reader's is not there.
     */
    #[DataProvider('databases')]
    public function testActingOnAListFromThePage(string $type, string $host, int $port): void
    {
        // Arrange — an action on people any signed-in person may take, and a list of three
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Bulk\BulkActions::reset();
        $ran = [];
        \Pramnos\Bulk\BulkActions::define('user', 'thank', 'Thank them', ['note' => ['label' => 'Note', 'type' => 'textarea']],
            function (array $ids, array $params, int $by) use (&$ran): array {
                $ran[] = [$ids, $params, $by];

                return ['done' => count($ids)];
            }, allowed: static fn (int $user): bool => true);
        \Pramnos\Bulk\BulkActions::forget('user', 'group_add');
        $owner = new Scope(7, null);
        $chat  = $this->page($owner)->exposed();
        $id    = (int) $chat->seed('People', [['role' => 'user', 'content' => 'Who?'], ['role' => 'assistant', 'content' => 'These.']]);
        $made  = (new \Pramnos\Llm\Chat\Artifacts($owner))->create(['kind' => 'list', 'title' => 'Three', 'entity' => 'user', 'ids' => [4, 5, 6]]);
        $this->db->queryBuilder()->table('chat_artifacts')->where('artifact_id', $made['artifact_id'])
            ->update(['conversation_id' => $id, 'message_id' => (int) $chat->find($id)['messages'][1]['message_id']]);

        try {
            foreach (self::THEMES as $theme) {
                // Act
                $_GET = ['c' => (string) $id];
                \Pramnos\Http\Request::resetInstance();
                $html = (string) $this->page($owner, $theme)->display();

                // Assert
                $this->assertStringContainsString('data-pf-bulk="' . $made['artifact_id'] . '"', $html, $theme);
                $this->assertStringContainsString('<summary>Thank them</summary>', $html, $theme);
                $this->assertStringContainsString('chat/act/' . $made['artifact_id'], $html, $theme);
                $this->assertStringContainsString('Do it for 3', $html, $theme);
                $this->assertStringContainsString('name="params[note]"', $html, $theme);
            }

            // Act — run from the page; a list that is not theirs
            $_POST  = ['action' => 'thank', 'params' => ['note' => 'Well done'], 'conversation_id' => (string) $id];
            $acting = $this->page($owner);
            $acting->act($made['artifact_id']);
            $other  = $this->page(new Scope(8, null));
            $other->act($made['artifact_id']);
            \Pramnos\Bulk\BulkActions::useQueue($queue = new RecordingQueue());
            $long   = (new \Pramnos\Llm\Chat\Artifacts($owner))->create(['kind' => 'list', 'title' => 'Many', 'entity' => 'user', 'ids' => range(10, 10 + \Pramnos\Bulk\BulkActions::INLINE)]);
            $queued = $this->page($owner);
            $queued->act($long['artifact_id']);
            $_POST  = ['action' => 'thank', 'params' => [], 'conversation_id' => (string) $id];
            \Pramnos\Bulk\BulkActions::define('user', 'shout', 'Shout', [], static fn (): array => ['done' => 3, 'says' => 'Shouted.'], allowed: static fn (): bool => true);
            $_POST['action'] = 'shout';
            $said   = $this->page($owner);
            $said->act($made['artifact_id']);

            // Assert
            $this->assertSame([[[4, 5, 6], ['note' => 'Well done'], 7]], $ran);
            $this->assertSame(['Done for 3.'], $acting->messages);
            $this->assertStringEndsWith('chat?c=' . $id, $acting->redirectedTo[0]);
            $this->assertSame(['There is no such list.'], $other->errors);
            $this->assertSame([(\Pramnos\Bulk\BulkActions::INLINE + 1) . ' queued; they will be done shortly.'], $queued->messages);
            $this->assertSame(['Shouted.'], $said->messages);
        } finally {
            \Pramnos\Bulk\BulkActions::reset();
        }
    }

    /**
     * Asked through pf-chat.js: JSON, with the same refusals; the question followed as JSON.
     */
    #[DataProvider('databases')]
    public function testAskedThroughTheScript(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        // Act
        $_POST  = ['content' => 'Hi'];
        $queued = json_decode((string) $this->page()->ask(), true);
        $_POST  = ['content' => ''];
        $empty  = json_decode((string) $this->page()->ask(), true);
        $asked  = json_decode((string) $this->page()->asked($queued['data']['ask_id']), true);
        $none   = json_decode((string) $this->page()->asked(999), true);

        // Assert
        $this->assertSame(202, $queued['status']);
        $this->assertSame([422, 'Ask it something.'], [$empty['status'], $empty['error']]);
        $this->assertSame('waiting', $asked['data']['status']);
        $this->assertSame(404, $none['status']);
    }

    /**
     * A refusal goes back to the page with the reason; files uploaded with the question become
     * its attachment.
     */
    #[DataProvider('databases')]
    public function testRefusalsAndFiles(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);

        // Act — refused
        $_POST = ['content' => ''];
        $refused = $this->page();
        $refused->ask();

        // Act — with a file
        $_POST = ['content' => 'Read this', 'conversation_id' => '0'];
        $withFile = $this->page();
        $withFile->files = [['name' => 'brief.md', 'bytes' => 'The brief']];
        $withFile->ask();

        // Assert
        $this->assertSame(['Ask it something.'], $refused->errors);
        $this->assertStringEndsWith('/chat', $refused->redirectedTo[0]);
        $this->assertSame('brief.md', $withFile->queue->tasks[0][1]['attachment']['name']);
        $uploaded = static fn (): array => (new \ReflectionMethod(ChatPageController::class, 'uploaded'))->invoke(new ChatPageController());
        $_FILES = [];
        $this->assertSame([], $uploaded(), 'nothing uploaded is no files');
        // A file that failed to upload, or one not uploaded through this request, is not read.
        $_FILES = ['files' => ['name' => ['a.md', 'b.md'], 'tmp_name' => ['/etc/hostname', ''], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_PARTIAL]]];
        $this->assertSame([], $uploaded());
        $_FILES = [];
    }

    /**
     * Forgetting a conversation or a memory, and agreeing and withdrawing — each back to the page.
     */
    #[DataProvider('databases')]
    public function testForgettingAndAgreeing(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['consent' => \Pramnos\Llm\Policy\StoredConsent::class]);
        $this->setting('llm_provider', 'deepseek');
        $this->setting('llm_deepseek_api_key', 'sk-d');
        $scope  = new Scope(7, null);
        $memory = new \Pramnos\Llm\Chat\Memory($scope);
        $memory->remember('fact', 'Likes tables');
        $id = $memory->all()[0]['memory_id'];

        // Act / Assert — consent asked on the page, then given
        $before = $this->page()->display();
        $this->assertStringContainsString('Nothing is sent until you agree.', $before);
        $_POST = ['give' => '1'];
        $this->page()->consent();
        $after = $this->page()->display();
        $this->assertStringContainsString('You agreed to the AI features using DeepSeek.', $after);
        $_POST = [];
        $this->page()->consent();
        $this->assertStringContainsString('Nothing is sent until you agree.', $this->page()->display());

        // Forgetting
        $forgot = $this->page();
        $forgot->forget($id);
        $this->assertSame(['Forgotten.'], $forgot->messages);
        $again = $this->page();
        $again->forget($id);
        $this->assertSame(['There is no such memory.'], $again->errors);
        $deleted = $this->page();
        $deleted->delete(999);
        $this->assertSame(['There is no such conversation.'], $deleted->errors);
    }

    /**
     * Nobody signed in is sent to sign in; the widget is drawn only for somebody signed in.
     */
    #[DataProvider('databases')]
    public function testSignedOutAndTheWidget(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $page = $this->page(null);

        // Act
        $drawn = $page->display();

        // Assert
        $this->assertNull($drawn);
        $this->assertStringEndsWith('login?return=chat', $page->redirectedTo[0]);

        foreach (self::THEMES as $theme) {
            $file = ROOT . '/scaffolding/themes/' . $theme . '/views/partials/chat_widget.html.php';
            \Pramnos\Llm\Llm::use('router', new SignedIn());
            // No key yet: a question that can only fail is not invited
            \Pramnos\Application\Settings::setSetting('llm_openai_api_key', '', false);
            ob_start();
            include $file;
            $this->assertSame('', (string) ob_get_clean(), $theme . ': no key, no widget');
            \Pramnos\Application\Settings::setSetting('llm_openai_api_key', 'sk-test', false);
            ob_start();
            include $file;
            $widget = (string) ob_get_clean();
            $this->assertStringContainsString('chat/ask', $widget, $theme);
            \Pramnos\Llm\Llm::reset();
            ob_start();
            include $file;
            $this->assertSame('', (string) ob_get_clean(), $theme . ': nobody signed in, no widget');
        }
    }

    /**
     * The page's JSON for pf-chat.js: the status as the HTTP status, and the answer encoded
     * with its Unicode as it is.
     */
    public function testTheJsonAnswer(): void
    {
        // Act
        $body = (new \ReflectionMethod(ChatPageController::class, 'json'))->invoke(new ChatPageController(), ['status' => 404, 'error' => 'Δεν υπάρχει.']);

        // Assert
        $this->assertSame('{"status":404,"error":"Δεν υπάρχει."}', $body);
        $this->assertSame(404, http_response_code());
        http_response_code(200);
    }
}

/** The page, with its redirects, flash, JSON, uploads and queue recorded, drawn through a real View. */
class PageFixture extends ChatPageController
{
    /** @var list<string> */
    public array $redirectedTo = [];

    /** @var list<string> */
    public array $messages = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<array{name: string, bytes: string}> */
    public array $files = [];

    public RecordingQueue $queue;

    public function __construct(private ?Scope $as, private string $theme)
    {
        $this->queue = new RecordingQueue();
    }

    public function exposed(): \Pramnos\Llm\Chat\Conversations
    {
        return $this->conversations();
    }

    /** The theme this page draws in. */
    public function themeName(): string
    {
        return $this->theme;
    }

    protected function scope(): ?Scope
    {
        return $this->as;
    }

    protected function asks(): \Pramnos\Llm\Chat\Asks
    {
        return new \Pramnos\Llm\Chat\Asks($this->conversations(), $this->queue);
    }

    protected function uploaded(): array
    {
        return $this->files;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo[] = (string) $url;
    }

    protected function addMessage($message)
    {
        $this->messages[] = (string) $message;

        return $this;
    }

    protected function addError($error)
    {
        $this->errors[] = (string) $error;

        return $this;
    }

    protected function json(array $answer): mixed
    {
        return json_encode($answer);
    }

    /** The view, as View draws it, from the chosen theme's scaffolding. */
    public function &getView($name = '', $type = '', $args = array())
    {
        $dir  = ROOT . '/scaffolding/themes/' . $this->theme . '/views/' . $name;
        $view = new class (new \Pramnos\Application\Controller(), $dir, $name) extends \Pramnos\Application\View {
            public function display($tpl = '', $render = false)
            {
                $file = $this->path . '/' . $this->name . '.html.php';
                ob_start();
                \Closure::bind(function () use ($file): void {
                    include $file;
                }, $this, \Pramnos\Application\View::class)();

                return (string) ob_get_clean();
            }

            public function insert(string $template, array $data = []): void
            {
            }
        };

        return $view;
    }
}

/** A router that says somebody is signed in. */
final class SignedIn extends \Pramnos\Llm\Policy\DefaultRouter
{
    public function context(array $context): array
    {
        return ['userid' => 7];
    }
}
