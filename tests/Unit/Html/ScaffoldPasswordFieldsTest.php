<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\PasswordToggle;

/**
 * Every password field a scaffolded project starts with can be revealed.
 *
 * `PasswordToggleTest` proves the control renders correctly; this proves it is *there*. The two are
 * different claims, and the evaluation that asked for this feature found the control missing from
 * every screen of a real installation — so «it exists in the framework» was never the question.
 *
 * These views are what `init` copies into a new project, which makes this the only place the rule can
 * be enforced once rather than screen by screen: a password field added to a scaffold later cannot
 * ship without a way to read what was typed, because this fails.
 *
 * It reads the files rather than rendering them. A scaffold view expects a controller, a theme and a
 * populated `$this`, and none of that is needed to answer «does this field have a toggle beside it».
 */
#[CoversClass(PasswordToggle::class)]
class ScaffoldPasswordFieldsTest extends TestCase
{
    /** The themes `init` can scaffold, each of which carries a full set of views. */
    private const THEMES = ['tailwind', 'bootstrap', 'plain-css'];

    private static function viewsDirectory(string $theme): string
    {
        return dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views';
    }

    /** @return list<string> Every view file of a theme. */
    private static function viewFiles(string $theme): array
    {
        $directory = self::viewsDirectory($theme);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<array{file: string, id: string|null, name: string}> */
    private static function passwordFields(string $theme): array
    {
        $fields = [];

        foreach (self::viewFiles($theme) as $path) {
            $source = (string) file_get_contents($path);

            preg_match_all('/<input\b[^>]*?>/s', $source, $matches);

            foreach ($matches[0] as $tag) {
                if (!str_contains($tag, 'type="password"')) {
                    continue;
                }

                preg_match('/\bid="([A-Za-z][\w:.-]*)"/', $tag, $id);
                preg_match('/\bname="([^"]*)"/', $tag, $name);

                $fields[] = [
                    'file' => str_replace(self::viewsDirectory($theme) . '/', '', $path),
                    'id'   => $id[1] ?? null,
                    'name' => $name[1] ?? '(unnamed)',
                ];
            }
        }

        return $fields;
    }

    /** @return array<string, array{0: string}> */
    public static function themes(): array
    {
        $cases = [];

        foreach (self::THEMES as $theme) {
            $cases[$theme] = [$theme];
        }

        return $cases;
    }

    /**
     * A scaffolded project has password fields at all.
     *
     * The guard against every assertion below passing because nothing was found — a broken path or a
     * renamed directory would otherwise read as «all fields comply».
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function testTheThemeHasPasswordFieldsToCheck(string $theme): void
    {
        // Act
        $fields = self::passwordFields($theme);

        // Assert
        $this->assertGreaterThanOrEqual(
            10,
            count($fields),
            'found almost no password fields in ' . $theme . ' — the path is probably wrong'
        );
    }

    /**
     * Every password field has an `id`.
     *
     * Two things need it: the toggle addresses the field by id, and so does `<label for>`. A field
     * without one has no programmatic label either, which is a practice these views otherwise keep
     * everywhere.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function testEveryPasswordFieldHasAnId(string $theme): void
    {
        // Act
        $missing = [];

        foreach (self::passwordFields($theme) as $field) {
            if ($field['id'] === null) {
                $missing[] = $field['file'] . ' — name="' . $field['name'] . '"';
            }
        }

        // Assert
        $this->assertSame(
            [],
            $missing,
            "These password fields have no id, so they can carry neither a toggle nor a\n"
            . "<label for>:\n  " . implode("\n  ", $missing)
        );
    }

    /**
     * Every password field has a toggle addressed to it.
     *
     * The rule this file exists for. A field added later without one fails here rather than shipping
     * to every new project — which is how the control came to be missing from every screen of a real
     * installation in the first place.
     *
     * Matched on the id inside a `PasswordToggle::render(` call in the same file, because that is the
     * claim: not «the file mentions the helper» but «this field is the one it points at».
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function testEveryPasswordFieldHasAToggle(string $theme): void
    {
        // Arrange
        $rendered = [];

        foreach (self::viewFiles($theme) as $path) {
            $source = (string) file_get_contents($path);
            $short  = str_replace(self::viewsDirectory($theme) . '/', '', $path);

            preg_match_all(
                "/PasswordToggle::render\(\s*'([^']+)'/",
                $source,
                $matches
            );

            foreach ($matches[1] as $id) {
                $rendered[$short][$id] = true;
            }
        }

        // Act
        $without = [];

        foreach (self::passwordFields($theme) as $field) {
            if ($field['id'] === null) {
                continue; // reported by its own test
            }

            if (!isset($rendered[$field['file']][$field['id']])) {
                $without[] = $field['file'] . ' — id="' . $field['id'] . '"';
            }
        }

        // Assert
        $this->assertSame(
            [],
            $without,
            "These password fields have no way to reveal what was typed. Add\n"
            . "  <?php echo \\Pramnos\\Html\\PasswordToggle::render('<id>', '', '', '<theme button class>'); ?>\n"
            . "beside each:\n  " . implode("\n  ", $without)
        );
    }

    /**
     * Every toggle comes **after** the field it belongs to.
     *
     * These forms carry no `tabindex`, so the tab order *is* the DOM order. A toggle placed above its
     * field — in the label row, which looks tidier — means tabbing off the previous field lands on
     * the «show» button instead of the password box. The form still works and looks fine; only a
     * keyboard notices, which is why this needs a test rather than a look.
     *
     * That is not hypothetical: the first installation wired for this feature had exactly that
     * ordering, and it was spotted by someone using the form rather than by anything automated.
     *
     * A `tabindex="-1"` on the button would silence the symptom by making the control unreachable
     * without a mouse. Placing it after the field costs nothing and keeps it reachable.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function testEveryToggleComesAfterItsField(string $theme): void
    {
        // Act
        $wrong = [];

        foreach (self::viewFiles($theme) as $path) {
            $source = (string) file_get_contents($path);
            $short  = str_replace(self::viewsDirectory($theme) . '/', '', $path);

            preg_match_all("/PasswordToggle::render\(\s*'([^']+)'/", $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as $match) {
                [$id, $toggleAt] = $match;

                $fieldAt = strpos($source, 'id="' . $id . '"');
                if ($fieldAt === false) {
                    continue; // reported by its own test
                }

                if ($toggleAt < $fieldAt) {
                    $wrong[] = $short . ' — id="' . $id . '"';
                }
            }
        }

        // Assert
        $this->assertSame(
            [],
            $wrong,
            "These toggles come before the field they control, so tabbing into the field lands on\n"
            . "the button instead:\n  " . implode("\n  ", $wrong)
        );
    }

    /**
     * Every toggle points at a field that exists in the same file.
     *
     * The other direction, and it catches the mistake a rename makes: the field becomes
     * `new_password`, the toggle still says `password`, and the control renders and addresses
     * nothing. Nothing about the page looks wrong.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function testEveryTogglePointsAtAFieldThatExists(string $theme): void
    {
        // Arrange
        $ids = [];

        foreach (self::passwordFields($theme) as $field) {
            if ($field['id'] !== null) {
                $ids[$field['file']][$field['id']] = true;
            }
        }

        // Act
        $dangling = [];

        foreach (self::viewFiles($theme) as $path) {
            $source = (string) file_get_contents($path);
            $short  = str_replace(self::viewsDirectory($theme) . '/', '', $path);

            preg_match_all("/PasswordToggle::render\(\s*'([^']+)'/", $source, $matches);

            foreach ($matches[1] as $id) {
                if (!isset($ids[$short][$id])) {
                    $dangling[] = $short . ' — toggle for id="' . $id . '", which is not there';
                }
            }
        }

        // Assert
        $this->assertSame(
            [],
            $dangling,
            "These toggles address a field that does not exist in their file:\n  "
            . implode("\n  ", $dangling)
        );
    }
}
