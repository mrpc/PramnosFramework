<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Adminer;

/**
 * The forty-two statements that actually run Adminer, none of which had ever executed.
 *
 * `serveAdminer()` is the largest single never-executed unit in the framework, and the reason is
 * mundane: `locate()` looks for `vendor/vrana/adminer` or `vendor/dg/adminer-custom`, neither of which
 * this repository installs, so the route always answered «not found» and the body of the method was
 * unreachable from any test that went through the front door.
 *
 * It takes its entry point as an argument, though — so it can be handed a script this test writes,
 * and every decision it makes around the include becomes observable. Which matters, because two of
 * those decisions are security decisions and one is a bug fix whose own comment explains that it was
 * invisible:
 *
 *  - **`unset($_POST['auth'], $_POST['logout'])`.** Adminer's `auth.inc.php` acts on `$_POST['auth']`
 *    — driver, server, username, password, database — before anything else. Removing its login form
 *    took away the page that submits that, not the ability to submit it: a hand-made POST, or a form
 *    on another site aimed at this URL, would have logged this Adminer into any host reachable from
 *    the server with any credentials the sender knew. `permanent` goes with it, which is the field
 *    that asks Adminer to write an encrypted copy of the password into a cookie.
 *  - **`$_SESSION = array()`**, and not merely `session_write_close()`. Adminer starts a session of
 *    its own only if none is active, and when it decides not to it reads and writes *our* keys. One
 *    of them is `token`: Adminer's CSRF token is `rand() ^ $_SESSION["token"]`, this framework's value
 *    is a hex string, and the result was «A non-numeric value encountered» twice per page and a CSRF
 *    check that could not work.
 *  - **the buffer callback.** Adminer is a script and a script ends with `exit`, several of its paths
 *    included. So code placed after the include never ran, PHP flushed the buffer at shutdown, and the
 *    page went out with Adminer's own `./static/default.css` links — which resolve to `/static/…` when
 *    served from `/adminer`, so the tool arrived with no stylesheet and looked broken rather than
 *    un-rewritten. A callback is invoked by the buffer's own flush, `exit` included.
 *
 * A fake entry point is the honest fixture here. What is under test is what this route does *around*
 * the include — the request it hands over, the session it hands over, and what it does to the bytes
 * that come back — and none of that is a claim about Adminer.
 */
#[CoversClass(Adminer::class)]
class AdminerServeTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DS . 'adminer_probe_' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0777, true);

        $_POST = [];
        $_GET  = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . DS . '*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->directory);

        $_POST = [];
        $_GET  = [];
        $_SESSION = [];

        parent::tearDown();
    }

    /**
     * A stand-in for Adminer's entry point.
     *
     * It records what the request looked like from inside the include — which is the only place the
     * `$_POST` and `$_SESSION` decisions can be observed — into a file, because a variable set inside
     * an included script is not visible to the test once that script has ended with `exit`.
     */
    private function writeEntryPoint(string $body): string
    {
        $path = $this->directory . DS . 'index.php';
        file_put_contents($path, "<?php\n" . $body . "\n");

        return $path;
    }

    private function probe(): object
    {
        return new class extends Adminer {
            public array $audited = [];

            public bool $terminated = false;

            public bool $notFounded = false;

            public bool $loginPrepared = true;

            public string $cwdInsideInclude = '';

            public function __construct()
            {
            }

            protected function audit(string $outcome): void
            {
                $this->audited[] = $outcome;
            }

            protected function prepareLogin(): bool
            {
                return $this->loginPrepared;
            }

            protected function terminate(): void
            {
                $this->terminated = true;
            }

            protected function notFound(): void
            {
                $this->notFounded = true;
            }

            public function callServeAdminer(string $entryPoint): void
            {
                $this->serveAdminer($entryPoint);
            }
        };
    }

    /**
     * Run it, and hand back what the browser would have received.
     *
     * `serveAdminer()` opens a buffer with a callback and never closes it — deliberately, because
     * Adminer ends the request itself and PHP's shutdown flush is what invokes the callback. So the
     * flush has to happen here, and it has to be a *flush* rather than a clean: `ob_get_clean()`
     * returns the raw buffer and discards it without running the callback, which is exactly the
     * rewrite under test.
     */
    private function serve(object $probe, string $entryPoint): string
    {
        $outer = ob_get_level();
        ob_start();

        $probe->callServeAdminer($entryPoint);

        while (ob_get_level() > $outer + 1) {
            ob_end_flush();
        }

        return (string) ob_get_clean();
    }

    /**
     * Nothing the request POSTs can choose a connection.
     *
     * The assertion is made from *inside* the include, where Adminer would read it, rather than from
     * the test afterwards — a route that unset the keys and then restored them would pass the second
     * check and fail the first, and the first is the one that matters.
     */
    public function testTheRequestCannotChooseAConnection(): void
    {
        // Arrange
        $record = $this->directory . DS . 'post.json';
        $entry = $this->writeEntryPoint(
            'file_put_contents(' . var_export($record, true) . ', json_encode($_POST));'
        );

        $_POST = [
            'auth' => [
                'driver'   => 'server',
                'server'   => 'attacker.example:3306',
                'username' => 'root',
                'password' => 'hunter2',
                'db'       => 'anything',
                'permanent' => 1,
            ],
            'logout'    => 1,
            'something' => 'kept',
        ];

        // Act
        $this->serve($this->probe(), $entry);

        // Assert
        $seen = json_decode((string) file_get_contents($record), true);
        $this->assertIsArray($seen);
        $this->assertArrayNotHasKey('auth', $seen, 'a POST could log Adminer into any reachable host');
        $this->assertArrayNotHasKey('logout', $seen);
        $this->assertSame('kept', $seen['something'] ?? null, 'unrelated fields were removed too');
    }

    /**
     * Adminer is handed an empty session, not our own.
     *
     * Closing the session writes it and releases the handle; `$_SESSION` keeps its contents in memory,
     * and Adminer reads them when it decides not to start a session of its own. `token` is the
     * collision that made this visible — its CSRF token is `rand() ^ $_SESSION["token"]` and ours is a
     * hex string.
     */
    public function testAdminerSeesAnEmptySession(): void
    {
        // Arrange
        $record = $this->directory . DS . 'session.json';
        $entry = $this->writeEntryPoint(
            'file_put_contents(' . var_export($record, true) . ', json_encode($_SESSION));'
        );

        $_SESSION = ['token' => 'a1b2c3d4', 'userid' => 7];

        // Act
        $this->serve($this->probe(), $entry);

        // Assert
        $this->assertSame(
            [],
            json_decode((string) file_get_contents($record), true),
            'Adminer was handed this framework\'s session keys to write into'
        );
    }

    /**
     * The rewrite is applied by the buffer's own flush, *after* `serveAdminer()` has returned.
     *
     * Which is the property that makes it survive `exit`, and the reason the transformation is a
     * callback rather than the `ob_get_clean()`-and-rewrite that used to sit after the include.
     * Adminer is a script and several of its paths end with `exit`, the login page among them, so the
     * post-include code never ran: PHP flushed the buffer at shutdown and the page went out with
     * Adminer's own `./static/default.css` links, which resolve to `/static/…` when served from
     * `/adminer`. The tool arrived with no stylesheet and looked broken rather than un-rewritten.
     *
     * `exit` itself cannot be exercised from inside a test — it takes the runner with it — so what is
     * asserted is the structural fact underneath: `serveAdminer()` returns with the buffer still open,
     * and the rewrite appears when *this test* flushes it. Under the old implementation that flush
     * would produce the raw bytes, because the rewrite was code that had already been skipped.
     */
    public function testTheRewriteIsAppliedByTheFlushAndNotByCodeAfterTheInclude(): void
    {
        // Arrange
        $entry = $this->writeEntryPoint(
            'echo \'<link rel="stylesheet" href="./static/default.css">\';'
        );
        $probe = $this->probe();

        // Act — deliberately not through serve(): the buffer state between the two is the subject
        $outer = ob_get_level();
        ob_start();
        $probe->callServeAdminer($entry);

        $stillOpen = ob_get_level() > $outer + 1;

        while (ob_get_level() > $outer + 1) {
            ob_end_flush();
        }
        $output = (string) ob_get_clean();

        // Assert
        $this->assertTrue(
            $stillOpen,
            'the buffer was closed inside serveAdminer(), so an exiting script would skip the rewrite'
        );
        $this->assertStringNotContainsString(
            'href="./static/default.css"',
            $output,
            'the asset URL was not rewritten, so it resolves to /static/… and 404s'
        );
        $this->assertStringContainsString('/adminer', $output);
    }

    /**
     * Every open is recorded, and the working directory is put back.
     *
     * The audit line is the only record of who opened the database tool: Adminer keeps none, and the
     * web server's log says a URL was fetched rather than which account fetched it.
     *
     * The `chdir()` is there because Adminer's includes are relative to its own directory. Restoring it
     * matters less in practice — this response ends the request — and is still not the kind of thing to
     * leave behind on the strength of that.
     */
    public function testTheOpenIsAuditedAndTheDirectoryRestored(): void
    {
        // Arrange
        $before = getcwd();
        $probe = $this->probe();
        $entry = $this->writeEntryPoint('echo "adminer ran";');

        // Act
        $output = $this->serve($probe, $entry);

        // Assert
        $this->assertStringContainsString('adminer ran', $output);
        $this->assertSame(['opened'], $probe->audited, 'the open was not recorded');
        $this->assertTrue($probe->terminated, 'the request continued, so the site theme renders after');
        $this->assertSame($before, getcwd(), 'the working directory was left inside Adminer');
    }

    /**
     * A script that throws produces a 404 and no half-written page.
     *
     * `ob_end_clean()` rather than a flush on that path, and this is what asserts it: a partial
     * Adminer page followed by a 404 is worse than either, because the browser renders whatever
     * arrived first and the status code is the part nobody sees.
     */
    public function testAThrowingScriptLeavesNoOutput(): void
    {
        // Arrange
        $before = getcwd();
        $probe = $this->probe();
        $entry = $this->writeEntryPoint(
            'echo "half a page";' . "\n" . 'throw new \\RuntimeException("adminer exploded");'
        );

        // Act
        $output = $this->serve($probe, $entry);

        // Assert
        $this->assertSame('', $output, 'half an Adminer page was sent before the 404');
        $this->assertTrue($probe->notFounded, 'the failure was not turned into a 404');
        $this->assertSame($before, getcwd());
    }

    /**
     * When the login could not be prepared, nothing is included at all.
     *
     * `prepareLogin()` returning false means it has redirected to the canonical URL, and going on to
     * include Adminer would write a second response body underneath a `Location:` header.
     */
    public function testNothingIsIncludedWhenTheLoginWasNotPrepared(): void
    {
        // Arrange
        $probe = $this->probe();
        $probe->loginPrepared = false;
        $entry = $this->writeEntryPoint('echo "must not run";');

        // Act
        $output = $this->serve($probe, $entry);

        // Assert
        $this->assertSame('', $output);
        $this->assertFalse($probe->terminated, 'the response was ended after a redirect had been sent');
        $this->assertSame(['opened'], $probe->audited, 'the attempt is still worth recording');
    }
}
