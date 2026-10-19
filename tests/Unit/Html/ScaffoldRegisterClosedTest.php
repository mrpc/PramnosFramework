<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The registration view, drawn: `$this` is what the view reads. */
class RegisterViewProbe
{
    /** @var bool|null */
    public $registrationOpen = null;

    /** @var string|null */
    public $error = null;

    /** @param string $flashed What the flashed errors print, '' for none */
    public function __construct(private string $flashed = '')
    {
    }

    /** Whether errors were flashed. */
    public function hasErrors(): bool
    {
        return $this->flashed !== '';
    }

    /** The flashed errors, as the view prints them. */
    public function _printErrors(): string
    {
        return $this->flashed;
    }

    /** A partial: the brand and home link are drawn by their own tests. */
    public function insert(string $partial): void
    {
    }

    /** The view's HTML. */
    public function draw(string $file): string
    {
        ob_start();
        include $file;

        return (string) ob_get_clean();
    }
}

/**
 * With registration closed, the registration card says so — once, as a notice — instead of
 * showing an empty card, or the same refusal twice when it arrives both as the error key and
 * flashed.
 */
#[CoversNothing]
final class ScaffoldRegisterClosedTest extends TestCase
{
    private const CLOSED = 'Registration is not open on this server.';

    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return ['tailwind' => ['tailwind'], 'bootstrap' => ['bootstrap'], 'plain-css' => ['plain-css']];
    }

    /** The theme's registration view. */
    private static function view(string $theme): string
    {
        return dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/register/register.html.php';
    }

    /**
     * Closed, with the controller's error key: one notice, no error box and no form.
     */
    #[DataProvider('themes')]
    public function testAClosedRegistrationSaysSoOnce(string $theme): void
    {
        // Arrange
        $probe = new RegisterViewProbe();
        $probe->registrationOpen = false;
        $probe->error = 'registration_closed';

        // Act
        $html = $probe->draw(self::view($theme));

        // Assert
        $this->assertSame(1, substr_count($html, self::CLOSED), 'said once');
        $this->assertStringContainsString('role="status"', $html, 'a notice, not an error');
        $this->assertStringNotContainsString('id="form-error"', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    /**
     * Closed with no error key at all — a controller that only says it is closed — still says so.
     */
    #[DataProvider('themes')]
    public function testAClosedRegistrationWithNoErrorIsNotAnEmptyCard(string $theme): void
    {
        // Arrange
        $probe = new RegisterViewProbe();
        $probe->registrationOpen = false;

        // Act
        $html = $probe->draw(self::view($theme));

        // Assert
        $this->assertSame(1, substr_count($html, self::CLOSED));
    }

    /**
     * When the refusal was flashed as well, the flashed one is the only one.
     */
    #[DataProvider('themes')]
    public function testAFlashedRefusalIsNotRepeated(string $theme): void
    {
        // Arrange
        $probe = new RegisterViewProbe('<p>' . self::CLOSED . '</p>');
        $probe->registrationOpen = false;
        $probe->error = 'registration_closed';

        // Act
        $html = $probe->draw(self::view($theme));

        // Assert
        $this->assertSame(1, substr_count($html, self::CLOSED), 'flashed and inline would be twice');
    }
}
