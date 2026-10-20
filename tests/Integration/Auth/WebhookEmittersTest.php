<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\WebhookService;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * Every built-in webhook type about a user is queued by the change it reports, and only for the
 * applications that user has used.
 *
 * The catalogue advertised `user_deauthorized`, `account_deleted` and `scope_changed` with nothing
 * in the framework that queued them; an endpoint subscribed to one waited for ever. These tests
 * make each change for real and read the event row it left: which endpoint it was queued for, and
 * what its body says.
 *
 * Runs on every backend: {@see WebhookEmittersPostgreSQLTest} re-runs it on PostgreSQL, where the
 * events' `user_id` is a foreign key — the reason `account_deleted` names the user in the body only.
 */
#[CoversClass(WebhookService::class)]
#[CoversClass(\Pramnos\Auth\AuthorizedApplications::class)]
#[CoversClass(\Pramnos\Auth\AccountErasure::class)]
#[CoversClass(\Pramnos\Auth\Controllers\Oauth::class)]
#[CoversClass(User::class)]
class WebhookEmittersTest extends BaseTestCase
{
    private $db;

    /** The application the user has used. */
    private int $appId = 0;

    /** An application the user never used, which must hear nothing about them. */
    private int $otherAppId = 0;

    private int $uid = 0;

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

        User::setupDb();
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsSchema::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateOauth2WebhooksTables::class,
            \Pramnos\Framework\Migrations\AuthServer\AllowPermissionsChangedWebhook::class,
            \Pramnos\Framework\Migrations\AuthServer\AddRegisteredByToOauth2WebhookEndpoints::class,
            \Pramnos\Framework\Migrations\AuthServer\OpenWebhookEventTypes::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateOauth2UserConsentsTable::class,
        ], $this->db);
        \Pramnos\Database\SchemaBuilder::forgetWhatExists($this->db);

        $this->appId      = $this->registerApplication('emitter client ' . bin2hex(random_bytes(3)));
        $this->otherAppId = $this->registerApplication('emitter other ' . bin2hex(random_bytes(3)));

        $user = new User();
        $user->username = 'emit_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->uid = (int) $user->userid;
    }

    protected function tearDown(): void
    {
        foreach ([$this->appId, $this->otherAppId] as $appId) {
            $ids = $this->db->queryBuilder()->table('applications.oauth2_webhook_endpoints')
                ->select(['webhook_id'])->where('appid', $appId)->get();
            while ($ids && $ids->fetch()) {
                $this->db->queryBuilder()->table('applications.oauth2_webhook_events')
                    ->where('webhook_id', (int) $ids->fields['webhook_id'])->delete();
            }
            $this->db->queryBuilder()->table('applications.oauth2_webhook_endpoints')->where('appid', $appId)->delete();
        }
        if ($this->uid > 0) {
            foreach (['#PREFIX#usertokens', 'authserver.oauth2_user_consents', '#PREFIX#userdetails', '#PREFIX#users'] as $table) {
                $this->db->queryBuilder()->table($table)->where('userid', $this->uid)->delete();
            }
        }
        foreach ([$this->appId, $this->otherAppId] as $appId) {
            $this->db->queryBuilder()->table('applications')->where('appid', $appId)->delete();
        }
        User::clearUserCache();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── Who hears it ──────────────────────────────────────────────────────────

    /**
     * A user's event reaches the applications that user has used, and no other.
     *
     * An application the user never signed in to has no business learning that they changed
     * their profile or deleted their account — that would be the server telling one client
     * about another client's users.
     */
    public function testAUserEventReachesOnlyTheUsersApplications(): void
    {
        // Arrange
        $mine   = $this->subscribe($this->appId, 'user_profile_changed');
        $theirs = $this->subscribe($this->otherAppId, 'user_profile_changed');
        $this->issueToken($this->appId);

        // Act
        $queued = WebhookService::userEvent('user_profile_changed', $this->uid, ['changed' => ['email']]);

        // Assert
        $this->assertSame(1, $queued);
        $this->assertSame([], $this->eventsFor($theirs), 'an application the user never used was told');
        $event = $this->eventsFor($mine)[0] ?? [];
        // assertEquals: the JSON column does not keep the keys' order
        $this->assertEquals(
            ['event_type' => 'user_profile_changed', 'user_id' => $this->uid, 'changed' => ['email']],
            $event['payload'] ?? null
        );
        $this->assertSame($this->uid, (int) $event['user_id']);
    }

    /** With nobody subscribed, nothing is queued and nothing about the user is looked up. */
    public function testWithNobodySubscribedNothingIsQueued(): void
    {
        // Arrange
        $this->issueToken($this->appId);

        // Act & Assert
        $this->assertSame(0, WebhookService::userEvent('user_profile_changed', $this->uid, []));
    }

    /**
     * A failure to queue never breaks the change being reported.
     *
     * The emitters sit after writes that have already happened — a revoked token, an erased
     * account. An exception from the queue would report the change as failed when it was not.
     */
    public function testAFailureToQueueIsSwallowed(): void
    {
        // Arrange — the endpoints table gone
        $this->subscribe($this->appId, 'user_profile_changed');
        $reference = &\Pramnos\Database\Database::getInstance();
        $broken    = clone $this->db;
        $broken->connected = false;
        $reference = $broken;

        try {
            // Act
            $queued = WebhookService::userEvent('user_profile_changed', $this->uid, []);
        } finally {
            $reference = $this->db;
        }

        // Assert
        $this->assertSame(0, $queued);
    }

    // ── Each emitter ──────────────────────────────────────────────────────────

    /**
     * Removing an application from the account tells that application, and only it.
     *
     * The other applications the user has keep their access, so they have nothing to act on.
     */
    public function testRemovingAnApplicationTellsThatApplication(): void
    {
        // Arrange
        $mine   = $this->subscribe($this->appId, 'user_deauthorized');
        $theirs = $this->subscribe($this->otherAppId, 'user_deauthorized');
        $this->issueToken($this->appId);
        $this->issueToken($this->otherAppId);

        // Act
        (new \Pramnos\Auth\AuthorizedApplications($this->db))->revoke($this->uid, $this->appId);

        // Assert
        $this->assertSame([], $this->eventsFor($theirs));
        $this->assertEquals(
            ['event_type' => 'user_deauthorized', 'user_id' => $this->uid, 'application_id' => $this->appId,
             'tokens_revoked' => 1, 'revoked_by' => $this->uid],
            $this->eventsFor($mine)[0]['payload'] ?? null
        );
    }

    /**
     * Revoking a live token tells the application it was issued to; revoking it again does not.
     *
     * The second revocation changes nothing, and an event for it would make the application
     * act twice on one change.
     */
    public function testRevokingATokenTellsItsApplicationOnce(): void
    {
        // Arrange
        $mine    = $this->subscribe($this->appId, 'token_revoked');
        $tokenId = $this->issueToken($this->appId);
        $user    = new User($this->uid);

        // Act
        $user->deleteToken($tokenId);
        $user->deleteToken($tokenId);

        // Assert
        $events = $this->eventsFor($mine);
        $this->assertCount(1, $events, 'an already revoked token was reported again');
        $this->assertEquals(
            ['event_type' => 'token_revoked', 'user_id' => $this->uid, 'token_id' => $tokenId,
             'token_type' => 'access_token', 'application_id' => $this->appId],
            $events[0]['payload']
        );
    }

    /**
     * Erasing an account tells the applications the user had, after the account is gone.
     *
     * The applications are read before the erase deletes the tokens they are found by; the
     * event names the user in the body only, because the column is a foreign key to the row
     * that was just deleted.
     */
    public function testErasingAnAccountTellsItsApplications(): void
    {
        // Arrange
        $mine   = $this->subscribe($this->appId, 'account_deleted');
        $theirs = $this->subscribe($this->otherAppId, 'account_deleted');
        $this->issueToken($this->appId);
        $email = (string) (new User($this->uid))->email;

        // Act
        (new \Pramnos\Auth\AccountErasure($this->db))->erase($this->uid);

        // Assert
        $this->assertSame([], $this->eventsFor($theirs));
        $event = $this->eventsFor($mine)[0] ?? [];
        $this->assertArrayHasKey('user_id', $event, 'no event was queued');
        $this->assertNull($event['user_id'], 'the column points at a deleted row');
        $this->assertSame('account_deleted', $event['payload']['event_type'] ?? null);
        $this->assertSame($this->uid, $event['payload']['user_id'] ?? null);
        $this->assertSame($email, $event['payload']['email'] ?? null);
        $this->assertNotFalse(strtotime((string) ($event['payload']['deleted_at'] ?? '')));
    }

    /**
     * A consent that grows tells its application which scopes were added; one that does not
     * grow says nothing.
     *
     * The first consent is not a change — the application asked for those scopes and has the
     * tokens to show for it.
     */
    public function testAWiderConsentTellsTheApplicationWhatWasAdded(): void
    {
        // Arrange
        $mine   = $this->subscribe($this->appId, 'scope_changed');
        $record = new \ReflectionMethod(\Pramnos\Auth\Controllers\Oauth::class, 'recordConsent');
        $oauth  = (new \ReflectionClass(\Pramnos\Auth\Controllers\Oauth::class))->newInstanceWithoutConstructor();

        // Act
        $record->invoke($oauth, $this->uid, $this->appId, 'read');
        $record->invoke($oauth, $this->uid, $this->appId, 'read write');
        $record->invoke($oauth, $this->uid, $this->appId, 'write');

        // Assert — one event, for the second call only
        $events = $this->eventsFor($mine);
        $this->assertCount(1, $events);
        $this->assertEquals(
            ['event_type' => 'scope_changed', 'user_id' => $this->uid, 'application_id' => $this->appId,
             'scope' => 'read write', 'previous_scope' => 'read', 'scopes_added' => ['write']],
            $events[0]['payload']
        );
    }

    // ── When the queue cannot be used ─────────────────────────────────────────

    /**
     * A queue that cannot be written never breaks the change it reports.
     *
     * The emitters run after the write: the application is already removed, the account
     * already erased. An exception from the queue would report a finished change as failed.
     */
    public function testAQueueThatCannotBeWrittenNeverBreaksTheChange(): void
    {
        // Arrange — subscribed, but the events table is gone
        $this->subscribe($this->appId, 'user_deauthorized');
        $this->subscribe($this->appId, 'account_deleted');
        $this->issueToken($this->appId);
        $this->db->query('DROP TABLE ' . $this->db->schema()->quoteTable('applications.oauth2_webhook_events'));
        \Pramnos\Database\SchemaBuilder::forgetWhatExists($this->db);

        try {
            // Act — the erase while the token still names the application, then the removal
            (new \Pramnos\Auth\AccountErasure($this->db))->erase($this->uid);
            $revoked = (new \Pramnos\Auth\AuthorizedApplications($this->db))->revoke($this->uid, $this->appId);

            // Assert — both went through: the account is gone, and the removal still answered
            $this->assertSame(0, (int) $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->uid)->count());
            $this->assertSame(0, $revoked, 'the erase took the token with it');
        } finally {
            $this->rebuildWebhookTables();
        }
    }

    /**
     * Without the webhook tables nobody is subscribed, and nothing is asked or logged.
     *
     * An installation without the auth server's webhooks still erases accounts; each erase must
     * not try to read a table that is not there.
     */
    public function testWithoutTheWebhookTablesNobodyIsSubscribed(): void
    {
        // Arrange
        foreach (['applications.oauth2_webhook_events', 'applications.oauth2_webhook_endpoints'] as $table) {
            $this->db->query('DROP TABLE ' . $this->db->schema()->quoteTable($table));
        }
        \Pramnos\Database\SchemaBuilder::forgetWhatExists($this->db);

        try {
            // Act & Assert
            $this->assertFalse((new WebhookService($this->db))->isSubscribed('account_deleted'));
            (new \Pramnos\Auth\AccountErasure($this->db))->erase($this->uid);
            $this->assertSame(0, (int) $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->uid)->count());
        } finally {
            $this->rebuildWebhookTables();
        }
    }

    /** The webhook tables back, for tearDown and the next test. */
    private function rebuildWebhookTables(): void
    {
        \Pramnos\Database\SchemaBuilder::forgetWhatExists($this->db);
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateOauth2WebhooksTables::class,
            \Pramnos\Framework\Migrations\AuthServer\AllowPermissionsChangedWebhook::class,
            \Pramnos\Framework\Migrations\AuthServer\AddRegisteredByToOauth2WebhookEndpoints::class,
            \Pramnos\Framework\Migrations\AuthServer\OpenWebhookEventTypes::class,
        ], $this->db);
        \Pramnos\Database\SchemaBuilder::forgetWhatExists($this->db);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    private function registerApplication(string $name): int
    {
        $this->db->queryBuilder()->table('applications')->insert(['name' => $name]);

        return (int) $this->db->queryBuilder()->table('applications')
            ->where('name', $name)->value('appid');
    }

    /** An active endpoint of one application, for one event type. */
    private function subscribe(int $appId, string $type): int
    {
        (new WebhookService($this->db))->saveEndpoint($appId, 'https://example.com/' . $type, $type, '');

        return (int) $this->db->queryBuilder()->table('applications.oauth2_webhook_endpoints')
            ->where('appid', $appId)->where('webhook_type', $type)->value('webhook_id');
    }

    /** A live access token of the user, issued to one application. */
    private function issueToken(int $appId): int
    {
        $token = bin2hex(random_bytes(16));
        $this->db->queryBuilder()->table('#PREFIX#usertokens')->insert([
            'userid' => $this->uid, 'tokentype' => 'access_token', 'token' => $token,
            'token_lookup' => \Pramnos\User\Token::lookup($token), 'created' => time(), 'status' => 1,
            'expires' => time() + 600, 'lastused' => time(), 'notes' => '', 'actions' => 0, 'removedate' => 0,
            'deviceinfo' => '', 'scope' => '', 'applicationid' => $appId,
        ]);

        return (int) $this->db->queryBuilder()->table('#PREFIX#usertokens')
            ->where('token_lookup', \Pramnos\User\Token::lookup($token))->value('tokenid');
    }

    /**
     * The events queued for one endpoint, payload decoded.
     *
     * @return list<array<string, mixed>>
     */
    private function eventsFor(int $webhookId): array
    {
        $rows   = [];
        $result = $this->db->queryBuilder()->table('applications.oauth2_webhook_events')
            ->where('webhook_id', $webhookId)->orderBy('event_id')->get();
        while ($result && $result->fetch()) {
            $row            = (array) $result->fields;
            $row['payload'] = json_decode((string) $row['payload'], true);
            $row['user_id'] = $row['user_id'] === null ? null : (int) $row['user_id'];
            $rows[]         = $row;
        }

        return $rows;
    }
}
