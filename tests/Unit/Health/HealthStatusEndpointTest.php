<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Health;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Health\HealthRegistry;
use Pramnos\Http\Response;

/**
 * `Health::status()` — the flattened, public verdict.
 *
 * `check()` publishes every check with its message, latency, driver, version and
 * paths. That is a fair trade for a monitoring endpoint on a private network and
 * not one to make on the open internet, and some probes cannot read a nested
 * document at all.
 *
 * So `status()` answers three keys, plus the *names* of what is failing when
 * something is — enough for an operator to know where to look, without the
 * endpoint describing the inside of the system to everybody. These tests hold
 * that line: the assertions about what is **absent** matter as much as the ones
 * about what is present.
 */
class HealthStatusEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        HealthRegistry::reset();
    }

    protected function tearDown(): void
    {
        HealthRegistry::reset();
        unset($_SERVER['REQUEST_METHOD']);
    }

    /**
     * Registers a check with a fixed verdict.
     *
     * @param string $name   Check name, which is also the key in the report
     * @param string $status One of ok / degraded / down
     */
    private function registerCheck(string $name, string $status): void
    {
        HealthRegistry::register(new class ($name, $status) implements HealthCheck {
            public function __construct(private string $name, private string $status)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function run(): HealthCheckResult
            {
                return match ($this->status) {
                    'degraded' => HealthCheckResult::degraded($this->name, 'reduced', ['driver' => 'secret-driver']),
                    'down'     => HealthCheckResult::down($this->name, 'unreachable', ['driver' => 'secret-driver']),
                    default    => HealthCheckResult::ok($this->name, 'fine', ['driver' => 'secret-driver']),
                };
            }
        });
    }

    /**
     * The action is public: no sign-in, because a monitor has no credentials.
     *
     * If it required authentication the endpoint would answer a redirect, and a
     * probe reading only the status code would score a login page as healthy.
     */
    public function testTheActionIsPublic(): void
    {
        // Arrange / Act
        $controller = new Health();

        // Assert
        $this->assertContains('status', $controller->actions);
        $this->assertNotContains('status', $controller->actions_auth);
    }

    /**
     * All checks passing gives `healthy` and a 200, with no `errors` key.
     *
     * The absent key is the assertion that matters: a consumer testing
     * `isset($body['errors'])` must not see an empty array on a well server.
     */
    public function testAllChecksPassingIsHealthyWithNoErrorList(): void
    {
        // Arrange
        $this->registerCheck('database', 'ok');
        $this->registerCheck('disk_space', 'ok');

        // Act
        $response = (new Health())->status();

        // Assert
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertSame('healthy', $body['status']);
        $this->assertArrayNotHasKey('errors', $body);
    }

    /**
     * A degraded check makes the whole answer unhealthy, with a 503.
     *
     * The flattened endpoint deliberately loses the ok/degraded/down distinction:
     * a caller that needs it has `check()`. Treating degraded as healthy here
     * would mean a reduced-capacity server never showed up on a status page.
     */
    public function testADegradedCheckIsUnhealthy(): void
    {
        // Arrange
        $this->registerCheck('cache', 'degraded');

        // Act
        $response = (new Health())->status();

        // Assert
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('unhealthy', json_decode($response->getBody(), true)['status']);
    }

    /**
     * Only the failing checks are named, and only by name.
     *
     * Two things at once: the passing check must not appear (that is a list of
     * problems, not a report), and the `details` a check attached — driver names,
     * versions, paths — must not leak into a public payload.
     */
    public function testOnlyFailingChecksAreNamedAndNoDetailsLeak(): void
    {
        // Arrange — one healthy, two not
        $this->registerCheck('database', 'ok');
        $this->registerCheck('redis', 'down');
        $this->registerCheck('disk_space', 'degraded');

        // Act
        $response = (new Health())->status();
        $raw      = $response->getBody();
        $body     = json_decode($raw, true);

        // Assert — the failing two, and not the passing one
        $this->assertContains('redis', $body['errors']);
        $this->assertContains('disk_space', $body['errors']);
        $this->assertNotContains('database', $body['errors']);

        // Assert — nothing from the checks' details or messages reached the wire
        $this->assertStringNotContainsString('secret-driver', $raw);
        $this->assertStringNotContainsString('unreachable', $raw);
    }

    /**
     * A timestamp is always present and parseable.
     *
     * A status page showing a cached answer as current is worse than showing
     * nothing, so the payload carries when it was produced.
     */
    public function testThePayloadCarriesAParseableTimestamp(): void
    {
        // Arrange
        $this->registerCheck('database', 'ok');

        // Act
        $body = json_decode((new Health())->status()->getBody(), true);

        // Assert
        $this->assertArrayHasKey('timestamp', $body);
        $this->assertNotFalse(strtotime($body['timestamp']));
    }

    /**
     * The answer is never cached.
     *
     * A cached health response is indistinguishable from a healthy one for as
     * long as the cache lives, which is exactly the window an outage happens in.
     */
    public function testTheAnswerIsNotCacheable(): void
    {
        // Arrange
        $this->registerCheck('database', 'ok');

        // Act
        $response = (new Health())->status();

        // Assert
        $this->assertStringContainsString('no-store', (string) $response->getHeaderLine('Cache-Control'));
    }

    /**
     * A CORS preflight is answered without running any check.
     *
     * A browser-based status page sends `OPTIONS` first. Answering it with the
     * report would run every probe for a request that discards the body.
     */
    public function testAPreflightIsAnsweredWithoutProbing(): void
    {
        // Arrange — a check that fails the test if it is ever run
        HealthRegistry::register(new class implements HealthCheck {
            public function getName(): string
            {
                return 'must_not_run';
            }

            public function run(): HealthCheckResult
            {
                throw new \RuntimeException('a preflight must not run the checks');
            }
        });
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';

        // Act
        $response = (new Health())->status();

        // Assert
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getBody());
        $this->assertSame('*', (string) $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
