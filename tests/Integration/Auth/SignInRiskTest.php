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
}
