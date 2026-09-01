<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a scaffolded form says when it fails, and when it is working.
 *
 * Findings five and six of eight from an evaluation against
 * <https://web.dev/articles/sign-in-form-best-practices>, and the only two of the eight about
 * something the visitor is told rather than something the keyboard does:
 *
 * - **the errors were never announced.** An `alert alert-error` box is a red rectangle to anybody who
 *   can see it and nothing at all to anybody who cannot. No `role`, no `aria-invalid`, no
 *   `aria-describedby` — so a screen reader read out an unchanged sign-in form and never said why the
 *   submission failed. Of the eight findings this was the one touching accessibility.
 * - **pressing submit changed nothing.** The human-check proof holds the form for a moment; without a
 *   disabled button somebody presses again.
 *
 * ## Why a test rather than a review
 *
 * Both are invisible in the place people look. The error box *looks* right — it is the right colour,
 * in the right position, with the right words — and the missing part is an attribute. The submit
 * indicator only exists during a pause too short to notice on a fast connection with a warm cache,
 * which describes the machine of everyone who would review it. A new screen written next to these
 * ones will copy their shape; that is the point of checking the shape.
 *
 * ## Why it reads by proximity rather than parsing the tag
 *
 * The obvious implementation — match `<div\b[^>]*?>` and inspect the attributes — is **wrong for a
 * PHP template**, and it broke about a hundred of these files when it was tried on the inputs: an
 * attribute value here routinely contains `<?php echo … ?>`, whose `>` ends the match early, so the
 * "tag" is a fragment and anything appended to it lands in the middle of PHP code. The checks below
 * anchor on a literal that is certainly inside the tag and read backwards to the `<` that opened it.
 * Less precise, and it cannot corrupt a file.
 */
class ScaffoldFormFeedbackTest extends TestCase
{
    private const THEMES = ['tailwind', 'bootstrap', 'plain-css'];

    /**
     * Which `role` each alert flavour must carry.
     *
     * `alert` is assertive — it interrupts, which is right for «that did not work» and wrong for
     * «saved». `status` is polite: announced at the next pause, never over the top of something the
     * person was already being told.
     */
    private const ROLES = [
        'error'   => 'alert',
        'danger'  => 'alert',
        'info'    => 'status',
        'success' => 'status',
        'warning' => 'status',
    ];

    /** The forms behind a password, where a held submit is a second press. */
    private const AUTH_VIEWS = [
        'login/login',
        'login/login_2fa',
        'login/forgotpassword',
        'login/resetpassword',
        'register/register',
    ];

    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        $cases = [];

        foreach (self::THEMES as $theme) {
            $cases[$theme] = [$theme];
        }

        return $cases;
    }

    private static function viewsDirectory(string $theme): string
    {
        return dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views';
    }

    /** @return list<string> */
    private static function viewFiles(string $theme): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::viewsDirectory($theme))
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function shortName(string $path): string
    {
        $parts = explode('/themes/', $path);

        return $parts[1] ?? $path;
    }

    /**
     * Every alert box carries a `role`, and the right one for its severity.
     *
     * A box with no role is a colour. It is in the document from the moment the page renders, so
     * nothing about it is announced and nothing about it is reachable — the person who cannot see
     * red is told that the form loaded, and nothing else.
     *
     * The severity split matters as much as the role: `role="alert"` on a success message interrupts
     * whatever was being read to say something that could have waited, which is how a well-meaning
     * sweep makes a page worse.
     */
    #[DataProvider('themes')]
    public function testEveryAlertBoxCarriesTheRightRole(string $theme): void
    {
        // Arrange
        $missing = [];
        $boxes = 0;

        // Act
        foreach (self::viewFiles($theme) as $path) {
            $source = (string) file_get_contents($path);
            $offset = 0;

            while (($at = strpos($source, 'class="alert alert-', $offset)) !== false) {
                $offset = $at + 1;

                $flavour = '';
                foreach (array_keys(self::ROLES) as $candidate) {
                    if (str_starts_with(substr($source, $at), 'class="alert alert-' . $candidate)) {
                        $flavour = $candidate;
                        break;
                    }
                }

                if ($flavour === '') {
                    continue;               // alert-secondary and friends: not a message about state
                }

                $boxes++;
                $tagStart = strrpos(substr($source, 0, $at), '<');
                $tag = $tagStart === false ? '' : substr($source, $tagStart, $at - $tagStart);
                $expected = 'role="' . self::ROLES[$flavour] . '"';

                if (!str_contains($tag, $expected)) {
                    $missing[] = self::shortName($path) . ' — alert-' . $flavour
                        . ' wants ' . $expected;
                }
            }
        }

        // Assert
        $this->assertGreaterThan(20, $boxes, 'the scan found almost no alert boxes — wrong anchor?');
        $this->assertSame([], $missing, 'alert boxes a screen reader never mentions');
    }

    /**
     * A view that renders a form error points its first field at the message.
     *
     * `role="alert"` is not enough on its own here, and this is the part that is easy to get wrong
     * while believing it is done: a live region is announced when it **changes**, and a
     * server-rendered error has been in the document since before the page existed. It never
     * changed, so most screen readers say nothing.
     *
     * What works with no JavaScript at all is the description. The field is marked `aria-invalid` and
     * `aria-describedby` the box, so the message is read out as part of the field the moment focus
     * lands on it — and focus lands there on load, because the first field carries `autofocus`.
     *
     * The *first* field only, which is why the count is asserted. These errors are form-level —
     * «wrong username or password» is about the pair — and marking four fields invalid to report one
     * failure tells a screen reader four things that are not true.
     */
    #[DataProvider('themes')]
    public function testAFormErrorIsWiredToTheFieldItConcerns(string $theme): void
    {
        // Arrange
        $problems = [];
        $views = 0;

        // Act
        foreach (self::viewFiles($theme) as $path) {
            $source = (string) file_get_contents($path);

            if (!str_contains($source, '$errorText')) {
                continue;
            }

            $views++;
            $name = self::shortName($path);

            if (!str_contains($source, '$errorFieldAttributes')) {
                $problems[] = $name . ' — renders an error nothing points at';
                continue;
            }

            if (!str_contains($source, 'id="form-error"')) {
                $problems[] = $name . ' — the error box has no id to be described by';
            }

            $described = substr_count($source, 'aria-describedby="form-error"');
            if ($described !== 1) {
                $problems[] = $name . ' — ' . $described . ' fields point at the error, want 1';
            }

            if (!str_contains($source, 'aria-invalid="true"')) {
                $problems[] = $name . ' — nothing is marked invalid';
            }
        }

        // Assert
        $this->assertGreaterThan(3, $views, 'no error-rendering views found — wrong anchor?');
        $this->assertSame([], $problems, 'form errors a screen reader cannot connect to a field');
    }

    /**
     * Every form behind a password says it heard the button.
     *
     * The pause is real and it is unavoidable: the human-check proof runs in a worker and the form
     * waits for it. What is avoidable is the form looking untouched while it waits, because a person
     * who cannot tell whether the press registered presses again — and a second sign-in attempt is
     * not free, it is a failed attempt against a lockout counter.
     *
     * Both halves are checked. `data-pf-progress` is what the script looks for, and `pf-auth.js` is
     * the script: two of these views had the attribute's siblings and no script tag at all, which
     * looks correct in a diff and does nothing in a browser.
     */
    #[DataProvider('themes')]
    public function testEveryAuthFormAsksForASubmitIndicator(string $theme): void
    {
        // Arrange
        $problems = [];

        // Act
        foreach (self::AUTH_VIEWS as $view) {
            $path = self::viewsDirectory($theme) . '/' . $view . '.html.php';
            $this->assertFileExists($path);

            $source = (string) file_get_contents($path);
            $forms = substr_count($source, '<form ');
            $marked = substr_count($source, 'data-pf-progress');

            if ($forms !== $marked) {
                $problems[] = $view . ' — ' . $marked . ' of ' . $forms . ' forms marked';
            }

            if (!str_contains($source, 'pf-auth.js')) {
                $problems[] = $view . ' — marks its forms and loads no script to read the mark';
            }
        }

        // Assert
        $this->assertSame([], $problems, 'forms that give no sign the submit registered');
    }
}
