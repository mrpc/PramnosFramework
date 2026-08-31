<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application as FrameworkApplication;
use Pramnos\Application\Settings;
use Pramnos\Auth\Application;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Integration tests for OAuth2 client authentication — {@see Application::validateCredentials()}
 * against a real database.
 *
 * ## Why this exists
 *
 * The method used to append the `apisecret` condition to its query only when a
 * secret was supplied:
 *
 * ```php
 * if ($clientSecret !== null) { $qb->where('apisecret', $clientSecret); }
 * return $qb->count() > 0;
 * ```
 *
 * A request that omitted `client_secret` therefore matched on `apikey` + `status`
 * alone, and every active application authenticated with no secret at all. That was
 * reachable through the front door: `league/oauth2-server` 8.5 resolves an absent
 * `client_secret` to `null`, and `AbstractGrant::validateClient()` passes it to this
 * method without examining it — so `POST /oauth/token` carrying nothing but a
 * `client_id` (a public identifier, shipped inside every SPA and mobile app) was
 * issued a token.
 *
 * These tests pin the contract in both directions, because the failing case and the
 * working case are one `if` apart: a registered secret must be presented and must
 * match, and a client that has no secret registered is unaffected.
 *
 * Runs against whichever database the fixture settings point at; the QueryBuilder
 * resolves the dialect, so the assertions hold on MySQL and PostgreSQL alike.
 */
#[CoversClass(Application::class)]
class OAuth2ClientSecretRequiredTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;

    /** apikeys inserted by this test — removed in tearDown. */
    private array $apikeys = [];

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        FrameworkApplication::getInstance();

        // Drop the singleton before taking it: tests that inject a mock database
        // restore a *clone* of the original, which reports itself as connected while
        // holding no live handle, and the next connect() attempt fails on the socket.
        // Nulling the reference forces a real instance. Same reason AccountExportTest
        // does this in its own setUp.
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = null;

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Built from the canonical migration rather than hand-rolled DDL, so the
        // columns under test are the ones a real installation has — `apisecret`
        // nullable among them, which the hand-rolled copies other tests leave
        // behind declare NOT NULL. The migration is a no-op when the table already
        // exists, so the stale shape has to go first; AccountExportTest rebuilds
        // this same table in its own setUp for the same reason.
        $this->db->schema()->dropTableIfExists('applications');
        $this->runMigrations(
            [\Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class],
            $this->db
        );

        $this->apikeys = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->apikeys as $apikey) {
            try {
                $this->db->queryBuilder()
                    ->table('applications')
                    ->where('apikey', $apikey)
                    ->delete();
            } catch (\Throwable) {
                // A row that is already gone is not a failure of this test.
            }
        }
        $this->apikeys = [];
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * Insert one application row and return its client_id.
     *
     * @param string|null $secret Value for `apisecret`; null stores SQL NULL,
     *                            which is what a client registered without a
     *                            secret looks like.
     * @param int         $status 1 = active, 0 = disabled.
     */
    private function seedApplication(?string $secret, int $status = 1): string
    {
        $apikey = 'test-client-' . bin2hex(random_bytes(8));

        $this->db->queryBuilder()->table('applications')->insert([
            'name'      => 'Client secret regression fixture',
            'apikey'    => $apikey,
            'apisecret' => $secret,
            'status'    => $status,
        ]);

        $this->apikeys[] = $apikey;

        return $apikey;
    }

    /**
     * The model without its constructor: validateCredentials() reads no instance
     * state, so a real Controller and the DB round-trip it needs are avoidable.
     */
    private function model(): Application
    {
        return (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
    }

    // ── The regression ────────────────────────────────────────────────────────

    /**
     * THE regression: a client that has a secret registered must not authenticate
     * when the secret is absent.
     *
     * This is the exact shape of the bypass — the league server hands `null` here
     * whenever `client_secret` is missing from the request — so it is asserted on
     * its own rather than folded into a data provider.
     */
    public function testClientWithARegisteredSecretIsRejectedWhenNoSecretIsPresented(): void
    {
        // Arrange
        $apikey = $this->seedApplication('s3cr3t-value');

        // Act
        $result = $this->model()->validateCredentials($apikey, null);

        // Assert — before the fix this returned true, and a token followed.
        $this->assertFalse(
            $result,
            'An application with a registered secret authenticated without presenting it.'
        );
    }

    /**
     * The empty string is the other way a secret goes missing: an HTML form or a
     * client library that always sends the parameter sends `client_secret=`.
     */
    public function testClientWithARegisteredSecretIsRejectedWhenAnEmptySecretIsPresented(): void
    {
        // Arrange
        $apikey = $this->seedApplication('s3cr3t-value');

        // Act + Assert
        $this->assertFalse($this->model()->validateCredentials($apikey, ''));
    }

    /**
     * The path that must keep working: correct client_id + correct secret.
     *
     * Without this the fix could be "return false always" and the regression test
     * above would still pass.
     */
    public function testCorrectSecretAuthenticates(): void
    {
        // Arrange
        $apikey = $this->seedApplication('s3cr3t-value');

        // Act + Assert
        $this->assertTrue($this->model()->validateCredentials($apikey, 's3cr3t-value'));
    }

    /**
     * A wrong secret is refused — including one that is a prefix of the real value,
     * which a comparison that stopped at the first mismatch of length would accept.
     */
    public function testWrongSecretIsRejected(): void
    {
        // Arrange
        $apikey = $this->seedApplication('s3cr3t-value');
        $model  = $this->model();

        // Act + Assert
        $this->assertFalse($model->validateCredentials($apikey, 'wrong'));
        $this->assertFalse($model->validateCredentials($apikey, 's3cr3t'));
        $this->assertFalse($model->validateCredentials($apikey, 's3cr3t-value-extra'));
    }

    // ── Clients with no secret registered ─────────────────────────────────────

    /**
     * A client whose `apisecret` is NULL has nothing to present, so presenting
     * nothing authenticates. This is the behaviour the fix deliberately preserved:
     * narrowing it would have locked out any installation that registered a client
     * without a secret.
     */
    public function testClientWithNoRegisteredSecretAuthenticatesWithNone(): void
    {
        // Arrange
        $apikey = $this->seedApplication(null);

        // Act + Assert
        $this->assertTrue($this->model()->validateCredentials($apikey, null));
    }

    /**
     * The same for an empty-string secret, which is what the column holds on an
     * installation whose `apisecret` is `NOT NULL DEFAULT ''`.
     */
    public function testClientWithEmptyRegisteredSecretAuthenticatesWithNone(): void
    {
        // Arrange
        $apikey = $this->seedApplication('');

        // Act + Assert
        $this->assertTrue($this->model()->validateCredentials($apikey, ''));
        $this->assertTrue($this->model()->validateCredentials($apikey, null));
    }

    /**
     * A client with no registered secret must not accept an arbitrary one.
     *
     * The pre-fix code got this right by accident — `WHERE apisecret = 'anything'`
     * simply matched no row — and the fix has to keep it, or it would be looser
     * than the version it replaced.
     */
    public function testClientWithNoRegisteredSecretRejectsAnArbitrarySecret(): void
    {
        // Arrange
        $apikey = $this->seedApplication(null);

        // Act + Assert
        $this->assertFalse($this->model()->validateCredentials($apikey, 'anything'));
    }

    // ── Status and identity ───────────────────────────────────────────────────

    /**
     * A disabled application does not authenticate even with the right secret —
     * `status = 1` is part of the lookup and must not have been lost in the rewrite.
     */
    public function testDisabledApplicationIsRejectedWithTheCorrectSecret(): void
    {
        // Arrange
        $apikey = $this->seedApplication('s3cr3t-value', 0);

        // Act + Assert
        $this->assertFalse($this->model()->validateCredentials($apikey, 's3cr3t-value'));
    }

    /**
     * An unknown client_id is refused rather than treated as a client with no
     * secret registered — the "nothing registered, nothing to present" branch must
     * be reached only by a row that actually exists.
     */
    public function testUnknownClientIdIsRejected(): void
    {
        // Arrange
        $model = $this->model();

        // Act + Assert
        $this->assertFalse($model->validateCredentials('no-such-client-id', null));
        $this->assertFalse($model->validateCredentials('no-such-client-id', 'anything'));
    }

    /**
     * A secret that belongs to a different client does not authenticate this one —
     * the secret is matched against the row the client_id selected, not against the
     * table.
     */
    public function testSecretFromAnotherClientIsRejected(): void
    {
        // Arrange
        $this->seedApplication('secret-of-client-a');
        $apikeyB = $this->seedApplication('secret-of-client-b');

        // Act + Assert
        $this->assertFalse($this->model()->validateCredentials($apikeyB, 'secret-of-client-a'));
    }
}
