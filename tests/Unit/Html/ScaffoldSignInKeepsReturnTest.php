<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sign-in forms carry the return address in their own URL, not only in a hidden field.
 *
 * After a wrong password the address bar shows the URL the form posted to. When that was a bare
 * `/login`, a refresh, a restored tab or a bookmark loaded the form without the return address,
 * and the next successful sign-in went to the dashboard instead of back to the application.
 * `Account::returnUrl()` already reads `?return=` when the field is absent; the forms had to
 * put it there.
 */
class ScaffoldSignInKeepsReturnTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function forms(): array
    {
        $cases = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $cases["$theme login"] = [$theme, 'login/login.html.php'];
            $cases["$theme two-step"] = [$theme, 'login/login_2fa.html.php'];
        }

        return $cases;
    }

    /**
     * Render a view with a return address and nothing else the page could want.
     */
    private function render(string $theme, string $relative, string $return): string
    {
        $path = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/' . $relative;
        $view = new class ($return) {
            /** @param string $returnUrl The sanitised return address */
            public function __construct(public string $returnUrl)
            {
            }

            /** Any other view property: absent. */
            public function __get(string $name): mixed
            {
                return null;
            }

            /** Any other view property: absent. */
            public function __isset(string $name): bool
            {
                return false;
            }

            /** Any view helper (`hasMessages()` and the like): nothing to show. */
            public function __call(string $name, array $arguments): mixed
            {
                return null;
            }
        };

        ob_start();
        try {
            (function () use ($path): void {
                include $path;
            })->call($view);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * Every form that posts back to the sign-in flow has `?return=` in its action, encoded.
     */
    #[DataProvider('forms')]
    public function testEveryFormPostsTheReturnInItsUrl(string $theme, string $relative): void
    {
        // Arrange
        $return = '/oauth/authorize?client_id=a&state=x';

        // Act
        $html = $this->render($theme, $relative, $return);
        preg_match_all('~<form[^>]*action="([^"]*/(?:login|verify)[^"]*)"~', $html, $actions);

        // Assert
        $this->assertNotEmpty($actions[1], 'no sign-in form rendered');
        foreach ($actions[1] as $action) {
            $this->assertStringEndsWith('?return=' . rawurlencode($return), html_entity_decode($action), $action);
        }
    }

    /**
     * With no return address there is no empty `?return=` either.
     */
    #[DataProvider('forms')]
    public function testNoReturnMeansNoQuery(string $theme, string $relative): void
    {
        // Act
        $html = $this->render($theme, $relative, '');

        // Assert
        $this->assertStringNotContainsString('?return=', $html);
    }
}
