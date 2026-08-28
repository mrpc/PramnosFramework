<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Email\Tracking;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Recording an open and a click, against the tables that now exist.
 *
 * The unit tests cover what may be tracked and what a link looks like. This covers the half that
 * has never existed: the writes. `Email::enableTracking()` has been in the framework for years
 * with no migration behind it, so every insert it attempted failed into a `catch` — which is why
 * a test that asserts a row is the point of this file.
 *
 * The distinction being protected is the one the whole feature turns on: an open by a person and
 * a fetch by a mailbox proxy land in **different columns**, and nothing adds them together.
 */
#[CoversClass(Tracking::class)]
#[CoversClass(\Pramnos\Email\Email::class)]
#[CoversClass(\Pramnos\Application\Controllers\EmailsController::class)]
class TrackingRecordingTest extends BaseTestCase
{
    private $db;

    /** @var array<string, mixed>|null */
    private ?array $savedInstances = null;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::loadSettings(
            ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php'
        );
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect();
        }

        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('Runs on MySQL only, like the rest of the messaging suite.');
        }

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
        ], $this->db);

        $this->db->queryBuilder()->table('#PREFIX#emailtracking')->truncate();
        $this->db->queryBuilder()->table('#PREFIX#emailtrackingclicks')->truncate();

        $this->trackingOn(true);
    }

    protected function tearDown(): void
    {
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))
                ->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }

        parent::tearDown();
    }

    /** Switch the installation-wide setting through a stub application. */
    private function trackingOn(bool $on): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['email' => ['tracking' => $on]];

        $reflection = new \ReflectionProperty(Application::class, 'appInstances');

        if ($this->savedInstances === null) {
            $this->savedInstances = $reflection->getValue() ?? [];
        }

        $instances = $this->savedInstances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);
    }

    /** @return array<string, mixed> */
    private function row(string $trackingId): array
    {
        $result = $this->db->queryBuilder()
            ->table('#PREFIX#emailtracking')
            ->where('tracking_id', $trackingId)
            ->get();

        return ($result->numRows ?? 0) > 0 ? (array) $result->fields : [];
    }

    /**
     * A tracked message gets a row; an untracked one gets nothing.
     *
     * The gate, asserted where it counts — against the table. A transactional message must leave
     * no trace at all, not a row with zero opens.
     */
    public function testARowIsWrittenOnlyForListMail(): void
    {
        // Act
        $tracked = Tracking::begin('a@example.com', 'newsletter', 'Hello', 42, 'id-tracked');
        $skipped = Tracking::begin('a@example.com', '', 'Password reset', 43, 'id-skipped');

        // Assert
        $this->assertTrue($tracked);
        $this->assertFalse($skipped);

        $row = $this->row('id-tracked');
        $this->assertSame('newsletter', $row['list']);
        $this->assertSame('Hello', $row['subject']);
        $this->assertSame(42, (int) $row['mailid']);
        $this->assertSame(0, (int) $row['opens']);

        $this->assertSame([], $this->row('id-skipped'), 'no row at all');
    }

    /**
     * With the setting off, nothing is written even for list mail.
     */
    public function testTheSettingIsTheOuterGate(): void
    {
        // Arrange
        $this->trackingOn(false);

        // Act & Assert
        $this->assertFalse(Tracking::begin('a@example.com', 'newsletter', 'Hello', 1, 'id-off'));
        $this->assertSame([], $this->row('id-off'));
    }

    /**
     * A person's open is counted, with first and last times.
     */
    public function testAnOpenIsRecorded(): void
    {
        // Arrange
        Tracking::begin('a@example.com', 'newsletter', 'Hello', 1, 'id-open');

        // Act
        $counted = Tracking::recordOpen('id-open', 'Mozilla/5.0 (iPhone) Safari/604.1', '85.72.1.2');
        Tracking::recordOpen('id-open', 'Mozilla/5.0 (iPhone) Safari/604.1', '85.72.1.2');

        // Assert
        $row = $this->row('id-open');
        $this->assertTrue($counted);
        $this->assertSame(2, (int) $row['opens']);
        $this->assertSame(0, (int) $row['proxy_opens']);
        $this->assertGreaterThan(0, (int) $row['first_open_at']);
        $this->assertSame((int) $row['first_open_at'], (int) $row['first_open_at']);
    }

    /**
     * The first open time does not move when the message is opened again.
     *
     * `first_open_at` answers "how long did it take them to read it", which is only meaningful if
     * it stays the first one.
     */
    public function testTheFirstOpenTimeIsTheFirstOne(): void
    {
        // Arrange
        Tracking::begin('a@example.com', 'newsletter', 'Hello', 1, 'id-first');
        Tracking::recordOpen('id-first', 'Safari', '85.72.1.2');
        $first = (int) $this->row('id-first')['first_open_at'];

        // Act — an hour later, as far as the row is concerned
        $this->db->queryBuilder()->table('#PREFIX#emailtracking')
            ->where('tracking_id', 'id-first')
            ->update(['first_open_at' => $first - 3600, 'last_open_at' => $first - 3600]);

        Tracking::recordOpen('id-first', 'Safari', '85.72.1.2');

        // Assert
        $row = $this->row('id-first');
        $this->assertSame($first - 3600, (int) $row['first_open_at'], 'unchanged');
        $this->assertGreaterThan((int) $row['first_open_at'], (int) $row['last_open_at']);
    }

    /**
     * A proxy fetch lands in its own column and is not reported as a reader.
     *
     * The distinction the whole feature turns on. Apple Mail fetches every remote image on
     * delivery, so counting these as opens reports an open for every Apple recipient minutes
     * after sending — for a message nobody has looked at.
     */
    public function testAProxyFetchIsCountedApart(): void
    {
        // Arrange
        Tracking::begin('a@example.com', 'newsletter', 'Hello', 1, 'id-proxy');

        // Act
        $counted = Tracking::recordOpen('id-proxy', 'GoogleImageProxy', '66.249.84.1');

        // Assert
        $row = $this->row('id-proxy');
        $this->assertFalse($counted, 'not a person');
        $this->assertSame(0, (int) $row['opens']);
        $this->assertSame(1, (int) $row['proxy_opens']);
        $this->assertNull($row['first_open_at'], 'nobody has opened it');
    }

    /**
     * A click is recorded, with the destination, and returns where to go.
     */
    public function testAClickIsRecordedAndRedirected(): void
    {
        // Arrange
        Tracking::begin('a@example.com', 'newsletter', 'Hello', 1, 'id-click');
        $link  = Tracking::link('id-click', 'https://example.com/offer?id=9');
        $token = urldecode(explode('c=', $link)[1]);

        // Act
        $destination = Tracking::recordClick($token);

        // Assert
        $this->assertSame('https://example.com/offer?id=9', $destination);

        $row = $this->row('id-click');
        $this->assertSame(1, (int) $row['clicks']);
        $this->assertGreaterThan(0, (int) $row['first_click_at']);

        $clicks = $this->db->queryBuilder()
            ->table('#PREFIX#emailtrackingclicks')
            ->where('tracking_id', 'id-click')
            ->get();

        $this->assertSame(1, (int) $clicks->numRows);
        $this->assertSame('https://example.com/offer?id=9', $clicks->fields['url']);
    }

    /**
     * Two clicks on different links are two rows, one counter.
     *
     * *Which* link is the only interesting question about a click, so the destinations are kept
     * separately while the message's own counter adds up.
     */
    public function testEachLinkIsItsOwnRow(): void
    {
        // Arrange
        Tracking::begin('a@example.com', 'newsletter', 'Hello', 1, 'id-two');

        // Act
        foreach (['https://example.com/a', 'https://example.com/b'] as $url) {
            Tracking::recordClick(urldecode(explode('c=', Tracking::link('id-two', $url))[1]));
        }

        // Assert
        $this->assertSame(2, (int) $this->row('id-two')['clicks']);

        $clicks = $this->db->queryBuilder()
            ->table('#PREFIX#emailtrackingclicks')
            ->where('tracking_id', 'id-two')
            ->get();

        $this->assertSame(2, (int) $clicks->numRows);
    }

    /**
     * An open for an id nobody knows is not an error.
     *
     * The pixel is fetched by the world. A message forwarded to somebody else, an id from a
     * database that was restored, a scanner walking URLs — none of it should raise anything.
     */
    public function testAnUnknownIdIsHarmless(): void
    {
        // Act & Assert
        $this->assertTrue(Tracking::recordOpen('no-such-id', 'Safari', '85.72.1.2'));
        $this->assertSame([], $this->row('no-such-id'));
    }

    /**
     * A tracked message gets its pixel and its wrapped links, and a row to match.
     *
     * The end-to-end path, which the unit tests cannot reach: `applyTracking()` writes a row and
     * then rewrites the body, and the two have to agree about the id.
     */
    public function testATrackedMessageGetsItsPixelAndItsLinks(): void
    {
        // Arrange
        $mail = new class extends \Pramnos\Email\Email {
            public function apply(string $body): string
            {
                return $this->applyTracking($body);
            }
        };
        $mail->to = 'reader@example.com';
        $mail->subject = 'This month';
        $mail->unsubscribeList = 'newsletter';
        $mail->unsubscribe = 'https://example.com/unsubscribe?u=abc';
        $mail->enableTracking('id-endtoend');

        // Act
        $body = $mail->apply(
            '<p><a href="https://example.com/offer">Offer</a> '
            . '<a href="https://example.com/unsubscribe?u=abc">Unsubscribe</a></p>'
        );

        // Assert — the row
        $row = $this->row('id-endtoend');
        $this->assertSame('newsletter', $row['list']);
        $this->assertSame('This month', $row['subject']);
        $this->assertSame('reader@example.com', $row['recipient']);

        // …the pixel, carrying the same id
        $this->assertStringContainsString(\Pramnos\Email\Tracking::PIXEL_PATH . '?t=id-endtoend', $body);

        // …the wrapped link, and the unsubscribe left alone
        $this->assertStringContainsString(\Pramnos\Email\Tracking::CLICK_PATH, $body);
        $this->assertStringContainsString('href="https://example.com/unsubscribe?u=abc"', $body);
        $this->assertStringNotContainsString('href="https://example.com/offer"', $body);
    }

    /**
     * A transactional message is returned untouched, with no row behind it.
     *
     * The gate, at the level a caller actually sees: not a row with zero opens and not a pixel
     * that records nothing — nothing at all.
     */
    public function testATransactionalMessageIsUntouched(): void
    {
        // Arrange
        $mail = new class extends \Pramnos\Email\Email {
            public function apply(string $body): string
            {
                return $this->applyTracking($body);
            }
        };
        $mail->to = 'reader@example.com';
        $mail->subject = 'Password reset';
        $mail->enableTracking('id-transactional');

        $html = '<p><a href="https://example.com/reset/abc">Reset</a></p>';

        // Act
        $body = $mail->apply($html);

        // Assert
        $this->assertSame($html, $body, 'no pixel, and the link is not wrapped');
        $this->assertSame([], $this->row('id-transactional'));
    }

    /**
     * The administration screen reads the row it was given.
     *
     * `trackingFor()` is the one part of that screen that touches the database, and it has to
     * find a row by the `mails` id rather than by the tracking id — which is the join the send
     * path back-fills after the audit row exists.
     */
    public function testTheAdministrationScreenFindsTheRowByMailId(): void
    {
        // Arrange
        \Pramnos\Email\Tracking::begin('a@example.com', 'newsletter', 'Hello', 4242, 'id-screen');
        \Pramnos\Email\Tracking::recordOpen('id-screen', 'Safari', '85.72.1.2');

        $screen = new class extends \Pramnos\Application\Controllers\EmailsController {
            public function __construct()
            {
            }

            public function lookup(int $mailId): ?array
            {
                return $this->trackingFor($mailId);
            }
        };

        // Act
        $found   = $screen->lookup(4242);
        $missing = $screen->lookup(999999);

        // Assert
        $this->assertSame(1, (int) $found['opens']);
        $this->assertSame('newsletter', $found['list']);
        $this->assertNull($missing);
        $this->assertNull($screen->lookup(0), 'no id, no lookup');
    }

    /**
     * `begin()` generates an id when the caller has none.
     *
     * The path a message takes when it did not call `enableTracking()` with an id of its own.
     */
    public function testAnIdIsGeneratedWhenNoneIsGiven(): void
    {
        // Act
        $this->assertTrue(Tracking::begin('a@example.com', 'newsletter', 'Hello'));

        // Assert — a row exists, with an id nobody chose
        $result = $this->db->queryBuilder()
            ->table('#PREFIX#emailtracking')
            ->where('recipient', 'a@example.com')
            ->get();

        $this->assertSame(1, (int) $result->numRows);
        $this->assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $result->fields['tracking_id']);
    }

    /**
     * A click still goes where it was going when it cannot be recorded.
     *
     * The documented behaviour, and the one that matters most: a tracker that can break a link
     * is worse than no tracker. The click is the thing the reader cared about; the measurement
     * is the thing that did not.
     */
    public function testAClickSurvivesTheTrackingTablesBeingGone(): void
    {
        // Arrange — the tables are away, as they would be mid-migration or on a restore
        Tracking::begin('a@example.com', 'newsletter', 'Hello', 1, 'id-broken');
        $token = urldecode(explode(
            'c=',
            Tracking::link('id-broken', 'https://example.com/offer')
        )[1]);

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefix . 'emailtrackingclicks`');

        try {
            // Act
            $destination = Tracking::recordClick($token);

            // Assert
            $this->assertSame('https://example.com/offer', $destination);
        } finally {
            $this->runMigrations([
                \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            ], $this->db);
        }
    }

    /**
     * An open that cannot be recorded does not raise.
     *
     * The pixel is served to the world and must answer with an image whatever the database is
     * doing. An exception here is a broken image in somebody's mail.
     */
    public function testAnOpenSurvivesTheTrackingTableBeingGone(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefix . 'emailtracking`');

        try {
            // Act & Assert — no exception, and it does not claim to have counted a reader
            $this->assertFalse(Tracking::recordOpen('id-gone', 'Safari', '85.72.1.2'));
        } finally {
            $this->runMigrations([
                \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            ], $this->db);
        }
    }

    /**
     * And starting to track cannot break sending.
     *
     * A message that cannot be tracked is still a message that has to go out.
     */
    public function testBeginSurvivesTheTrackingTableBeingGone(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefix . 'emailtracking`');

        try {
            // Act & Assert
            $this->assertFalse(Tracking::begin('a@example.com', 'newsletter', 'Hello', 1, 'id-x'));
        } finally {
            $this->runMigrations([
                \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            ], $this->db);
        }
    }

    /**
     * The administration screen degrades to "not tracked" when the tables are absent.
     *
     * An installation that never switched tracking on has no tables at all, and every row of
     * that screen calls this. A missing table there is not an error — it is the normal state.
     */
    public function testTheScreenSurvivesTheTablesBeingAbsent(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefix . 'emailtracking`');

        $screen = new class extends \Pramnos\Application\Controllers\EmailsController {
            public function __construct()
            {
            }

            public function lookup(int $mailId): ?array
            {
                return $this->trackingFor($mailId);
            }
        };

        try {
            // Act & Assert
            $this->assertNull($screen->lookup(1));
        } finally {
            $this->runMigrations([
                \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            ], $this->db);
        }
    }
}
