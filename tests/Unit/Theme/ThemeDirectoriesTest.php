<?php

declare(strict_types=1);

namespace Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Theme\Theme;

/**
 * Where the framework looks for themes.
 *
 * Two places, merged: `app/themes/` for a project's own, and `ROOT/themes/` for the older layout.
 * A project may legitimately have both, and the reason this exists at all is a defect it was
 * written to fix — searching `ROOT/themes` alone returned an empty array **silently** on a project
 * laid out the way `init` lays one out, which the reporting application showed as an empty theme
 * picker with nothing in any log.
 *
 * Seven statements, never executed. The properties that matter are the ones a single-directory
 * version got wrong: both are searched, a directory that does not exist is dropped rather than
 * returned, and the same path appearing twice appears once.
 */
#[CoversClass(Theme::class)]
class ThemeDirectoriesTest extends TestCase
{
    /** @var list<string> Directories this test created and must remove */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The fixture project ships neither `app/themes` nor `ROOT/themes`, so without this the
         * method returns `[]` and every assertion below is vacuous — two of them reported "did not
         * perform any assertions", which is how I found that out rather than by reading it.
         *
         * Both are created, because the merge is the behaviour: a project may legitimately have
         * one, the other, or both.
         */
        foreach ([APP_PATH . DS . 'themes', ROOT . DS . 'themes'] as $directory) {
            if (!is_dir($directory)) {
                @mkdir($directory, 0777, true);
                $this->created[] = $directory;
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $directory) {
            @rmdir($directory);
        }
        $this->created = [];

        parent::tearDown();
    }

    /** @return list<string> */
    private function directories(): array
    {
        return (new \ReflectionMethod(Theme::class, 'themeDirectories'))->invoke(null);
    }

    /**
     * Every directory returned exists.
     *
     * The filter is the point: a caller iterates these and reads what is inside, so a path that is
     * not there would be an unreadable-directory warning on every page that builds a theme list.
     */
    public function testEveryDirectoryReturnedExists(): void
    {
        // Act
        $directories = $this->directories();

        /*
         * Assert once, on a derived value, rather than once per directory.
         *
         * A loop of assertions makes this test's contribution to the suite's assertion total
         * depend on how many theme directories the checkout happens to have — which showed up as
         * a total that moved between identical runs. Harmless in itself, and it costs the one
         * signal that says whether a change to the suite added assertions or lost some.
         */
        $this->assertNotEmpty($directories, 'nothing was found, so the filter proves nothing');
        $this->assertSame(
            $directories,
            array_values(array_filter($directories, 'is_dir')),
            'a directory that does not exist was returned'
        );
    }

    /**
     * A candidate that does not exist is dropped rather than returned.
     *
     * Asserted by removing one of the two and checking it is gone from the answer — the filter is
     * the whole reason a caller can iterate these without checking each one.
     */
    public function testACandidateThatDoesNotExistIsDropped(): void
    {
        // Arrange — take away whichever of the two this test created
        $removed = [];
        foreach ($this->created as $directory) {
            @rmdir($directory);
            $removed[] = $directory;
        }

        if ($removed === []) {
            $this->markTestSkipped('Both theme directories already existed in this checkout.');
        }

        // Act
        $directories = $this->directories();

        // Assert — one assertion, whichever of the two this test happened to create
        $this->assertSame(
            [],
            array_values(array_intersect($removed, $directories)),
            'a directory that had just been removed was still returned'
        );
    }

    /**
     * The project's own theme directory is among them when it exists.
     *
     * The half that was missing. `APP_PATH . '/themes'` is where `init` puts a project's themes,
     * and a search that skipped it is the defect this method was written for.
     */
    public function testTheProjectsOwnThemeDirectoryIsSearched(): void
    {
        // Arrange — setUp() created it if the checkout did not have one
        $own = APP_PATH . DS . 'themes';
        $this->assertDirectoryExists($own);

        // Act + Assert
        $this->assertContains($own, $this->directories());
    }

    /**
     * No path appears twice.
     *
     * `APP_PATH` and `ROOT` are the same directory on some layouts, and a duplicate would make
     * every theme appear twice in a picker — which reads as a broken theme rather than a broken
     * search.
     */
    public function testNoPathAppearsTwice(): void
    {
        // Act
        $directories = $this->directories();

        // Assert
        $this->assertSame(
            array_values(array_unique($directories)),
            $directories,
            'the same directory is searched twice, so its themes would be listed twice'
        );
    }

    /**
     * The result is a list, with no gaps in its keys.
     *
     * `array_filter()` preserves keys, so without the `array_values()` around it a dropped
     * directory leaves a hole — and `json_encode()` then turns the list into an object, which is a
     * silent shape change for anything reading it over the wire.
     */
    public function testTheResultIsAListAndNotAMap(): void
    {
        // Act
        $directories = $this->directories();

        // Assert
        $this->assertSame(array_keys($directories), range(0, count($directories) - 1));
    }
}
