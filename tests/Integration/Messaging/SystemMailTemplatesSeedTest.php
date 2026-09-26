<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Messaging\SeedSystemMailTemplates;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Messaging\MailTemplate;
use Pramnos\Messaging\SystemMailTemplates;

/**
 * The framework's own mail categories appear in the editor, empty.
 *
 * `MailChannel` gave an operator the ability to rewrite a system email and no way to find
 * it: Administration → Mail templates showed an empty list, and nothing said that
 * `auth.twofactor_code` exists or that `{code}` is a thing it may say. A feature reachable
 * only by reading the source is a feature nobody uses.
 *
 * So a migration seeds one row per category — **blank subject, blank body**. Blank means
 * "use the text compiled into the class", so a seeded installation behaves exactly as an
 * unseeded one until somebody types something.
 *
 * Seeding the real wording would look friendlier and be a trap: the row would fork from the
 * class the moment the framework improved a sentence or fixed a translation, and the
 * installation would keep sending the old one for ever with nothing to say why. That is the
 * assertion below that looks pedantic and is not.
 */
#[CoversClass(SeedSystemMailTemplates::class)]
#[CoversClass(SystemMailTemplates::class)]
class SystemMailTemplatesSeedTest extends BaseTestCase
{
    protected \Pramnos\Database\Database $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateMailtemplatesTable::class,
        ], $this->db);

        $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->truncate();
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->truncate();
    }

    /** Which connection this class runs against. */
    protected function settingsFixture(): string
    {
        return 'settings';
    }

    /** Run the seeding migration against this connection. */
    private function seed(): void
    {
        $this->runMigrations([SeedSystemMailTemplates::class], $this->db);
    }

    /** Every row currently in the table, keyed by category. */
    private function rows(): array
    {
        $result = $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->get();
        $rows   = [];

        foreach ($result->fetchAll() as $row) {
            $rows[(string) $row['category']] = $row;
        }

        return $rows;
    }

    /**
     * One row per declared category, and every field an operator has not written is blank.
     */
    public function testItSeedsOneBlankRowPerCategory(): void
    {
        // Act
        $this->seed();

        // Assert
        $rows     = $this->rows();
        $declared = SystemMailTemplates::all();
        $this->assertNotEmpty($declared, 'the sweep found nothing to check');

        foreach ($declared as $category => $declaration) {
            $this->assertArrayHasKey($category, $rows, $category . ' is not in the editor');
            $this->assertSame($declaration['title'], (string) $rows[$category]['title']);

            // The assertion the whole design rests on: a seeded row must not carry the
            // built-in wording, or it forks from the class the first time the class changes.
            $this->assertSame('', trim((string) $rows[$category]['defaultsubject']), $category);
            $this->assertSame('', trim((string) $rows[$category]['defaulttext']), $category);
            $this->assertSame(
                MailTemplate::TYPE_EMAIL,
                (int) $rows[$category]['type'],
                $category . ' was seeded on the wrong channel'
            );
        }
    }

    /**
     * A blank seeded row changes nothing about what is sent.
     *
     * The seeding and the fallback are two halves of one claim, and asserting them apart
     * leaves the interesting case untested: a row now *exists* for every category, so the
     * lookup finds one where it previously found none.
     */
    public function testASeededRowStillSendsTheBuiltInText(): void
    {
        // Arrange
        $this->seed();

        $spy          = new \Pramnos\Tests\Unit\Notification\Channels\SpyEmail();
        $channel      = new \Pramnos\Notification\Channels\MailChannel($spy);
        $notification = new \Pramnos\Tests\Unit\Notification\Channels\OverridableNotification(
            ['subject' => 'Built-in subject', 'body' => 'Built-in body'],
            'auth.twofactor_code',
            ['code' => '481920']
        );

        // Act
        $channel->send(
            new \Pramnos\Tests\Unit\Notification\Channels\MailNotifiable('reader@example.com'),
            $notification
        );

        // Assert
        $this->assertSame('Built-in subject', $spy->subject, 'a blank seeded row replaced the text');
        $this->assertSame('Built-in body', $spy->body);
    }

    /**
     * Running it twice does not make two rows.
     *
     * A migration is recorded once, but a re-run happens: an adopted ledger, a
     * `migrate --force`, an installation whose history moved. Two rows for one category
     * would make which one an operator edits a matter of chance.
     */
    public function testRunningItTwiceSeedsOnce(): void
    {
        // Act
        $this->seed();
        $this->seed();

        // Assert
        $result = $this->db->queryBuilder()->table('#PREFIX#mailtemplates')
            ->where('category', 'auth.twofactor_code')
            ->get();

        $this->assertCount(1, $result->fetchAll());
    }

    /**
     * An operator's text is never overwritten.
     *
     * The case that decides whether this migration is safe to ship: an installation that
     * had already written a template — the whole point of the feature — must not have it
     * replaced by a blank row on upgrade.
     */
    public function testItLeavesAnOperatorsRowAlone(): void
    {
        // Arrange — somebody got there first
        $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->insert([
            'title'          => 'Ours',
            'category'       => 'auth.twofactor_code',
            'language'       => 'en',
            'type'           => MailTemplate::TYPE_EMAIL,
            'defaultsubject' => 'Your code is {code}',
            'defaulttext'    => 'Body we wrote',
            'emailtemplate'  => '',
            'sendmethod'     => 0,
            'sound'          => '',
        ]);

        // Act
        $this->seed();

        // Assert
        $rows = $this->rows();
        $this->assertSame('Your code is {code}', (string) $rows['auth.twofactor_code']['defaultsubject']);
        $this->assertSame('Body we wrote', (string) $rows['auth.twofactor_code']['defaulttext']);
    }
}
