<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every template this framework ships parses.
 *
 * WHAT: each `scaffolding/templates/*.stub` that is PHP or JavaScript is rendered and run
 *       through `php -l` / `node --check`.
 * WHY:  **the framework ships far more code than it runs.** Forty-eight of eighty-one stub
 *       templates are never named by any test; they are written here, rendered into
 *       somebody else's project, and first executed there. Everything that went wrong in
 *       the scaffolding this month had that shape — a controller example that fatals at
 *       autoload, a webhook calling a package nobody requires, a form wrapper whose CSS
 *       framework removed the class — and none of it could have been caught by a test of
 *       the generator, because the generator was working. It emitted exactly what it was
 *       told to.
 *
 *       A syntax check is the cheapest possible floor and it is not nothing: it is the
 *       difference between "this file is broken" being found here, in a second, and being
 *       found by whoever received the scaffold.
 *
 * It is a floor and not a ceiling. Valid PHP can still be wrong PHP — the `$actions`
 * example that fatals at autoload parses perfectly — so this sits underneath the guards
 * that check meaning: the controller conventions, the daisyUI classes, the generated
 * routes matching the client.
 */
#[CoversNothing]
class EveryStubIsSyntacticallyValidTest extends TestCase
{
    /**
     * Placeholders whose value is a variable *name*, `$` included.
     *
     * `function load({{ primaryKeyVal }})` becomes `function load($channelid)`, so an
     * identifier substituted here would not parse where a variable is required.
     */
    private const VARIABLE_LIKE = ['primaryKeyVal'];

    /**
     * Placeholders whose value is a block of statements rather than one token.
     *
     * Matched by name, because the shape is in the name — `postContent`, `schemaBlock`,
     * `ormOptions`, `cssImport` — and a convention that holds for a hundred tokens is
     * worth more than a table somebody has to extend for each new one.
     */
    private const BLOCK_LIKE = '/(Content|Block|Options|Fixes|Imports?|Section|Assertions|List)$'
        . '|^(props|workers|arrayFix|fields|columns)$/';

    public function testEveryPhpAndJavaScriptStubParses(): void
    {
        // Arrange
        $templates = glob(dirname(__DIR__, 3) . '/scaffolding/templates/*.stub') ?: [];
        $this->assertNotEmpty($templates);

        // Act
        $checked   = 0;
        $offenders = [];
        foreach ($templates as $path) {
            $rendered = $this->render((string) file_get_contents($path));
            $name     = basename($path);

            if (str_ends_with($name, '.php.stub') || str_starts_with(ltrim($rendered), '<?php')) {
                $checked++;
                $error = $this->check($rendered, '.php', 'php -l');
            } elseif (str_ends_with($name, '.js.stub') || str_ends_with($name, '.mjs.stub')) {
                $checked++;
                $error = $this->check($rendered, '.mjs', 'node --check');
            } else {
                // Markdown, CSS, Svelte, shell. Svelte has its own block-balance guard in
                // GeneratedSpaPathsTest; the rest have no parser worth shelling out to.
                continue;
            }

            if ($error !== '') {
                $offenders[] = $name . ' — ' . $error;
            }
        }

        // Assert — and that the sweep actually swept, because a glob that matches nothing
        // passes this test in silence.
        $this->assertGreaterThan(40, $checked, 'the sweep found almost no templates to check');
        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * Substitute the placeholders for something of the right shape.
     *
     * A line that is nothing but placeholders is a block and disappears; a name that reads
     * like a block disappears wherever it sits; everything else becomes an identifier.
     * That is three rules for a hundred and eighteen tokens, and a new token needs no
     * change here unless it is a block sharing a line with code.
     */
    private function render(string $source): string
    {
        $lines = [];
        foreach (explode("\n", $source) as $line) {
            if (preg_match('/^\s*(\{\{\s*[\w.]+\s*\}\}\s*)+$/', $line) === 1) {
                continue;
            }

            $lines[] = preg_replace_callback(
                '/\{\{\s*([\w.]+)\s*\}\}/',
                function (array $match): string {
                    $name = trim($match[1]);

                    if (in_array($name, self::VARIABLE_LIKE, true)) {
                        return '$x';
                    }

                    return preg_match(self::BLOCK_LIKE, $name) === 1 ? '' : 'X';
                },
                $line
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Run one parser over the rendered template; '' when it parses.
     */
    private function check(string $rendered, string $extension, string $command): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pf-stub') . $extension;
        file_put_contents($file, $rendered);

        $output = [];
        $status = 0;
        exec($command . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        @unlink($file);

        if ($status === 0) {
            return '';
        }

        /*
         * Built from the whole output, not from `$output[0]`.
         *
         * `php -l` prints a blank line first, so the message is in `$output[1]` — and
         * returning `$output[0]` handed back an empty string, which is this method's own
         * signal for "it parsed". The guard reported success for every broken template,
         * and was caught only because it was tried against a deliberately broken one.
         *
         * A sentinel that can also be a real value is not a sentinel. The status decides.
         */
        $message = trim(implode(' ', array_filter(array_map('trim', $output))));

        return str_replace($file, '<rendered>', $message !== '' ? $message : 'did not parse');
    }
}
