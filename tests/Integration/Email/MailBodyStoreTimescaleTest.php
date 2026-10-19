<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Email\Actions;
use Pramnos\Email\Body;
use Pramnos\Email\BodyStore;
use Pramnos\Email\Email;
use Pramnos\Framework\Factory;

/**
 * The body store in the database (mail body storage, phase 3): on TimescaleDB, `mail_bodies` is a
 * compressed hypertable, chosen by 'auto' once it exists; a sent message's row says `db:`, and its
 * body reads back as it was sent — compressed or not.
 */
#[CoversClass(BodyStore::class)]
#[CoversClass(Body::class)]
#[CoversClass(Email::class)]
#[CoversClass(\Pramnos\Framework\Migrations\Messaging\CreateMailBodiesTable::class)]
final class MailBodyStoreTimescaleTest extends TestCase
{
    private ?Database $previous = null;

    private ?array $savedInstances = null;

    private Database $db;

    protected function setUp(): void
    {
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped('timescaledb not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('timescaledb not reachable: ' . $e->getMessage());
        }
        if (!$db->capabilities()->hasTimescaleDB()) {
            $this->markTestSkipped('No TimescaleDB on this connection.');
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
            \Pramnos\Framework\Migrations\Messaging\CreateEmailTrackingTables::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailBodiesTable::class,
        ], $db);

        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['email' => ['tracking' => true]];
        $ref = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances = $ref->getValue() ?? [];
        $ref->setValue(null, ['default' => $stub] + $this->savedInstances);
        BodyStore::forget();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->queryBuilder()->table('#PREFIX#mails')->whereRaw('tomail like ?', ['%@bodystore.test'])->delete();
            // The tracking rows too: another test reads "no tracked mail this week" off this table.
            $this->db->queryBuilder()->table('pramnos.emailtracking')->whereRaw('recipient like ?', ['%@bodystore.test'])->delete();
        }
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, $this->savedInstances);
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        BodyStore::forget();
    }

    /**
     * With `mail_bodies` there, 'auto' keeps bodies in it: the row's path is `db:`, its content
     * empty, and the body reads back as it was sent, to the byte — and still does once its chunk
     * is compressed.
     */
    public function testABodyKeptInTheDatabaseReadsBackAsSent(): void
    {
        // Arrange
        $transport = new HtmlCapturingTransport();
        $to        = 'db-' . bin2hex(random_bytes(3)) . '@bodystore.test';
        $stop      = htmlspecialchars(\Pramnos\Email\Unsubscribe::url(\Pramnos\Email\Unsubscribe::token($to, 'newsletter')), ENT_QUOTES);
        $mail      = (new Email())->withTransport($transport);
        $mail->setTo($to)->setSubject('In the database')
            ->setBody('<html><head></head><body><p><a href="https://example.com/x">x</a> <a href="' . $stop . '">stop</a></p></body></html>')
            ->offerUnsubscribe('newsletter', $to)
            ->enableTracking();
        $mail->addStructuredData(Actions::view('Open', 'https://example.com/x'));

        // Act
        $mail->send();
        $row = (array) $this->db->queryBuilder()->table('#PREFIX#mails')->where('tomail', $to)->first()->fields;
        $before = Body::of($row);
        $this->db->query("SELECT compress_chunk(c, if_not_compressed => true) FROM show_chunks('" . $this->db->prefix . "mail_bodies') c");
        $after = Body::of($row);

        // Assert
        $this->assertSame(BodyStore::DATABASE, BodyStore::mode(), 'auto: the table is there');
        $this->assertSame(BodyStore::IN_DATABASE, (string) $row['path']);
        $this->assertSame('', (string) $row['content']);
        $this->assertSame($transport->html[0], $before, 'as sent, to the byte');
        $this->assertSame($transport->html[0], $after, 'and compressed');
    }
}
