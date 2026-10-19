<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Dashboard\Channels\EmailMessages;
use Pramnos\Dashboard\MessageChannel;
use Pramnos\Dashboard\MessageReach;
use Pramnos\Dashboard\WidgetRegistry;
use Pramnos\Dashboard\Widgets\Messages;
use Pramnos\Database\Database;
use Pramnos\Email\SendHistory;
use Pramnos\Framework\Factory;

/** An application's SMS channel, with a cost and the people it reached. */
final class SmsChannel implements MessageChannel, MessageReach
{
    /** Its id. */
    public function id(): string { return 'sms'; }

    /** Its name. */
    public function label(): string { return 'SMS'; }

    /** Its screen. */
    public function link(): ?string { return '/admin/Sms'; }

    /** A week's figures. */
    public function figures(int $from, int $to): array
    {
        return ['total' => 200, 'failed' => 20, 'delivered' => 180, 'cost' => 12.4, 'currency' => '€'];
    }

    /** The people reached, with two days unknown. */
    public function reach(int $from, int $to): ?array
    {
        return ['recipients' => 150, 'average' => 21.4, 'missing_days' => 2];
    }
}

/** A channel whose source is down. */
final class BrokenChannel implements MessageChannel
{
    /** Its id. */
    public function id(): string { return 'broken'; }

    /** Its name. */
    public function label(): string { return 'Viber'; }

    /** No screen. */
    public function link(): ?string { return null; }

    /** It fails. */
    public function figures(int $from, int $to): array { throw new \RuntimeException('down'); }
}

/**
 * Every way messages go out, on one card — on MySQL and PostgreSQL.
 *
 * What holds: the send history's figures for a period — by status, an application's own status by
 * number, by module with an empty one as `other`, the failures by module and the failure rate;
 * email is the first channel where there is a history, then app.php's, then those registered in
 * code, a class that is not a channel left out; the card draws a row per channel — sent, failed,
 * what waits, what was delivered, the cost, the people reached — and a channel that fails is a
 * line, not a missing card; and the analyst's email answer carries the same history.
 */
#[CoversClass(SendHistory::class)]
#[CoversClass(EmailMessages::class)]
#[CoversClass(Messages::class)]
#[CoversClass(WidgetRegistry::class)]
class MessagesTest extends TestCase
{
    private ?Database $db = null;

    private ?Database $previous = null;

    private string $marker = '';

    /** @var array<string, mixed> */
    private array $info = [];

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
        WidgetRegistry::reset();
    }

    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        WidgetRegistry::reset();
        if ($this->db !== null) {
            $this->db->queryBuilder()->table('#PREFIX#mails')->where('hash', 'like', $this->marker . '%')->delete();
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
    }

    /** A connection made the framework's, with the send history, and four messages of a week in it. */
    private function connect(string $type, string $host, int $port): void
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class], $db);
        $this->marker = 'mt' . bin2hex(random_bytes(3));
        $at = time() - 10 * 365 * 86400; // far in the past, so this test's rows are alone in their days
        foreach ([[1, 'invoices'], [1, ''], [0, 'invoices'], [3, 'news']] as $i => [$status, $module]) {
            $db->queryBuilder()->table('#PREFIX#mails')->insert(['status' => $status, 'frommail' => 'a@b.c', 'fromname' => '', 'tomail' => 'x@y.z', 'toname' => '',
                'subject' => 's', 'content' => '', 'date' => $at + $i, 'module' => $module, 'moduleinfo' => '', 'extrainfo' => '', 'path' => '', 'hash' => $this->marker . $i]);
        }
    }

    /** The test's own days. @return array{0: int, 1: int} */
    private static function range(): array
    {
        $at = time() - 10 * 365 * 86400;

        return [$at - 60, $at + 60];
    }

    /**
     * A period's figures from the send history: by status (an application's own status by
     * number), by module (an empty one is `other`), the failures by module, the failure rate.
     */
    #[DataProvider('databases')]
    public function testTheSendHistorysFigures(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);

        // Act
        $f     = (new SendHistory())->figures(...self::range());
        $empty = (new SendHistory())->figures(0, 1);

        // Assert
        $this->assertSame([4, 2, 1, 0], [$f['total'], $f['sent'], $f['failed'], $f['queued']]);
        // In whatever order the database groups them
        $this->assertEquals(['sent' => 2, 'failed' => 1, 'status 3' => 1], $f['by_status']);
        $this->assertEqualsWithDelta(0.3333, $f['failure_rate'], 0.0001);
        $this->assertSame('invoices', array_key_first($f['by_module']), 'largest first');
        $this->assertEquals(['invoices' => 2, 'other' => 1, 'news' => 1], $f['by_module']);
        $this->assertSame(['invoices' => 1], $f['failed_by_module']);
        $this->assertSame([0, null, []], [$empty['total'], $empty['failure_rate'], $empty['failed_by_module']]);
        $this->assertSame(['total' => 4, 'failed' => 1, 'queued' => 0, 'delivered' => 2], (new EmailMessages())->figures(...self::range()));
    }

    /**
     * Email first, then app.php's channels, then those registered in code — one under a taken id
     * replacing it, a class that is not a channel left out — and the card draws each row: sent,
     * failed, delivered, cost, reached; a channel that fails is a line; with no channel at all
     * the card is not offered.
     */
    #[DataProvider('databases')]
    public function testTheChannelsAndTheCard(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        Application::getInstance()->applicationInfo['dashboard'] = ['channels' => [SmsChannel::class, \stdClass::class]];
        WidgetRegistry::channel(BrokenChannel::class);
        $card = (new Messages())->configure(['period' => 'custom', 'from' => date('Y-m-d', self::range()[0]), 'to' => date('Y-m-d', self::range()[1])]);
        $root = (object) ['userid' => 7, 'usertype' => 99];

        // Act
        $ids  = array_keys(WidgetRegistry::channels());
        $html = $card->render();
        // Figures kept before a channel was added: the channels they do not name are left out.
        $kept = $card->present(['channels' => ['sms' => $card->data()['channels']['sms']]]);

        // Assert
        $this->assertTrue($card->shared(), 'its figures are kept and shared (FW-167)');
        $this->assertStringContainsString('<h4 class="pf-dashboard-channel">SMS</h4>', $kept);
        $this->assertStringNotContainsString('Email', $kept, 'a channel the figures do not name');
        $this->assertSame(['email', 'sms', 'broken'], $ids);
        $this->assertTrue($card->allowed($root));
        $this->assertFalse($card->byDefault());
        $this->assertSame(['messages', Messages::FULL, 'Messages'], [$card->id(), $card->size(), $card->title()]);
        $this->assertNotSame('', $card->description());
        $this->assertStringContainsString('<h4 class="pf-dashboard-channel">Email</h4>', $html);
        $this->assertStringContainsString('<h4 class="pf-dashboard-channel">SMS</h4>', $html);
        $this->assertStringContainsString('€12.40', $html, 'the cost, in its currency');
        $this->assertStringContainsString('150', $html, 'the people reached');
        $this->assertStringContainsString('2 days missing', $html);
        $this->assertStringContainsString('10.0%', $html, 'SMS failure rate');
        $this->assertStringContainsString('Viber could not be read.', $html, 'a failing channel is a line');
    }

    /** The analyst's email answer carries the send history, and the email card the module that failed most. */
    #[DataProvider('databases')]
    public function testTheAnalystAndTheEmailCardSayTheSame(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        [$from, $to] = self::range();

        // Act
        $answer = (new \Pramnos\Llm\Analyst\Tools\EmailTool())->execute(['from' => date('Y-m-d', $from), 'to' => date('Y-m-d', $to)]);
        $card   = (new \Pramnos\Dashboard\Widgets\EmailActivity())->configure(['period' => 'custom', 'from' => date('Y-m-d', $from), 'to' => date('Y-m-d', $to)])->render();

        // Assert
        $this->assertSame(4, $answer['history']['total']);
        $this->assertSame(['invoices' => 1], $answer['history']['failed_by_module']);
        $this->assertStringContainsString('invoices', $card, 'where most failures were');
    }
}
