<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\WebhookService;

/**
 * The webhook card on an application's page, rendered in each bundled theme.
 *
 * Rendered rather than grepped: the card takes rows from `WebhookService::endpointsFor()`
 * and posts back to three actions, and what matters is that each theme produces the forms,
 * the token, the right action for each row, and an endpoint URL that cannot break out of
 * the markup — an application chooses that URL, and an administrator's browser renders it.
 */
class ApplicationWebhooksCardTest extends TestCase
{
    /** What the page is told about https. */
    private bool $httpsOnly = true;

    /** Render one theme's application page with these endpoints. */
    private function render(string $theme, array $webhooks): string
    {
        \Pramnos\Http\Session::getInstance();

        $view = new \stdClass();
        $view->app          = ['appid' => 7, 'name' => 'Relying party', 'status' => 1, 'apikey' => 'k'];
        $view->tokenStats   = ['total' => 0, 'active' => 0, 'revoked' => 0];
        $view->lastUsers    = [];
        $view->capabilities = [];
        $view->webhooks     = $webhooks;
        $view->webhookTypes = WebhookService::EVENT_TYPES;
        $view->webhookRequiresHttps = $this->httpsOnly;

        $file   = dirname(__DIR__, 4) . '/scaffolding/themes/' . $theme . '/views/applications/view.html.php';
        $render = \Closure::bind(function (string $file): void {
            include $file;
        }, $view, null);

        ob_start();
        try {
            $render($file);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return ['tailwind' => ['tailwind'], 'bootstrap' => ['bootstrap'], 'plain-css' => ['plain-css']];
    }

    /** Two endpoints: one the application registered, one an administrator entered. */
    private function endpoints(): array
    {
        $events = ['pending' => 1, 'sent' => 4, 'failed' => 2, 'cancelled' => 0];

        return [
            [
                'webhook_id' => 11, 'webhook_type' => 'token_revoked', 'registered_by' => 'client',
                'endpoint_url' => 'https://app.example.com/in?"><script>alert(1)</script>',
                'events' => $events,
            ],
            [
                'webhook_id' => 12, 'webhook_type' => 'account_deleted', 'registered_by' => 'admin',
                'endpoint_url' => 'https://10.8.0.5/hooks', 'events' => $events,
            ],
        ];
    }

    /**
     * Each row carries its own new-secret and remove forms, each with the token and the
     * application's id, and the add form lists every event type.
     */
    #[DataProvider('themes')]
    public function testTheCardPostsEachActionWithTheToken(string $theme): void
    {
        // Act
        $html = $this->render($theme, $this->endpoints());

        // Assert
        $this->assertStringContainsString('id="webhooks"', $html);
        foreach (['webhookrotate/11', 'webhookrotate/12', 'webhookdelete/11', 'webhookdelete/12'] as $action) {
            $this->assertStringContainsString($action . '"', $html, "no form for $action");
        }
        $this->assertStringContainsString('applications/webhook"', $html, 'no add form');
        // Two per row plus the add form.
        $this->assertSame(5, substr_count($html, 'name="_csrf_token"'), 'a form without its token');
        $this->assertSame(5, substr_count($html, 'name="appid" value="7"'));
        foreach (WebhookService::EVENT_TYPES as $type) {
            $this->assertStringContainsString('<option value="' . $type . '"', $html);
        }
        $this->assertStringContainsString('4 sent', $html);
    }

    /**
     * Whose address it is, per row.
     */
    #[DataProvider('themes')]
    public function testTheCardSaysWhoSetEachAddress(string $theme): void
    {
        // Act
        $html = $this->render($theme, $this->endpoints());

        // Assert
        $this->assertStringContainsString('Administrator</span>', $html);
        $this->assertStringContainsString('Application</span>', $html);
    }

    /**
     * An endpoint URL is text, not markup.
     *
     * The application chose it; the administrator's browser renders it.
     */
    #[DataProvider('themes')]
    public function testAnEndpointUrlCannotBreakOutOfTheMarkup(string $theme): void
    {
        // Act
        $html = $this->render($theme, $this->endpoints());

        // Assert
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    /**
     * With no endpoints the card says how one arrives, and still offers the form.
     */
    #[DataProvider('themes')]
    public function testWithNoEndpointsTheCardExplainsAndOffersTheForm(string $theme): void
    {
        // Act
        $html = $this->render($theme, []);

        // Assert
        $this->assertStringContainsString('No endpoints.', $html);
        $this->assertStringContainsString('/Webhook/register', $html);
        $this->assertStringContainsString('applications/webhook"', $html);
        $this->assertStringNotContainsString('webhookrotate', $html);
    }

    /**
     * The form's own check follows the setting, so a browser does not refuse an `http://`
     * address the server would accept.
     */
    #[DataProvider('themes')]
    public function testTheFormsSchemeCheckFollowsTheSetting(string $theme): void
    {
        // Act
        $strict = $this->render($theme, []);
        $this->httpsOnly = false;
        $relaxed = $this->render($theme, []);

        // Assert
        $this->assertStringContainsString('pattern="https://.*"', $strict);
        $this->assertStringContainsString('It must be https.', $strict);
        $this->assertStringContainsString('pattern="https?://.*"', $relaxed);
        $this->assertStringNotContainsString('It must be https.', $relaxed);
    }
}
