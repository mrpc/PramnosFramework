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
#[CoversClass(\Pramnos\Llm\Analyst\Review::class)]
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
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable::class,
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
        $this->assertCount(14, $tools, 'the help tool is the help feature\'s');
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
                $hidden = (string) (new AnalystScreen($theme, hideSettings: true))->display();
                $this->assertStringNotContainsString('Ai/settings', $hidden, $theme . ': settingsLink() \'\' hides the link');
                $this->assertStringContainsString('Ai/ask', $page, $theme);
                $this->assertStringContainsString('data-page="', $page, $theme);
                $this->assertStringContainsString('You agreed to the AI features using DeepSeek.', $page, $theme);
                $this->assertStringContainsString('data-pf-chat-suggestion="Who signed up today?"', $page, $theme . ': the analyst\'s own examples');
                $this->assertStringContainsString('data-question-class=', $page, $theme . ': a question asked in place takes the theme\'s bubble');
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
     * Root reads the other administrators' Ask conversations, read only: the list with owner, message
     * count and cost, filtered by person and title — root's own neither listed nor offered in the
     * filter, and opened here it goes to Ask, where it is not read only; one opened as its owner sees
     * it — tools and the model calls made for each answer — under a banner, with no question box and
     * no delete; each view in the audit log; nothing else written.
     */
    #[DataProvider('databases')]
    public function testRootReadsEverybodysAskConversations(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->seed();
        $stamp = static fn (int $ago): string => gmdate('Y-m-d H:i:s', time() - $ago);
        $this->insert('chat_conversations', ['organization_id' => null, 'userid' => 8, 'title' => 'Why did NAN-6250 fail', 'space' => Scope::ADMIN, 'created_at' => $stamp(600), 'updated_at' => $stamp(500)]);
        $this->insert('chat_conversations', ['organization_id' => null, 'userid' => 7, 'title' => 'Own spend', 'space' => Scope::ADMIN, 'created_at' => $stamp(900), 'updated_at' => $stamp(900)]);
        $this->insert('chat_conversations', ['organization_id' => 1, 'userid' => 8, 'title' => 'A private Ask AI', 'space' => Scope::CHAT, 'created_at' => $stamp(300), 'updated_at' => $stamp(300)]);
        $ids    = array_column($this->db->queryBuilder()->table('chat_conversations')->orderBy('conversation_id')->getAllOrFail(), 'conversation_id');
        [$theirs, $mine] = [(int) $ids[0], (int) $ids[1]];
        $this->insert('chat_messages', ['conversation_id' => $theirs, 'organization_id' => null, 'role' => 'user', 'content' => 'Why did it fail?', 'created_at' => $stamp(600), 'updated_at' => $stamp(600)]);
        $this->insert('chat_messages', ['conversation_id' => $theirs, 'organization_id' => null, 'role' => 'assistant', 'content' => "The queue refused it.\n```chart\n{\"type\":\"bar\",\"title\":\"Refusals\",\"labels\":[\"Mon\"],\"series\":[{\"data\":[3]}]}\n```",
            'tools' => json_encode([['name' => 'queue_failures', 'arguments' => ['days' => 1]]]), 'cost' => 0.0125, 'created_at' => $stamp(600), 'updated_at' => $stamp(600)]);
        $this->insert('llm_calls', ['organization_id' => null, 'userid' => 8, 'purpose' => 'analyst', 'provider' => 'openai', 'model' => 'gpt-x', 'created_at' => $stamp(550)]);
        $call   = (int) $this->db->queryBuilder()->table('llm_calls')->where('purpose', 'analyst')->first()->fields['call_id'];
        // A second question, later: the first answer's calls stop where it begins.
        $this->insert('chat_messages', ['conversation_id' => $theirs, 'organization_id' => null, 'role' => 'user', 'content' => 'And now?', 'created_at' => $stamp(100), 'updated_at' => $stamp(100)]);
        $this->insert('chat_messages', ['conversation_id' => $theirs, 'organization_id' => null, 'role' => 'assistant', 'content' => 'Fine now.', 'created_at' => $stamp(100), 'updated_at' => $stamp(100)]);
        $this->insert('llm_calls', ['organization_id' => null, 'userid' => 8, 'purpose' => 'analyst', 'provider' => 'openai', 'model' => 'gpt-x', 'created_at' => $stamp(50)]);
        $later  = (int) $this->db->queryBuilder()->table('llm_calls')->where('purpose', 'analyst')->orderBy('call_id', 'desc')->first()->fields['call_id'];
        $this->insert('chat_conversations', ['organization_id' => 1, 'userid' => 8, 'title' => 'Asked inside an organisation', 'space' => Scope::ADMIN, 'created_at' => $stamp(300), 'updated_at' => $stamp(300)]);
        $inOrg  = (int) $this->db->queryBuilder()->table('chat_conversations')->orderBy('conversation_id', 'desc')->first()->fields['conversation_id'];
        $before = [$this->rowsIn('chat_messages'), $this->rowsIn('chat_memory'), $this->rowsIn('chat_conversations')];

        // Act
        $_GET = [];
        \Pramnos\Http\Request::resetInstance();
        $list = (string) (new AnalystScreen())->all();
        $_GET = ['person' => '8', 'q' => 'NAN'];
        \Pramnos\Http\Request::resetInstance();
        $filtered = \Pramnos\Llm\Analyst\Review::list(['person' => 8, 'q' => 'NAN']);
        $none     = \Pramnos\Llm\Analyst\Review::list(['q' => 'nothing like this']);
        $opened   = \Pramnos\Llm\Analyst\Review::open($theirs);
        $pages = [];
        foreach (['plain-css', 'tailwind', 'bootstrap'] as $theme) {
            $_GET = ['c' => (string) $theirs];
            \Pramnos\Http\Request::resetInstance();
            $pages[$theme] = (string) (new AnalystScreen($theme))->all();
        }
        $_GET = ['c' => (string) $mine];
        \Pramnos\Http\Request::resetInstance();
        $own = new AnalystScreen();
        $ownPage = $own->all();
        $audit = $this->db->queryBuilder()->table('authserver.audit_log')->where('event_type', 'ai.conversation_viewed')->getAllOrFail();

        // Assert
        $this->assertStringContainsString('Why did NAN-6250 fail', $list);
        $this->assertStringNotContainsString('Own spend', $list, 'root\'s own are in Ask itself');
        $this->assertStringNotContainsString('<option value="7"', $list, 'and root is not one of whose to pick');
        $this->assertStringContainsString('<option value="8"', $list);
        $this->assertNull($ownPage);
        $this->assertSame([adminUrl('Ai') . '?c=' . $mine], $own->redirectedTo, 'one\'s own opens in Ask');
        $this->assertStringNotContainsString('A private Ask AI', $list, 'Ask, not somebody\'s own Ask AI');
        $this->assertSame([], $none);
        $this->assertSame([[$call], [$later]], array_values(array_map(static fn (array $m): array => $m['calls'],
            array_filter($opened['conversation']['messages'], static fn (array $m): bool => $m['role'] === 'assistant'))), 'each answer, its own calls');
        $this->assertNull(\Pramnos\Llm\Analyst\Review::open($inOrg), 'not the analyst\'s, which is outside every organisation');
        $this->assertSame([[$theirs, 'Nikos Papadakis', 4, 0.0125]], array_map(static fn (array $r): array => [$r['conversation_id'], $r['owner_name'], $r['messages'], round($r['cost'], 4)], $filtered));
        foreach ($pages as $theme => $page) {
            $this->assertStringContainsString('Nikos Papadakis&#039;s conversation — read only', $page, $theme);
            $this->assertStringContainsString('queue_failures', $page, $theme . ': the tool and its input');
            $this->assertStringContainsString('Ai/call/' . $call, $page, $theme . ': the model call, with what the tool returned');
            $this->assertStringNotContainsString('<textarea', $page, $theme . ': nothing to ask');
            $this->assertStringNotContainsString('Ai/delete', $page, $theme . ': nothing to delete');
            $this->assertDoesNotMatchRegularExpression('/<details[^>]*\sopen[\s>]/', $page, $theme . ': steps and tools folded, as on Ask\'s page');
            $this->assertStringContainsString('data-pf-chart="', $page, $theme . ': the chart, for pf-chat.js to draw');
            $this->assertMatchesRegularExpression('/<div[^>]*data-pf-chat>/', $page, $theme . ': which runs over the page');
            $this->assertStringContainsString('assets/js/pf-chat.js', $page, $theme);
        }
        $this->assertCount(3, $audit, 'each view of theirs, in each theme; not root\'s own');
        $this->assertSame(['7', '8', (string) $theirs], [(string) $audit[0]['actor_userid'], (string) $audit[0]['target_id'], (string) $audit[0]['object_id']]);
        $this->assertSame($before, [$this->rowsIn('chat_messages'), $this->rowsIn('chat_memory'), $this->rowsIn('chat_conversations')], 'nothing else written');
        $this->assertNull(\Pramnos\Llm\Analyst\Review::open(999999));
        $this->assertNull(\Pramnos\Llm\Analyst\Review::open((int) $ids[2]), 'a private Ask AI conversation is not opened here');
    }

    /**
     * Anybody who does not read them all — a superuser, 98 — gets a 404, and the Ask page offers
     * them no link; a conversation that does not exist is a 404 to root too.
     */
    #[DataProvider('databases')]
    public function testEverybodysAskConversationsAreA404ToEverybodyElse(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->seed();
        $super = new AnalystScreen();
        $super->reading = (object) ['userid' => 8, 'usertype' => 98, 'active' => 1];
        $_GET = ['c' => '999999'];
        \Pramnos\Http\Request::resetInstance();
        $status = static function (AnalystScreen $screen): int {
            try {
                $screen->all();
            } catch (\Pramnos\Application\ApplicationClosedException $e) {
                return $e->getStatusCode();
            }

            return 200;
        };

        // Act
        $refused = $status($super);
        $missing = $status(new AnalystScreen());

        // Assert
        $this->assertSame(404, $refused);
        $this->assertSame(404, $missing);
        $this->assertFalse(\Pramnos\Llm\Analyst\Review::readsAll((object) ['userid' => 8, 'usertype' => 98, 'active' => 1]));
        $this->assertFalse(\Pramnos\Llm\Analyst\Review::readsAll((object) ['userid' => 7, 'usertype' => 99, 'active' => 0]), 'deactivated');
        $this->assertFalse(\Pramnos\Llm\Analyst\Review::readsAll(null));
        $this->assertSame(0, $this->rowsIn('authserver.audit_log'), 'a refusal reads nothing, records no view');
    }

    /**
     * Everybody's conversations are not one of the page's main actions: they sit behind the ⚙
     * under the title with the settings, each shown to whoever has it, and a reader with neither
     * sees no gear at all — in every theme.
     */
    #[DataProvider('databases')]
    public function testEverybodysConversationsSitBehindTheGear(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->seed();
        $this->setting('llm_provider', 'deepseek');
        $this->setting('llm_deepseek_api_key', 'sk-d');
        $super = static function (string $theme, bool $hideSettings): AnalystScreen {
            $screen = new AnalystScreen($theme, false, $hideSettings);
            $screen->reading = (object) ['userid' => 8, 'usertype' => 98, 'active' => 1];

            return $screen;
        };
        $_GET = [];
        \Pramnos\Http\Request::resetInstance();

        foreach (['plain-css', 'tailwind', 'bootstrap'] as $theme) {
            // Act
            $root     = (string) (new AnalystScreen($theme))->display();
            $onlyAll  = (string) (new AnalystScreen($theme, false, true))->display();
            $settings = (string) $super($theme, false)->display();
            $neither  = (string) $super($theme, true)->display();
            $nav      = substr($root, strpos($root, '<nav'), strpos($root, '</nav>') - strpos($root, '<nav'));
            $gear     = substr($root, strpos($root, 'pf-ask-gear'), strpos($root, '</details>', strpos($root, 'pf-ask-gear')) - strpos($root, 'pf-ask-gear'));

            // Assert
            $this->assertStringNotContainsString('Ai/all', $nav, $theme . ': not beside New conversation');
            $this->assertStringContainsString('aria-label="Ask page tools"', $gear, $theme . ': the gear is named for a screen reader');
            $this->assertStringContainsString('Ai/all', $gear, $theme);
            $this->assertStringContainsString('Ai/settings', $gear, $theme);
            $this->assertStringContainsString('Ai/all', $onlyAll, $theme . ': the gear holds the one it has');
            $this->assertStringNotContainsString('Ai/settings', $onlyAll, $theme);
            $this->assertStringContainsString('pf-ask-gear', $settings, $theme);
            $this->assertStringNotContainsString('Ai/all', $settings, $theme . ': 98 does not read them all');
            $this->assertStringNotContainsString('pf-ask-gear', $neither, $theme . ': nothing behind it, no gear');
        }
    }

    /**
     * The analyst's tools follow whose rights an answer is given with, not who asked: an
     * application that cuts a tool for some people cuts it for the least privileged reader of a
     * conversation; an answer others read makes no file for the asker; and the model is told which
     * of the asker's tools it may not use there, to say so and suggest a direct message.
     */
    #[DataProvider('databases')]
    public function testAnAnswerOthersReadHasItsReadersRights(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->seed();
        $this->configure(['analyst' => ['export' => true]]);
        // As an application does: what usertype 98 — person 8 — may not read is cut for them.
        $assistant = new class () extends \Pramnos\Llm\Analyst\AnalystAssistant {
            public function tools(\Pramnos\Llm\Chat\Turn $turn): array
            {
                return $turn->rightsOf() === 8 ? array_diff_key(parent::tools($turn), ['llm_spend' => true]) : parent::tools($turn);
            }
        };
        $chat  = \Pramnos\Llm\Analyst\Analyst::conversations(7);
        $turn  = static fn (array $extra): \Pramnos\Llm\Chat\Turn => new \Pramnos\Llm\Chat\Turn(\Pramnos\Llm\Analyst\Analyst::scope(7), null, false, [], [], $extra, $chat);

        // Act
        $alone   = $assistant->tools($turn([]));
        $shared  = $assistant->tools($turn(['rights_of' => 8]));
        $prompt  = $assistant->systemPrompt($turn(['rights_of' => 8]));
        $own     = $assistant->systemPrompt($turn([]));

        // Assert
        $this->assertArrayHasKey('llm_spend', $alone);
        $this->assertArrayHasKey('export_csv', $alone);
        $this->assertArrayNotHasKey('llm_spend', $shared, 'cut for the least privileged reader');
        $this->assertArrayNotHasKey('export_csv', $shared, 'no file made for the asker where others read');
        $this->assertStringContainsString('least privileged reader', $prompt);
        $this->assertMatchesRegularExpression('/may not use here: (export_csv, llm_spend|llm_spend, export_csv)\./', $prompt);
        $this->assertStringContainsString('direct message', $prompt);
        $this->assertStringNotContainsString('least privileged', $own, 'the asker alone hears nothing of it');
    }

    /** Rows in a table. */
    private function rowsIn(string $table): int
    {
        return count($this->db->queryBuilder()->table($table)->getAllOrFail());
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
        $who  = $call('reader');
        $all  = $call('readsAll', null);

        // Assert
        $this->assertNull($desk->asked(1), 'nobody signed in follows nothing');
        $this->assertNull($who, 'nobody signed in reads nothing');
        $this->assertFalse($all);
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
            $keyless = Analyst::headerButton('<li>', '</li>');
            $this->setting('llm_openai_api_key', 'sk-test');
            $button = Analyst::headerButton('<li>', '</li>');
            \Pramnos\Http\RequestIdentity::seal((object) ['userid' => 8, 'usertype' => 1]);
            $member = Analyst::headerButton();
        } finally {
            \Pramnos\Http\RequestIdentity::reset();
            $enabled->setValue(null, $saved);
        }
        $this->assertMatchesRegularExpression('#^<li><a class="pf-ask" href="[^"]*Ai" data-pf-assist-open[^>]*>Ask</a><template data-pf-assist>.*</template><script src="[^"]*pf-assist\.js" defer></script></li>$#s', $button, 'an administrator sees it, and the panel it opens');
        $this->assertSame('', $member, 'below the floor, and no help on this page: no button');
        $this->assertSame('', $keyless, 'no key: nothing to ask with, and no help here either');
        $app = \Pramnos\Application\Application::getInstance();
        $was = $app->applicationInfo['namespace'] ?? null;
        $app->applicationInfo['namespace'] = 'Pramnos\\Tests\\Integration\\Llm\\Fixture';
        try {
            $this->assertSame(98, Analyst::floor(), 'the application\'s Admin\\Controllers\\Ai says');
        } finally {
            $app->applicationInfo['namespace'] = $was;
        }
    }

    /** Without a key there is nothing to ask with: Ask opens the settings, where it is set. */
    public function testWithoutAKeyAskIsTheSettings(): void
    {
        // Arrange
        foreach (['llm_openai_api_key', 'llm_deepseek_api_key', 'llm_provider'] as $name) {
            $this->setting($name, '');
        }
        $screen = new AnalystScreen();
        $_GET   = [];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $page = $screen->display();

        // Assert
        $this->assertNull($page);
        $this->assertStringEndsWith('Ai/settings', $screen->redirectedTo[0]);
        $this->assertSame(['Set a provider\'s API key to use Ask.'], $screen->messages);
        $this->assertFalse(Analyst::available());
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
        // The scheduled page's actions, from the trait, behind the same floor
        $scheduled = [$screen->scheduled(), $screen->schedule(), $screen->scheduledupdate(1), $screen->scheduleddelete(1), $screen->scheduledrun(1), $screen->scheduledsubscribe(1), $screen->report(1)];
        $_POST   = [];

        // Assert
        $this->assertSame([null, null, null, null, null, null, null], $answers);
        $this->assertSame([null, null, null, null, null, null, null], $scheduled);
        $this->assertSame([], $screen->queue->tasks);
        // Nothing was flashed or redirected: each action stopped before it touched a task.
        $this->assertSame([], $screen->redirectedTo);
        $this->assertSame([[], []], [$screen->messages, $screen->errors]);
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

    /** Who reads, for `all()`: root (7) unless a test says otherwise. */
    public ?object $reading = null;

    public function __construct(private string $theme = 'plain-css', private bool $refused = false, private bool $hideSettings = false)
    {
        // Not parent::__construct(): it registers actions against an application this test does not have.
        $this->queue = new RecordingQueue();
    }

    protected function requireMinUserType($type): bool
    {
        return $this->refused;
    }

    protected function reader(): ?object
    {
        return $this->reading ?? (object) ['userid' => 7, 'usertype' => 99, 'active' => 1];
    }

    /** The settings link, or none — as an application with its own settings menu answers. */
    protected function settingsLink(): string
    {
        return $this->hideSettings ? '' : parent::settingsLink();
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
