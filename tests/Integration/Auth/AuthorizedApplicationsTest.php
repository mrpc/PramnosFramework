<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\AuthorizedApplications;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * The applications a person has authorized, and taking that back, on both backends (FW-163).
 *
 * Listed: an application holding a live token, and one holding only a consent — whose tokens all
 * expired, and which would still approve the next authorization unasked — with the consent's date
 * and scopes; a deactivated one as well. Revoked: every live token, access and refresh, to status
 * 3, the consent withdrawn, recorded with who did it — the person, or an administrator for them —
 * and for a deactivated application too.
 */
#[CoversClass(AuthorizedApplications::class)]
final class AuthorizedApplicationsTest extends TestCase
{
    private ?Database $db = null;

    private ?Database $previous = null;

    private int $user = 0;

    /** @var list<int> */
    private array $apps = [];

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $qb = fn () => $this->db->queryBuilder();
            $qb()->table('authserver.user_activity_log')->where('userid', $this->user)->delete();
            $qb()->table('authserver.oauth2_user_consents')->where('userid', $this->user)->delete();
            $qb()->table('#PREFIX#usertokens')->where('userid', $this->user)->delete();
            $qb()->table('#PREFIX#applications')->whereIn('appid', $this->apps)->delete();
            $qb()->table('#PREFIX#users')->where('userid', $this->user)->delete();
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        \Pramnos\Auth\ActivityLog::resetTableCache();
    }

    /** The database, the tables, a person and three applications: live, consent only, deactivated. */
    private function arrange(string $type, string $host, int $port): AuthorizedApplications
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUsertokensTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateOauth2UserConsentsTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class,
        ], $db);
        \Pramnos\Auth\ActivityLog::resetTableCache();

        $base       = 7000000 + random_int(0, 899999);
        $this->user = $base;
        $this->apps = [$base + 1, $base + 2, $base + 3];
        $qb         = fn () => $db->queryBuilder();
        $qb()->table('#PREFIX#users')->insert(['userid' => $this->user, 'username' => 'u' . $base, 'email' => 'u' . $base . '@example.com',
            'usertype' => 0, 'sex' => 0, 'birthdate' => 0, 'modified' => 0, 'active' => 1]);
        foreach ([[$base + 1, 'Assistant', 1], [$base + 2, 'Old tool', 1], [$base + 3, 'Retired', 0]] as [$id, $name, $status]) {
            $qb()->table('#PREFIX#applications')->insert(['appid' => $id, 'name' => $name, 'apikey' => 'key' . $id, 'apisecret' => '', 'status' => $status, 'added' => time()]);
        }
        $token = function (int $app, string $type, int $status, int $expires, int $lastused) use ($qb): void {
            $qb()->table('#PREFIX#usertokens')->insert(['userid' => $this->user, 'tokentype' => $type, 'token' => bin2hex(random_bytes(8)),
                'created' => 1700000000, 'lastused' => $lastused, 'status' => $status, 'applicationid' => $app, 'expires' => $expires,
                'removedate' => 0, 'deviceinfo' => '', 'scope' => '', 'notes' => '']);
        };
        $token($base + 1, 'access_token', 1, time() + 3600, 1750000000);
        $token($base + 1, 'refresh_token', 1, 0, 0);
        $token($base + 2, 'access_token', 1, time() - 60, 1710000000);   // expired
        $token($base + 3, 'refresh_token', 1, 0, 0);                      // a deactivated client's, live
        $qb()->table('authserver.oauth2_user_consents')->insert(['userid' => $this->user, 'applicationid' => $base + 2, 'scope' => 'profile mcp', 'created_at' => '2026-01-02 03:04:05']);

        return new AuthorizedApplications($db);
    }

    /**
     * Listed: the live one with its token count and last use, the consent-only one with the
     * consent's date and scopes, the deactivated one as not active — in name order.
     */
    #[DataProvider('databases')]
    public function testWhatIsListed(string $type, string $host, int $port): void
    {
        // Arrange
        $apps = $this->arrange($type, $host, $port);

        // Act
        $list = $apps->forUser($this->user);

        // Assert
        $this->assertSame(['Assistant', 'Old tool', 'Retired'], array_column($list, 'name'));
        $this->assertSame([2, 0, 1], array_column($list, 'token_count'), 'the old tool has no live token, only its consent');
        $this->assertSame(1750000000, $list[0]['last_used'], 'the latest of lastused and created');
        $this->assertSame('profile mcp', $list[1]['scope']);
        $this->assertStringStartsWith('2026-01-02', (string) $list[1]['consented_at']);
        $this->assertSame([true, true, false], array_column($list, 'active'));
    }

    /**
     * Revoked by an administrator: every live token to status 3 with its moment, the consent
     * gone, recorded with who did it; a deactivated application found and revoked all the same.
     */
    #[DataProvider('databases')]
    public function testRevokingForSomebody(string $type, string $host, int $port): void
    {
        // Arrange
        $apps = $this->arrange($type, $host, $port);

        // Act
        $live    = $apps->revoke($this->user, $this->apps[0], byUserId: 1);
        $retired = $apps->byClientId('key' . $this->apps[2]);
        $dormant = $apps->revoke($this->user, (int) $retired['appid']);
        $consent = $apps->revoke($this->user, $this->apps[1]);
        $tokens  = [];
        $rows    = $this->db->queryBuilder()->table('#PREFIX#usertokens')->select(['applicationid', 'status', 'removedate'])->where('userid', $this->user)->get();
        while ($rows->fetch()) {
            $tokens[] = [(int) $rows->fields['applicationid'], (int) $rows->fields['status'], (int) $rows->fields['removedate'] > 0];
        }
        $log = $this->db->queryBuilder()->table('authserver.user_activity_log')->select(['details'])->where('userid', $this->user)->where('action', 'application_revoked')->get();
        $details = [];
        while ($log->fetch()) {
            $details[] = json_decode((string) $log->fields['details'], true);
        }
        usort($details, static fn (array $a, array $b): int => $a['application_id'] <=> $b['application_id']);

        // Assert
        $this->assertSame([2, 1, 1], [$live, $dormant, $consent], 'access and refresh; the retired client\'s; the old tool\'s expired one too, left at status 1 by nothing else');
        $this->assertSame('Retired', $retired['name'], 'found though deactivated');
        $this->assertSame([], $apps->forUser($this->user), 'nothing left: no live token, no consent');
        foreach ($tokens as [, $status, $removed]) {
            $this->assertSame([3, true], [$status, $removed], 'revoked, kept for the audit trail');
        }
        $this->assertSame(1, $details[0]['revoked_by'] ?? null, 'the administrator who did it');
        $this->assertArrayNotHasKey('revoked_by', $details[1], 'the person themselves');
    }
}
