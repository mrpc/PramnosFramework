<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Settings;
use Pramnos\Auth\SignInRisk;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Factory;
use Pramnos\User\User;

/**
 * Why a sign-in is worth questioning, beyond the browser being unfamiliar.
 *
 * "A device this account has not used" fires constantly on a real user base — new phones,
 * cleared cookies, a borrowed laptop — so a demand attached to it is a tax everybody pays.
 * These are the signals that are hard to explain innocently, and each one is tested against
 * a real activity log because each is a question about history rather than about
 * configuration.
 *
 * What is *not* asserted is geography in kilometres. There is no IP-to-location database
 * here; the country comes from Cloudflare's header or an application's listener, so
 * "impossible travel" is measured at country granularity. The tests say so by seeding
 * countries rather than coordinates.
 *
 * Runs on MySQL only, like the other tests that touch `authserver.*`.
 */
#[CoversClass(SignInRisk::class)]
class SignInRiskTest extends BaseTestCase
{
    private $db;
    private int $uid = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('SignInRiskTest runs on MySQL only.');
        }

        User::setupDb();
        $prefix = $this->db->prefix;
        $this->db->query('DROP TABLE IF EXISTS `' . $prefix . 'authserver_user_activity_log`');
        // Both dropped first, `sessions` included. Another test class in the same run
        // creates a `sessions` table with its own columns, and the migration below is
        // guarded by hasTable() — so without the drop this test inserted into somebody
        // else's schema and failed on a column that does not exist there.
        $this->db->query('DROP TABLE IF EXISTS `' . $prefix . 'sessions`');
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class,
            \Pramnos\Framework\Migrations\Core\CreateSessionsTable::class,
        ], $this->db);

        $user = new User();
        $user->username = 'risk_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.com';
        $user->save();
        $this->uid = (int) $user->userid;

        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'GR';
        $_SERVER['REMOTE_ADDR']       = '10.1.2.3';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_CF_IPCOUNTRY']);

        try {
            $this->db->queryBuilder()->table('authserver.user_activity_log')
                ->where('userid', $this->uid)->delete();
            $this->db->queryBuilder()->table('#PREFIX#sessions')
                ->where('userid', $this->uid)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')
                ->where('userid', $this->uid)->delete();
        } catch (\Throwable $exception) {
            // Nothing to undo.
        }

        parent::tearDown();
    }

    /** A previous sign-in, as `Auth` records one. */
    private function seedLogin(string $country, int $secondsAgo, string $device = 'known-device'): void
    {
        $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
            'userid'     => $this->uid,
            'action'     => 'login',
            'details'    => json_encode(['device' => $device, 'country' => $country]),
            'ip_address' => '10.1.2.3',
            'created_at' => gmdate('Y-m-d H:i:s', time() - $secondsAgo),
        ]);
    }

    /** A failed attempt, as the flow records one. */
    private function seedFailure(int $secondsAgo): void
    {
        $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
            'userid'     => $this->uid,
            'action'     => 'login_failed',
            'details'    => null,
            'ip_address' => '10.1.2.3',
            'created_at' => gmdate('Y-m-d H:i:s', time() - $secondsAgo),
        ]);
    }

    /**
     * An account with no history at all is not suspicious.
     *
     * The day a signal ships, every account looks new. Treating an empty history as
     * suspicious would demand a step-up of everybody at once — including somebody who
     * registered a minute ago, for whom it is a wall rather than a defence.
     */
    public function testAnAccountWithNoHistoryIsNotSuspicious(): void
    {
        // Act & Assert
        $this->assertFalse(SignInRisk::isSuspicious($this->uid));
    }

    /**
     * A country the account has never signed in from is suspicious.
     */
    public function testANewCountryIsSuspicious(): void
    {
        // Arrange — history in Greece, and this request is from Indonesia
        $this->seedLogin('GR', 86400);
        $this->seedLogin('GR', 172800);
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'ID';

        // Act
        $signals = SignInRisk::assess($this->uid);

        // Assert
        $this->assertContains(SignInRisk::SIGNAL_NEW_COUNTRY, $signals);
        $this->assertTrue(SignInRisk::isSuspicious($this->uid, $signals));
    }

    /**
     * A different country too soon to have travelled is suspicious on its own.
     *
     * Even when the account *has* used both countries before, which is what separates this
     * signal from the previous one: a person who works in two countries is normal, and
     * being in both within ten minutes is not.
     */
    public function testADifferentCountryTooSoonIsSuspicious(): void
    {
        // Arrange — both countries are known to the account
        $this->seedLogin('ID', 600);
        $this->seedLogin('GR', 86400);

        // Act
        $signals = SignInRisk::assess($this->uid);

        // Assert
        $this->assertContains(SignInRisk::SIGNAL_IMPOSSIBLE_TRAVEL, $signals);
        $this->assertNotContains(
            SignInRisk::SIGNAL_NEW_COUNTRY,
            $signals,
            'Greece is not a new country for this account'
        );
    }

    /**
     * The same journey with enough time between is not.
     */
    public function testTheSameJourneyGivenTimeIsNotSuspicious(): void
    {
        // Arrange — the other country, but a day ago
        $this->seedLogin('ID', 86400);
        $this->seedLogin('GR', 172800);

        // Act & Assert
        $this->assertNotContains(
            SignInRisk::SIGNAL_IMPOSSIBLE_TRAVEL,
            SignInRisk::assess($this->uid)
        );
    }

    /**
     * A success straight after a run of failures is suspicious.
     *
     * The signature of a guess that landed, whether from a list of leaked passwords or from
     * somebody who knew three of the four things they needed.
     */
    public function testASuccessAfterManyFailuresIsSuspicious(): void
    {
        // Arrange
        $this->seedLogin('GR', 86400);
        for ($i = 0; $i < 3; $i++) {
            $this->seedFailure(60 + $i);
        }

        // Act
        $signals = SignInRisk::assess($this->uid);

        // Assert
        $this->assertContains(SignInRisk::SIGNAL_AFTER_FAILURES, $signals);
    }

    /**
     * Old failures do not count.
     */
    public function testFailuresOutsideTheWindowAreIgnored(): void
    {
        // Arrange — three failures, all yesterday
        $this->seedLogin('GR', 86400);
        for ($i = 0; $i < 3; $i++) {
            $this->seedFailure(86400 + $i);
        }

        // Act & Assert
        $this->assertNotContains(
            SignInRisk::SIGNAL_AFTER_FAILURES,
            SignInRisk::assess($this->uid)
        );
    }

    /**
     * A live session on an unrelated network is "two places at once".
     */
    public function testAConcurrentSessionElsewhereIsSuspicious(): void
    {
        // Arrange
        $this->seedLogin('GR', 86400);
        $this->db->queryBuilder()->table('#PREFIX#sessions')->insert([
            'visitorid' => 'risk_' . bin2hex(random_bytes(6)),
            'uname'     => 'risk',
            'time'      => time() - 60,
            'host_addr' => '203.0.113.9',
            'guest'     => 0,
            'agent'     => 'test',
            'userid'    => $this->uid,
            'url'       => '/',
            'history'   => '',
            'logout'    => 0,
            'sid'       => md5('other'),
        ]);

        // Act & Assert
        $this->assertContains(
            SignInRisk::SIGNAL_CONCURRENT_ELSEWHERE,
            SignInRisk::assess($this->uid)
        );
    }

    /**
     * A second session on the same network is one person with two tabs.
     *
     * Compared on the address prefix rather than the whole address, because a mobile
     * connection changes its address within an operator's range constantly — comparing
     * whole addresses would report every phone as a second place.
     */
    public function testASessionOnTheSameNetworkIsNotSuspicious(): void
    {
        // Arrange — same /16 as REMOTE_ADDR
        $this->seedLogin('GR', 86400);
        $this->db->queryBuilder()->table('#PREFIX#sessions')->insert([
            'visitorid' => 'risk_' . bin2hex(random_bytes(6)),
            'uname'     => 'risk',
            'time'      => time() - 60,
            'host_addr' => '10.1.9.9',
            'guest'     => 0,
            'agent'     => 'test',
            'userid'    => $this->uid,
            'url'       => '/',
            'history'   => '',
            'logout'    => 0,
            'sid'       => md5('same'),
        ]);

        // Act & Assert
        $this->assertNotContains(
            SignInRisk::SIGNAL_CONCURRENT_ELSEWHERE,
            SignInRisk::assess($this->uid)
        );
    }

    /**
     * With no country available, the country signals stay silent rather than guessing.
     *
     * The honest failure mode: an installation not behind a proxy that resolves countries
     * gets the other signals and no invented geography.
     */
    public function testWithNoCountryTheCountrySignalsAreSilent(): void
    {
        // Arrange
        unset($_SERVER['HTTP_CF_IPCOUNTRY']);
        $this->seedLogin('GR', 600);

        // Act
        $signals = SignInRisk::assess($this->uid);

        // Assert
        $this->assertSame('', SignInRisk::country());
        $this->assertNotContains(SignInRisk::SIGNAL_NEW_COUNTRY, $signals);
        $this->assertNotContains(SignInRisk::SIGNAL_IMPOSSIBLE_TRAVEL, $signals);
    }

    /**
     * Cloudflare's own "unknown" is not a country.
     *
     * `XX` read as a country would make every unresolvable address look like one
     * consistent place — and then the *first* resolvable one look like impossible travel.
     */
    public function testCloudflaresUnknownIsNotACountry(): void
    {
        // Arrange
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';

        // Act & Assert
        $this->assertSame('', SignInRisk::country());
    }

    /**
     * The system account is never assessed.
     */
    public function testTheSystemAccountIsNotAssessed(): void
    {
        // Act & Assert
        $this->assertSame([], SignInRisk::assess(1));
    }

    /**
     * A listener that throws must not fail the login — the assessment proceeds without its
     * vote, exactly as the method's own docblock promises for every other signal.
     */
    public function testAThrowingApplicationListenerDoesNotFailTheAssessment(): void
    {
        // Arrange
        $this->seedLogin('GR', 86400);
        \Pramnos\Event\Event::listen('auth.signin_risk', static function (): never {
            throw new \RuntimeException('the application listener is unreachable');
        });

        try {
            // Act
            $signals = SignInRisk::assess($this->uid);

            // Assert — reaching here at all is half of it
            $this->assertNotContains(\Pramnos\Auth\SignInRisk::SIGNAL_APPLICATION, $signals);
        } finally {
            \Pramnos\Event\Event::forget('auth.signin_risk');
        }
    }

    /**
     * An application's own assessment can flag a sign-in the framework's own signals miss —
     * a geolocation database or a device-reputation service, say.
     */
    public function testAnApplicationListenerCanFlagASignIn(): void
    {
        // Arrange
        $this->seedLogin('GR', 86400);
        \Pramnos\Event\Event::listen('auth.signin_risk', static fn (): bool => true);

        try {
            // Act
            $signals = SignInRisk::assess($this->uid);

            // Assert
            $this->assertContains(SignInRisk::SIGNAL_APPLICATION, $signals);
            $this->assertTrue(SignInRisk::isSuspicious($this->uid, $signals));
        } finally {
            \Pramnos\Event\Event::forget('auth.signin_risk');
        }
    }

    /**
     * With no Cloudflare header, an application's own `auth.signin_country` listener is
     * asked — the hook for an installation with a real geolocation source.
     */
    public function testAListenerSupplyingACountryIsUsed(): void
    {
        // Arrange
        unset($_SERVER['HTTP_CF_IPCOUNTRY']);
        \Pramnos\Event\Event::listen('auth.signin_country', static fn (): string => 'jp');

        try {
            // Act & Assert — upper-cased, like the header's own answer
            $this->assertSame('JP', SignInRisk::country());
        } finally {
            \Pramnos\Event\Event::forget('auth.signin_country');
        }
    }

    /** A country listener that throws answers "unknown", not a fatal. */
    public function testAThrowingCountryListenerAnswersUnknown(): void
    {
        // Arrange
        unset($_SERVER['HTTP_CF_IPCOUNTRY']);
        \Pramnos\Event\Event::listen('auth.signin_country', static function (): never {
            throw new \RuntimeException('the geolocation service is down');
        });

        try {
            // Act & Assert
            $this->assertSame('', SignInRisk::country());
        } finally {
            \Pramnos\Event\Event::forget('auth.signin_country');
        }
    }

    /**
     * With no address for this request, there is nothing to compare a session against —
     * a live session elsewhere is not reported as concurrent when "elsewhere" can't be
     * distinguished from "here".
     */
    public function testWithNoAddressForThisRequestConcurrencyIsNotChecked(): void
    {
        // Arrange
        $this->seedLogin('GR', 86400);
        $savedAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        unset($_SERVER['REMOTE_ADDR']);
        $this->db->queryBuilder()->table('#PREFIX#sessions')->insert([
            'visitorid' => 'risk_' . bin2hex(random_bytes(6)),
            'uname'     => 'risk',
            'time'      => time() - 60,
            'host_addr' => '203.0.113.9',
            'guest'     => 0,
            'agent'     => 'test',
            'userid'    => $this->uid,
            'url'       => '/',
            'history'   => '',
            'logout'    => 0,
            'sid'       => md5('elsewhere'),
        ]);

        try {
            // Act & Assert
            $this->assertNotContains(
                SignInRisk::SIGNAL_CONCURRENT_ELSEWHERE,
                SignInRisk::assess($this->uid)
            );
        } finally {
            if ($savedAddr !== null) {
                $_SERVER['REMOTE_ADDR'] = $savedAddr;
            }
        }
    }

    /** The exact same address is the same place — the common case, not only the /16 match. */
    public function testTheExactSameAddressIsTheSamePlace(): void
    {
        // Arrange
        $this->seedLogin('GR', 86400);
        $this->db->queryBuilder()->table('#PREFIX#sessions')->insert([
            'visitorid' => 'risk_' . bin2hex(random_bytes(6)),
            'uname'     => 'risk',
            'time'      => time() - 60,
            'host_addr' => $_SERVER['REMOTE_ADDR'],
            'guest'     => 0,
            'agent'     => 'test',
            'userid'    => $this->uid,
            'url'       => '/',
            'history'   => '',
            'logout'    => 0,
            'sid'       => md5('exact'),
        ]);

        // Act & Assert
        $this->assertNotContains(
            SignInRisk::SIGNAL_CONCURRENT_ELSEWHERE,
            SignInRisk::assess($this->uid)
        );
    }

    /** IPv6 addresses are compared on their first three groups, the same crude-on-purpose rule. */
    public function testIpv6AddressesAreComparedOnTheFirstThreeGroups(): void
    {
        // Arrange
        $this->seedLogin('GR', 86400);
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1::1';
        $this->db->queryBuilder()->table('#PREFIX#sessions')->insert([
            'visitorid' => 'risk_' . bin2hex(random_bytes(6)),
            'uname'     => 'risk',
            'time'      => time() - 60,
            'host_addr' => '2001:db8:1::ffff', // same first three groups
            'guest'     => 0,
            'agent'     => 'test',
            'userid'    => $this->uid,
            'url'       => '/',
            'history'   => '',
            'logout'    => 0,
            'sid'       => md5('v6-same'),
        ]);

        // Act
        $sameNetwork = SignInRisk::assess($this->uid);

        // Assert — same network, not flagged
        $this->assertNotContains(SignInRisk::SIGNAL_CONCURRENT_ELSEWHERE, $sameNetwork);

        // And a different IPv6 network is
        $this->db->queryBuilder()->table('#PREFIX#sessions')
            ->where('sid', md5('v6-same'))->update(['host_addr' => '2001:db8:2::1']);
        $this->assertContains(
            SignInRisk::SIGNAL_CONCURRENT_ELSEWHERE,
            SignInRisk::assess($this->uid)
        );
    }

    /**
     * A database that cannot be read answers every question with "nothing to report",
     * never a fatal — a risk assessment that can break authentication is a worse problem
     * than the one it solves.
     */
    public function testADatabaseThatCannotBeReadReportsNoSignals(): void
    {
        // Arrange — a connection whose queryBuilder() cannot be used at all
        $broken = new class extends \Pramnos\Database\Database {
            public function __construct()
            {
            }

            public function queryBuilder()
            {
                throw new \RuntimeException('the connection is gone');
            }
        };

        // Act / Assert — the point of the test is that this does not throw
        $this->assertSame([], SignInRisk::assess($this->uid, $broken));
    }

    /**
     * A query that answers `null` rather than a `Result` — a possible answer the query
     * builder's own return type allows for — is read the same way a failed read is: nothing.
     */
    public function testANullQueryResultReportsNoSignals(): void
    {
        // Arrange — every terminal call (`get()`) answers null
        $fake = new class {
            public function table(string $table): self
            {
                return $this;
            }

            public function select($columns): self
            {
                return $this;
            }

            public function where(...$args): self
            {
                return $this;
            }

            public function orderBy(...$args): self
            {
                return $this;
            }

            public function limit(int $limit): self
            {
                return $this;
            }

            public function get(): ?object
            {
                return null;
            }
        };
        $broken = new class ($fake) extends \Pramnos\Database\Database {
            public function __construct(private object $fake)
            {
            }

            public function queryBuilder()
            {
                return $this->fake;
            }
        };

        // Act / Assert
        $this->assertSame([], SignInRisk::assess($this->uid, $broken));
    }
}
