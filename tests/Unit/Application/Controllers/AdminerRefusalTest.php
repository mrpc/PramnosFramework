<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Adminer;
use Pramnos\Logs\Logger;

/**
 * What the `/adminer` route does when it says no — and where that is visible.
 *
 * The gate itself has its own tests. This is what happens on either side of it, and the design is
 * unusual enough to be worth pinning: **the page says nothing on purpose**.
 *
 * A refused request is answered with the site's own 404, not a line of plain text, because a
 * refusal has to be indistinguishable from an address that does not exist. «Not found» in Courier
 * is a page nothing else on the site produces — it tells whoever is looking that something *is*
 * here and that they were turned away, which is precisely the thing not to say.
 *
 * Which leaves the log as the only place a refusal exists at all. So the log line is not
 * bookkeeping here, it is the feature: a run of these from one address is the shape of somebody
 * trying the door, and nothing else in the system would show it. It is written **before** the 404
 * for the same reason.
 *
 * The other 404 — no Adminer installed — says something different in the log, and deliberately:
 * "404 on /adminer with the package missing" and "404 because you are not allowed" are different
 * problems, and the person reading is usually an administrator who cannot tell which one they
 * have.
 *
 * A unit test, because none of that touches a database: the gate is a decision, the log is a
 * stream, and everything that would reach Adminer's own code is replaced.
 */
#[CoversClass(Adminer::class)]
class AdminerRefusalTest extends TestCase
{
    /** @var resource|null */
    private $stream = null;

    /** The application's own settings, restored in tearDown when a test changed them. */
    private ?array $originalInfo = null;

    protected function setUp(): void
    {
        $this->stream = fopen('php://memory', 'r+');
        Logger::setOutputMode(Logger::OUTPUT_STREAM);
        Logger::setStreamTarget($this->stream);

        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $_SERVER['REQUEST_URI'] = '/adminer';
        $_GET = [];
        \Pramnos\Http\RequestIdentity::reset();
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        if ($this->originalInfo !== null) {
            $application = \Pramnos\Application\Application::currentInstance();

            if (is_object($application)) {
                $application->applicationInfo = $this->originalInfo;
            }
            $this->originalInfo = null;
        }

        Logger::setStreamTarget(null);
        Logger::setOutputMode(Logger::OUTPUT_FILE);

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_URI']);
        $_GET = [];
        \Pramnos\Http\RequestIdentity::reset();
        \Pramnos\Http\Request::resetInstance();
    }

    // ── Being turned away ─────────────────────────────────────────────────────

    /**
     * A refusal is recorded, and then answered with nothing.
     *
     * Both halves, in that order. The record is the only trace the request leaves — the answer is
     * a 404 that says nothing about why — so a refusal that was not logged would be a refusal
     * nobody can ever know happened.
     */
    public function testARefusalIsRecordedAndThenSaysNothing(): void
    {
        // Arrange
        $probe = $this->probe(mayOpen: false);

        // Act
        $probe->display();

        // Assert
        $this->assertStringContainsString('Adminer refused', $this->logged());
        $this->assertSame(1, $probe->notFounds, 'the refusal did not answer with the site 404');
        $this->assertSame([], $probe->served, 'a refused request reached Adminer anyway');
    }

    /**
     * The record names who was turned away, from where, and for what.
     *
     * A line saying only "refused" is a line nobody can act on. Which address, and which URL,
     * is what turns a handful of these into a pattern.
     */
    public function testTheRecordNamesTheVisitorAndTheAddress(): void
    {
        // Arrange
        $probe = $this->probe(mayOpen: false);

        // Act
        $probe->display();

        // Assert
        $logged = $this->logged();
        $this->assertStringContainsString('198.51.100.9', $logged, 'the address is not recorded');
        $this->assertStringContainsString('/adminer', $logged, 'the URL is not recorded');
        $this->assertStringContainsString(
            'a visitor with no session',
            $logged,
            'an anonymous refusal is not described as one'
        );
    }

    /** A signed-in visitor is named with the usertype that decided it. */
    public function testASignedInVisitorIsNamedWithTheUsertypeThatDecided(): void
    {
        // Arrange
        $user = new \Pramnos\User\User();
        $user->userid   = 41;
        $user->username = 'someone';
        $user->usertype = 10;
        \Pramnos\Http\RequestIdentity::seal($user, 'test');

        $probe = $this->probe(mayOpen: false);

        // Act
        $probe->display();

        // Assert
        $logged = $this->logged();
        $this->assertStringContainsString('someone', $logged);
        $this->assertStringContainsString('41', $logged);
        $this->assertStringContainsString(
            'usertype 10',
            $logged,
            'the usertype the refusal turned on is not in the record'
        );
    }

    // ── Not installed at all ──────────────────────────────────────────────────

    /**
     * With no Adminer installed the answer is the same 404, and the log says something else.
     *
     * The visitor must not be able to tell the two apart — but the operator has to. "There is no
     * package here" and "you are not allowed here" are different problems with different fixes,
     * and the person reading the log is usually an administrator who cannot tell which one they
     * have from the page.
     */
    public function testNotInstalledIs404WithADifferentReason(): void
    {
        // Arrange
        $probe = $this->probe(mayOpen: true, entryPoint: null);

        // Act
        $probe->display();

        // Assert
        $logged = $this->logged();
        $this->assertSame(1, $probe->notFounds);
        $this->assertStringContainsString('no Adminer package is installed', $logged);
        $this->assertStringContainsString(
            'composer require vrana/adminer',
            $logged,
            'the log does not say how to fix it'
        );
        $this->assertStringNotContainsString(
            'Adminer refused',
            $logged,
            'a missing package was recorded as a refusal'
        );
    }

    // ── Getting through ───────────────────────────────────────────────────────

    /** An allowed visitor with the package installed reaches Adminer, and nothing is refused. */
    public function testAnAllowedVisitorReachesAdminer(): void
    {
        // Arrange
        $probe = $this->probe(mayOpen: true, entryPoint: '/somewhere/adminer.php');

        // Act
        $probe->display();

        // Assert
        $this->assertSame(['/somewhere/adminer.php'], $probe->served);
        $this->assertSame(0, $probe->notFounds);
        $this->assertStringNotContainsString('refused', $this->logged());
    }

    /**
     * A `?file=` request is served as an asset rather than as Adminer itself.
     *
     * Adminer's own stylesheet and script come back through the same route, and they have to be
     * served from beside the entry point — not by running it again, which would answer a request
     * for a `.css` with a database client.
     */
    public function testAnAssetRequestIsServedAsAnAsset(): void
    {
        // Arrange
        $probe = $this->probe(mayOpen: true, entryPoint: '/somewhere/adminer.php');
        $_GET['file'] = 'default.css';
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->display();

        // Assert
        $this->assertSame([['/somewhere', 'default.css']], $probe->assets);
        $this->assertSame([], $probe->served, 'an asset request ran the database client');
    }

    // ── Handing the connection over ───────────────────────────────────────────

    /**
     * With auto-login switched off, the login form is what the visitor gets.
     *
     * Every falsy shape a configuration value arrives in — `false`, the string `'false'`, `'0'`,
     * the integer `0` — because this one is written by hand into `app.php` and read back as
     * whatever the file made of it. A switch that only understood the boolean would hand the
     * application's database credentials to Adminer on an installation that had asked it not to,
     * and the installation would have no way of telling.
     */
    public function testAutoLoginOffLeavesTheLoginForm(): void
    {
        // Act & Assert
        foreach ([false, 'false', '0', 0] as $off) {
            $this->configure(['adminer_autologin' => $off]);

            $this->assertTrue(
                $this->probe()->login(),
                'auto-login stayed on for a configured ' . var_export($off, true)
            );
        }
    }

    /**
     * With nothing to hand over, the login form is the honest answer.
     *
     * A half-filled form that fails with "Invalid credentials" is worse than an empty one: it
     * reads as a wrong password rather than as there never having been a connection to use. This
     * is the state of any installation whose application has no database of its own configured.
     */
    public function testWithNoConnectionTheLoginFormIsTheAnswer(): void
    {
        // Arrange — auto-login on, and nothing for it to use.
        $this->configure(['adminer_autologin' => true]);

        // Act & Assert
        $this->assertTrue($this->probe()->login());
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The route with everything past the decision replaced.
     *
     * `serveAdminer()` includes another package's entry point and buffers its output;
     * `serveAsset()` reads a file and writes headers; `notFound()` ends the request. None of them
     * is what this file is about, and each would need Adminer installed to mean anything.
     */
    private function probe(
        bool $mayOpen = true,
        ?string $entryPoint = '/somewhere/adminer.php'
    ): object {
        return new class ($mayOpen, $entryPoint) extends Adminer {
            public int $notFounds = 0;

            /** @var list<string> entry points Adminer was run from */
            public array $served = [];

            /** @var list<array{0: string, 1: string}> assets served */
            public array $assets = [];

            public function __construct(
                private bool $open,
                private ?string $entry
            ) {
            }

            /** `prepareLogin()` is protected; this is the test's way in. */
            public function login(): bool
            {
                return $this->prepareLogin();
            }

            protected function mayOpen(): bool
            {
                return $this->open;
            }

            protected function locate(): ?string
            {
                return $this->entry;
            }

            protected function serveAdminer(string $entryPoint): void
            {
                $this->served[] = $entryPoint;
            }

            protected function serveAsset(string $directory, string $path): void
            {
                $this->assets[] = [$directory, $path];
            }

            protected function notFound(): void
            {
                $this->notFounds++;
            }
        };
    }

    /**
     * Put a `devpanel` block on the current application for the length of one test.
     *
     * `DevPanelController::config()` reads `applicationInfo['devpanel']`, so this is the real
     * path a configured value takes — no seam invented for the test, and no doubt about whether
     * the production read would have seen the same thing.
     *
     * @param array<string, mixed> $devpanel
     */
    private function configure(array $devpanel): void
    {
        $application = \Pramnos\Application\Application::getInstance();

        if ($this->originalInfo === null) {
            $this->originalInfo = (array) $application->applicationInfo;
        }

        $application->applicationInfo['devpanel'] = $devpanel;
    }

    /** Everything written to the log during this test. */
    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }
}
