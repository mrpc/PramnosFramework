<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\Tree;

/**
 * A password toggle is a **sibling** of its input, never part of its opening tag.
 *
 * `PasswordToggle::render()` returns markup, so emitting it inside an attribute puts a
 * `>` in the middle of an `<input>`: the tag closes there, and the rest of the line —
 * the closing quote, `autocomplete`, `value` — becomes visible text in the page.
 *
 * It had happened in all three themes on the same field, each in a different attribute:
 * the Tailwind theme inside `class`, the other two inside `value`. The result was the
 * SMTP password field on `/admin/Settings` rendering as `" autocomplete="new-password"
 * value="">` beside an eye icon — the one field that screen exists to fill in, on an
 * installation configuring outgoing mail for the first time.
 *
 * It reads like a missing `">` and is not, which is why it survived three copies: the
 * toggle is a sibling of the input, not one of its attributes.
 *
 * The check is structural rather than a search for one broken string, because the next
 * one will be in a different attribute of a different field.
 */
class PasswordToggleMarkupTest extends TestCase
{
    /**
     * Every scaffolded view that renders a toggle.
     *
     * @return list<string>
     */
    /** Walked once per process: every walk is another chance for the mount to blink. */
    private static ?array $cache = null;

    private function viewsWithAToggle(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $root  = dirname(__DIR__, 3) . '/scaffolding';
        $found = [];

        foreach (Tree::files($root) as $path) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains(Tree::read($path), 'PasswordToggle::render(')) {
                $found[] = $path;
            }
        }

        return self::$cache = $found;
    }

    /**
     * No toggle is emitted from inside an HTML tag.
     *
     * PHP blocks are replaced before the scan so that `?>` cannot be mistaken for the
     * end of a tag — which is exactly what makes the broken markup hard to see when
     * reading it. What is left is HTML, and a marker whose nearest `<` is closer than
     * its nearest `>` is sitting inside a tag.
     */
    public function testNoToggleIsRenderedInsideATag(): void
    {
        // Arrange
        $views = $this->viewsWithAToggle();
        $this->assertNotEmpty($views, 'the sweep found no view rendering a password toggle');
        $offenders = [];

        foreach ($views as $path) {
            $body = Tree::read($path);

            // Act — every PHP block becomes one harmless token; a toggle becomes a marker.
            $flattened = (string) preg_replace_callback(
                '/<\?php.*?\?>/s',
                static fn(array $m): string => str_contains($m[0], 'PasswordToggle::render(')
                    ? "\x01TOGGLE\x01"
                    : 'x',
                $body
            );

            foreach ($this->offsetsOf($flattened, "\x01TOGGLE\x01") as $offset) {
                $before   = substr($flattened, 0, $offset);
                $lastOpen = strrpos($before, '<');
                $lastShut = strrpos($before, '>');

                if ($lastOpen !== false && ($lastShut === false || $lastOpen > $lastShut)) {
                    $offenders[] = $this->shortName($path)
                        . ' (line ' . (substr_count($before, "\n") + 1) . ')';
                }
            }
        }

        // Assert — every one of them, so a second copy is not found a run later
        $this->assertSame(
            [],
            $offenders,
            'a password toggle is rendered inside an HTML tag; its markup closes that tag early'
        );
    }

    /** Theme and file, which is what identifies one of three copies of a view. */
    private function shortName(string $path): string
    {
        $parts = explode('/', $path);
        $count  = count($parts);

        return implode('/', array_slice($parts, max(0, $count - 4)));
    }

    /**
     * No view prints a stored password back into the page.
     *
     * The second half of the same line, and the more expensive one: `smtp_pass` is
     * encrypted at rest and was rendered in clear into `value=""` on an admin screen —
     * a proxy cache, a screenshot and a shared screen all then have it. The field
     * submits empty to mean "keep what is stored".
     */
    public function testNoViewPrintsTheStoredSmtpPasswordBack(): void
    {
        // Arrange
        $views     = $this->viewsWithAToggle();
        $offenders = [];
        $this->assertNotEmpty($views, 'the sweep found nothing to check');

        foreach ($views as $path) {
            $body = Tree::read($path);

            // Act
            if (preg_match('/value="[^"]*\$s\[.smtp_pass.\]/', $body) === 1) {
                $offenders[] = basename(dirname(dirname(dirname($path)))) . '/' . basename($path);
            }
        }

        // Assert
        $this->assertSame([], $offenders, 'a stored SMTP password is rendered into the page');
    }

    /**
     * Byte offsets of every occurrence of a needle.
     *
     * @return list<int>
     */
    private function offsetsOf(string $haystack, string $needle): array
    {
        $offsets = [];
        $at      = 0;

        while (($at = strpos($haystack, $needle, $at)) !== false) {
            $offsets[] = $at;
            $at       += strlen($needle);
        }

        return $offsets;
    }
}
