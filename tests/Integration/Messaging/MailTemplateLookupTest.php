<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Messaging\MailTemplate;

/**
 * Finding the template an operator wrote, in the language the reader speaks.
 *
 * The `mailtemplates` table and this model shipped long ago with `findByKey()` — an exact
 * (category, language, type) match and nothing else — and **no caller**. Wiring it into the
 * mail channel needed a lookup that answers for a real installation rather than for a test:
 * an operator writes one template, in one language, and expects it to be used.
 *
 * So the order is the reader's language, then the site's default, then **any row for the
 * category**. The last step is the one worth a test: refusing a template because the
 * recipient's tag does not match would look exactly like the override not working, and the
 * operator would have no way to tell the two apart.
 */
#[CoversClass(MailTemplate::class)]
#[CoversClass(\Pramnos\Notification\Channels\MailChannel::class)]
class MailTemplateLookupTest extends BaseTestCase
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

        // The seeding migration is exercised in `SystemMailTemplatesSeedTest`; here the
        // table starts empty so each case controls exactly which rows exist.

        $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->truncate();
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->truncate();
    }

    /** Which connection this class runs against; the PostgreSQL subclass answers otherwise. */
    protected function settingsFixture(): string
    {
        return 'settings';
    }

    /** @param array<string, mixed> $extra */
    private function seed(string $category, string $language, string $subject, array $extra = []): void
    {
        $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->insert(array_merge([
            'title'          => $category . ' ' . $language,
            'category'       => $category,
            'language'       => $language,
            'type'           => MailTemplate::TYPE_EMAIL,
            'defaultsubject' => $subject,
            'defaulttext'    => 'Body ' . $language,
            'emailtemplate'  => '',
            'sendmethod'     => 0,
            'sound'          => '',
        ], $extra));
    }

    /**
     * The reader's own language is preferred over every other row.
     */
    public function testTheReadersLanguageWins(): void
    {
        // Arrange
        $this->seed('auth.twofactor_code', 'en', 'English subject');
        $this->seed('auth.twofactor_code', 'el', 'Greek subject');

        // Act
        $found = MailTemplate::lookup('auth.twofactor_code', 'el');

        // Assert
        $this->assertNotNull($found);
        $this->assertSame('Greek subject', $found['subject']);
    }

    /**
     * A template in a language nobody asked for is still used when it is the only one.
     *
     * An operator who wrote one template meant it to be used. Answering null here would be
     * indistinguishable, from the operator's side, from the whole feature not working.
     */
    public function testTheOnlyTemplateIsUsedWhateverItsLanguage(): void
    {
        // Arrange — nothing in the reader's language
        $this->seed('auth.twofactor_code', 'fr', 'French subject');

        // Act
        $found = MailTemplate::lookup('auth.twofactor_code', 'el');

        // Assert
        $this->assertNotNull($found, 'the only template there is was refused');
        $this->assertSame('French subject', $found['subject']);
    }

    /**
     * The site's default language is tried before giving up on language altogether.
     *
     * The middle step, and the one a test would skip: a reader whose tag matches nothing
     * should get the site's own language rather than whichever row happens to sort first.
     */
    public function testTheSiteDefaultIsPreferredOverAnyOtherRow(): void
    {
        // Arrange — the setting is written rather than read, because the fixture declares
        // none and a test that skips on that never runs anywhere.
        $previous = \Pramnos\Application\Settings::getSetting('default_language', '');
        \Pramnos\Application\Settings::setSetting('default_language', 'de');

        // Seeded out of order, so sorting by templateid would pick the wrong one.
        $this->seed('auth.new_signin', 'zz', 'Wrong subject');
        $this->seed('auth.new_signin', 'de', 'Default subject');

        // Act — a reader whose language matches neither
        $found = MailTemplate::lookup('auth.new_signin', 'xx');

        // Assert
        $this->assertNotNull($found);
        $this->assertSame('Default subject', $found['subject']);

        \Pramnos\Application\Settings::setSetting('default_language', (string) $previous);
    }

    /**
     * A category nobody wrote a template for answers null.
     *
     * The case every installation is in for every notification until somebody writes one,
     * so it decides what the default behaviour costs: one query that finds nothing.
     */
    public function testAnUnknownCategoryIsNull(): void
    {
        // Arrange
        $this->seed('auth.twofactor_code', 'en', 'English subject');

        // Act + Assert
        $this->assertNull(MailTemplate::lookup('auth.new_signin', 'en'));
    }

    /**
     * An SMS row for the same category is not an email template.
     *
     * The table holds three channels keyed the same way, so a category with an SMS
     * template and no email one must answer null rather than send the SMS text as mail.
     */
    public function testAnSmsRowIsNotAnEmailTemplate(): void
    {
        // Arrange
        $this->seed('auth.twofactor_code', 'en', 'SMS subject', ['type' => MailTemplate::TYPE_SMS]);

        // Act + Assert
        $this->assertNull(MailTemplate::lookup('auth.twofactor_code', 'en'));
    }

    /**
     * An empty category asks nothing of the database.
     */
    public function testAnEmptyCategoryIsNull(): void
    {
        // Act + Assert
        $this->assertNull(MailTemplate::lookup('', 'en'));
    }

    /**
     * `fill()` substitutes what it was given and leaves the rest visible.
     *
     * Against a row that came back from the database rather than one built in memory, so
     * the column names and the substitution are checked together.
     */
    public function testRenderSubstitutesFromARowThatCameFromTheDatabase(): void
    {
        // Arrange
        $this->seed('auth.twofactor_code', 'en', '{code} is your code', [
            'defaulttext' => 'Expires in {minutes} minutes, {firstname}.',
        ]);

        // Act
        $found    = MailTemplate::lookup('auth.twofactor_code', 'en');
        $rendered = MailTemplate::fill($found, ['code' => '481920', 'minutes' => 10]);

        // Assert
        $this->assertSame('481920 is your code', $rendered['subject']);
        $this->assertSame(
            'Expires in 10 minutes, {firstname}.',
            $rendered['body'],
            'an unknown placeholder was blanked instead of left visible'
        );
    }

    /**
     * The channel is wired to the real lookup, not only to a stub.
     *
     * Every other test of the substitution rules overrides `templateFor()` so it can run
     * without a database — which proves the rules and proves nothing about the wiring. A
     * channel whose lookup pointed at the wrong table, or built a model that cannot be
     * constructed, would pass all of them. **That is not hypothetical: the first version of
     * the lookup was an instance method on a `Model` whose constructor requires a
     * `Controller`, so it raised everywhere it was actually called.**
     */
    public function testTheChannelUsesTheStoredTemplateEndToEnd(): void
    {
        // Arrange
        $this->seed('auth.twofactor_code', 'en', '{code} is your code', [
            'defaulttext' => 'Operator body for {code}.',
        ]);

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
        $this->assertSame('481920 is your code', $spy->subject, 'the channel did not reach the table');
        $this->assertSame('Operator body for 481920.', $spy->body);
    }
}
