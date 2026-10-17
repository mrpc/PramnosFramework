<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;

/**
 * The consent screen speaks the application's language: its own words, the scopes' categories
 * and their descriptions all go through `t()`.
 *
 * It is the page where a person decides what to hand an application, and it was the one page of
 * the flow still in English whatever the site's language: a Greek customer was asked to "Allow"
 * a list of English sentences.
 */
final class ConsentScreenTranslatesTest extends TestCase
{
    /** @var array<string, string> The catalogue before the test, put back after. */
    private array $saved = [];

    protected function setUp(): void
    {
        $language    = \Pramnos\Framework\Factory::getLanguage();
        $this->saved = (array) (new \ReflectionProperty($language, '_strings'))->getValue($language);
        $language->addlang([
            'Authorize %s'                       => 'Εξουσιοδότηση: %s',
            'This application will be able to:'  => 'Η εφαρμογή θα μπορεί:',
            'Allow'                              => 'Αποδοχή',
            'Deny'                               => 'Άρνηση',
            'Personal User Data'                 => 'Προσωπικά στοιχεία',
            'Read your profile, fixture wording' => 'Να διαβάζει το προφίλ σας',
            'Use a different account'            => 'Άλλος λογαριασμός',
        ]);
    }

    protected function tearDown(): void
    {
        $language = \Pramnos\Framework\Factory::getLanguage();
        (new \ReflectionProperty($language, '_strings'))->setValue($language, $this->saved);
        $_GET = [];
    }

    /**
     * Each theme's consent screen, rendered with a translated catalogue, shows the translations
     * and none of the English it replaced.
     */
    public function testEveryThemesConsentScreenIsTranslated(): void
    {
        // Arrange
        $_GET = ['client_id' => 'client-42', 'redirect_uri' => 'https://client.example/cb'];
        $_SERVER['REQUEST_URI'] = '/oauth/authorize';
        $view = new class () {
            public object $application;
            public object $user;
            /** @var array<string, array<string, array<string, mixed>>> */
            public array $allScopes = ['Personal User Data' => ['profile' => ['description' => 'Read your profile, fixture wording']]];
            /** @var list<string> */
            public array $requestedScopes = ['profile'];
        };
        $view->application = (object) ['name' => 'Claude'];
        $view->user        = (object) ['username' => 'maria', 'email' => 'm@example.test'];

        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            // Act
            $file = ROOT . '/scaffolding/themes/' . $theme . '/views/OAuth2/authorize.html.php';
            $html = (function () use ($file): string {
                ob_start();
                include $file;

                return (string) ob_get_clean();
            })->call($view);

            // Assert
            foreach (['Εξουσιοδότηση: Claude', 'Η εφαρμογή θα μπορεί:', 'Αποδοχή', 'Άρνηση', 'Προσωπικά στοιχεία', 'Να διαβάζει το προφίλ σας', 'Άλλος λογαριασμός'] as $greek) {
                $this->assertStringContainsString($greek, $html, $theme);
            }
            foreach (['>Allow<', '>Deny<', 'This application will be able to', 'Read your profile, fixture wording'] as $english) {
                $this->assertStringNotContainsString($english, $html, $theme);
            }
        }
    }
}
