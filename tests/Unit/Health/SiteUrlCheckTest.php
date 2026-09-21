<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Health\Checks\SiteUrlCheck;
use Pramnos\Http\SiteUrl;

/**
 * The check that says whether this installation knows its own public address.
 *
 * A web request infers one, so nothing on the site reports it missing — and the process
 * that needs it most, the scheduler, has no `Host` header to infer from and puts
 * `http:///uploads/x.jpg` into an email instead. The three states are therefore worth
 * distinguishing: configured, inferred, and nothing at all.
 */
#[CoversClass(SiteUrlCheck::class)]
class SiteUrlCheckTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    private string|false $appUrl;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->appUrl = getenv('APP_URL');

        putenv('APP_URL');
        unset($_ENV['APP_URL']);
        foreach (['HTTP_HOST', 'SERVER_NAME', 'SCRIPT_NAME',
                  'SCRIPT_FILENAME', 'DOCUMENT_ROOT'] as $key) {
            unset($_SERVER[$key]);
        }
        SiteUrl::reset();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        if (is_string($this->appUrl)) {
            putenv('APP_URL=' . $this->appUrl);
        } else {
            putenv('APP_URL');
        }
        unset($_ENV['APP_URL']);
        SiteUrl::reset();
    }

    /**
     * Configured is `ok`, and the resolved address is reported.
     *
     * The address is in the result because the common way to get this wrong is not
     * leaving it unset but setting it to the wrong thing — a staging host copied into
     * production. One line of `health:check` output showing what the application believes
     * is what makes that visible.
     */
    public function testAConfiguredRootIsOkAndIsReported(): void
    {
        // Arrange
        putenv('APP_URL=https://example.com');
        SiteUrl::reset();

        // Act
        $result = (new SiteUrlCheck())->run();

        // Assert
        $this->assertSame('site_url', $result->name);
        $this->assertSame('ok', $result->status->value);
        $this->assertSame('https://example.com/', $result->details['url']);
        $this->assertSame('configured', $result->details['source']);
    }

    /**
     * Inferred from a request is `degraded`, and says why that is not enough.
     *
     * Every page works, so `down` would page somebody for a site that is up — and a check
     * that cries wolf gets muted. What is broken is the half nobody is looking at.
     */
    public function testARootInferredFromTheRequestIsDegraded(): void
    {
        // Arrange
        $_SERVER['HTTP_HOST']       = 'example.com';
        $_SERVER['SCRIPT_NAME']     = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = '/srv/site/www/index.php';
        $_SERVER['DOCUMENT_ROOT']   = '/srv/site/www';
        SiteUrl::reset();

        // Act
        $result = (new SiteUrlCheck())->run();

        // Assert
        $this->assertSame('degraded', $result->status->value);
        $this->assertSame('request', $result->details['source']);
        $this->assertStringContainsString('scheduled task', $result->message);
        $this->assertStringContainsString('APP_URL', $result->details['fix']);
    }

    /**
     * Nothing at all is `degraded` too, and names the setting to add.
     *
     * This is the state a CLI health check reports on an installation that has never set
     * `APP_URL` — which is the state the scheduler is actually in, every run, on every
     * such installation.
     */
    public function testNoRootAtAllIsDegradedAndNamesTheFix(): void
    {
        // Act — no host, no configuration
        $result = (new SiteUrlCheck())->run();

        // Assert
        $this->assertSame('degraded', $result->status->value);
        $this->assertSame('', $result->details['url']);
        $this->assertSame('none', $result->details['source']);
        $this->assertStringContainsString('APP_URL', $result->message);
    }

    /**
     * It is registered by default.
     *
     * A health check nobody registers reports nothing, which is the same as not having
     * written it — and this one exists precisely because the condition is invisible.
     */
    public function testItIsRegisteredWithTheDefaultChecks(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Application/Application.php'
        );

        // Act + Assert
        $this->assertStringContainsString(
            'new \Pramnos\Health\Checks\SiteUrlCheck()',
            $source
        );
    }
}
