<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Html\StatTile;

/**
 * The dashboard tile: a figure, its label, a note, a link, a state, and a progress variant.
 *
 * The markup is the contract the three themes style, so it is asserted exactly where a theme
 * depends on it: the `pf-stat-tile` hooks, the modifier for the state, and the progress bar.
 */
#[CoversClass(StatTile::class)]
class StatTileTest extends TestCase
{
    /** Keep the application's component classes as they were. */
    private mixed $saved = null;

    private bool $hadKey = false;

    protected function setUp(): void
    {
        $app = Application::getInstance();
        $this->hadKey = array_key_exists('component_classes', $app->applicationInfo);
        $this->saved  = $this->hadKey ? $app->applicationInfo['component_classes'] : null;
        unset($app->applicationInfo['component_classes']);
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();
        if ($this->hadKey) {
            $app->applicationInfo['component_classes'] = $this->saved;
        } else {
            unset($app->applicationInfo['component_classes']);
        }
    }

    /**
     * A tile is its label, its value and its note, in the hooks the themes style.
     */
    public function testATileCarriesItsLabelValueAndNote(): void
    {
        // Act
        $html = StatTile::render('MRR', '€1,240', 'ARR €14,880');

        // Assert
        $this->assertSame(
            '<div class="pf-stat-tile"><span class="pf-stat-tile__label">MRR</span>'
            . '<span class="pf-stat-tile__value">€1,240</span>'
            . '<span class="pf-stat-tile__note">ARR €14,880</span></div>',
            $html
        );
    }

    /**
     * With a link the tile is the link; with a state it carries the modifier and data-state.
     */
    public function testALinkAndAState(): void
    {
        // Act
        $html = StatTile::render('Failed today', 3, link: '/admin/Queue?show=failed', state: StatTile::CRITICAL);

        // Assert
        $this->assertStringStartsWith('<a class="pf-stat-tile pf-stat-tile--critical" href="/admin/Queue?show=failed" data-state="critical">', $html);
        $this->assertStringEndsWith('</a>', $html);
        $this->assertStringContainsString('<span class="pf-stat-tile__value">3</span>', $html);
        $this->assertStringNotContainsString('__note', $html, 'no empty note line');
    }

    /**
     * A state that is not one of the three is ignored rather than emitted as a class.
     */
    public function testAnUnknownStateIsIgnored(): void
    {
        // Act
        $html = StatTile::render('X', '1', state: 'purple" onclick="x');

        // Assert
        $this->assertStringStartsWith('<div class="pf-stat-tile">', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    /**
     * Every text is escaped, the link included.
     */
    public function testEverythingIsEscaped(): void
    {
        // Act
        $html = StatTile::render('<b>L</b>', '<i>1</i>', '<u>n</u>', '/x?a=1&b="2"');

        // Assert
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('<u>', $html);
        $this->assertStringContainsString('href="/x?a=1&amp;b=&quot;2&quot;"', $html);
    }

    /** @return array<string, array{float, float, string, ?int, bool}> */
    public static function progressCases(): array
    {
        return [
            'part of the way' => [18, 50, '18 / 50', 36, false],
            'reached'         => [50, 50, '50 / 50', 100, true],
            'past it'         => [72, 50, '72 / 50', 100, true],
            'a fraction'      => [12.5, 40, '12.5 / 40', 31, false],
            'no target'       => [7, 0, '7', null, false],
        ];
    }

    /**
     * Progress shows current over target, a bar as a percentage capped at 100, and is good
     * once the target is reached.
     */
    #[DataProvider('progressCases')]
    public function testProgress(float $current, float $target, string $value, ?int $percent, bool $good): void
    {
        // Act
        $html = StatTile::progress('Paying', $current, $target, 'target');

        // Assert
        $this->assertStringContainsString('<span class="pf-stat-tile__value">' . $value . '</span>', $html);
        if ($percent === null) {
            $this->assertStringNotContainsString('<progress', $html);
        } else {
            $this->assertStringContainsString('value="' . $percent . '" max="100" aria-label="Paying: ' . $percent . '%"', $html);
        }
        $this->assertSame($good, str_contains($html, 'pf-stat-tile--good'));
    }

    /**
     * A grid wraps the tiles in its hook.
     */
    public function testAGridWrapsTheTiles(): void
    {
        // Act & Assert
        $this->assertSame('<div class="pf-stat-grid">ab</div>', StatTile::grid(['a', 'b']));
    }

    /**
     * A project that names its own classes gets them, modifiers included.
     */
    public function testConfiguredClassesAreUsed(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['component_classes'] = ['stat_tile' => 'kpi', 'stat_grid' => 'kpis'];

        // Act
        $html = StatTile::grid([StatTile::render('X', '1', state: StatTile::GOOD)]);

        // Assert
        $this->assertStringStartsWith('<div class="kpis"><div class="kpi kpi--good" data-state="good"><span class="kpi__label">', $html);
    }

    /**
     * Every scaffold theme styles the hooks, the states and tabular digits.
     */
    public function testEveryThemeStylesTheTile(): void
    {
        foreach (['bootstrap', 'tailwind', 'plain-css'] as $theme) {
            // Act
            $css = (string) file_get_contents(dirname(__DIR__, 4) . '/scaffolding/themes/' . $theme . '/style.css');

            // Assert
            foreach (['.pf-stat-grid', '.pf-stat-tile__value', 'tabular-nums', '.pf-stat-tile--good', '.pf-stat-tile--warning', '.pf-stat-tile--critical'] as $rule) {
                $this->assertStringContainsString($rule, $css, $theme . ' lacks ' . $rule);
            }
        }
    }
}
