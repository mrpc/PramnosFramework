<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Settings;
use Pramnos\Auth\EmailVerification;
use Pramnos\Auth\InvitationException;
use Pramnos\Auth\Invitations;
use Pramnos\Auth\RegistrationPolicy;
use Pramnos\Auth\Role;
use Pramnos\Event\Event;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\Token;

/**
 * Invitation-only registration and address confirmation, against the real tables.
 *
 * The guarantees are all about a link: that the stored copy cannot be used, that it works once
 * even when two registrations race, that it works for one address, and that it stops working.
 * Each is a property of the rows and the conditional update that writes them, which is why this
 * runs against a database rather than a stub.
 */
#[CoversClass(Invitations::class)]
#[CoversClass(EmailVerification::class)]
#[CoversClass(RegistrationPolicy::class)]
class InvitationsTest extends BaseTestCase
{
    protected \Pramnos\Database\Database $db;

    private const ADMIN   = 4601;
    private const NEWBIE  = 4602;
    private const ORG     = 4610;
    private const ROLE    = 4620;
    private const EMAIL   = 'invitee.test@example.com';

    /** @var array<string, mixed> */
    private array $savedSettings = [];

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        \Pramnos\User\User::setupDb();
        // Built from their own migrations, as the role and resolver suites do: other suites
        // create these tables with other columns, and `CREATE TABLE IF NOT EXISTS` keeps
        // whichever came first — one without `userid` was found here.
        foreach (['authserver.user_roles', 'authserver.roles', 'authserver.user_organizations'] as $table) {
            $this->db->schema()->dropTableIfExists($table);
        }
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateOrganizationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverRolesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserRolesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserOrganizationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverInvitationsTable::class,
        ], $this->db);

        foreach ([Invitations::TTL_SETTING, RegistrationPolicy::DOMAINS_SETTING, RegistrationPolicy::VERIFY_SETTING,
                  'auth_allow_registration', \Pramnos\Auth\AdminAccess::MODE_SETTING,
                  \Pramnos\Auth\AdminAccess::SUPERUSER_SETTING] as $name) {
            $this->savedSettings[$name] = Settings::getSetting($name, null);
        }

        $this->clearRows();
        $qb = fn () => $this->db->queryBuilder();
        $qb()->table('#PREFIX#users')->insert(['usertype' => 0, 'sex' => 0, 'birthdate' => 0, 'modified' => time(), 'userid' => self::ADMIN, 'username' => 'inv_admin', 'email' => 'inv_admin@example.com']);
        $qb()->table('organizations')->insert(['organization_id' => self::ORG, 'name' => 'Invitation org']);
    }

    protected function tearDown(): void
    {
        $this->clearRows();
        foreach ($this->savedSettings as $name => $value) {
            Settings::setSetting($name, $value === null ? '' : (string) $value, false);
        }
        Event::forget(Invitations::EVENT_ACCEPTED);

        parent::tearDown();
    }

    // ── Invitations ─────────────────────────────────────────────────────────────

    /**
     * The row holds the token's lookup hash, never the token, and the link carries the token.
     *
     * A copy of the table must not be a way to register as anybody with a pending invitation.
     */
    public function testTheTokenIsStoredOnlyAsItsHash(): void
    {
        // Act
        $made = $this->service()->invite(' Invitee.Test@Example.com ', ['invitedBy' => self::ADMIN, 'send' => false]);

        // Assert
        $row = $this->row($made['invitation_id']);
        $this->assertSame(self::EMAIL, $row['email'], 'the address is stored lowercased and trimmed');
        $this->assertSame(Token::lookup($made['token']), $row['token_lookup']);
        $this->assertStringNotContainsString($made['token'], json_encode($row));
        $this->assertStringEndsWith('register?invite=' . $made['token'], $made['link']);
        $this->assertFalse($made['sent']);
    }

    /** An invitation lasts `auth_invitation_ttl_hours`, 48 unless set. */
    public function testTheLinkLastsTheConfiguredHours(): void
    {
        // Arrange
        Settings::setSetting(Invitations::TTL_SETTING, '', false);
        $default = Invitations::ttlSeconds();
        Settings::setSetting(Invitations::TTL_SETTING, '2', false);

        // Act
        $made = $this->service()->invite(self::EMAIL, ['send' => false]);

        // Assert
        $this->assertSame(48 * 3600, $default);
        $row = $this->row($made['invitation_id']);
        $this->assertSame(7200, (int) $row['expires_at'] - (int) $row['created_at']);
    }

    /** Nobody is invited twice: a new invitation withdraws the one still waiting. */
    public function testANewInvitationWithdrawsTheOldOne(): void
    {
        // Arrange
        $first = $this->service()->invite(self::EMAIL, ['send' => false]);

        // Act
        $second = $this->service()->invite(self::EMAIL, ['send' => false]);

        // Assert
        $this->assertNull($this->service()->find($first['token']), 'the first link must stop working');
        $this->assertNotNull($this->service()->find($second['token']));
        $this->assertSame('revoked', Invitations::stateOf($this->row($first['invitation_id'])));
    }

    /** An address that already has an account is not invited, and nor is something that is not one. */
    public function testAnExistingAccountAndABadAddressAreRefused(): void
    {
        // Act & Assert
        foreach (['INV_ADMIN@example.com' => 'account_exists', 'not an address' => 'invalid_email'] as $email => $reason) {
            try {
                $this->service()->invite($email, ['send' => false]);
                $this->fail("{$email} must be refused");
            } catch (InvitationException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }

    /** A role must exist, and an organisation's role goes only with an invitation into it. */
    public function testARoleMustFitTheOrganisation(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('authserver.roles')->insert([
            'roleid' => self::ROLE, 'role_name' => 'Org role', 'description' => '',
            Role::organizationColumn() => self::ORG, 'is_active' => 1,
        ]);

        // Act & Assert
        foreach ([[self::ROLE, null, 'role_outside_organization'], [99999, null, 'unknown_role']] as [$role, $org, $reason]) {
            try {
                $this->service()->invite(self::EMAIL, ['roleId' => $role, 'organizationId' => $org, 'send' => false]);
                $this->fail("role {$role} must be refused");
            } catch (InvitationException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        $this->assertNotNull($this->service()->invite(self::EMAIL, [
            'roleId' => self::ROLE, 'organizationId' => self::ORG, 'send' => false,
        ])['invitation_id']);
    }

    /**
     * Accepting joins the organisation, gives the role, and tells listeners — with the row, so an
     * application can apply what it attached as metadata.
     */
    public function testAcceptingJoinsGivesTheRoleAndFiresTheEvent(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('authserver.roles')->insert([
            'roleid' => self::ROLE, 'role_name' => 'Org role', 'description' => '',
            Role::organizationColumn() => self::ORG, 'is_active' => 1,
        ]);
        $made = $this->service()->invite(self::EMAIL, [
            'invitedBy' => self::ADMIN, 'organizationId' => self::ORG, 'roleId' => self::ROLE,
            'metadata' => ['plan' => 'gold'], 'send' => false,
        ]);
        $this->newAccount();
        $heard = [];
        Event::listen(Invitations::EVENT_ACCEPTED, function (array $row, int $userId) use (&$heard): void {
            $heard[] = [$row['metadata'], $userId];
        });

        // Act
        $row = $this->service()->accept($made['token'], self::NEWBIE);

        // Assert
        $this->assertNotNull($row);
        $this->assertSame(self::NEWBIE, (int) $row['accepted_userid']);
        $this->assertTrue($this->db->queryBuilder()->table(Role::membershipTable())
            ->where('userid', self::NEWBIE)->where(Role::organizationColumn(), self::ORG)->exists());
        $this->assertTrue($this->db->queryBuilder()->table('authserver.user_roles')
            ->where('userid', self::NEWBIE)->where('roleid', self::ROLE)->exists());
        $this->assertSame([['{"plan":"gold"}', self::NEWBIE]], $heard);
    }

    /**
     * A link works once. The second registration with it gets nothing — the update that claims the
     * row is conditioned on it being unused, so this holds even when both passed find() first.
     */
    public function testALinkWorksOnce(): void
    {
        // Arrange
        $made = $this->service()->invite(self::EMAIL, ['send' => false]);
        $this->newAccount();

        // Act
        $first  = $this->service()->accept($made['token'], self::NEWBIE);
        $second = $this->service()->accept($made['token'], self::NEWBIE);

        // Assert
        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertNull($this->service()->find($made['token']));
    }

    /**
     * The race itself: a registration that found the invitation live, then lost the row to
     * another before claiming it, gets nothing.
     */
    public function testALostRaceClaimsNothing(): void
    {
        // Arrange — find() still answers live, but the row was claimed in between
        $made = $this->service()->invite(self::EMAIL, ['send' => false]);
        $late = new class ($this->db) extends Invitations {
            public bool $claimedElsewhere = false;

            public function find(string $token): ?array
            {
                $row = parent::find($token);
                if ($row !== null && !$this->claimedElsewhere) {
                    $this->claimedElsewhere = true;
                    \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('authserver.invitations')
                        ->where('invitation_id', $row['invitation_id'])->update(['accepted_at' => time(), 'accepted_userid' => 1]);
                }

                return $row;
            }
        };

        // Act
        $result = $late->accept($made['token'], self::NEWBIE);

        // Assert
        $this->assertNull($result);
    }

    /** An expired, a withdrawn and a malformed link find nothing. */
    public function testDeadLinksFindNothing(): void
    {
        // Arrange
        $expired = $this->service()->invite(self::EMAIL, ['send' => false]);
        $this->db->queryBuilder()->table('authserver.invitations')
            ->where('invitation_id', $expired['invitation_id'])->update(['expires_at' => time() - 1]);
        $other = $this->service()->invite('other.test@example.com', ['send' => false]);

        // Act
        $withdrawn = $this->service()->revoke($other['invitation_id']);

        // Assert
        $this->assertNull($this->service()->find($expired['token']));
        $this->assertTrue($withdrawn);
        $this->assertFalse($this->service()->revoke($other['invitation_id']), 'withdrawing twice changes nothing');
        $this->assertNull($this->service()->find($other['token']));
        $this->assertNull($this->service()->find('not-hex'));
        $this->assertNull($this->service()->find(''));
        $this->assertNull($this->service()->accept($expired['token'], self::NEWBIE));
    }

    /** Resending makes a new link and kills the old one; a used invitation cannot be resent. */
    public function testResendReplacesTheLink(): void
    {
        // Arrange
        $made = $this->service()->invite(self::EMAIL, ['send' => false]);

        // Act
        $again = $this->service()->resend($made['invitation_id']);

        // Assert
        $this->assertNotNull($again);
        $this->assertNull($this->service()->find($made['token']));
        $this->assertNotNull($this->service()->find($again['token']));
        $this->newAccount();
        $this->service()->accept($again['token'], self::NEWBIE);
        $this->assertNull($this->service()->resend($made['invitation_id']));
        $this->assertNull($this->service()->resend(99999999));
    }

    /** The list names every invitation's state and never carries the hash. */
    public function testTheListShowsStatesWithoutHashes(): void
    {
        // Arrange
        $this->service()->invite(self::EMAIL, ['organizationId' => self::ORG, 'send' => false]);
        $this->service()->invite('other.test@example.com', ['send' => false]);

        // Act
        $all  = array_filter($this->service()->all(), fn ($r) => str_ends_with((string) $r['email'], '.test@example.com'));
        $org  = $this->service()->all(self::ORG);

        // Assert
        $this->assertCount(2, $all);
        $this->assertSame(['waiting', 'waiting'], array_values(array_column($all, 'state')));
        $this->assertArrayNotHasKey('token_lookup', array_values($all)[0]);
        $this->assertSame([self::EMAIL], array_column($org, 'email'));
    }

    /** Sending goes through the notifier; a failure to queue is reported, not thrown. */
    public function testSendingReportsWhatHappened(): void
    {
        // Act
        $made = $this->service()->invite(self::EMAIL, ['invitedBy' => self::ADMIN, 'note' => 'Finance']);

        // Assert
        $this->assertIsBool($made['sent']);
        $this->assertSame('Finance', $this->row($made['invitation_id'])['note']);
    }

    // ── Registration policy ─────────────────────────────────────────────────────

    /**
     * The domain list matches whole domains, case-insensitively, whatever separates them — and a
     * list turns address confirmation on whether or not it was asked for.
     */
    public function testTheDomainListMatchesWholeDomains(): void
    {
        // Arrange
        Settings::setSetting(RegistrationPolicy::DOMAINS_SETTING, 'Example.com; @example.org,  ', false);
        Settings::setSetting(RegistrationPolicy::VERIFY_SETTING, '', false);

        // Act & Assert
        $this->assertSame(['example.com', 'example.org'], RegistrationPolicy::domains());
        $this->assertTrue(RegistrationPolicy::allowsEmail('maria@EXAMPLE.com'));
        $this->assertTrue(RegistrationPolicy::allowsEmail('maria@example.org'));
        $this->assertFalse(RegistrationPolicy::allowsEmail('maria@evil-example.com'), 'a suffix is not the domain');
        $this->assertFalse(RegistrationPolicy::allowsEmail('maria@sub.example.com'));
        $this->assertFalse(RegistrationPolicy::allowsEmail('no-at-sign'));
        $this->assertTrue(RegistrationPolicy::requiresEmailVerification());

        Settings::setSetting(RegistrationPolicy::DOMAINS_SETTING, '', false);
        $this->assertTrue(RegistrationPolicy::allowsEmail('anyone@anywhere.test'));
        $this->assertFalse(RegistrationPolicy::requiresEmailVerification());
        Settings::setSetting(RegistrationPolicy::VERIFY_SETTING, 'yes', false);
        $this->assertTrue(RegistrationPolicy::requiresEmailVerification());
        Settings::setSetting('auth_allow_registration', 'on', false);
        $this->assertTrue(RegistrationPolicy::isOpen());
    }

    // ── Address confirmation ────────────────────────────────────────────────────

    /**
     * begin() holds the account back and confirm() lets it go — once, and only while the link is
     * fresh. `validated` follows, which is what the ID token's email_verified is read from.
     */
    public function testConfirmationHoldsTheAccountUntilTheLinkIsFollowed(): void
    {
        // Arrange
        $this->newAccount();
        $verification = new class ($this->db) extends EmailVerification {
            public string $lastToken = '';

            protected function issue(int $userId): bool
            {
                $this->lastToken = bin2hex(random_bytes(32));
                $this->store($userId, self::FIELD_HASH, \Pramnos\User\Token::lookup($this->lastToken));
                $this->store($userId, self::FIELD_EXPIRES, (string) (time() + self::ttlSeconds()));

                return true;
            }
        };

        // Act
        $verification->begin(self::NEWBIE);
        $pending   = $verification->isPending(self::NEWBIE);
        $validated = (int) $this->userField('validated');
        $confirmed = $verification->confirm($verification->lastToken);

        // Assert
        $this->assertTrue($pending);
        $this->assertSame(0, $validated);
        $this->assertSame(self::NEWBIE, $confirmed);
        $this->assertFalse($verification->isPending(self::NEWBIE));
        $this->assertSame(1, (int) $this->userField('validated'));
        $this->assertNull($verification->confirm($verification->lastToken), 'a link works once');
        $this->assertNull($verification->confirm('not-hex'));
    }

    /** An expired confirmation link confirms nothing, and a fresh one goes out at most every ten minutes. */
    public function testAnExpiredLinkConfirmsNothingAndResendingIsThrottled(): void
    {
        // Arrange
        $this->newAccount();
        $verification = new class ($this->db) extends EmailVerification {
            public int $issued = 0;
            public string $lastToken = '';

            protected function issue(int $userId): bool
            {
                $this->issued++;
                $this->lastToken = bin2hex(random_bytes(32));
                $this->store($userId, self::FIELD_HASH, \Pramnos\User\Token::lookup($this->lastToken));
                $this->store($userId, self::FIELD_EXPIRES, (string) (time() + self::ttlSeconds()));

                return true;
            }

            public function age(int $userId, int $seconds): void
            {
                $this->store($userId, self::FIELD_EXPIRES, (string) (time() + self::ttlSeconds() - $seconds));
            }
        };
        $verification->begin(self::NEWBIE);

        // Act
        $tooSoon = $verification->resendIfDue(self::NEWBIE);
        $verification->age(self::NEWBIE, EmailVerification::RESEND_AFTER + 1);
        $due = $verification->resendIfDue(self::NEWBIE);
        $verification->age(self::NEWBIE, EmailVerification::ttlSeconds() + 1);

        // Assert
        $this->assertFalse($tooSoon);
        $this->assertTrue($due);
        $this->assertSame(2, $verification->issued);
        $this->assertNull($verification->confirm($verification->lastToken), 'an expired link must not confirm');
        $this->assertFalse($verification->resendIfDue(self::ADMIN), 'an account that is not waiting gets nothing');
        $this->assertStringContainsString('register/confirmemail?token=', $verification->linkFor('ab'));
    }

    /** The real issue() stores a working link and reports whether the mail went out. */
    public function testTheRealIssueStoresAWorkingLink(): void
    {
        // Arrange
        $this->newAccount();
        $verification = new EmailVerification($this->db);

        // Act
        $sent = $verification->begin(self::NEWBIE);

        // Assert
        $this->assertIsBool($sent);
        $this->assertTrue($verification->isPending(self::NEWBIE));
        $this->assertFalse((new EmailVerification($this->db))->begin(1), 'the system account is never held back');
    }

    // ── The screen ──────────────────────────────────────────────────────────────

    /** The screen invites, shows the link once, and refuses a stale form. */
    public function testTheScreenInvitesOnAValidForm(): void
    {
        // Arrange
        $this->signIn(self::ADMIN, 98);
        $screen = $this->screen();

        // Act
        $this->postTo($screen, 'invite', ['email' => self::EMAIL], 'stale');
        $afterStale = $this->service()->all();
        $this->postTo($screen, 'invite', ['email' => self::EMAIL, 'note' => 'Finance']);

        // Assert
        $this->assertSame([], array_filter($afterStale, fn ($r) => $r['email'] === self::EMAIL));
        $rows = array_values(array_filter($this->service()->all(), fn ($r) => $r['email'] === self::EMAIL));
        $this->assertCount(1, $rows);
        $this->assertSame((string) self::ADMIN, (string) $rows[0]['invited_by']);
        $this->assertStringContainsString('register?invite=', $_SESSION['invitations_fresh_link']['link'] ?? '');
    }

    /** A role is attached only by somebody who may assign roles, and a refused address is reported. */
    public function testTheScreenGuardsRolesAndReportsRefusals(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('authserver.roles')->insert([
            'roleid' => self::ROLE, 'role_name' => 'Plain role', 'description' => '', 'is_active' => 1,
        ]);
        Settings::setSetting(\Pramnos\Auth\AdminAccess::MODE_SETTING, 'permissions', false);
        Settings::setSetting(\Pramnos\Auth\AdminAccess::SUPERUSER_SETTING, '98', false);
        $this->signIn(self::ADMIN, 90);
        $screen = new class (\Pramnos\Application\Application::getInstance()) extends \Pramnos\Auth\Controllers\InvitationsController {
            protected function requireMinUserType(int $minType): bool
            {
                return false;
            }
        };

        try {
            // Act — Roles is not hers, so the role is refused; then an existing account
            $this->postTo($screen, 'invite', ['email' => self::EMAIL, 'role_id' => (string) self::ROLE]);
            $withRole = $this->service()->all();
            $this->postTo($screen, 'invite', ['email' => 'inv_admin@example.com']);
            $existing = $this->service()->all();
        } finally {
            Settings::setSetting(\Pramnos\Auth\AdminAccess::MODE_SETTING, 'usertype', false);
        }

        // Assert
        $this->assertSame([], array_filter($withRole, fn ($r) => $r['email'] === self::EMAIL));
        $this->assertSame([], array_filter($existing, fn ($r) => $r['email'] === 'inv_admin@example.com'));
    }

    /** Resend and withdraw work from the list, and a GET changes nothing. */
    public function testTheScreenResendsAndWithdraws(): void
    {
        // Arrange
        $this->signIn(self::ADMIN, 98);
        $screen = $this->screen();
        $made = $this->service()->invite(self::EMAIL, ['send' => false]);

        // Act
        $this->postTo($screen, 'resend', ['invitation_id' => (string) $made['invitation_id']], null, 'GET');
        $afterGet = $this->service()->find($made['token']);
        $this->postTo($screen, 'resend', ['invitation_id' => (string) $made['invitation_id']]);
        $afterResend = $this->service()->find($made['token']);
        $this->postTo($screen, 'revoke', ['invitation_id' => (string) $made['invitation_id']]);
        $this->postTo($screen, 'revoke', ['invitation_id' => (string) $made['invitation_id']]);
        $this->postTo($screen, 'resend', ['invitation_id' => '99999999']);

        // Assert
        $this->assertNotNull($afterGet, 'a GET must not resend');
        $this->assertNull($afterResend, 'resending replaces the link');
        $this->assertSame('revoked', Invitations::stateOf($this->row($made['invitation_id'])));
    }

    /** The list screen renders its data: the invitations, the organisations, the fresh link once. */
    public function testTheScreenListsInvitations(): void
    {
        // Arrange
        $this->signIn(self::ADMIN, 98);
        $this->service()->invite(self::EMAIL, ['organizationId' => self::ORG, 'send' => false]);
        $this->db->queryBuilder()->table('authserver.roles')->insert([
            'roleid' => self::ROLE, 'role_name' => 'Listed role', 'description' => '', 'is_active' => 1,
        ]);
        $_SESSION['invitations_fresh_link'] = ['email' => self::EMAIL, 'link' => 'https://x/register?invite=ab', 'sent' => true];
        $screen = new class (\Pramnos\Application\Application::getInstance()) extends \Pramnos\Auth\Controllers\InvitationsController {
            public object $captured;

            public function &getView($name = '', $type = '', $args = array())
            {
                $this->captured = new class {
                    public array $props = [];

                    public function __set(string $n, mixed $v): void
                    {
                        $this->props[$n] = $v;
                    }

                    public function display(?string $t = null): string
                    {
                        return 'VIEW:' . $t;
                    }
                };

                return $this->captured;
            }
        };

        // Act
        $out = $screen->display();

        // Assert
        $this->assertSame('VIEW:invitations', $out);
        $props = $screen->captured->props;
        $this->assertSame('Invitation org', $props['organizations'][self::ORG] ?? null);
        $this->assertSame('https://x/register?invite=ab', $props['freshLink']['link']);
        $this->assertArrayNotHasKey('invitations_fresh_link', $_SESSION, 'the link is shown once');
        $this->assertNotEmpty(array_filter($props['invitations'], fn ($r) => $r['email'] === self::EMAIL));
        $this->assertSame(48, $props['ttlHours']);
        $this->assertSame('Listed role', $props['roles'][self::ROLE] ?? null, 'a superuser may give roles');
    }

    // ── Fixture ─────────────────────────────────────────────────────────────────

    // ── What glideday found moving onto invitations ─────────────────────────────

    /**
     * Erasing an account removes what it left in the invitations table.
     *
     * The invitations it sent that nobody accepted hold other people's addresses, attributed to
     * a user who no longer exists: deleted. One that was accepted belongs to the account it
     * created: kept, with `invited_by` cleared. The invitation the erased account itself came
     * from carries its own address: deleted. Invitations nobody here sent are untouched.
     */
    public function testErasingAnAccountForgetsItsInvitations(): void
    {
        // Arrange — ADMIN sent one still waiting and one NEWBIE accepted; ADMIN came from a third
        $service = $this->service();
        $waiting = $service->invite('someone.else@example.com', ['invitedBy' => self::ADMIN, 'send' => false]);
        $insert  = fn (array $row) => $this->db->queryBuilder()->table('authserver.invitations')->insert($row + [
            'token_lookup' => bin2hex(random_bytes(16)), 'created_at' => time(), 'expires_at' => time() + 3600,
        ]);
        $insert(['email' => self::EMAIL, 'invited_by' => self::ADMIN, 'accepted_userid' => self::NEWBIE, 'accepted_at' => time()]);
        $insert(['email' => 'inv_admin@example.com', 'accepted_userid' => self::ADMIN, 'accepted_at' => time()]);
        $insert(['email' => 'unrelated@example.com']);

        // Act
        $service->forgetUser(self::ADMIN);

        // Assert
        $rows   = $this->db->queryBuilder()->table('authserver.invitations')->get()->fetchAll();
        $emails = array_column($rows, 'email');
        sort($emails);
        $this->assertSame([self::EMAIL, 'unrelated@example.com'], $emails, 'waiting and own-origin rows are gone');
        foreach ($rows as $row) {
            $this->assertNull($row['invited_by'], 'nothing is attributed to the erased account');
        }
        $this->assertNotEmpty($waiting['link']);
    }

    /**
     * An application attaches `metadata` from its own form fields, through one seam.
     *
     * The screen built its options inline, so nothing could reach `metadata` from the
     * framework's own screen. What the seam returns is merged **under** the screen's options:
     * it cannot replace who invited, which the screen decided and checked.
     */
    public function testTheScreenTakesWhatAnApplicationAttaches(): void
    {
        // Arrange
        $this->signIn(self::ADMIN, 98);
        $screen = new class (\Pramnos\Application\Application::getInstance()) extends \Pramnos\Auth\Controllers\InvitationsController {
            protected function inviteOptions(): array
            {
                return ['metadata' => ['capabilities' => ['publish']], 'invitedBy' => 1];
            }
        };

        // Act
        $this->postTo($screen, 'invite', ['email' => self::EMAIL]);

        // Assert
        $rows = array_values(array_filter($this->service()->all(), fn ($r) => $r['email'] === self::EMAIL));
        $this->assertCount(1, $rows);
        $stored = $this->db->queryBuilder()->table('authserver.invitations')->where('email', self::EMAIL)->first()->fields;
        $this->assertSame(['capabilities' => ['publish']], json_decode((string) $stored['metadata'], true));
        $this->assertSame((string) self::ADMIN, (string) $stored['invited_by'], 'the seam cannot change who invited');
    }

    /**
     * The mail says days when the link lasts whole days, and takes a preheader of its own.
     *
     * A 21-day link read "504 hours". `{days}` is offered to a stored template beside
     * `{hours}`, and the built-in text uses it. The preheader is what glideday had chosen and
     * lost: without one the line is derived from the body's first sentence.
     */
    public function testTheMailSaysDaysAndCarriesAPreheader(): void
    {
        // Arrange
        $three  = new \Pramnos\Auth\Notifications\InvitationNotification('https://x.test/l', 21 * 86400);
        $partly = new \Pramnos\Auth\Notifications\InvitationNotification('https://x.test/l', 36 * 3600);

        // Act
        $withLine = $three->withPreheader('  Your link is inside.  ');

        // Assert
        $this->assertSame('21', (string) $three->storedMailTemplate()['vars']['days']);
        $this->assertSame(504, $three->storedMailTemplate()['vars']['hours']);
        $this->assertStringContainsString('21 days', $three->toMail((object) ['email' => 'a@b.c'])['body']);
        $this->assertSame('1.5', (string) $partly->storedMailTemplate()['vars']['days']);
        $this->assertStringContainsString('36 hours', $partly->toMail((object) ['email' => 'a@b.c'])['body']);
        $this->assertSame('', $three->mailPreheader(), 'withPreheader() returns a copy');
        $this->assertSame('Your link is inside.', $withLine->mailPreheader());
    }

    /**
     * The service builds its mail through a seam an application can override.
     */
    public function testTheMailIsBuiltThroughAnOverridableSeam(): void
    {
        // Arrange
        $service = new class ($this->db) extends Invitations {
            public function built(string $link, array $row): \Pramnos\Auth\Notifications\InvitationNotification
            {
                return $this->notification($link, $row);
            }
        };

        // Act
        $mail = $service->built('https://x.test/l', ['invited_by' => self::ADMIN, 'note' => 'Finance']);

        // Assert — the inviter's name and the note reach the mail
        $vars = $mail->storedMailTemplate()['vars'];
        $this->assertSame('inv_admin', $vars['inviter']);
        $this->assertSame('Finance', $vars['note']);
    }

    private function signIn(int $userId, int $usertype): void
    {
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => $userId, 'usertype' => $usertype], 'test');
        $_SESSION['uid'] = $userId;
    }

    private function screen(): \Pramnos\Auth\Controllers\InvitationsController
    {
        return new \Pramnos\Auth\Controllers\InvitationsController(\Pramnos\Application\Application::getInstance());
    }

    /** @param array<string, string> $fields */
    private function postTo(object $screen, string $action, array $fields, ?string $token = null, string $method = 'POST'): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = ['_csrf_token' => $token ?? \Pramnos\Http\Session::getInstance()->getCsrfToken()] + $fields;
        ob_start();
        try {
            $screen->$action();
        } catch (\Pramnos\Application\ApplicationClosedException) {
            // The screen redirects back to the list after every action.
        } finally {
            ob_end_clean();
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
    }

    private function service(): Invitations
    {
        return new Invitations($this->db);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return $this->db->queryBuilder()->table('authserver.invitations')->where('invitation_id', $id)->first()->fields;
    }

    private function newAccount(): void
    {
        $this->db->queryBuilder()->table('#PREFIX#users')->insert([
            'usertype' => 0,
            'sex' => 0,
            'birthdate' => 0,
            'modified' => time(),
            'userid' => self::NEWBIE, 'username' => 'inv_newbie', 'email' => self::EMAIL, 'validated' => 1,
        ]);
    }

    private function userField(string $field): string
    {
        return (string) $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', self::NEWBIE)->first()->fields[$field];
    }

    private function clearRows(): void
    {
        $qb = fn () => $this->db->queryBuilder();
        $qb()->table('authserver.invitations')->whereRaw('1 = 1')->delete();
        $qb()->table('authserver.user_roles')->whereIn('userid', [self::ADMIN, self::NEWBIE])->delete();
        $qb()->table(Role::membershipTable())->whereIn('userid', [self::ADMIN, self::NEWBIE])->delete();
        $qb()->table('authserver.roles')->where('roleid', self::ROLE)->delete();
        $qb()->table('organizations')->where('organization_id', self::ORG)->delete();
        $qb()->table('#PREFIX#userdetails')->whereIn('userid', [self::ADMIN, self::NEWBIE])->delete();
        $qb()->table('#PREFIX#users')->whereIn('userid', [self::ADMIN, self::NEWBIE])->delete();
    }
}
