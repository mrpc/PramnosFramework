<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Audience\Rules\Actions;
use Pramnos\Audience\Rules\Context;
use Pramnos\Audience\Rules\Engine;
use Pramnos\Audience\Rules\RuleTask;
use Pramnos\Console\Commands\AudiencePruneEvents;
use Pramnos\Console\Commands\RulesRun;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The framework's rule actions on MySQL, prefixed MySQL and PostgreSQL: an email from a mail
 * template into the outbox with its origin, a notification on the site and as a push, groups, an
 * attribute, an administrator told, a webhook — and the worker's task, the scheduled command and
 * the run log's retention.
 */
#[CoversClass(Actions::class)]
#[CoversClass(Context::class)]
#[CoversClass(RuleTask::class)]
#[CoversClass(RulesRun::class)]
#[CoversClass(Engine::class)]
#[CoversClass(AudiencePruneEvents::class)]
class RuleActionsTest extends TestCase
{
    use AudienceProbe {
        tearDown as private probeTearDown;
    }

    /** No fake responses left for the next test. */
    protected function tearDown(): void
    {
        Client::resetFakes();
        $this->probeTearDown();
    }

    /** A context for a step of rule 7. */
    private function context(string $person, string $step = 'mail'): Context
    {
        return new Context(7, 'Welcome', 2, $step, 99, $this->ids[$person], ['event' => 'signed.up'], 'B', false, null);
    }

    /** An action run directly. @return array{status: string, detail: array<string, mixed>} */
    private function act(string $action, string $person, array $params, string $step = 'mail'): array
    {
        return (Actions::get($action)['run'])($this->ids[$person], $params, $this->context($person, $step));
    }

    /**
     * The email action fills a mail template for the person, in their language, and hands it to the
     * outbox — the mail log row carrying the account and the step's origin, which is how a branch
     * finds it later. No template, no address, or an address that left the list: said, not sent.
     */
    #[DataProvider('databases')]
    public function testTheEmailActionQueuesATemplateWithItsOrigin(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        foreach (['el' => 'Γεια {firstname}', 'en' => 'Hello {firstname}'] as $language => $subject) {
            $this->db->queryBuilder()->table('mailtemplates')->insert(['title' => 'Welcome', 'defaulttext' => '<p>{firstname}, welcome.</p>',
                'defaultsubject' => $subject, 'category' => 'rule.welcome', 'language' => $language, 'type' => 0, 'sound' => '',
                'sendmethod' => 0, 'emailtemplate' => '']);
        }
        $this->db->queryBuilder()->table('pramnos.emailoptouts')->insert(['email' => 'bob@example.com', 'list' => 'news', 'source' => 'page', 'created_at' => time()]);
        $this->db->queryBuilder()->table('users')->where('userid', $this->ids['dan'])->update(['email' => '']);

        // Act
        $sent    = $this->act('email', 'cara', ['template' => 'rule.welcome', 'list' => 'news']);
        $missing = $this->act('email', 'anna', ['template' => 'rule.nothing']);
        $nobody  = $this->act('email', 'dan', ['template' => 'rule.welcome']);
        $left    = $this->act('email', 'bob', ['template' => 'rule.welcome', 'list' => 'news']);

        // Assert
        $this->assertSame('sent', $sent['status']);
        $row = $this->db->queryBuilder()->table('mails')->where('id', $sent['detail']['mail'])->getAll()[0];
        $this->assertSame('Γεια Cara', $row['subject'], "cara's language, cara's name");
        $this->assertSame(['rule', 'rule:7:v2:mail:run99', $this->ids['cara']], [$row['module'], $row['moduleinfo'], (int) $row['userid']]);
        $this->assertSame(['error', 'no template rule.nothing'], [$missing['status'], $missing['detail']['reason']]);
        $this->assertSame(['skipped', 'no address'], [$nobody['status'], $nobody['detail']['reason']]);
        $this->assertSame(['skipped', 'unsubscribed from news'], [$left['status'], $left['detail']['reason']]);
        $this->assertNull(Actions::mailId('rule:nothing'));
    }

    /**
     * A notification on the site lands in the person's notifications, filled with their name; a push
     * goes the push channel's way; an administrator is told with a link to the person's card.
     */
    #[DataProvider('databases')]
    public function testNotificationsReachThePersonAndTheAdministrator(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);

        // Act
        $bell  = $this->act('bell', 'anna', ['subject' => 'Hi {firstname}', 'body' => 'Welcome', 'link' => '/start']);
        $push  = $this->act('push', 'anna', ['subject' => 'Hi', 'body' => 'x']);
        $admin = $this->act('notify_admin', 'cara', ['to' => (string) $this->ids['bob'], 'subject' => '{firstname} paid', 'body' => '']);
        $none  = $this->act('notify_admin', 'cara', ['to' => '424242']);
        $ghost = (Actions::get('bell')['run'])(424242, ['subject' => 'x'], $this->context('anna'));

        // Assert
        $this->assertSame(['sent', 'database'], [$bell['status'], $bell['detail']['channel']]);
        $this->assertSame('sent', $push['status']);
        $this->assertSame(['done', $this->ids['bob']], [$admin['status'], $admin['detail']['to']]);
        $this->assertSame('error', $none['status']);
        $this->assertSame('skipped', $ghost['status']);
        $rows = $this->db->queryBuilder()->table('notifications')->getAll();
        $this->assertNotSame([], $rows);
        $this->assertStringContainsString('Hi Anna', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Cara paid', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Dear Anna', Actions::fill('Dear {firstname}', $this->ids['anna']));
    }

    /**
     * Groups and attributes: in and out of a group as a rule, a column of the users table and of a
     * one-to-one table set; a computed attribute cannot be set.
     */
    #[DataProvider('databases')]
    public function testGroupsAndAttributesAreChanged(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $press = $this->groups['Press'];

        // Act
        $in       = $this->act('group_add', 'cara', ['group' => $press]);
        $out      = $this->act('group_remove', 'bob', ['group' => $press]);
        $language = $this->act('attribute', 'bob', ['attribute' => 'language', 'value' => 'fr']);
        $pillar   = $this->act('attribute', 'cara', ['attribute' => 'pillar', 'value' => 7]);
        $computed = $this->act('attribute', 'cara', ['attribute' => 'level', 'value' => 1]);

        // Assert
        $this->assertSame([1, 1], [$in['detail']['added'], $out['detail']['removed']]);
        $members = array_map('intval', array_column($this->db->queryBuilder()->table('userstogroups')->where('groupid', $press)->getAll(), 'userid'));
        $this->assertSame([$this->ids['cara']], $members);
        $this->assertSame('done', $language['status']);
        $this->assertSame('fr', $this->db->queryBuilder()->table('users')->where('userid', $this->ids['bob'])->first()->fields['language']);
        $this->assertSame('done', $pillar['status']);
        $this->assertEquals(7, $this->db->queryBuilder()->table('audtest_profiles')->where('userid', $this->ids['cara'])->first()->fields['pillar']);
        $this->assertSame('error', $computed['status']);
    }

    /**
     * A webhook is POSTed the person and the origin, and answers done or error by its status; an
     * address that is not http is refused before anything is sent.
     */
    #[DataProvider('databases')]
    public function testAWebhookIsCalledWithTheOrigin(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $sent = [];
        Client::fake(['https://hooks.example/ok' => function (Client $client) use (&$sent) {
            $sent[] = $client->url();

            return ClientResponse::make('', 204);
        }, 'https://hooks.example/down' => fn () => ClientResponse::make('', 503)]);

        // Act
        $ok   = $this->act('webhook', 'anna', ['url' => 'https://hooks.example/ok'], 'hook');
        $down = $this->act('webhook', 'anna', ['url' => 'https://hooks.example/down']);
        $ftp  = $this->act('webhook', 'anna', ['url' => 'ftp://hooks.example/']);

        // Assert
        $this->assertSame(['done', 204], [$ok['status'], $ok['detail']['status']]);
        $this->assertSame(['https://hooks.example/ok'], $sent);
        $this->assertSame('error', $down['status']);
        $this->assertSame('error', $ftp['status']);
        $this->assertSame('rule:7:v2:hook:run99', $this->context('anna', 'hook')->toArray()['origin']);
        $this->assertSame('B', $this->context('anna')->toArray()['variant']);
    }

    /**
     * The worker's task runs what it is given and describes it; the minute command says how many
     * schedules were due; the retention removes old run-log rows and keeps them at 0.
     */
    #[DataProvider('databases')]
    public function testTheTaskTheCommandAndTheRetention(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $controller = $this->getMockBuilder(\Pramnos\Application\Controller::class)->disableOriginalConstructor()->getMock();
        $task = new RuleTask($controller);
        $item = static function (array $job): \Pramnos\Queue\QueueItem {
            $item = (new \ReflectionClass(\Pramnos\Queue\QueueItem::class))->newInstanceWithoutConstructor();
            $item->payload = json_encode($job);

            return $item;
        };
        $this->db->queryBuilder()->table('rule_log')->insert(['runid' => 1, 'ruleid' => 1, 'version' => 1, 'personid' => $this->ids['anna'],
            'step' => null, 'outcome' => 'entered', 'at' => time() - 200 * 86400]);
        $tester = new CommandTester(new RulesRun());
        $prune  = new CommandTester(new AudiencePruneEvents());

        // Act
        $ran          = $task->execute($item(['kind' => 'step', 'run' => 424242, 'seq' => 0]));
        $descriptions = array_map(static fn (array $job): string => $task->getDescription($item($job)),
            [['kind' => 'step', 'run' => 5, 'timeout' => 1], ['kind' => 'event', 'event' => 'x', 'person' => 3], ['kind' => 'everybody', 'rule' => 2], []]);
        $tester->execute([]);
        $prune->execute([]);
        $kept = \Pramnos\Application\Application::getInstance()->applicationInfo['audience'] ?? null;
        \Pramnos\Application\Application::getInstance()->applicationInfo['audience'] = ['rule_log_days' => 0];
        $whole = Engine::prune();
        \Pramnos\Application\Application::getInstance()->applicationInfo['audience'] = $kept;

        // Assert
        $this->assertSame(['message' => 'Run 424242 has moved on.'], $ran);
        $this->assertSame(['Rule step: run 5 (timeout)', 'Rule event: x for 3', 'Rule 2: everybody who matches', 'Rule task'], $descriptions);
        $this->assertStringContainsString('0 scheduled rule(s) due.', $tester->getDisplay());
        $this->assertStringContainsString('Rule log: 1 rows past its retention deleted.', $prune->getDisplay());
        $this->assertNull($whole);
        $this->assertSame([], $this->db->queryBuilder()->table('rule_log')->getAll());
    }

    /**
     * A message's origin read back from the Email Log: the rule by name with a link to its page
     * while it is there, by id once it is gone; anything else is not an origin. Every theme's
     * message page shows it.
     */
    #[DataProvider('databases')]
    public function testAnOriginIsReadBackForTheEmailLog(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $rule = \Pramnos\Audience\Rules\Rules::create('Welcome', ['trigger' => ['type' => 'once'], 'steps' => []], 2);

        // Act
        $known   = Context::parse('rule:' . $rule . ':v2:mail:run99');
        $gone    = Context::parse('rule:424242:v1:mail:run1');
        $nothing = Context::parse('massmessage:5');

        // Assert
        $this->assertSame(['rule' => $rule, 'version' => 2, 'step' => 'mail', 'run' => 99, 'name' => 'Welcome', 'url' => adminUrl('Rules/view/') . $rule], $known);
        $this->assertSame([null, null], [$gone['name'], $gone['url']]);
        $this->assertNull($nothing);
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $view = (string) file_get_contents(ROOT . '/scaffolding/themes/' . $theme . '/views/emails/show.html.php');
            $this->assertStringContainsString('data-field="origin"', $view, $theme);
        }
    }
}
