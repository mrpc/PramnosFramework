<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\DevPanel\AdminerBridge;

/**
 * The connection `/adminer` is handed, read from whatever shape the settings are in.
 *
 * This is the method that took the route down on its first real request:
 * *Object of class stdClass could not be converted to string*. `Settings::getSetting('database')`
 * hands back the **whole nested connection block** — as an object, because that is what the
 * settings store does with an array — and the code cast it to a string expecting a database
 * name.
 *
 * A settings file is written by hand and comes in two shapes, one of them older than the other,
 * so every value is checked for being a scalar before it is used. What is asserted here is both
 * shapes and the refusals, because the interesting failures are the ones that produce a *wrong*
 * connection rather than none: Adminer would then show a login form with somebody else's server
 * in it, or connect to the wrong database on a machine that has two.
 */
#[CoversClass(AdminerBridge::class)]
class AdminerBridgeTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $saved = null;

    protected function setUp(): void
    {
        $reflection  = new \ReflectionProperty(Settings::class, 'settings');
        $this->saved = (array) $reflection->getValue();
    }

    protected function tearDown(): void
    {
        if ($this->saved !== null) {
            (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $this->saved);
            $this->saved = null;
        }

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function withSettings(array $settings): void
    {
        (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $settings);
    }

    /**
     * The nested shape — what a project scaffolded today has.
     *
     * `'database' => ['database' => …]` is the collision that broke it: the outer key is set, so
     * asking for `database` hands back the block rather than the name inside it.
     */
    public function testTheNestedConnectionBlockIsRead(): void
    {
        // Arrange
        $this->withSettings([
            'database' => [
                'type'     => 'postgresql',
                'hostname' => 'db',
                'database' => 'app_db',
                'user'     => 'app_user',
                'password' => 'app_password',
            ],
        ]);

        // Act
        $connection = AdminerBridge::applicationConnection();

        // Assert
        $this->assertSame('pgsql', $connection['driver']);
        $this->assertSame('db', $connection['server']);
        $this->assertSame('app_db', $connection['database']);
        $this->assertSame('app_user', $connection['user']);
        $this->assertSame('app_password', $connection['password']);
    }

    /**
     * And the flat shape, which older installations still have.
     */
    public function testTheFlatConnectionKeysAreRead(): void
    {
        // Arrange
        $this->withSettings([
            'type'     => 'mysql',
            'hostname' => 'localhost',
            'database' => 'legacy_db',
            'user'     => 'legacy_user',
            'password' => 'legacy_password',
        ]);

        // Act
        $connection = AdminerBridge::applicationConnection();

        // Assert — `server` is Adminer's own key for its MySQL driver, kept for compatibility
        $this->assertSame('server', $connection['driver']);
        $this->assertSame('legacy_db', $connection['database']);
    }

    /**
     * An array where a string belongs produces no connection, not a crash.
     *
     * The shape that took the route down. A settings file with a `database` block and no
     * `user` inside it is not exotic — it is what a half-finished edit looks like — and the
     * answer has to be "hand Adminer nothing and let it ask", not a fatal on a page an
     * administrator opened.
     */
    public function testAConfigThatCannotBeReadYieldsNoConnection(): void
    {
        // Arrange — a nested block with the user missing, and a name that is an array
        $this->withSettings([
            'database' => ['hostname' => 'db', 'database' => ['not', 'a', 'string']],
            'sitename' => ['also', 'not', 'a', 'string'],
        ]);

        // Act
        $connection = AdminerBridge::applicationConnection();

        // Assert
        $this->assertSame([], $connection);
    }

    /**
     * Nothing configured at all is the same answer.
     */
    public function testNoConfigurationYieldsNoConnection(): void
    {
        // Arrange
        $this->withSettings([]);

        // Act & Assert
        $this->assertSame([], AdminerBridge::applicationConnection());
    }

    /**
     * The framework's database types map onto Adminer's driver keys.
     *
     * `server` for MySQL is not a typo — it is Adminer's own key for its first driver, and
     * getting it wrong means the login form opens on the wrong system with the right
     * credentials in it.
     */
    public function testTheDriverNamesMapToAdminersOwn(): void
    {
        // Act & Assert
        $this->assertSame('pgsql', AdminerBridge::driverFor('postgresql'));
        $this->assertSame('pgsql', AdminerBridge::driverFor('TimescaleDB'));
        $this->assertSame('server', AdminerBridge::driverFor('mysql'));
        $this->assertSame('sqlite', AdminerBridge::driverFor('sqlite'));
        $this->assertSame('server', AdminerBridge::driverFor('something-unheard-of'));
    }

    /**
     * The connection goes in the URL, and the password does not.
     *
     * A password in a URL is a password in a browser history, a proxy log and an access log.
     * Adminer reads it from its own session instead, seeded by the plugin.
     */
    public function testTheQueryStringNamesTheConnectionButNotThePassword(): void
    {
        // Arrange
        $connection = [
            'driver' => 'pgsql', 'server' => 'db', 'user' => 'app_user',
            'password' => 'the-secret', 'database' => 'app_db', 'name' => 'Site',
        ];

        // Act
        $query = AdminerBridge::query($connection);
        parse_str($query, $parsed);

        // Assert
        $this->assertSame('db', $parsed['pgsql']);
        $this->assertSame('app_user', $parsed['username']);
        $this->assertSame('app_db', $parsed['db']);
        $this->assertStringNotContainsString('the-secret', $query);
    }

    /**
     * The schema travels too, so Adminer needs no redirect of its own.
     *
     * Without `ns=`, Adminer redirects once to add it — correct behaviour on its part, and it
     * publishes the connection into the address bar on the way. The framework already knows
     * which schema it is using.
     */
    public function testTheSchemaIsPartOfTheQueryWhenThereIsOne(): void
    {
        // Arrange
        $connection = [
            'driver' => 'pgsql', 'server' => 'db', 'user' => 'u',
            'password' => 'p', 'database' => 'd', 'schema' => 'public', 'name' => '',
        ];

        // Act
        parse_str(AdminerBridge::query($connection), $parsed);

        // Assert
        $this->assertSame('public', $parsed['ns']);

        // …and a driver with no schema does not gain an empty one
        unset($connection['schema']);
        parse_str(AdminerBridge::query($connection), $withoutSchema);
        $this->assertArrayNotHasKey('ns', $withoutSchema);
    }

    /**
     * Aligning the URI fills `$_GET` and the request URI together, and leaves the address bar.
     *
     * Together is the point. Adminer reads parameters from `$_GET` and builds its self-links
     * from `$_SERVER['REQUEST_URI']`; when the two disagree it decides the visitor is at the
     * wrong address and redirects to the one it derived — the bare route — which comes straight
     * back here. `ERR_TOO_MANY_REDIRECTS` on the first click.
     *
     * A real redirect to the parameterised URL was the first fix, and it wrote the driver, the
     * host, the username and the database name into the address bar and the browser history.
     * This leaves the visitor's URL alone.
     */
    public function testAligningTheUriFillsBothHalves(): void
    {
        // Arrange
        $connection = [
            'driver' => 'pgsql', 'server' => 'db', 'user' => 'app_user',
            'password' => 'the-secret', 'database' => 'app_db', 'name' => 'Site',
        ];
        $savedGet = $_GET;
        $savedUri = $_SERVER['REQUEST_URI'] ?? null;
        $_GET = [];

        try {
            // Act
            AdminerBridge::alignRequestUri($connection, '/adminer');

            // Assert
            $this->assertSame('db', $_GET['pgsql']);
            $this->assertSame('app_user', $_GET['username']);
            $this->assertSame('app_db', $_GET['db']);
            $this->assertStringStartsWith('/adminer?', $_SERVER['REQUEST_URI']);
            $this->assertStringContainsString('username=app_user', $_SERVER['REQUEST_URI']);
            $this->assertStringNotContainsString(
                'the-secret',
                $_SERVER['REQUEST_URI'],
                'the password never goes in a URL'
            );
        } finally {
            $_GET = $savedGet;

            if ($savedUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $savedUri;
            }
        }
    }

    /**
     * A parameter the request already carries is not overwritten.
     *
     * Somebody following «log in as somebody else» inside Adminer has chosen a connection, and
     * dragging them back to the application's own database would be the opposite of helpful.
     */
    public function testAligningDoesNotOverwriteWhatTheRequestChose(): void
    {
        // Arrange
        $savedGet = $_GET;
        $savedUri = $_SERVER['REQUEST_URI'] ?? null;
        $_GET = ['username' => 'somebody-else'];

        try {
            // Act
            AdminerBridge::alignRequestUri([
                'driver' => 'pgsql', 'server' => 'db', 'user' => 'app_user',
                'password' => 'p', 'database' => 'app_db', 'name' => '',
            ], '/adminer');

            // Assert
            $this->assertSame('somebody-else', $_GET['username']);
        } finally {
            $_GET = $savedGet;

            if ($savedUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $savedUri;
            }
        }
    }

    /**
     * A URL that already names a connection is left alone — which is what stops the loop.
     *
     * The parameters used to be injected into `$_GET` and the page served. Adminer builds its
     * own self-links from `$_SERVER['REQUEST_URI']`, so with them in `$_GET` and absent from the
     * URI its idea of "the canonical address of this page" was the bare route — which it
     * redirected to, arriving back where they were injected again. `ERR_TOO_MANY_REDIRECTS`, on
     * the first click.
     *
     * The redirect now happens only when the URL does not name the connection, and its target
     * does, so a second one is impossible. That is the invariant asserted here.
     */
    public function testARequestThatAlreadyNamesAConnectionNeedsNoRedirect(): void
    {
        // Arrange
        $connection = [
            'driver' => 'pgsql', 'server' => 'db', 'user' => 'u',
            'password' => 'p', 'database' => 'd', 'name' => 'Site',
        ];
        $saved = $_GET;

        try {
            // Act & Assert — a bare route needs the redirect
            $_GET = [];
            $this->assertFalse(AdminerBridge::urlNamesConnection($connection));

            // …the URL the redirect points at does not
            parse_str(AdminerBridge::query($connection), $_GET);
            $this->assertTrue(
                AdminerBridge::urlNamesConnection($connection),
                'the redirect target must not need another redirect'
            );

            // …and a request naming a different driver is somebody logging in elsewhere
            $_GET = ['server' => 'another-host', 'username' => 'somebody-else'];
            $this->assertTrue(
                AdminerBridge::urlNamesConnection($connection),
                'a connection the visitor chose must not be overridden'
            );
        } finally {
            $_GET = $saved;
        }
    }

    /**
     * The declared replicas are offered; anything else the request asks for is not.
     *
     * Adminer's own design is that the query string says *who to connect as*, which is exactly
     * what this route is not for: the gate on the URL is the whole authorisation, so a URL able
     * to point Adminer at another host would be a way to use somebody else's credentials through
     * our door. The connections the installation declared are the allow-list, and a request
     * naming anything else gets the default.
     */
    public function testTheReplicasAreOfferedAndNothingElseIs(): void
    {
        // Arrange — a primary with a read replica on another host
        $this->withSettings([
            'database' => [
                'type'     => 'postgresql',
                'hostname' => 'db',
                'database' => 'app_db',
                'user'     => 'app_user',
                'password' => 'app_password',
                'read'     => ['hostname' => 'db-replica', 'user' => 'reader'],
            ],
        ]);
        $savedGet = $_GET;

        try {
            // Act
            $connections = AdminerBridge::connections();

            // Assert — both, and the replica keeps the primary's database when it names none
            $this->assertArrayHasKey('primary', $connections);
            $this->assertArrayHasKey('read', $connections);
            $this->assertSame('db-replica', $connections['read']['server']);
            $this->assertSame('app_db', $connections['read']['database']);

            // …a request naming the replica gets the replica
            $_GET = ['pgsql' => 'db-replica', 'username' => 'reader'];
            $this->assertSame('db-replica', AdminerBridge::chosen()['server']);

            // …and a request naming somebody else's server gets the default, not that server
            $_GET = ['pgsql' => 'somewhere-else.example.com', 'username' => 'postgres'];
            $chosen = AdminerBridge::chosen();
            $this->assertSame('db', $chosen['server']);
            $this->assertSame('app_user', $chosen['user']);
        } finally {
            $_GET = $savedGet;
        }
    }

    /**
     * A replica that repeats the primary is not offered twice.
     *
     * `'write' => ['hostname' => 'db']` beside the same primary is the common case, and two
     * identical entries in a picker say nothing except that somebody generated the list.
     */
    public function testAReplicaIdenticalToThePrimaryIsNotListedTwice(): void
    {
        // Arrange
        $this->withSettings([
            'database' => [
                'type'     => 'mysql',
                'hostname' => 'db',
                'database' => 'app_db',
                'user'     => 'app_user',
                'password' => 'p',
                'write'    => ['hostname' => 'db', 'user' => 'app_user', 'database' => 'app_db'],
            ],
        ]);

        // Act & Assert
        $this->assertSame(['primary'], array_keys(AdminerBridge::connections()));
    }
}
