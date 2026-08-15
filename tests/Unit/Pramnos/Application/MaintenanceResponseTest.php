<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;

/**
 * `showError()` answers with a status a client can act on.
 *
 * It used to emit an HTML page and nothing else — no status code, no content type —
 * and the two consequences look unrelated until you notice they are the same defect:
 *
 *   - a JSON API answered `200 OK` with a page of HTML, so the consumer failed with a
 *     parse error rather than a recognisable "the site is down". Applications that
 *     route with `Router::dispatch()` never call `init()` or `exec()`, but they *do*
 *     construct an `Application`, and the constructor is what calls this — so they
 *     inherit the path whether or not they use the rest of the MVC stack;
 *   - a crawler was served the maintenance page as a `200`, making it eligible to be
 *     indexed in place of the real page. An hour of planned downtime could cost the
 *     search result the page exists to earn.
 *
 * The header call itself is not asserted here. Under PHPUnit `headers_sent()` is
 * already true — the progress dots have been printed — so a test of the sending would
 * be a test of nothing. The decisions are what these cover, which is why they were
 * split out of `showError()` in the first place.
 */
class MaintenanceResponseTest extends TestCase
{
    /** @var Application The application under test */
    private Application $app;

    /** @var string Path to the maintenance flag */
    private string $flag;

    /** @var array<string, string> `$_SERVER` keys this test writes, to restore after */
    private array $serverBackup = [];

    /**
     * Builds an application and remembers the request headers it will forge.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->app  = Application::getInstance();
        $this->flag = ROOT . DS . 'var' . DS . 'MAINTENANCE';

        foreach (['HTTP_ACCEPT', 'HTTP_X_REQUESTED_WITH'] as $key) {
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }
    }

    /**
     * Removes the flag and restores `$_SERVER`.
     *
     * The flag is a real file at the repository root; leaving one behind would put
     * every later test — and the developer's next request — into maintenance mode.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (file_exists($this->flag)) {
            unlink($this->flag);
        }
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }

    /**
     * Calls a protected method on the application.
     *
     * @param  string $method Method name
     * @param  mixed  ...$args Arguments
     * @return mixed
     */
    private function call(string $method, ...$args)
    {
        // No setAccessible(): protected members are reachable through reflection
        // without it since PHP 8.1, and calling it raises a deprecation.
        return (new \ReflectionMethod($this->app, $method))
            ->invokeArgs($this->app, $args);
    }

    /**
     * A browser's `Accept` header never names `application/json`.
     *
     * That is the whole reason this test can be a header check rather than a list of
     * API paths to keep in sync with the router.
     *
     * @return void
     */
    public function testABrowserAcceptHeaderIsNotTreatedAsJson(): void
    {
        // Arrange — what Chrome actually sends
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

        // Act & Assert
        $this->assertFalse($this->call('clientWantsJson'));
    }

    /**
     * A client asking for JSON is recognised, whatever else it also accepts.
     *
     * @return void
     */
    public function testAJsonAcceptHeaderIsRecognised(): void
    {
        // Arrange
        $_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain, */*';

        // Act & Assert
        $this->assertTrue($this->call('clientWantsJson'));
    }

    /**
     * `X-Requested-With` is honoured for clients that send no useful `Accept`.
     *
     * Several JS clients send `*\/*` and identify themselves only this way.
     *
     * @return void
     */
    public function testXRequestedWithIsHonoured(): void
    {
        // Arrange
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        // Act & Assert
        $this->assertTrue($this->call('clientWantsJson'));
    }

    /**
     * With no `Accept` at all — a CLI run, a bare curl — HTML is the answer.
     *
     * Defaulting to JSON would break the one case that has a human reading it.
     *
     * @return void
     */
    public function testNoAcceptHeaderMeansHtml(): void
    {
        // Act & Assert — setUp() unset both keys
        $this->assertFalse($this->call('clientWantsJson'));
    }

    /**
     * The maintenance flag is what separates 503 from 500.
     *
     * `showError()` is also the framework's terminal fault path — an unsupported PHP
     * version, an addon that would not load, a database that would not answer. Those
     * are faults, not a planned stop, and answering `503 Retry-After` to them would
     * tell a crawler to come back to something that is not coming back.
     *
     * @return void
     */
    public function testMaintenanceFlagDecidesTheStatus(): void
    {
        // Arrange & Assert — no flag
        $this->assertFalse($this->call('isInMaintenance'));

        // Act
        if (!is_dir(ROOT . DS . 'var')) {
            mkdir(ROOT . DS . 'var', 0777, true);
        }
        file_put_contents($this->flag, 'down for tests');

        // Assert
        $this->assertTrue($this->call('isInMaintenance'));
    }

    /**
     * `Retry-After` defaults to five minutes and is overridable by constant.
     *
     * A constant rather than a setting on purpose: this runs while the site is down,
     * and in the case that matters most — the database being *why* it is down — asking
     * the database how long to wait cannot work.
     *
     * @return void
     */
    public function testRetryAfterDefaultsToFiveMinutes(): void
    {
        // Act & Assert
        $this->assertSame(300, $this->call('maintenanceRetryAfter'));
    }

    /**
     * A maintenance response aimed at an API is JSON that names the state.
     *
     * `close()` throws under `PRAMNOS_TESTING` with the body it was given, which is
     * how the body is reachable at all — the real one calls `exit()`.
     *
     * @return void
     */
    public function testJsonMaintenanceBodyNamesTheStateAndTheRetry(): void
    {
        // Arrange
        if (!is_dir(ROOT . DS . 'var')) {
            mkdir(ROOT . DS . 'var', 0777, true);
        }
        file_put_contents($this->flag, 'down for tests');
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        // Act
        $body = null;
        try {
            $this->app->showError('Upgrading the database');
        } catch (\Exception $e) {
            $body = $e->getMessage();
        }

        // Assert — a parseable body, not a page
        $this->assertNotNull($body);
        $json = json_decode(
            (string) preg_replace('/^.*?(\{.*\}).*$/s', '$1', $body),
            true
        );
        $this->assertIsArray($json, 'The JSON branch must emit parseable JSON.');
        $this->assertSame('maintenance', $json['error']);
        $this->assertSame(300, $json['retry_after']);
        // The same message the HTML branch shows: a format the client can parse must
        // not be the one told least.
        $this->assertStringContainsString('Upgrading the database', $json['message']);
    }

    /**
     * Outside maintenance the JSON body says `unavailable` and advertises no retry.
     *
     * Telling a client to retry in five minutes after a fatal misconfiguration is a
     * promise nothing will keep.
     *
     * @return void
     */
    public function testJsonFaultBodyDoesNotAdvertiseARetry(): void
    {
        // Arrange — no flag
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        // Act
        $body = null;
        try {
            $this->app->showError('Cannot load addon: broken');
        } catch (\Exception $e) {
            $body = $e->getMessage();
        }

        // Assert
        $json = json_decode(
            (string) preg_replace('/^.*?(\{.*\}).*$/s', '$1', (string) $body),
            true
        );
        $this->assertIsArray($json);
        $this->assertSame('unavailable', $json['error']);
        $this->assertArrayNotHasKey('retry_after', $json);
    }

    /**
     * A client that did not ask for JSON still gets the HTML page, unchanged.
     *
     * This is the regression guard for everyone who was already relying on this path:
     * the fix adds a status code and a content type, and must change nothing a human
     * sees.
     *
     * @return void
     */
    public function testHtmlBranchIsUnchanged(): void
    {
        // Arrange
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        // Act
        $body = null;
        try {
            $this->app->showError('Something went wrong', 'Custom Title');
        } catch (\Exception $e) {
            $body = $e->getMessage();
        }

        // Assert
        $this->assertStringContainsString('<html>', (string) $body);
        $this->assertStringContainsString('Custom Title', (string) $body);
        $this->assertStringContainsString('Something went wrong', (string) $body);
    }
}
