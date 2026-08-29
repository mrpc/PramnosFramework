<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Email\Retention;
use Pramnos\Framework\Factory;

/**
 * The mail log's retention policy, against the real table.
 *
 * Two stages, and the reason they are two is arithmetic: a password-reset mail is two hundred
 * bytes of facts wrapped around forty kilobytes of markup. Stripping keeps every question an
 * operator asks months later and reclaims almost all the disk; deleting throws away the cheap
 * half to save the expensive one.
 */
#[CoversClass(Retention::class)]
class RetentionTest extends TestCase
{
    private $db;

    private string $marker;

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');

        $this->db = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        \Pramnos\User\User::setupDb();
        $this->db->query('DROP TABLE IF EXISTS `mails`');
        $this->runMails();

        $this->marker = 'retention_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `mails`');
    }

    /**
     * Stripping keeps the row and drops the body.
     *
     * The row is the audit trail — who, when, which module, did it send. The body is the bytes.
     */
    public function testStrippingKeepsTheRowAndDropsTheBody(): void
    {
        // Arrange
        $old = $this->insert(time() - 86400 * 200, str_repeat('x', 5000));
        $new = $this->insert(time() - 3600, 'recent body');

        // Act
        $stripped = Retention::strip(86400 * 90);

        // Assert
        $this->assertSame(1, $stripped);
        $this->assertSame('', $this->contentOf($old));
        $this->assertSame('recent body', $this->contentOf($new));
        $this->assertSame(2, $this->rows(), 'both rows are still there');
    }

    /**
     * A row with no timestamp is not "older than everything".
     *
     * `date = 0` is what a malformed write leaves, and it is before every cutoff — so without
     * a guard the first run of a policy strips the bodies of every malformed row in the table,
     * including ones written this morning.
     */
    public function testARowWithNoTimestampIsNotAncient(): void
    {
        // Arrange
        $undated = $this->insert(0, 'never dated');

        // Act
        Retention::strip(86400 * 90);
        Retention::prune(86400 * 365);

        // Assert
        $this->assertSame('never dated', $this->contentOf($undated));
        $this->assertSame(1, $this->rows());
    }

    /**
     * Pruning removes the row entirely.
     */
    public function testPruningRemovesTheRow(): void
    {
        // Arrange
        $this->insert(time() - 86400 * 800, 'ancient');
        $this->insert(time() - 86400 * 10, 'recent');

        // Act
        $removed = Retention::prune(86400 * 365);

        // Assert
        $this->assertSame(1, $removed);
        $this->assertSame(1, $this->rows());
    }

    /**
     * Stripping the same rows twice is not stripping them twice.
     *
     * The scheduled sweep runs every day over a table whose old half is already stripped. A
     * pass that matched them again would rewrite the whole history nightly.
     */
    public function testASecondPassTouchesNothing(): void
    {
        // Arrange
        $this->insert(time() - 86400 * 200, 'body');

        // Act
        $first  = Retention::strip(86400 * 90);
        $second = Retention::strip(86400 * 90);

        // Assert
        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
    }

    /**
     * A policy of zero does nothing at all.
     *
     * "No policy" reaches these methods as 0, and 0 seconds means every row is older than the
     * cutoff — so this is the difference between an unconfigured installation and an empty one.
     */
    public function testNoPolicyDoesNothing(): void
    {
        // Arrange
        $this->insert(time() - 86400 * 800, 'body');

        // Act & Assert
        $this->assertSame(0, Retention::strip(0));
        $this->assertSame(0, Retention::prune(0));
        $this->assertSame(0, Retention::pruneRecipients(0));
        $this->assertSame(1, $this->rows());
        $this->assertSame('body', $this->contentOf($this->firstId()));
    }

    /**
     * The statistics say what the bodies cost, which is the argument for stripping.
     */
    public function testTheStatisticsSayWhatTheBodiesCost(): void
    {
        // Arrange
        $this->insert(time() - 86400 * 200, str_repeat('x', 4000));
        $this->insert(time() - 3600, str_repeat('y', 1000));

        // Act
        $stats = Retention::stats(86400 * 90, 86400 * 365);

        // Assert
        $this->assertSame(2, $stats['rows']);
        $this->assertSame(2, $stats['with_body']);
        $this->assertSame(5000, $stats['body_bytes']);
        $this->assertSame(1, $stats['would_strip']);
        $this->assertSame(0, $stats['would_delete']);
    }

    /**
     * With no table at all, the statistics report the failure rather than zero.
     *
     * Zero rows and no table are different answers, and reporting the second as the first is
     * how somebody concludes the mail log is empty.
     */
    public function testAMissingTableIsAnErrorNotAnEmptyLog(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `mails`');

        // Act
        $stats = Retention::stats();

        // Assert
        $this->assertArrayHasKey('error', $stats);
    }

    /**
     * Recipient rows of a finished campaign go; those of a live one stay.
     *
     * The campaign row is the record of what was sent, and it is one row. The forty thousand
     * recipient rows behind it have one remaining purpose once it is finished — the count on
     * its page — and the count is on the campaign row.
     */
    public function testOnlyFinishedCampaignsLoseTheirRecipients(): void
    {
        // Arrange
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()->getMock();
        $app->database = $this->db;

        foreach (['massmessagerecipients', 'massmessages'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefix . $table . '`');
        }

        foreach (\Pramnos\Database\MigrationLoader::loadFromDirectory(
            dirname(__DIR__, 3) . '/database/migrations/framework/messaging',
            $app
        ) as $migration) {
            if ($migration instanceof \Pramnos\Framework\Migrations\Messaging\CreateMassmessagesTable
                || $migration instanceof \Pramnos\Framework\Migrations\Messaging\CreateMassmessagerecepientsTable
            ) {
                $migration->up();
            }
        }

        $finished = $this->campaign(\Pramnos\Messaging\MassMessage::STATUS_SENT, time() - 86400 * 400);
        $running  = $this->campaign(\Pramnos\Messaging\MassMessage::STATUS_PENDING, time() - 86400 * 400);
        $recent   = $this->campaign(\Pramnos\Messaging\MassMessage::STATUS_SENT, time() - 3600);

        foreach ([$finished, $running, $recent] as $id) {
            $this->db->queryBuilder()->table('#PREFIX#massmessagerecipients')
                ->insert(['messageid' => $id, 'userid' => 2, 'status' => 1]);
        }

        try {
            // Act
            $removed = \Pramnos\Email\Retention::pruneRecipients(86400 * 365);

            // Assert
            $this->assertSame(1, $removed, 'only the finished, old campaign');
            $this->assertSame(0, $this->recipientsOf($finished));
            $this->assertSame(1, $this->recipientsOf($running), 'it has not finished sending');
            $this->assertSame(1, $this->recipientsOf($recent), 'and this one is recent');
        } finally {
            foreach (['massmessagerecipients', 'massmessages'] as $table) {
                $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefix . $table . '`');
            }
        }
    }

    private function campaign(int $status, int $created): int
    {
        $this->db->queryBuilder()->table('#PREFIX#massmessages')->insert([
            'subject'         => 'Campaign',
            'message'         => 'Body',
            'type'            => 1,
            'status'          => $status,
            'created'         => $created,
            'scheduled'       => 0,
            'totalrecipients' => 1,
            'request'         => '{}',
        ]);

        return (int) $this->db->getInsertId();
    }

    private function recipientsOf(int $messageId): int
    {
        $result = $this->db->query(
            'SELECT COUNT(*) AS total FROM ' . $this->db->prefix
            . 'massmessagerecipients WHERE messageid = ' . $messageId
        );

        return (int) (((array) ($result?->fetch() ?? []))['total'] ?? 0);
    }

    /**
     * On an engine that cannot batch, one statement does the whole range.
     *
     * PostgreSQL has no `LIMIT` on UPDATE or DELETE. Branched on the driver rather than
     * attempted and caught, because "run it and see whether it was a syntax error" makes a real
     * failure — a lock timeout, a dropped connection — indistinguishable from a dialect
     * difference.
     */
    public function testAnEngineThatCannotBatchStillWorks(): void
    {
        // Arrange
        $this->insert(time() - 86400 * 200, 'old body');
        $this->insert(time() - 3600, 'new body');

        $unbatched = new class extends \Pramnos\Email\Retention {
            protected static function batches(): bool { return false; }
        };

        // Act
        $stripped = $unbatched::strip(86400 * 90);

        // Assert
        $this->assertSame(1, $stripped);
        $this->assertSame(2, $this->rows());
    }

    /**
     * A statement that fails is logged and reported as nothing done.
     *
     * This runs from a scheduled job at four in the morning. A fatal there is a cron mail
     * nobody reads; a zero is a number the next run can act on.
     */
    public function testAFailingStatementIsZeroRatherThanAnException(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `mails`');

        // Act & Assert — no exception, and nothing claimed
        $this->assertSame(0, \Pramnos\Email\Retention::strip(86400 * 90));
        $this->assertSame(0, \Pramnos\Email\Retention::prune(86400 * 365));
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    private function runMails(): void
    {
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)
            ->disableOriginalConstructor()->getMock();
        $app->database = $this->db;

        foreach (\Pramnos\Database\MigrationLoader::loadFromDirectory(
            dirname(__DIR__, 3) . '/database/migrations/framework/messaging',
            $app
        ) as $migration) {
            if ($migration instanceof \Pramnos\Framework\Migrations\Messaging\CreateMailsTable) {
                $migration->up();
            }
        }
    }

    private function insert(int $date, string $content): int
    {
        $this->db->queryBuilder()->table('#PREFIX#mails')->insert([
            'status'         => 1,
            'frommail'       => 'from@example.com',
            'fromname'       => 'From',
            'tomail'         => $this->marker . '@example.com',
            'toname'         => 'To',
            'subject'        => 'Subject',
            'content'        => $content,
            'date'           => $date,
            'module'         => 'test',
            'moduleinfo'     => '',
            'extrainfo'      => '',
            'path'           => '',
            'hash'           => md5($content . $date . random_bytes(4)),
        ]);

        return (int) $this->db->getInsertId();
    }

    private function contentOf(int $id): string
    {
        $result = $this->db->queryBuilder()->table('#PREFIX#mails')->where('id', $id)->get();

        return (string) (((array) ($result?->fetch() ?? []))['content'] ?? '');
    }

    private function firstId(): int
    {
        $result = $this->db->queryBuilder()->table('#PREFIX#mails')->orderBy('id', 'asc')->get();

        return (int) (((array) ($result?->fetch() ?? []))['id'] ?? 0);
    }

    private function rows(): int
    {
        $result = $this->db->query('SELECT COUNT(*) AS total FROM ' . $this->db->prefix . 'mails');

        return (int) (((array) ($result?->fetch() ?? []))['total'] ?? 0);
    }
}
