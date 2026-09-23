<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Health;
use Pramnos\Health\HealthCheck;
use Pramnos\Health\HealthCheckResult;
use Pramnos\Health\HealthRegistry;
use Pramnos\Health\HealthStatus;

/**
 * Which statuses page somebody, and which only say something.
 *
 * `SessionStorageCheck` carried this sentence in its own doc-block:
 *
 * > **Degraded, not down.** A single-server installation is not broken and must not be
 * > paged for — most installations are single-server, and a check that cries wolf gets
 * > muted.
 *
 * And `degraded` was answered as **503**, which is a page. So the check did the one thing
 * it documented itself as avoiding, to the majority case it named: a correct installation's
 * `/health/check` went from 200 to 503 the minute it shipped, and an uptime monitor pointed
 * at that endpoint — which is what it is for, and what the scaffolded README recommends —
 * alerted permanently on nothing.
 *
 * The sentence was right and nothing enforced it. `HealthStatus::Notice` is the category it
 * wanted, `isHealthy()` is the one place that decides, and this file is what holds the
 * decision — because a doc-block cannot.
 */
class HealthStatusPagingTest extends TestCase
{
    protected function setUp(): void
    {
        HealthRegistry::reset();
    }

    protected function tearDown(): void
    {
        HealthRegistry::reset();
    }

    /** Registers one check answering a fixed status. */
    private function registerCheck(string $name, HealthStatus $status): void
    {
        HealthRegistry::register(new class ($name, $status) implements HealthCheck {
            public function __construct(private string $name, private HealthStatus $status)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function run(): HealthCheckResult
            {
                return new HealthCheckResult($this->status, $this->name, 'fixed');
            }
        });
    }

    /**
     * A `notice` is 200 on both endpoints — the regression, stated as an assertion.
     */
    public function testANoticeDoesNotPage(): void
    {
        // Arrange — exactly the shape of a correct single-server installation
        $this->registerCheck('session_storage', HealthStatus::Notice);
        $controller = new Health();

        // Act
        $check  = $controller->check();
        $status = $controller->status();

        // Assert
        $this->assertSame(200, $check->getStatusCode(), 'a correct installation answered 503');
        $this->assertSame(200, $status->getStatusCode());
        $this->assertStringContainsString('"status":"notice"', (string) $check->getBody());
    }

    /**
     * `degraded` and `down` still page.
     *
     * The control. A change that answered 200 for everything would satisfy the test above
     * and turn the endpoint into a constant.
     */
    public function testDegradedAndDownStillPage(): void
    {
        foreach ([HealthStatus::Degraded, HealthStatus::Down] as $status) {
            // Arrange
            HealthRegistry::reset();
            $this->registerCheck('something', $status);

            // Act
            $controller = new Health();

            // Assert
            $this->assertSame(503, $controller->check()->getStatusCode(), $status->value);
            $this->assertSame(503, $controller->status()->getStatusCode(), $status->value);
        }
    }

    /**
     * One notice beside an ok leaves the overall verdict at notice, not ok.
     *
     * The point of the status is that it is *visible*. A severity that collapsed into `ok`
     * would be a comment, and the detail — which store is actually in use — is the value of
     * the check.
     */
    public function testANoticeIsVisibleInTheOverallVerdict(): void
    {
        // Arrange
        $this->registerCheck('fine', HealthStatus::Ok);
        $this->registerCheck('worth_saying', HealthStatus::Notice);

        // Act
        $report = HealthRegistry::runAll();

        // Assert
        $this->assertSame('notice', $report['status']);
    }

    /**
     * And a real problem beside a notice is still a real problem.
     */
    public function testADegradedCheckOutranksANotice(): void
    {
        // Arrange
        $this->registerCheck('worth_saying', HealthStatus::Notice);
        $this->registerCheck('broken', HealthStatus::Degraded);

        // Act
        $report = HealthRegistry::runAll();

        // Assert
        $this->assertSame('degraded', $report['status']);
    }

    /**
     * `health:check` succeeds on a notice, fails on a degraded.
     *
     * The third copy of the same policy. This command runs in deploy steps and in CI, so a
     * status that exists to say "correct here, and here is what changes on a second server"
     * must not fail the build either — for exactly the reason it must not answer 503.
     */
    public function testTheConsoleExitCodeFollowsTheSameRule(): void
    {
        // Arrange — exitCode() is private, which is the right visibility for it; the rule
        // it encodes is what matters and is reachable no other way.
        $command = new \Pramnos\Console\Commands\HealthCheck();
        $method  = new \ReflectionMethod($command, 'exitCode');

        // Act + Assert
        $this->assertSame(0, $method->invoke($command, 'ok'));
        $this->assertSame(0, $method->invoke($command, 'notice'), 'a notice failed the build');
        $this->assertSame(1, $method->invoke($command, 'degraded'));
        $this->assertSame(2, $method->invoke($command, 'down'));
        $this->assertSame(2, $method->invoke($command, 'something-from-the-future'));
    }

    /**
     * The severity order, asserted directly.
     *
     * `worst()` is a rank table, and a rank table is the kind of thing that gets a new row
     * appended in the wrong place. Ok < Notice < Degraded < Down.
     */
    public function testTheSeverityOrder(): void
    {
        // Act + Assert
        $this->assertSame(HealthStatus::Notice, HealthStatus::Ok->worst(HealthStatus::Notice));
        $this->assertSame(HealthStatus::Degraded, HealthStatus::Notice->worst(HealthStatus::Degraded));
        $this->assertSame(HealthStatus::Down, HealthStatus::Degraded->worst(HealthStatus::Down));

        // The named constructor, since every other test here builds the result directly
        $result = HealthCheckResult::notice('a_check', 'worth saying', ['k' => 'v']);
        $this->assertSame(HealthStatus::Notice, $result->status);
        $this->assertSame(['k' => 'v'], $result->details);

        $this->assertTrue(HealthStatus::Ok->isHealthy());
        $this->assertTrue(HealthStatus::Notice->isHealthy());
        $this->assertFalse(HealthStatus::Degraded->isHealthy());
        $this->assertFalse(HealthStatus::Down->isHealthy());
    }
}
