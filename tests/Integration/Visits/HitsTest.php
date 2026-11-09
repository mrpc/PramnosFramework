<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Visits;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\WriteSpool;
use Pramnos\Framework\Factory;
use Pramnos\User\Token;
use Pramnos\Visits\GeoIp;
use Pramnos\Visits\Hits;
use Pramnos\Visits\Visits;

/**
 * The site's own traffic written (`Hits`, `Visits`, the `Hits` controller), on MySQL with and
 * without a prefix and PostgreSQL, the rows read back from `pramnos.page_hits`:
 * - the cookie model: a visit's first hit carries where it came from, its place, its device and
 *   the application's dimension; the next hits go on the same visit, in order; one after the
 *   idle time is a new visit;
 * - the cookieless model: the day's hash, no visit;
 * - what is refused before it is written: a foreign origin, a page of no site, a spam referrer,
 *   too many in a request, over the minute's limit. A bot is written, flagged;
 * - the `<noscript>` pixel; a url only a hit refers to survives `urls:prune`.
 */
#[CoversClass(Hits::class)]
#[CoversClass(Visits::class)]
#[CoversClass(\Pramnos\Application\Controllers\Hits::class)]
#[CoversClass(\Pramnos\User\UrlRegistry::class)]
#[CoversClass(\Pramnos\Visits\VisitsMetrics::class)]
#[CoversClass(\Pramnos\Llm\Analyst\Tools\VisitsTool::class)]
class HitsTest extends TestCase
{
    use \Pramnos\Tests\Support\ReusesProbeDatabase;

    /** The throwaway database. */
    private const PROBE = 'pramnos_visits_probe';

    /** A phone's browser. */
    private const PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

    private ?Database $db = null;

    private ?Database $previous = null;

    /** @var array<string, mixed> */
    private array $info = [];

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'           => ['mysql', 'db', 3306, 'root'],
            'mysql, prefixed' => ['mysql+pf_', 'db', 3306, 'root'],
            'postgresql'      => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    /** The application's settings kept, to be given back. */
    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
    }

    /** The settings, the database, the spool and the readers given back. */
    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        \Pramnos\Metrics\Metrics::reset();
        Hits::limitWith(null);
        GeoIp::useReader(null);
        WriteSpool::setDriver(null);
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $this->db?->close();
        unset($_SERVER['HTTP_REFERER']);
    }

    /** The probe made the application's, with a site, a place for every address, and the spool written at once. */
    private function boot(string $type, string $host, int $port, string $user, string $visitor = 'cookie'): void
    {
        require_once ROOT . '/database/migrations/framework/core/2020_01_01_000001_create_settings_table.php';
        require_once ROOT . '/database/migrations/framework/auth/2020_01_01_000015_create_urls_table.php';
        require_once ROOT . '/database/migrations/framework/auth/2026_11_09_000016_add_sha_to_urls.php';
        require_once ROOT . '/database/migrations/framework/auth/2020_01_01_000016_create_tokenactions_table.php';
        require_once ROOT . '/database/migrations/framework/visits/2026_11_10_000002_create_visits_tables.php';
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        [$type, $prefix] = array_pad(explode('+', $type, 2), 2, '');
        $make = static function (string $name) use ($type, $host, $port, $user, $prefix): Database {
            $db           = new Database();
            $db->type     = $type;
            $db->server   = $host;
            $db->port     = $port;
            $db->user     = $user;
            $db->password = 'secret';
            $db->database = $name;
            $db->prefix   = $prefix;

            return $db;
        };
        $admin = $make(TEST_DATABASE);
        try {
            if (!$admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        if ($prefix !== '') {
            // The prefixed and the plain lane share a server: each builds its own.
            $this->probeChanged($type);
        }
        $this->db = $this->openProbe($admin, self::PROBE . ($prefix !== '' ? '_pf' : ''), $make, [
            \Pramnos\Framework\Migrations\Core\CreateSettingsTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUrlsTable::class,
            \Pramnos\Framework\Migrations\Auth\AddShaToUrls::class,
            // urls:prune reads the framework's own reference too.
            \Pramnos\Framework\Migrations\Auth\CreateTokenactionsTable::class,
            \Pramnos\Framework\Migrations\Visits\CreateVisitsTables::class,
        ]);
        $admin->close();
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;
        (new \ReflectionProperty(Token::class, 'urlIdCache'))->setValue(null, []);
        (new \ReflectionProperty(Token::class, 'urlsHaveSha'))->setValue(null, null);
        WriteSpool::setDriver(WriteSpool::DRIVER_SYNC);
        Application::getInstance()->applicationInfo['visits'] = [
            'sites'     => ['main' => ['hosts' => ['site.test'], 'visitor' => $visitor, 'idle' => 1800]],
            'dimension' => static fn (string $path, array $hit): ?string => explode('/', trim($path, '/'))[0] ?: null,
            'spam'      => ['spam.test'],
        ];
        GeoIp::useReader(new class {
            /** Every address in Athens. */
            public function get(string $ip): array
            {
                return ['country' => ['iso_code' => 'gr'], 'subdivisions' => [['names' => ['en' => 'Attica']]], 'city' => ['names' => ['en' => 'Athens', 'el' => 'Αθήνα']]];
            }
        });
    }

    /** The hits written, oldest first. @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->db->queryBuilder()->table('pramnos.page_hits')->orderBy('hitid')->getAllOrFail();
    }

    /** A request from the site's own page. @return array<string, string> */
    private static function server(string $ua = self::PHONE, string $origin = 'https://site.test'): array
    {
        return ['HTTP_ORIGIN' => $origin, 'HTTP_USER_AGENT' => $ua, 'REMOTE_ADDR' => '203.0.113.7'];
    }

    /** The cookies a take set, as the next request sends them. @param array<string, array{0: string, 1: int}> $set @return array<string, string> */
    private static function cookies(array $set): array
    {
        return array_map(static fn (array $c): string => $c[0], $set);
    }

    /**
     * A visit: its first hit carries where it came from (a newsletter's campaign), the place, the
     * device and the application's dimension; a click out in the same request is its second;
     * the next page goes on the same visit; a page after the idle time is a new visit, internal.
     */
    #[DataProvider('databases')]
    public function testAVisitIsWrittenInOrder(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $now = 1_800_000_000;

        // Act
        $first = Hits::take([
            ['k' => 'view', 'u' => 'https://site.test/el/jobs?utm_source=newsletter&utm_medium=email&utm_campaign=nov', 'r' => 'https://mail.google.com/', 's' => '412x915'],
            ['k' => 'click', 'u' => 'https://site.test/el/jobs', 't' => 'https://elsewhere.test/x?y=1'],
        ], self::server(), [], 42, $now);
        $next  = Hits::take([['k' => 'view', 'u' => 'https://site.test/uk/about', 'r' => 'https://site.test/el/jobs']], self::server(), self::cookies($first['cookies']), 42, $now + 60);
        $later = Hits::take([['k' => 'view', 'u' => 'https://site.test/el/', 'r' => 'https://site.test/uk/about']], self::server(), self::cookies($next['cookies']), null, $now + 60 + 1801);
        $rows  = $this->rows();

        // Assert
        $this->assertCount(4, $rows, 'every hit written, at once through the spool');
        [$landing, $click, $page, $again] = $rows;
        $this->assertSame(['view', 1, 'email', 'email', 'nov', 'mail.google.com'],
            [$landing['kind'], (int) $landing['seq'], $landing['source'], $landing['medium'], $landing['campaign'], $landing['referrer_host']]);
        $this->assertSame(['GR', 'Attica', 'Athens', 'mobile', 'chrome', 'android', '412x915', 'el', 42],
            [$landing['country'], $landing['region'], $landing['city'], $landing['device'], $landing['browser'], $landing['os'], $landing['screen'], $landing['dimension'], (int) $landing['userid']]);
        $this->assertSame(['click', 2], [$click['kind'], (int) $click['seq']]);
        $this->assertNotNull($click['target'], 'the link out, through urls');
        $this->assertNull($click['source'], 'where a visit came from is its first hit\'s');
        $this->assertSame([3, (int) $landing['visit'], 'uk'], [(int) $page['seq'], (int) $page['visit'], $page['dimension']], 'the same visit, in order');
        $this->assertSame((int) $landing['visitor'], (int) $again['visitor'], 'the same visitor');
        $this->assertNotSame((int) $landing['visit'], (int) $again['visit'], 'after the idle time, a new visit');
        $this->assertSame([1, 'internal', null], [(int) $again['seq'], $again['source'], $again['userid']]);
        $this->assertSame(0, (int) $landing['flags']);
        $this->assertSame([Visits::VISITOR_COOKIE, Visits::VISIT_COOKIE], array_keys($first['cookies']));
    }

    /**
     * Refused before anything is written: another site's page, an origin of none of the sites,
     * a spam referrer, more than twenty in a request, over the minute's limit. A bot is written,
     * flagged. Cookieless, the day's hash is the visitor, the same for the same address and agent,
     * with no visit.
     */
    #[DataProvider('databases')]
    public function testRefusalsBotsAndTheCookielessVisitor(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user, 'cookieless');
        $view = static fn (string $u, ?string $r = null): array => ['k' => 'view', 'u' => $u, 'r' => $r];
        Hits::limitWith(static fn (): bool => true);

        // Act
        $foreign = Hits::take([$view('https://site.test/')], self::server(origin: 'https://evil.test'), []);
        $other   = Hits::take([$view('https://other.test/')], self::server(origin: 'https://other.test'), []);
        $spam    = Hits::take([$view('https://site.test/', 'https://www.spam.test/x')], self::server(), []);
        $many    = Hits::take(array_fill(0, 21, $view('https://site.test/')), self::server(), []);
        $left = 1;
        Hits::limitWith(static function (string $key) use (&$left): bool {
            return $left-- > 0;
        });
        $limited = Hits::take([$view('https://site.test/a'), $view('https://site.test/b')], self::server(), []);
        Hits::limitWith(static fn (): bool => true);
        $bot     = Hits::take([$view('https://site.test/a')], self::server('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'), []);
        $again   = Hits::take([$view('https://site.test/c')], self::server(), []);
        $rows    = $this->rows();

        // Assert
        $this->assertSame([[], [], [], []], [$foreign['rows'], $other['rows'], $spam['rows'], $many['rows']]);
        $this->assertCount(1, $limited['rows'], 'the second over the limit');
        $this->assertCount(3, $rows, 'the limited one, the bot and the next');
        $this->assertSame(Hits::BOT, (int) $rows[1]['flags'] & Hits::BOT, 'a bot is kept, flagged');
        $this->assertSame((int) $rows[0]['visitor'], (int) $rows[2]['visitor'], 'the same address and agent, the same day: the same visitor');
        $this->assertNull($rows[0]['visit']);
        $this->assertNull($rows[0]['seq']);
        $this->assertSame([], $again['cookies'], 'cookieless sets none');
        $this->assertNotSame('', (string) \Pramnos\Application\Settings::getSetting('visits_salt', ''), 'today\'s salt kept');
    }

    /**
     * The `<noscript>` pixel counts its page, flagged without JavaScript, and answers a GIF; a url
     * only a hit refers to — a click's target — survives `urls:prune`.
     */
    #[DataProvider('databases')]
    public function testThePixelAndThePrune(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $_SERVER['HTTP_REFERER'] = 'https://site.test/el/no-script';
        $_SERVER['HTTP_USER_AGENT'] = self::PHONE;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        Hits::take([['k' => 'click', 'u' => 'https://site.test/', 't' => 'https://only-a-target.test/']], self::server(), []);
        $controller = new \Pramnos\Application\Controllers\Hits(Application::getInstance());

        // Act
        $answer = $controller->pixel();
        \Pramnos\User\UrlRegistry::prune(10000, time() + 3 * 86400);
        $kept = $this->db->queryBuilder()->table('#PREFIX#urls')->where('url', 'https://only-a-target.test/')->count();

        // Assert
        $this->assertSame(['image/gif', 200], [$answer->getHeaderLine('Content-Type'), $answer->getStatusCode()]);
        $rows = $this->rows();
        $this->assertSame(Hits::NO_JS, (int) end($rows)['flags'] & Hits::NO_JS);
        $this->assertSame(1, $kept, 'kept: page_hits.target refers to it');
    }

    /**
     * The traffic as metrics, computed for a day and read back: page views by people, visits,
     * visitors, signed-in and single-page visits, the mean visit, and the robots and pages
     * without JavaScript counted apart; a breakdown by device and by the application's own
     * dimension under the name it chose.
     */
    #[DataProvider('databases')]
    public function testTheTrafficAsMetrics(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['visits']['dimension_name'] = 'market';
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'UTC'];
        \Pramnos\Metrics\Metrics::reset();
        Hits::limitWith(static fn (): bool => true);
        $day   = '2026-10-05';
        $ten   = (int) gmmktime(10, 0, 0, 10, 5, 2026);
        $desk  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';
        $a     = Hits::take([['k' => 'view', 'u' => 'https://site.test/el/']], self::server(), [], null, $ten);
        $a     = Hits::take([['k' => 'view', 'u' => 'https://site.test/el/jobs']], self::server(), self::cookies($a['cookies']), 42, $ten + 60);
        Hits::take([['k' => 'view', 'u' => 'https://site.test/el/jobs/1'], ['k' => 'click', 'u' => 'https://site.test/el/jobs/1', 't' => 'https://out.test/']],
            self::server(), self::cookies($a['cookies']), 42, $ten + 120);
        Hits::take([['k' => 'view', 'u' => 'https://site.test/uk/', 'r' => 'https://www.google.com/']], self::server($desk), [], null, $ten + 30);
        Hits::take([['k' => 'view', 'u' => 'https://site.test/el/']], self::server('Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'), [], null, $ten);
        Hits::take([['k' => 'view', 'u' => 'https://site.test/el/', 'n' => 1]], self::server($desk), [], null, $ten);
        $names = ['visits.views', 'visits.visits', 'visits.visitors', 'visits.signed_in', 'visits.single_page', 'visits.duration', 'visits.bots', 'visits.no_js'];

        // Act
        \Pramnos\Metrics\Metrics::compute($names, ['day'], $day, $day, true, $ten + 86400 * 2);
        $values = array_map(static fn (string $m): ?float => \Pramnos\Metrics\Metrics::value($m, 'day', $day), $names);
        $desktop = \Pramnos\Metrics\Metrics::value('visits.views', 'day', $day, ['visit_device' => 'desktop']);
        $uk      = \Pramnos\Metrics\Metrics::value('visits.visits', 'day', $day, ['market' => 'uk']);
        $search  = \Pramnos\Metrics\Metrics::value('visits.visits', 'day', $day, ['visit_source' => 'search']);
        $tool    = new \Pramnos\Llm\Analyst\Tools\VisitsTool(2);
        $read    = $tool->execute(['from' => $day, 'to' => $day, 'compare' => true, 'breakdown' => 'sources']);
        $jobs    = $tool->execute(['from' => $day, 'to' => $day, 'page' => 'jobs', 'breakdown' => 'pages']);
        $clicks  = $tool->execute(['from' => $day, 'to' => $day, 'breakdown' => 'clicks', 'site' => 'main']);
        $wrong   = [$tool->execute(['from' => 'yesterday-ish']), $tool->execute(['from' => $day, 'to' => $day, 'breakdown' => 'moods']),
            $tool->execute(['site' => 'nowhere']), $tool->execute(['from' => '2026-10-06', 'to' => '2026-10-05'])];

        // Assert
        $this->assertSame([4.0, 2.0, 2.0, 1.0, 1.0, 60.0, 1.0, 1.0], $values,
            'four pages by people; two visits by two visitors, one signed in, one of a page; 120 s and 0 s; a robot and a page without JavaScript apart');
        $this->assertSame(1.0, $desktop);
        $this->assertSame(1.0, $uk, 'the application\'s own dimension, under the name it chose');
        $this->assertSame(1.0, $search, 'a visit\'s source is its landing page\'s');
        $this->assertArrayHasKey('market', \Pramnos\Metrics\Metrics::dimensions());
        $this->assertSame(['views' => 4, 'visits' => 2, 'visitors' => 2, 'signed_in' => 1, 'single_page' => 1, 'mean_visit_seconds' => 60.0, 'bots' => 1, 'no_js' => 1],
            $read['figures'], 'the tool\'s figures are the metrics\'');
        $this->assertSame(0, $read['before']['figures']['views'], 'the day before: nothing');
        $this->assertSame(['direct', 'search'], array_column(array_values(array_filter($read['breakdown']['sources'], static fn (array $r): bool => $r['visits'] > 0)), 'name'));
        $this->assertSame(2, $jobs['figures']['views'], 'only pages holding "jobs"');
        $this->assertCount(2, $jobs['breakdown']['pages']);
        $this->assertSame('https://out.test/', $clicks['breakdown']['clicks'][0]['name']);
        foreach ($wrong as $answer) {
            $this->assertIsString($answer);
            $this->assertStringStartsWith('Error:', $answer);
        }
        $this->assertSame('visits', $tool->name());
    }
}
