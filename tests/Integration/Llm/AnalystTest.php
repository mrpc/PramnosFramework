<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Llm\Analyst\Analyst;
use Pramnos\Llm\Analyst\AnalystAssistant;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Support\ReusesProbeDatabase;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The administrators' analyst over a real installation's tables, on MySQL and PostgreSQL.
 *
 * Every table it reads is built by its own migration and filled with people who have names,
 * addresses and phone numbers — and every aggregate tool's answer is checked for not one of
 * them, since the provider that reads it is a third party.
 */
#[CoversClass(Analyst::class)]
#[CoversClass(AnalystAssistant::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\AdminTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\UsersTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\UserTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\SessionsTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\OrganizationsTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\MailingListsTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\EmailTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\PushTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\QueueTool::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\LlmSpendTool::class)]
#[CoversClass(Scope::class)]
#[CoversClass(\Pramnos\Llm\Chat\Attachments::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\Window::class)]
#[CoversClass(\Pramnos\Llm\Chat\Chat::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Desk::class)]
#[CoversClass(\Pramnos\Application\Controllers\AiController::class)]
#[CoversClass(\Pramnos\Llm\Chat\AskTask::class)]
class AnalystTest extends LlmTestCase
{
    use ReusesProbeDatabase;

    private const PROBE = 'pramnos_analyst_probe';

    /** People and what identifies them, none of which an aggregate may carry. */
    private const PEOPLE = [
        ['userid' => 7, 'username' => 'maria.k', 'email' => 'maria@example.com', 'firstname' => 'Maria', 'lastname' => 'Konstantinou', 'usertype' => 99],
        ['userid' => 8, 'username' => 'nikos', 'email' => 'nikos@example.org', 'firstname' => 'Nikos', 'lastname' => 'Papadakis', 'usertype' => 1],
        ['userid' => 9, 'username' => 'eleni', 'email' => 'eleni@example.net', 'firstname' => 'Eleni', 'lastname' => 'Georgiou', 'usertype' => 1],
    ];

    private Database $db;

    private ?Database $previous = null;

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    private static function connection(string $type, string $host, int $port, string $user, string $name): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = $name;

        return $db;
    }

    private function boot(string $type, string $host, int $port, string $user): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $admin = self::connection($type, $host, $port, $user, TEST_DATABASE);
        try {
            if (!$admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        $llm = [];
        foreach (glob(ROOT . '/database/migrations/framework/llm/*.php') ?: [] as $file) {
            preg_match('/^class (\w+)/m', (string) file_get_contents($file), $m);
            $llm[] = 'Pramnos\\Framework\\Migrations\\Llm\\' . $m[1];
        }

        $this->db = $this->openProbe($admin, self::PROBE, static fn (string $name): Database => self::connection($type, $host, $port, $user, $name), [
            \Pramnos\Framework\Migrations\Core\CreateFrameworkPoliciesTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
            \Pramnos\Framework\Migrations\Core\CreateSessionsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateOrganizationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverSchema::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserOrganizationsTable::class,
            \Pramnos\Framework\Migrations\Core\CreatePramnosSchema::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailingListSubscribersTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            \Pramnos\Framework\Migrations\Notifications\CreatePushLogTable::class,
            \Pramnos\Framework\Migrations\Queue\CreateQueueitemsTable::class,
            ...$llm,
        ]);
        $admin->close();

        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        parent::tearDown();
    }

    private function insert(string $table, array $row): void
    {
        $this->db->queryBuilder()->table($table)->insert($row);
    }

    /** A small installation: three people, two organisations, mail, push, a queue and model calls. */
    private function seed(): void
    {
        $now = time();
        $ts  = static fn (int $ago): string => gmdate('Y-m-d H:i:s', $now - $ago);

        foreach (self::PEOPLE as $i => $p) {
            $this->insert('users', $p + ['password' => 'x', 'regdate' => $now - 86400 * (2 + $i), 'lastlogin' => $i === 2 ? 0 : $now - 3600, 'active' => 1, 'validated' => $i === 0 ? 1 : 0]);
        }
        $this->insert('sessions', ['visitorid' => 'v1', 'uname' => 'maria.k', 'time' => $now - 60, 'host_addr' => '192.168.1.10', 'guest' => 0, 'userid' => 7, 'agent' => 'Firefox', 'url' => '/x?email=maria@example.com', 'history' => '', 'sid' => 's' . $now . mt_rand()]);
        $this->insert('sessions', ['visitorid' => 'v2', 'uname' => '', 'time' => $now - 120, 'host_addr' => '10.0.0.2', 'guest' => 1, 'userid' => 0, 'agent' => 'Safari', 'url' => '/', 'history' => '', 'sid' => 's' . $now . mt_rand()]);

        $this->insert('organizations', ['organization_id' => 1, 'name' => 'Daily News', 'org_type' => 'newsroom', 'is_active' => true, 'created_at' => $ts(86400)]);
        $this->insert('organizations', ['organization_id' => 2, 'name' => 'Radio Aroma', 'org_type' => 'radio', 'is_active' => true, 'created_at' => $ts(86400 * 3)]);
        foreach ([[7, 1], [8, 1], [9, 2]] as [$user, $org]) {
            $this->insert(\Pramnos\Auth\Role::membershipTable(), ['userid' => $user, \Pramnos\Auth\Role::organizationColumn() => $org, 'is_active' => true]);
        }

        $this->insert('pramnos.mailing_list_subscribers', ['list' => 'news', 'email' => 'maria@example.com', 'userid' => 7, 'status' => 'confirmed', 'created_at' => $now - 86400, 'confirmed_at' => $now - 80000]);
        $this->insert('pramnos.mailing_list_subscribers', ['list' => 'news', 'email' => 'nikos@example.org', 'userid' => 8, 'status' => 'unsubscribed', 'created_at' => $now - 86400 * 2, 'unsubscribed_at' => $now - 3600]);

        $this->insert('pramnos.emailtracking', ['tracking_id' => 't1', 'mailid' => 1, 'recipient' => 'maria@example.com', 'list' => 'news', 'subject' => 'Welcome Maria, call +30 694 123 4567', 'sent_at' => $now - 7200, 'opens' => 2, 'proxy_opens' => 0, 'clicks' => 1]);
        $this->insert('pramnos.emailtracking', ['tracking_id' => 't2', 'mailid' => 2, 'recipient' => 'nikos@example.org', 'list' => 'news', 'subject' => 'Weekly digest', 'sent_at' => $now - 7200, 'opens' => 0, 'proxy_opens' => 1, 'clicks' => 0]);
        foreach (['t3', 't4'] as $id) {
            $this->insert('pramnos.emailtracking', ['tracking_id' => $id, 'mailid' => 3, 'recipient' => 'eleni@example.net', 'list' => 'news', 'subject' => 'Weekly digest', 'sent_at' => $now - 7200, 'opens' => 0, 'proxy_opens' => 0, 'clicks' => 0]);
        }
        $this->insert('pramnos.emailtrackingclicks', ['tracking_id' => 't1', 'url' => 'https://example.com/offer?u=maria@example.com&t=abc', 'clicked_at' => $now - 3600]);

        $this->insert('pramnos.pushlog', ['userid' => 7, 'notification' => 'news', 'title' => 'Hi Maria', 'body' => 'b', 'status' => 201, 'sent' => $ts(3600)]);
        $this->insert('pramnos.pushlog', ['userid' => 8, 'notification' => 'news', 'title' => 'Hi Nikos', 'body' => 'b', 'status' => 410, 'error' => "Gone for nikos@example.org\nmore", 'sent' => $ts(3600)]);

        $this->insert('queueitems', ['type' => 'email', 'payload' => '{}', 'status' => 'pending', 'createdat' => $ts(60), 'updatedat' => $ts(60)]);
        $this->insert('queueitems', ['type' => 'email', 'payload' => '{}', 'status' => 'failed', 'error' => "SMTP refused eleni@example.net\ntrace", 'createdat' => $ts(600), 'updatedat' => $ts(300)]);
        $this->insert('queueitems', ['type' => 'push', 'payload' => '{}', 'status' => 'completed', 'execution_time' => 1.5, 'createdat' => $ts(600), 'updatedat' => $ts(500), 'completedat' => $ts(500)]);

        $this->insert('llm_calls', ['organization_id' => 1, 'userid' => 7, 'purpose' => 'chat', 'provider' => 'openai', 'model' => 'gpt-x', 'tokens' => 1000, 'cost' => 0.02, 'currency' => 'USD', 'created_at' => $ts(3600)]);
        $this->insert('llm_calls', ['organization_id' => 2, 'userid' => 9, 'purpose' => 'chat', 'provider' => 'deepseek', 'model' => 'ds', 'tokens' => 500, 'cost' => 0.01, 'currency' => 'USD', 'error' => 'timeout', 'created_at' => $ts(7200)]);
    }

    /** One tool's answer, in a list. @return list<string> */
    private function answerOf(string $name): array
    {
        return [(Analyst::tools()[$name]['run'])(['days' => 30])];
    }

    /** Each tool's answer, as the model reads it. @return array<string, string> */
    private function ask(array $arguments = ['days' => 30]): array
    {
        $out = [];
        foreach (Analyst::tools() as $name => $tool) {
            $out[$name] = ($tool['run'])($arguments);
        }

        return $out;
    }

    /**
     * Every aggregate answers the installation's figures — and no tool's answer carries a
     * person's name, username or address, even where the rows hold them in a subject, a URL,
     * an error or a session.
     */
    #[DataProvider('databases')]
    public function testFiguresAndNoPeople(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->seed();

        // Act
        $answers = $this->ask();
        $read    = static fn (string $name): array => (array) json_decode($answers[$name], true);

        // Assert — figures
        $users = $read('users');
        $this->assertSame([3, 3, 1, 1, 1], [$users['total'], $users['signed_up'], $users['validated'], $users['by_usertype']['99'], $users['never_signed_in']]);
        $sessions = $read('sessions');
        $this->assertSame([2, 1, 1], [$sessions['online_now'], $sessions['people_online'], $sessions['guests_online']]);
        $orgs = $read('organizations');
        $this->assertSame([2, ['organization_id' => 1, 'name' => 'Daily News', 'members' => 2]], [$orgs['total'], $orgs['largest'][0]]);
        $this->assertSame(['confirmed' => 1, 'unsubscribed' => 1], $read('mailing_lists')['lists']['news']);
        $email = $read('email');
        $this->assertSame([4, 1, 1, 1, 0.25], [$email['sent'], $email['opened'], $email['proxy_only'], $email['clicked'], $email['open_rate']]);
        // A subject sent to one person is theirs ("Welcome Maria"); a campaign's is shared.
        $this->assertSame(['Weekly digest' => 3], $email['subjects']);
        $this->assertSame(['https://example.com/offer' => 1], $email['links'], 'the query string is not a link');
        $push = $read('push');
        $this->assertSame([2, 1, 1], [$push['sent'], $push['delivered'], $push['failed']]);
        $queue = $read('queue');
        $this->assertSame([['email' => 1], 1, 1.5], [$queue['waiting'], $queue['failed']['email']['count'], $queue['seconds']['longest']]);
        $spend = $read('llm_spend');
        $this->assertSame([2, 1500, 0.03, 1], [$spend['calls'], $spend['tokens'], $spend['cost'], $spend['failed']]);
        $this->assertSame(['organization_id' => 1, 'name' => 'Daily News'], array_intersect_key($spend['organizations'][0], ['organization_id' => 0, 'name' => 0]));

        // Assert — nobody
        $this->assertNotEmpty($answers, 'the sweep found nothing to check');
        foreach ($answers as $name => $answer) {
            if ($name === 'user') {
                continue;
            }
            $this->assertStringNotContainsString('@example.', $answer, $name . ' carried an address');
            $this->assertStringNotContainsString('694 123', $answer, $name . ' carried a phone number');
            foreach (self::PEOPLE as $p) {
                $this->assertStringNotContainsString($p['firstname'], $answer, $name . ' carried a name');
                $this->assertStringNotContainsString($p['username'], $answer, $name . ' carried a username');
            }
        }
    }

    /**
     * One person when named: the address only when asked for, tagged `personal`; nobody named
     * is an error, and nobody found says so.
     */
    #[DataProvider('databases')]
    public function testOnePersonWhenNamed(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->seed();
        $tool = Analyst::tools()['user'];

        // Act
        $plain   = json_decode(($tool['run'])(['userid' => 7]), true);
        $asked   = json_decode(($tool['run'])(['username' => 'nikos', 'include_email' => true]), true);
        $nobody  = ($tool['run'])([]);
        $missing = ($tool['run'])(['userid' => 999]);

        // Assert
        $this->assertSame(['maria.k', 99, true], [$plain['username'], $plain['usertype'], $plain['validated']]);
        $this->assertArrayNotHasKey('email', $plain);
        $this->assertSame('nikos@example.org', $asked['email']);
        $this->assertSame(['personal' => true], $tool['tags']);
        $this->assertStringStartsWith('Error:', $nobody);
        $this->assertSame('There is no such account.', $missing);
    }

    /**
     * An administrator's analyst conversations and their Ask AI ones never meet; a queued
     * analyst question is answered by the analyst.
     */
    #[DataProvider('databases')]
    public function testTheAnalystIsKeptApart(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $now = \Pramnos\Llm\CallLog::instant(time());
        foreach ([Scope::CHAT => 'Ask AI question', Scope::ADMIN => 'Analyst question'] as $space => $title) {
            $this->insert('chat_conversations', ['organization_id' => null, 'userid' => 7, 'title' => $title, 'space' => $space, 'created_at' => $now, 'updated_at' => $now]);
        }
        (new \Pramnos\Llm\Chat\Memory(Analyst::scope(7)))->remember('fact', 'Org 5 is a test organisation');
        $queue = new RecordingQueue();

        // Act
        $analyst = array_column(Analyst::conversations(7)->list(), 'title');
        $askAi   = array_column((new \Pramnos\Llm\Chat\Conversations(new \Pramnos\Llm\Chat\BaseAssistant()))->as(new Scope(7))->list(), 'title');
        $askId   = (new \Pramnos\Llm\Chat\Asks(Analyst::conversations(7), $queue))->start(Analyst::scope(7), null, 'How many users?', null, []);
        $asked   = (new \Pramnos\Llm\Chat\Asks(Analyst::conversations(7)))->find(Analyst::scope(7), $askId);

        // Assert
        $this->assertSame(['Analyst question'], $analyst);
        $this->assertSame(['Ask AI question'], $askAi);
        $this->assertSame([], (new \Pramnos\Llm\Chat\Memory(new Scope(7)))->all(), 'what the analyst remembers is the analyst\'s');
        $this->assertSame('admin', $queue->tasks[0][1]['space']);
        $this->assertSame('waiting', $asked['status']);
        $this->assertInstanceOf(AnalystAssistant::class, \Pramnos\Llm\Chat\Chat::assistant(Scope::ADMIN));
        $assistant = new AnalystAssistant();
        $this->assertSame(['remember', 'forget'], $assistant->writes(), 'only its memory writes');
        $this->assertStringEndsWith('admin/Ai/analyst?c=4', $assistant->conversationUrl(4));
        $this->assertStringEndsWith('admin/Ai/analyst', $assistant->conversationUrl(null));
        $this->assertSame('analyst', Analyst::scope(7)->purpose());
    }

    /**
     * The analyst asks the router with `organization_id` present and null — deliberately, since it
     * reads the whole installation. A router that fills a tenant must keep that null
     * (`array_key_exists`), and then the installation's connection is used.
     */
    #[DataProvider('databases')]
    public function testTheAnalystIsOutsideEveryOrganisation(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $router = new TenantRouter();
        \Pramnos\Llm\Llm::use('router', $router);

        // Act
        $analyst = Analyst::conversations(7)->connection();
        $askAi   = (new \Pramnos\Llm\Chat\Conversations(new \Pramnos\Llm\Chat\BaseAssistant()))->as(new Scope(7, 3))->connection();

        // Assert
        $this->assertArrayHasKey('organization_id', $router->seen[0]);
        $this->assertSame([null, 'analyst'], [$router->seen[0]['organization_id'], $router->seen[0]['purpose']]);
        $this->assertSame('openai', $analyst->provider(), 'the installation\'s, not the tenant\'s');
        $this->assertSame('deepseek', $askAi->provider(), 'an organisation\'s own choice still applies to Ask AI');
    }

    /**
     * An application's own read tools join the framework's — a class name or an instance — and
     * their answers are redacted too; anything that is not a tool is ignored.
     */
    #[DataProvider('databases')]
    public function testTheApplicationsTools(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->configure(['admin_tools' => [PostsTool::class, new PostsTool('posts_today'), \stdClass::class, 'NoSuchClass']]);

        // Act
        $tools = Analyst::tools();

        // Assert
        $this->assertArrayHasKey('posts', $tools);
        $this->assertArrayHasKey('posts_today', $tools);
        $this->assertSame('{"published":12,"by":"e***@e***.com"}', ($tools['posts']['run'])([]));
        $this->assertCount(14, $tools);
    }

    /**
     * A queued analyst question is answered by the analyst: its call is logged as the analyst's,
     * and the conversation is the administrator's analyst one.
     */
    #[DataProvider('databases')]
    public function testTheWorkerAnswersAsTheAnalyst(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->answer([[200, self::says('Three accounts.')]]);
        $queue = new RecordingQueue();
        (new \Pramnos\Llm\Chat\Asks(Analyst::conversations(7), $queue))->start(Analyst::scope(7), null, 'How many accounts?');
        $item = $this->getMockBuilder(\Pramnos\Queue\QueueItem::class)->disableOriginalConstructor()->getMock();
        $item->payload = json_encode($queue->tasks[0][1]);

        // Act
        $done = (new \ReflectionClass(\Pramnos\Llm\Chat\AskTask::class))->newInstanceWithoutConstructor()->execute($item);

        // Assert
        $this->assertTrue($done);
        $this->assertSame(['How many accounts?'], array_column(Analyst::conversations(7)->list(), 'title'));
        // The page following that question opens the conversation it went into.
        $page = (new \Pramnos\Llm\Analyst\Desk(7))->page(0, (int) $queue->tasks[0][1]['ask_id']);
        $this->assertSame('How many accounts?', $page['conversation']['title']);
        $this->assertSame([], json_decode(($this->answerOf('organizations'))[0], true)['largest'], 'no members, no largest');
        $this->assertSame('analyst', $this->db->queryBuilder()->table('llm_calls')->first()->fields['purpose']);
    }

    /**
     * `/admin/Ai/analyst`, drawn through a real view in every theme; asking, following,
     * forgetting and agreeing — each at the analyst's own addresses.
     */
    #[DataProvider('databases')]
    public function testTheScreen(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->setting('llm_provider', 'deepseek');
        $this->setting('llm_deepseek_api_key', 'sk-d');
        $this->configure(['consent' => \Pramnos\Llm\Policy\StoredConsent::class, 'analyst' => ['suggestions' => ['Who signed up today?']]]);
        $_POST = [];
        $_GET  = [];
        \Pramnos\Http\Request::resetInstance();

        try {
            // Act / Assert — agreement asked, then given, on the analyst's page
            $this->assertStringContainsString('Nothing is sent until you agree.', (string) (new AnalystScreen())->display());
            $_POST = ['give' => '1'];
            $agreed = new AnalystScreen();
            $agreed->consent();
            $this->assertStringEndsWith('Ai', $agreed->redirectedTo[0]);

            // Asked without JavaScript, then as pf-chat.js does
            $_POST = ['content' => 'How many accounts?'];
            $asked = new AnalystScreen();
            $asked->ask();
            $this->assertMatchesRegularExpression('/Ai\?ask=\d+$/', $asked->redirectedTo[0]);
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
            $json = json_decode((string) (new AnalystScreen())->ask(), true);
            $_SERVER['HTTP_ACCEPT'] = '';
            $this->assertSame(202, $json['status']);
            $this->assertSame('waiting', json_decode((string) (new AnalystScreen())->asked($json['data']['ask_id']), true)['data']['status']);
            $this->assertSame(404, json_decode((string) (new AnalystScreen())->asked(999), true)['status']);
            $_POST = ['content' => ''];
            $empty = new AnalystScreen();
            $empty->ask();
            $this->assertSame(['Ask it something.'], $empty->errors);

            // Drawn in every theme, at the analyst's addresses
            foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
                $page = (string) (new AnalystScreen($theme))->display();
                $this->assertStringContainsString('Ask', $page, $theme);
                $this->assertStringContainsString('Ai/settings', $page, $theme . ': the settings are a link away');
                $this->assertStringContainsString('Ai/ask', $page, $theme);
                $this->assertStringContainsString('data-page="', $page, $theme);
                $this->assertStringContainsString('You agreed to the AI features using DeepSeek.', $page, $theme);
                $this->assertStringContainsString('data-pf-chat-suggestion="Who signed up today?"', $page, $theme . ': the analyst\'s own examples');
            }

            // Forgetting, and withdrawing
            $stamp = \Pramnos\Llm\CallLog::instant(time());
            $this->insert('chat_conversations', ['organization_id' => null, 'userid' => 7, 'title' => 'Spend', 'space' => Scope::ADMIN, 'created_at' => $stamp, 'updated_at' => $stamp]);
            $deleted = new AnalystScreen();
            $deleted->delete((int) Analyst::conversations(7)->list()[0]['conversation_id']);
            $this->assertSame(['The conversation is forgotten.'], $deleted->messages);
            $missing = new AnalystScreen();
            $missing->delete(999);
            $missing->forget(999);
            $this->assertSame(['There is no such conversation.', 'There is no such memory.'], $missing->errors);
            (new \Pramnos\Llm\Chat\Memory(Analyst::scope(7)))->remember('fact', 'Org 5 is a test');
            $forgot = new AnalystScreen();
            $forgot->forget((int) (new \Pramnos\Llm\Chat\Memory(Analyst::scope(7)))->all()[0]['memory_id']);
            $this->assertSame(['Forgotten.'], $forgot->messages);
            $_POST = [];
            (new AnalystScreen())->consent();
            $this->assertStringContainsString('Nothing is sent until you agree.', (string) (new AnalystScreen())->display());
        } finally {
            $_POST = [];
            $_SERVER['HTTP_ACCEPT'] = '';
        }
    }

    /**
     * The real screen's analyst is the signed-in administrator's — nobody, here — and its JSON
     * carries the answer's status as the HTTP status.
     */
    public function testTheScreensOwnSeams(): void
    {
        // Arrange
        $screen = (new \ReflectionClass(\Pramnos\Application\Controllers\AiController::class))->newInstanceWithoutConstructor();
        $call   = static fn (string $method, mixed ...$args): mixed => (new \ReflectionMethod(\Pramnos\Application\Controllers\AiController::class, $method))->invoke($screen, ...$args);

        // Act
        $desk = $call('analystDesk');
        $json = $call('analystJson', ['status' => 404, 'error' => 'Δεν υπάρχει.']);

        // Assert
        $this->assertNull($desk->asked(1), 'nobody signed in follows nothing');
        $this->assertSame('{"status":404,"error":"Δεν υπάρχει."}', $json);
        $this->assertSame(404, http_response_code());
        http_response_code(200);
    }

    /** One call to `/admin/Ai/api/…` as administrator 7, with the session's token or without. */
    private function api(string $method, string $path, array $body = [], bool $token = true): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = '/admin/Ai/api/' . $path;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token ? \Pramnos\Http\Session::getInstance()->getCsrfToken() : '';
        $_POST = [];
        \Pramnos\Http\Request::setRawInput($body === [] ? '' : json_encode($body));

        try {
            return (array) json_decode((string) (new AnalystScreen())->api(), true);
        } finally {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_X_CSRF_TOKEN']);
            \Pramnos\Http\Request::setRawInput(null);
        }
    }

    /**
     * The analyst as JSON in the conversation API's shape, for a screen of the application's
     * own: every address, a JSON body, and the token any write needs.
     */
    #[DataProvider('databases')]
    public function testTheJsonSurface(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->setting('llm_openai_api_key', 'sk-test');
        $stamp = \Pramnos\Llm\CallLog::instant(time());
        $this->insert('chat_conversations', ['organization_id' => null, 'userid' => 7, 'title' => 'Spend', 'space' => Scope::ADMIN, 'created_at' => $stamp, 'updated_at' => $stamp]);
        $id = (int) Analyst::conversations(7)->list()[0]['conversation_id'];
        (new \Pramnos\Llm\Chat\Memory(Analyst::scope(7)))->remember('fact', 'Org 5 is a test');
        $memoryId = (int) (new \Pramnos\Llm\Chat\Memory(Analyst::scope(7)))->all()[0]['memory_id'];

        // Act / Assert — reading
        $list = $this->api('GET', 'chat');
        $this->assertSame([200, ['Spend']], [$list['status'], array_column($list['data']['conversations'], 'title')]);
        $this->assertArrayHasKey('limits', $list['data']);
        $this->assertSame('Spend', $this->api('GET', 'chat/' . $id)['data']['title']);
        $this->assertSame(404, $this->api('GET', 'chat/999')['status']);
        $this->assertSame(['Org 5 is a test'], array_column($this->api('GET', 'memory')['data']['memories'], 'content'));
        $this->assertArrayHasKey('given', $this->api('GET', 'consent')['data']);
        $this->assertSame([404, 404], [$this->api('GET', 'nothing')['status'], $this->api('GET', '')['status']]);

        // Writing: refused without the token, then asked from a JSON body
        $this->assertSame(403, $this->api('POST', 'chat', ['content' => 'How many?'], token: false)['status']);
        $asked = $this->api('POST', 'chat', ['content' => 'How many?']);
        $this->assertSame(202, $asked['status']);
        $this->assertSame('waiting', $this->api('GET', 'asks/' . $asked['data']['ask_id'])['data']['status']);
        $this->assertSame(404, $this->api('GET', 'asks/999')['status']);
        $this->assertTrue($this->api('POST', 'consent')['data']['given']);
        $this->assertArrayHasKey('given', $this->api('DELETE', 'consent')['data']);
        $this->assertSame([200, 404], [$this->api('DELETE', 'memory/' . $memoryId)['status'], $this->api('DELETE', 'memory/' . $memoryId)['status']]);
        $this->assertSame([200, 404], [$this->api('DELETE', 'chat/' . $id)['status'], $this->api('DELETE', 'chat/' . $id)['status']]);
    }

    /**
     * The analyst's first address and the settings' old ones lead where they went; the header's
     * Ask link is drawn only for somebody who may open the page, at the application's floor.
     */
    #[DataProvider('databases')]
    public function testAddressesAndTheHeaderButton(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $moved    = new AnalystScreen();
        $problems = new AnalystScreen();

        // Act
        $_GET = ['c' => '4', 'ask' => '9'];
        $moved->analyst();
        $_GET = ['show' => 'problems'];
        \Pramnos\Http\Request::resetInstance();
        $problems->display();
        $_GET = [];
        \Pramnos\Http\Request::resetInstance();

        // Assert
        $this->assertStringEndsWith('Ai?c=4&ask=9', $moved->redirectedTo[0]);
        $this->assertStringEndsWith('Ai/settings?show=problems', $problems->redirectedTo[0]);
        $this->assertSame(\Pramnos\Auth\AdminAccess::defaultUsertype(), Analyst::floor(), 'no wrapper here: the area\'s floor');
        $this->assertSame('', Analyst::headerButton(), 'nobody signed in');
        $enabled = new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled');
        $saved   = $enabled->getValue();
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['llm']);
        try {
            \Pramnos\Http\RequestIdentity::seal((object) ['userid' => 7, 'usertype' => 99]);
            $button = Analyst::headerButton('<li>', '</li>');
            \Pramnos\Http\RequestIdentity::seal((object) ['userid' => 8, 'usertype' => 1]);
            $member = Analyst::headerButton();
        } finally {
            \Pramnos\Http\RequestIdentity::reset();
            $enabled->setValue(null, $saved);
        }
        $this->assertMatchesRegularExpression('#^<li><a class="pf-ask" href="[^"]*Ai">Ask</a></li>$#', $button, 'an administrator sees it');
        $this->assertSame('', $member, 'below the floor, no button');
        $app = \Pramnos\Application\Application::getInstance();
        $was = $app->applicationInfo['namespace'] ?? null;
        $app->applicationInfo['namespace'] = 'Pramnos\\Tests\\Integration\\Llm\\Fixture';
        try {
            $this->assertSame(98, Analyst::floor(), 'the application\'s Admin\\Controllers\\Ai says');
        } finally {
            $app->applicationInfo['namespace'] = $was;
        }
    }

    /** Below the screen's usertype nothing happens, whatever is asked. */
    #[DataProvider('databases')]
    public function testBelowTheFloorNothingHappens(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $_POST  = ['content' => 'How many accounts?', 'give' => '1'];
        $screen = new AnalystScreen(refused: true);

        // Act
        $answers = [$screen->display(), $screen->ask(), $screen->asked(1), $screen->delete(1), $screen->forget(1), $screen->consent(), $screen->api()];
        $_POST   = [];

        // Assert
        $this->assertSame([null, null, null, null, null, null, null], $answers);
        $this->assertSame([], $screen->queue->tasks);
        $this->assertSame([], $screen->redirectedTo);
    }
}

/** A router that fills an organisation from the request's tenant — keeping an explicit null. */
final class TenantRouter extends \Pramnos\Llm\Policy\DefaultRouter
{
    /** @var list<array<string, mixed>> */
    public array $seen = [];

    public function context(array $context): array
    {
        // array_key_exists, not ??: a caller that said "no organisation" meant it.
        if (!array_key_exists('organization_id', $context)) {
            $context['organization_id'] = 3;
        }

        return parent::context($context);
    }

    public function route(array $context, \Pramnos\Llm\Connection $connection): \Pramnos\Llm\Connection
    {
        $this->seen[] = $context;

        return (int) ($context['organization_id'] ?? 0) === 3 ? $connection->onProvider('deepseek') : $connection;
    }
}

/** An application's read tool: posts published. */
final class PostsTool implements \Pramnos\Mcp\McpToolInterface
{
    public function __construct(private string $named = 'posts')
    {
    }

    public function name(): string
    {
        return $this->named;
    }

    public function description(): string
    {
        return 'Posts published.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): mixed
    {
        return ['published' => 12, 'by' => 'editor@example.com'];
    }
}

/** The AI screen's analyst, as administrator 7, with its queue, flash and redirects recorded and real views. */
final class AnalystScreen extends \Pramnos\Application\Controllers\AiController
{
    /** @var list<string> */
    public array $redirectedTo = [];

    /** @var list<string> */
    public array $messages = [];

    /** @var list<string> */
    public array $errors = [];

    public RecordingQueue $queue;

    public function __construct(private string $theme = 'plain-css', private bool $refused = false)
    {
        // Not parent::__construct(): it registers actions against an application this test does not have.
        $this->queue = new RecordingQueue();
    }

    protected function requireMinUserType($type): bool
    {
        return $this->refused;
    }

    protected function analystDesk(): \Pramnos\Llm\Analyst\Desk
    {
        $queue = $this->queue;

        return new class (7, $queue) extends \Pramnos\Llm\Analyst\Desk {
            public function __construct(int $adminId, private RecordingQueue $recorded)
            {
                parent::__construct($adminId);
            }

            protected function asks(): \Pramnos\Llm\Chat\Asks
            {
                return new \Pramnos\Llm\Chat\Asks($this->conversations(), $this->recorded);
            }
        };
    }

    protected function analystJson(array $answer): mixed
    {
        return json_encode($answer);
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

    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo[] = (string) $url;
    }

    /** The view, as View draws it, from the chosen theme's scaffolding. */
    public function &getView($name = '', $type = '', $args = array())
    {
        $dir  = ROOT . '/scaffolding/themes/' . $this->theme . '/views/' . $name;
        $view = new class (new \Pramnos\Application\Controller(), $dir, $name) extends \Pramnos\Application\View {
            public function display($tpl = '', $render = false)
            {
                $file = $this->path . '/' . ($tpl ?: $this->name) . '.html.php';
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
