<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Application;

/**
 * The warning an administrator sees for a client that cannot sign anyone in.
 *
 * A client that cannot keep a secret — public, or with none stored — is refused at
 * `/oauth/authorize` until it has a registered redirect URI. That refusal lands on the person
 * trying to sign in, who can do nothing about it; the administrator who can is the one who has
 * to be told. So the application's page and its edit form say so in every bundled theme, and
 * link to the guide for why.
 *
 * Rendered rather than grepped, because the condition is evaluated in the template.
 */
#[CoversClass(Application::class)]
class ApplicationCallbackWarningTest extends TestCase
{
    private const WARNING = 'This application cannot sign anyone in yet.';

    /**
     * Which rows need a registration.
     *
     * Public, or no secret stored — and no usable callback. A confidential client with a
     * secret never does, and any registered callback satisfies the rule; a callback column
     * holding only separators or a script scheme is no registration.
     */
    #[DataProvider('rows')]
    public function testWhichClientsNeedARegisteredCallback(array $row, bool $cannotKeep, bool $needs): void
    {
        // Act + Assert
        $this->assertSame($cannotKeep, Application::cannotKeepASecret($row));
        $this->assertSame($needs, Application::needsARegisteredCallback($row));
    }

    /** @return array<string, array{0:array<string,mixed>,1:bool,2:bool}> */
    public static function rows(): array
    {
        return [
            'confidential, secret, no callback'   => [['is_confidential' => 1, 'apisecret' => 'h', 'callback' => null], false, false],
            'public, secret, no callback'         => [['is_confidential' => 0, 'apisecret' => 'h', 'callback' => ''], true, true],
            'confidential, no secret, no callback'=> [['is_confidential' => 1, 'apisecret' => null, 'callback' => null], true, true],
            'public, callback'                    => [['is_confidential' => 0, 'apisecret' => null, 'callback' => 'https://a.test/cb'], true, false],
            'public, only separators'             => [['is_confidential' => 0, 'apisecret' => 'h', 'callback' => ' , '], true, true],
            'public, only a script scheme'        => [['is_confidential' => 0, 'apisecret' => 'h', 'callback' => 'javascript:alert(1)'], true, true],
            'column absent: confidential default' => [['apisecret' => 'h'], false, false],
        ];
    }

    /**
     * The application's page warns, and links to the guide.
     */
    #[DataProvider('themes')]
    public function testTheApplicationPageWarnsAboutAPublicClientWithNoCallback(string $theme): void
    {
        // Act
        $html = $this->render($theme, 'view', $this->publicWithoutCallback());

        // Assert
        $this->assertStringContainsString(self::WARNING, $html);
        $this->assertStringContainsString(Application::CALLBACK_GUIDE_URL, $html);
    }

    /**
     * The edit form warns too — it is where the fix is made.
     */
    #[DataProvider('themes')]
    public function testTheEditFormWarnsAboutAPublicClientWithNoCallback(string $theme): void
    {
        // Act
        $html = $this->render($theme, 'edit', $this->publicWithoutCallback());

        // Assert
        $this->assertStringContainsString(self::WARNING, $html);
    }

    /**
     * A client that is fine gets no warning on either screen.
     *
     * The control: a warning shown for every client teaches administrators to ignore it.
     */
    #[DataProvider('themes')]
    public function testASoundClientGetsNoWarning(string $theme): void
    {
        // Arrange — public, but registered
        $app = ['callback' => 'https://client.example/cb'] + $this->publicWithoutCallback();

        // Act
        $page = $this->render($theme, 'view', $app);
        $form = $this->render($theme, 'edit', $app);

        // Assert
        $this->assertStringNotContainsString(self::WARNING, $page);
        $this->assertStringNotContainsString(self::WARNING, $form);
    }

    /**
     * The fields that decide it explain the difference where they are filled in.
     *
     * A hover on each label, and a link to the guide in the help under them — the warning is
     * too late to be the first time an administrator hears of the rule.
     */
    #[DataProvider('themes')]
    public function testTheFieldsExplainTheDifference(string $theme): void
    {
        // Act — a new application, so no warning is involved
        $html = $this->render($theme, 'edit', []);

        // Assert
        $this->assertMatchesRegularExpression('/title="Confidential: runs on a server[^"]*">Client Type</', $html);
        $this->assertMatchesRegularExpression('/title="The exact addresses[^"]*">OAuth2 Redirect URI/', $html);
        $this->assertSame(2, substr_count($html, Application::CALLBACK_GUIDE_URL));
    }

    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return ['tailwind' => ['tailwind'], 'bootstrap' => ['bootstrap'], 'plain-css' => ['plain-css']];
    }

    /** @return array<string, mixed> */
    private function publicWithoutCallback(): array
    {
        return [
            'appid' => 7, 'name' => 'Mobile app', 'status' => 1, 'apikey' => 'k',
            'is_confidential' => 0, 'apisecret' => '$2y$10$h', 'callback' => null,
        ];
    }

    /** Render one theme's application template with this row. */
    private function render(string $theme, string $template, array $app): string
    {
        \Pramnos\Http\Session::getInstance();

        $view = new \stdClass();
        $view->app          = $app;
        $view->application  = $app;
        $view->tokenStats   = ['total' => 0, 'active' => 0, 'revoked' => 0];
        $view->lastUsers    = [];
        $view->capabilities = [];
        $view->webhooks     = [];
        $view->webhookTypes = \Pramnos\Auth\WebhookService::EVENT_TYPES;
        $view->webhookRequiresHttps = true;
        $view->message      = '';
        $view->error        = '';

        $file   = dirname(__DIR__, 4) . '/scaffolding/themes/' . $theme . '/views/applications/' . $template . '.html.php';
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
}
