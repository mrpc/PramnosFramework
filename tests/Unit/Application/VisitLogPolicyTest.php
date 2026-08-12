<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Application\VisitLogPolicy;

/**
 * How much of what a visitor does gets written down.
 *
 * The framework writes one `tokenactions` row per request — URL, method,
 * parameters, status, duration. For an application that needs an audit trail
 * that is exactly right. For one that does not, it is a table growing by a row
 * per request for ever, holding a copy of every request body ever posted, and a
 * page making ten API calls writes eleven rows.
 *
 * Nobody chose that. These tests pin down the choice now that there is one —
 * and, most of all, that the default still does what installations have today.
 */
#[CoversClass(VisitLogPolicy::class)]
class VisitLogPolicyTest extends TestCase
{
    /** @var mixed The setting as the suite had it */
    private $original = null;

    /** @var array<string, mixed> The request headers as the suite had them */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = Settings::getSetting('visit_log');
        $this->server   = $_SERVER;
    }

    protected function tearDown(): void
    {
        Settings::setSetting('visit_log', $this->original, false);
        $_SERVER = $this->server;
        parent::tearDown();
    }

    /**
     * Pretend the request arrived the way a browser navigation does.
     */
    private function asNavigation(): void
    {
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    /**
     * Pretend the request is the page talking to the server.
     */
    private function asXhr(): void
    {
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'empty';
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    /**
     * With nothing configured, everything is logged.
     *
     * The behaviour every installation has today. A setting that quietly
     * changed what an audit log contains would be worse than no setting.
     */
    public function testTheDefaultLogsEverything(): void
    {
        // Arrange
        Settings::setSetting('visit_log', false, false);

        // Act & Assert
        $this->assertSame(VisitLogPolicy::ALL, VisitLogPolicy::mode());
        $this->assertTrue(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_WEB));
        $this->assertTrue(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_API));
    }

    /**
     * `none` logs nothing at all.
     */
    public function testNoneLogsNothing(): void
    {
        // Arrange
        Settings::setSetting('visit_log', 'none', false);

        // Act & Assert
        $this->assertFalse(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_WEB));
        $this->assertFalse(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_API));
    }

    /**
     * `api` logs the API and nothing else.
     *
     * The setting for an application whose audit requirement is about its API —
     * which is the usual shape of the requirement.
     */
    public function testApiLogsOnlyTheApi(): void
    {
        // Arrange
        Settings::setSetting('visit_log', 'api', false);

        // Act & Assert
        $this->assertTrue(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_API));
        $this->assertFalse(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_WEB));
    }

    /**
     * `pages` logs web requests and not the API.
     *
     * Including the XHR a page makes: that is what separates it from
     * `navigations`.
     */
    public function testPagesLogsWebRequestsIncludingItsXhr(): void
    {
        // Arrange
        Settings::setSetting('visit_log', 'pages', false);
        $this->asXhr();

        // Act & Assert
        $this->assertTrue(
            VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_WEB),
            'a page\'s own call is still a web request'
        );
        $this->assertFalse(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_API));
    }

    /**
     * `navigations` logs pages a visitor opened, and not what those pages then do.
     *
     * On a datatable-heavy admin panel this is the difference between eleven
     * rows for a page view and one — and the ten it drops are not a visitor
     * going anywhere.
     */
    public function testNavigationsExcludesAPagesOwnCalls(): void
    {
        // Arrange
        Settings::setSetting('visit_log', 'navigations', false);

        // Act & Assert — the page itself
        $this->asNavigation();
        $this->assertTrue(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_WEB));

        // ...and the datatable call it makes a second later
        $this->asXhr();
        $this->assertFalse(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_WEB));
    }

    /**
     * An older client that sends nothing is treated as a navigation.
     *
     * Being logged is the safe failure here: an audit log that silently omits
     * whatever the framework could not classify is an audit log nobody can
     * trust.
     */
    public function testAClientThatSaysNothingIsLogged(): void
    {
        // Arrange
        Settings::setSetting('visit_log', 'navigations', false);
        unset(
            $_SERVER['HTTP_SEC_FETCH_DEST'],
            $_SERVER['HTTP_X_REQUESTED_WITH'],
            $_SERVER['HTTP_ACCEPT']
        );

        // Act & Assert
        $this->assertTrue(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_WEB));
    }

    /**
     * The older `X-Requested-With` header is understood.
     *
     * jQuery and DataTables set it, and plenty of deployed code still uses
     * both.
     */
    public function testTheLegacyXhrHeaderIsUnderstood(): void
    {
        // Arrange
        Settings::setSetting('visit_log', 'navigations', false);
        unset($_SERVER['HTTP_SEC_FETCH_DEST']);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        // Act & Assert
        $this->assertFalse(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_WEB));
    }

    /**
     * An `Accept` header that asks for JSON is not a navigation.
     */
    public function testAnAcceptHeaderIsTheLastResort(): void
    {
        // Arrange
        Settings::setSetting('visit_log', 'navigations', false);
        unset($_SERVER['HTTP_SEC_FETCH_DEST'], $_SERVER['HTTP_X_REQUESTED_WITH']);

        // Act & Assert
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertFalse(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_WEB));

        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
        $this->assertTrue(VisitLogPolicy::shouldLog(VisitLogPolicy::CONTEXT_WEB));
    }

    /**
     * The spellings somebody would reasonably write all work.
     *
     * A setting nobody can remember the vocabulary of gets set wrong, and this
     * one fails safe towards *more* logging — so a typo would quietly restore
     * the volume the operator was trying to reduce. Accepting the obvious
     * synonyms is cheaper than explaining that.
     */
    #[DataProvider('spellings')]
    public function testTheObviousSpellingsAreAccepted($configured, string $expected): void
    {
        // Arrange
        Settings::setSetting('visit_log', $configured, false);

        // Act & Assert
        $this->assertSame($expected, VisitLogPolicy::mode());
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function spellings(): array
    {
        return [
            'off'         => ['off',         VisitLogPolicy::NONE],
            'no'          => ['no',          VisitLogPolicy::NONE],
            'false string'=> ['false',       VisitLogPolicy::NONE],
            'zero'        => ['0',           VisitLogPolicy::NONE],
            'empty'       => ['',            VisitLogPolicy::NONE],
            'boolean true'=> [true,          VisitLogPolicy::ALL],
            'yes'         => ['yes',         VisitLogPolicy::ALL],
            'one'         => ['1',           VisitLogPolicy::ALL],
            'ALL upper'   => ['ALL',         VisitLogPolicy::ALL],
            'navigation'  => ['navigation',  VisitLogPolicy::NAVIGATIONS],
            'navigations' => ['navigations', VisitLogPolicy::NAVIGATIONS],
            'page'        => ['page',        VisitLogPolicy::PAGES],
            'web'         => ['web',         VisitLogPolicy::PAGES],
            'padded'      => ['  api  ',     VisitLogPolicy::API],
        ];
    }

    /**
     * A value nobody recognises logs everything rather than nothing.
     *
     * A typo in a settings table must not switch off the audit log — the
     * failure would be discovered by somebody going to look for the one request
     * that is missing.
     */
    public function testAnUnrecognisedValueFallsBackToLoggingEverything(): void
    {
        // Arrange
        Settings::setSetting('visit_log', 'sometimes', false);

        // Act & Assert
        $this->assertSame(VisitLogPolicy::ALL, VisitLogPolicy::mode());
    }
}
