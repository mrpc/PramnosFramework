<?php

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Messaging\Mail;
use Pramnos\Messaging\MailTemplate;
use Pramnos\Messaging\MassMessage;
use Pramnos\Messaging\MassMessageRecipient;
use Pramnos\Messaging\Message;

/**
 * Integration tests for Pramnos\Messaging models against MySQL 8.0.
 *
 * Exercises model CRUD operations against a live database, verifying that
 * save/load/delete and helper methods (countUnread, findByKey) actually
 * interact with the database correctly — not just that SQL is formed.
 *
 * The messaging tables are created via framework migrations before each test
 * and torn down in tearDown. The database singleton used by the models is
 * the same instance returned by Database::getInstance(), so model operations
 * are transparent to the test.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class MessagingModelsMySQLTest extends TestCase
{
    protected Database $db;
    protected Application $app;
    protected Controller $controller;
    protected string $migrationsBase;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        // Bootstrap the framework singleton so models can call Database::getInstance()
        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Mock Application for migration constructors (needs ->database)
        $this->app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->app->database = $this->db;

        $this->migrationsBase = dirname(__DIR__, 3)
            . '/database/migrations/framework';

        $this->controller = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->dropAllTestTables();
        $this->createMessagingTables();
    }

    protected function tearDown(): void
    {
        $this->dropAllTestTables();
    }

    // -------------------------------------------------------------------------
    // Mail model
    // -------------------------------------------------------------------------

    /**
     * Mail::save() must persist a new row; Mail::load() must retrieve it
     * with the same field values and the primary key must be populated on insert.
     *
     * This verifies the basic ORM round-trip: model → database → model.
     */
    public function testMailSaveAndLoad(): void
    {
        // Arrange
        $mail             = new Mail($this->controller);
        $mail->status     = Mail::STATUS_QUEUED;
        $mail->frommail   = 'sender@example.com';
        $mail->fromname   = 'Sender Name';
        $mail->tomail     = 'recipient@example.com';
        $mail->toname     = 'Recipient Name';
        $mail->subject    = 'Test Subject';
        $mail->content    = '<p>Hello</p>';
        $mail->date       = time();
        $mail->module     = 'auth';
        $mail->moduleinfo = 'welcome';
        $mail->extrainfo  = '{}';
        $mail->path       = 'templates/welcome.html';
        $mail->hash       = md5('test');

        // Act
        $mail->save();

        // Assert – primary key was assigned on insert
        $this->assertNotEmpty($mail->id, 'save() must populate the primary key after insert');
        $savedId = $mail->id;

        // Assert – round-trip load returns the same values
        $loaded = (new Mail($this->controller))->load($savedId);
        $this->assertSame(Mail::STATUS_QUEUED, (int) $loaded->status);
        $this->assertSame('sender@example.com', $loaded->frommail);
        $this->assertSame('recipient@example.com', $loaded->tomail);
        $this->assertSame('Test Subject', $loaded->subject);
        $this->assertSame('auth', $loaded->module);
    }

    /**
     * Mail::save() called on an already-loaded record must update the existing row
     * without inserting a duplicate. Verified by reloading and checking the updated value.
     */
    public function testMailUpdate(): void
    {
        // Arrange — insert a queued mail
        $mail             = new Mail($this->controller);
        $mail->status     = Mail::STATUS_QUEUED;
        $mail->frommail   = 'a@b.com';
        $mail->fromname   = 'A';
        $mail->tomail     = 'c@d.com';
        $mail->toname     = 'C';
        $mail->subject    = 'original';
        $mail->content    = 'body';
        $mail->date       = time();
        $mail->module     = 'test';
        $mail->moduleinfo = '';
        $mail->extrainfo  = '';
        $mail->path       = '';
        $mail->hash       = md5('a');
        $mail->save();
        $id = $mail->id;

        // Act — reload and change status to sent
        $loaded         = (new Mail($this->controller))->load($id);
        $loaded->status = Mail::STATUS_SENT;
        $loaded->save();

        // Assert — reloaded record shows updated status; only 1 row exists
        $reloaded = (new Mail($this->controller))->load($id);
        $this->assertSame(Mail::STATUS_SENT, (int) $reloaded->status);

        $count = $this->db->query("SELECT COUNT(*) AS cnt FROM `mails` WHERE id = {$id}");
        $this->assertSame(1, (int) $count->fields['cnt'], 'update must not insert a duplicate row');
    }

    /**
     * Mail::delete() must remove the row from the database.
     */
    public function testMailDelete(): void
    {
        // Arrange
        $mail             = new Mail($this->controller);
        $mail->status     = Mail::STATUS_FAILED;
        $mail->frommail   = 'x@y.com';
        $mail->fromname   = 'X';
        $mail->tomail     = 'y@z.com';
        $mail->toname     = 'Y';
        $mail->subject    = 'del';
        $mail->content    = '';
        $mail->date       = time();
        $mail->module     = 'test';
        $mail->moduleinfo = '';
        $mail->extrainfo  = '';
        $mail->path       = '';
        $mail->hash       = md5('del');
        $mail->save();
        $id = $mail->id;

        // Act
        (new Mail($this->controller))->delete($id);

        // Assert — the row is gone
        $result = $this->db->query("SELECT COUNT(*) AS cnt FROM `mails` WHERE id = {$id}");
        $this->assertSame(0, (int) $result->fields['cnt'], 'delete() must remove the row');
    }

    // -------------------------------------------------------------------------
    // MailTemplate model
    // -------------------------------------------------------------------------

    /**
     * MailTemplate::save() must persist and MailTemplate::findByKey() must
     * return the correct record for a (category, language, type) lookup.
     *
     * findByKey() is the primary read path for template resolution at send time —
     * its correctness is critical to the messaging feature.
     */
    public function testMailTemplateSaveAndFindByKey(): void
    {
        // Arrange
        $tpl                 = new MailTemplate($this->controller);
        $tpl->title          = 'Welcome Email';
        $tpl->defaulttext    = 'Hello {username}, welcome!';
        $tpl->defaultsubject = 'Welcome to our platform';
        $tpl->category       = 'auth';
        $tpl->language       = 'en';
        $tpl->type           = MailTemplate::TYPE_EMAIL;
        $tpl->sound          = '';
        $tpl->sendmethod     = MailTemplate::SENDMETHOD_SMTP;
        $tpl->defaultaccount = null;
        $tpl->emailtemplate  = 'default';

        // Act
        $tpl->save();

        // Assert – ID was assigned
        $this->assertNotEmpty($tpl->templateid);

        // Assert – findByKey returns the right record
        $found = (new MailTemplate($this->controller))->findByKey('auth', 'en', MailTemplate::TYPE_EMAIL);
        $this->assertNotNull($found, 'findByKey() must return the template that was saved');
        $this->assertSame('Welcome Email', $found->title);
        $this->assertSame('auth', $found->category);
        $this->assertSame('en', $found->language);
        $this->assertSame(MailTemplate::TYPE_EMAIL, (int) $found->type);
    }

    /**
     * MailTemplate::findByKey() must return null when no template exists
     * for the requested (category, language, type) combination.
     */
    public function testMailTemplateFindByKeyReturnsNullWhenNotFound(): void
    {
        // Act — request a template that was never saved
        $found = (new MailTemplate($this->controller))->findByKey('nonexistent', 'zz', MailTemplate::TYPE_PUSH);

        // Assert
        $this->assertNull($found, 'findByKey() must return null for an unknown key');
    }

    // -------------------------------------------------------------------------
    // Message model
    // -------------------------------------------------------------------------

    /**
     * Message::save() must persist and Message::load() must retrieve a direct
     * message. The type column (state machine) must survive the round-trip.
     */
    public function testMessageSaveAndLoad(): void
    {
        // Arrange
        $msg                = new Message($this->controller);
        $msg->type          = Message::TYPE_NEW;
        $msg->subject       = 'Hello';
        $msg->text          = 'Message body';
        $msg->url           = '';
        $msg->urlcaption    = '';
        $msg->attachmenttext = '';
        $msg->image         = '';
        $msg->securitycode  = 'abc123';
        $msg->fromuserid    = 10;
        $msg->touserid      = 20;
        $msg->date          = time();
        $msg->ip            = '127.0.0.1';
        $msg->bbcode        = 1;
        $msg->html          = 0;
        $msg->smilies       = 1;
        $msg->signature     = 0;
        $msg->attachment    = 0;

        // Act
        $msg->save();

        // Assert
        $this->assertNotEmpty($msg->messageid);
        $loaded = (new Message($this->controller))->load($msg->messageid);
        $this->assertSame(Message::TYPE_NEW, (int) $loaded->type);
        $this->assertSame('Hello', $loaded->subject);
        $this->assertSame(20, (int) $loaded->touserid);
    }

    /**
     * Message::countUnread() must count only TYPE_NEW and TYPE_UNREAD rows for
     * the specified user — ignoring other types and other users' rows.
     *
     * This is the count used for inbox badges; wrong counts cause user confusion.
     */
    public function testMessageCountUnread(): void
    {
        // Arrange — 2 unread for user 42, 1 outbox (not counted), 1 for another user
        $this->insertMessage(Message::TYPE_NEW,    42); // unread inbox → counted
        $this->insertMessage(Message::TYPE_UNREAD, 42); // also unread → counted
        $this->insertMessage(Message::TYPE_SENT,   42); // outbox → must NOT be counted
        $this->insertMessage(Message::TYPE_NEW,    99); // different user → must NOT be counted

        // Act
        $count = (new Message($this->controller))->countUnread(42);

        // Assert — only the 2 unread rows for user 42
        $this->assertSame(2, $count, 'countUnread() must count only TYPE_NEW and TYPE_UNREAD for the given user');
    }

    /**
     * Message::countUnreadNotifications() must count only TYPE_NOTIFICATION_NEW rows
     * for the specified user — regular unread inbox messages are NOT notifications.
     */
    public function testMessageCountUnreadNotifications(): void
    {
        // Arrange
        $this->insertMessage(Message::TYPE_NOTIFICATION_NEW,  55); // notification → counted
        $this->insertMessage(Message::TYPE_NOTIFICATION_READ, 55); // seen → must NOT be counted
        $this->insertMessage(Message::TYPE_NEW,               55); // inbox → must NOT be counted
        $this->insertMessage(Message::TYPE_NOTIFICATION_NEW,  77); // other user → not counted

        // Act
        $count = (new Message($this->controller))->countUnreadNotifications(55);

        // Assert
        $this->assertSame(1, $count, 'countUnreadNotifications() must count only TYPE_NOTIFICATION_NEW for the given user');
    }

    // -------------------------------------------------------------------------
    // MassMessage model
    // -------------------------------------------------------------------------

    /**
     * MassMessage::save() must persist and MassMessage::load() must retrieve
     * a broadcast header including its JSON request payload.
     */
    public function testMassMessageSaveAndLoad(): void
    {
        // Arrange
        $mass                  = new MassMessage($this->controller);
        $mass->subject         = 'Broadcast Subject';
        $mass->message         = 'Hello everyone';
        $mass->type            = MassMessage::TYPE_MESSAGE;
        $mass->sender          = 1;
        $mass->status          = MassMessage::STATUS_PENDING;
        $mass->created         = time();
        $mass->scheduled       = 0;
        $mass->totalrecipients = 0;
        $mass->request         = json_encode(['channel' => 'internal']);

        // Act
        $mass->save();

        // Assert
        $this->assertNotEmpty($mass->messageid);
        $loaded = (new MassMessage($this->controller))->load($mass->messageid);
        $this->assertSame('Broadcast Subject', $loaded->subject);
        $this->assertSame(MassMessage::STATUS_PENDING, (int) $loaded->status);
        $this->assertStringContainsString('internal', $loaded->request);
    }

    // -------------------------------------------------------------------------
    // MassMessageRecipient model
    // -------------------------------------------------------------------------

    /**
     * MassMessageRecipient::save() must persist a delivery record linked to its
     * parent massmessage. MassMessageRecipient::load() must retrieve it with the
     * correct (messageid, userid, status) values.
     *
     * The FK constraint from massmessagerecipients.messageid → massmessages.messageid
     * is enforced by MySQL — this test implicitly verifies that the FK is correctly
     * defined by the migration (insert succeeds when parent exists, and would fail otherwise).
     */
    public function testMassMessageRecipientSaveAndLoad(): void
    {
        // Arrange — create the parent broadcast (FK dependency)
        $mass            = new MassMessage($this->controller);
        $mass->subject   = 'FK Test';
        $mass->message   = 'Body';
        $mass->type      = MassMessage::TYPE_MESSAGE;
        $mass->status    = MassMessage::STATUS_PENDING;
        $mass->created   = time();
        $mass->scheduled = 0;
        $mass->totalrecipients = 0;
        $mass->save();
        $massId = $mass->messageid;

        $recip            = new MassMessageRecipient($this->controller);
        $recip->messageid = $massId;
        $recip->userid    = 100;
        $recip->status    = MassMessageRecipient::STATUS_PENDING;

        // Act
        $recip->save();

        // Assert
        $this->assertNotEmpty($recip->recipientid);
        $loaded = (new MassMessageRecipient($this->controller))->load($recip->recipientid);
        $this->assertSame($massId, (int) $loaded->messageid);
        $this->assertSame(100, (int) $loaded->userid);
        $this->assertSame(MassMessageRecipient::STATUS_PENDING, (int) $loaded->status);
    }

    /**
     * MassMessageRecipient::delete() must remove the row from the database.
     */
    public function testMassMessageRecipientDelete(): void
    {
        // Arrange
        $mass            = new MassMessage($this->controller);
        $mass->subject   = 'Del Test';
        $mass->message   = 'Body';
        $mass->type      = MassMessage::TYPE_MESSAGE;
        $mass->status    = MassMessage::STATUS_PENDING;
        $mass->created   = time();
        $mass->scheduled = 0;
        $mass->totalrecipients = 0;
        $mass->save();

        $recip            = new MassMessageRecipient($this->controller);
        $recip->messageid = $mass->messageid;
        $recip->userid    = 200;
        $recip->status    = MassMessageRecipient::STATUS_DELIVERED;
        $recip->save();
        $rid = $recip->recipientid;

        // Act
        (new MassMessageRecipient($this->controller))->delete($rid);

        // Assert — the row is gone
        $result = $this->db->query("SELECT COUNT(*) AS cnt FROM `massmessagerecipients` WHERE recipientid = {$rid}");
        $this->assertSame(0, (int) $result->fields['cnt'], 'delete() must remove the recipient row');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Insert a message row directly for setup efficiency. */
    private function insertMessage(int $type, int $touserid): void
    {
        $this->db->query(
            "INSERT INTO `messages` (type, subject, `text`, url, urlcaption,
             attachmenttext, image, securitycode, fromuserid, touserid, `date`,
             ip, bbcode, html, smilies, signature, attachment)
             VALUES ({$type}, 'subj', 'body', '', '', '', '', '', NULL, {$touserid},
             " . time() . ", '127.0.0.1', 1, 0, 1, 1, 0)"
        );
    }

    /** Run all messaging migrations in priority order. */
    private function createMessagingTables(): void
    {
        $dir        = $this->migrationsBase . '/messaging';
        $migrations = MigrationLoader::loadFromDirectory($dir, $this->app);

        usort($migrations, fn($a, $b) => $a->priority <=> $b->priority);

        foreach ($migrations as $m) {
            $m->up();
        }
    }

    protected function dropAllTestTables(): void
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['massmessagerecipients', 'massmessages', 'mailtemplates', 'mails', 'messages'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
    }
}
