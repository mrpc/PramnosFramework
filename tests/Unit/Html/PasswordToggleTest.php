<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\PasswordToggle;

/**
 * The «show password» control, and the two ways a toggle like this is usually wrong.
 *
 * It is the first thing the sign-in form guidance asks for, and the reason is mobile: on a phone the
 * commonest cause of a failed sign-in is a typo in a field nobody can read. Somebody who cannot see
 * what they typed retries the same wrong thing and then resets a password they never forgot.
 *
 * Two failure modes are what the assertions are really about:
 *
 * - **a button that cannot work.** Rendered visible and driven by JavaScript, it is a dead control
 *   for a visitor without it — pressed twice, then the whole form is distrusted. So it ships `hidden`
 *   and its own script unhides it, which means it appears exactly where it functions.
 * - **a toggle that breaks the password manager.** Only `type` may change. `name`, `id` and
 *   `autocomplete` are what a manager matches on, and a control that renamed the field would stop it
 *   offering the saved password — a worse outcome than not being able to read what you typed.
 */
#[CoversClass(PasswordToggle::class)]
class PasswordToggleTest extends TestCase
{
    // ── The button ────────────────────────────────────────────────────────────

    /**
     * It renders a button that names the field it controls.
     *
     * `aria-controls` is both the accessibility contract and how the script finds the input, so a
     * missing or wrong value is a toggle that does nothing and says nothing.
     */
    public function testItRendersAButtonNamingItsField(): void
    {
        // Act
        $html = PasswordToggle::render('password');

        // Assert
        $this->assertStringContainsString('<button type="button"', $html);
        $this->assertStringContainsString('aria-controls="password"', $html);
        $this->assertStringContainsString('data-pramnos-password-toggle', $html);
    }

    /**
     * The button is hidden until its script unhides it.
     *
     * The property that keeps a no-JS visitor from seeing a control that cannot do anything. Both
     * halves are asserted: the attribute is on the button, and the script is what removes it.
     */
    public function testTheButtonIsHiddenUntilTheScriptRuns(): void
    {
        // Act
        $html = PasswordToggle::render('password');

        // Assert
        $this->assertMatchesRegularExpression(
            '/<button[^>]*\shidden/',
            $html,
            'a visitor without JavaScript sees a button that does nothing'
        );
        $this->assertStringContainsString('button.hidden = false', $html, 'nothing ever unhides it');
    }

    /**
     * It starts in the not-pressed state, labelled «show».
     *
     * `aria-pressed` is what a screen reader announces, and a toggle that starts out claiming to be
     * pressed describes the opposite of what the field is doing.
     */
    public function testItStartsUnpressedAndLabelledShow(): void
    {
        // Act
        $html = PasswordToggle::render('password');

        // Assert
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('data-show-label="', $html);
        $this->assertStringContainsString('data-hide-label="', $html);
    }

    /**
     * Both labels can be given, and they become the accessible name.
     *
     * The control is an eye inside the field, so the words are not on screen — a bold «Show
     * password» under the box is louder than the field it belongs to, for something most people
     * never press. `aria-label` is where the meaning goes instead, which is also what gets swapped
     * on toggle so a screen reader hears the new state.
     */
    public function testTheLabelsCanBeGivenAndBecomeTheAccessibleName(): void
    {
        // Act
        $html = PasswordToggle::render('password', 'Εμφάνιση', 'Απόκρυψη');

        // Assert
        $this->assertStringContainsString('aria-label="Εμφάνιση"', $html);
        $this->assertStringContainsString('title="Εμφάνιση"', $html);
        $this->assertStringContainsString('data-show-label="Εμφάνιση"', $html);
        $this->assertStringContainsString('data-hide-label="Απόκρυψη"', $html);

        $this->assertStringNotContainsString(
            '>Εμφάνιση</button>',
            $html,
            'the words are on screen again, which is what the icon replaced'
        );
    }

    /**
     * The visible content is an inline SVG that inherits the field's colour.
     *
     * Inline rather than a font or a sprite, because this ships with the markup and has to work in a
     * project with no icon set, no build step and no network. `currentColor` and `em` sizing mean it
     * fits a theme nobody told it about.
     */
    public function testTheVisibleContentIsAnInheritingIcon(): void
    {
        // Act
        $html = PasswordToggle::render('password');

        // Assert
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('currentColor', $html, 'the icon has a colour of its own');
        $this->assertStringContainsString('1.15em', $html, 'the icon has a fixed pixel size');
        $this->assertStringContainsString(
            'aria-hidden="true"',
            $html,
            'a screen reader announces the drawing after the label'
        );
    }

    /**
     * The script positions the control inside the field rather than under it.
     *
     * Done in JavaScript on purpose: the button is rendered straight after its input, which is the
     * correct **tab** order, and absolute positioning moves it visually without moving it in the
     * document. No view has to change and no theme has to ship CSS for it.
     */
    public function testTheScriptPlacesTheControlInsideTheField(): void
    {
        // Act
        $html = PasswordToggle::render('password');

        // Assert
        $this->assertStringContainsString('position = \'absolute\'', $html);
        $this->assertStringContainsString('data-pramnos-password-wrap', $html);
        $this->assertStringContainsString(
            'paddingRight',
            $html,
            'a long password would run underneath the icon'
        );
        $this->assertStringContainsString('rtl', $html, 'the icon lands on the wrong edge in Arabic');
    }

    /**
     * A label carrying markup is escaped, in the attribute and in the text.
     *
     * It appears in both places, so escaping one and forgetting the other is the ordinary mistake —
     * and a label is the kind of value that arrives from a settings screen sooner or later.
     */
    public function testLabelsAreEscaped(): void
    {
        // Act
        $html = PasswordToggle::render('password', '"><script>alert(1)</script>');

        // Assert
        $this->assertStringNotContainsString('<script>alert(1)', $html, 'a label broke out');
        $this->assertStringContainsString('&quot;', $html);
        // It reaches three attributes now — aria-label, title and the data attribute — so a single
        // unescaped one would be enough.
        $this->assertSame(
            0,
            preg_match('/(aria-label|title|data-show-label)="[^"]*<script/', $html),
            'a label reached an attribute unescaped'
        );
    }

    /**
     * The theme's own classes can be put on the button.
     *
     * This class has no opinion about how a button looks, and a helper that hard-coded one would
     * render a control that matches nothing on the page it lands in.
     */
    public function testTheThemesClassesCanBePutOnTheButton(): void
    {
        // Act
        $html = PasswordToggle::render('password', '', '', 'btn btn-ghost btn-xs');

        // Assert
        $this->assertStringContainsString('class="btn btn-ghost btn-xs"', $html);
    }

    /** With no class given, no empty class attribute is emitted. */
    public function testWithNoClassNoAttributeIsEmitted(): void
    {
        // Act
        $html = PasswordToggle::render('password');

        // Assert
        $this->assertStringNotContainsString('class=""', $html);
    }

    /** A class carrying a quote is escaped rather than ending the attribute. */
    public function testTheClassIsEscaped(): void
    {
        // Act
        $html = PasswordToggle::render('password', '', '', 'btn" onfocus="alert(1)');

        // Assert
        $this->assertStringNotContainsString('onfocus="alert(1)"', $html, 'the class broke out');
        $this->assertStringContainsString('&quot;', $html);
    }

    // ── The script ────────────────────────────────────────────────────────────

    /**
     * Every button carries the script, and a second copy is inert.
     *
     * There is deliberately no «already emitted» flag in PHP. The first version had one, and it was
     * process state rather than request state: a process rendering more than one response — an
     * in-process test client, a long-running worker — gave the second page a button and no listener.
     * A visible control that does nothing is worse than no control.
     *
     * So the script goes out every time and guards itself in the browser: the first copy binds, the
     * rest return immediately. Both halves are asserted, because the guard is what makes the repeat
     * acceptable.
     */
    public function testEveryButtonCarriesAnIdempotentScript(): void
    {
        // Act
        $first  = PasswordToggle::render('current_password');
        $second = PasswordToggle::render('new_password');

        // Assert
        $this->assertStringContainsString('<script', $first);
        $this->assertStringContainsString(
            '<script',
            $second,
            'a second render got a button with no listener behind it'
        );

        foreach ([$first, $second] as $html) {
            $this->assertStringContainsString(
                '__pramnosPasswordToggleBound',
                $html,
                'a repeated script would bind a second listener and toggle the field back'
            );
        }

        $this->assertStringContainsString('aria-controls="new_password"', $second);
    }

    /**
     * The listener is delegated, so a field added after the script still works.
     *
     * Asserted on the mechanism rather than the behaviour, which is as far as a PHP test can see: a
     * listener bound to each button would have to be re-bound for anything rendered later.
     */
    public function testTheListenerIsDelegatedFromTheDocument(): void
    {
        // Act
        $html = PasswordToggle::render('password');

        // Assert
        $this->assertStringContainsString("document.addEventListener('click'", $html);
        $this->assertStringContainsString('closest', $html, 'the handler does not delegate');
    }

    /**
     * Only the `type` attribute is touched.
     *
     * `name`, `id` and `autocomplete` are what a password manager matches on. A toggle that renamed
     * the field would stop it offering the saved password, which costs the visitor more than an
     * unreadable field does.
     */
    public function testOnlyTheTypeAttributeIsTouched(): void
    {
        // Act
        $html = PasswordToggle::render('password');

        // Assert
        $this->assertStringContainsString('field.type =', $html);
        foreach (['field.name', 'field.id', 'field.autocomplete', 'removeAttribute'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $html,
                'the script touches ' . $forbidden . ', which a password manager matches on'
            );
        }
    }

    /**
     * The caret position survives the toggle.
     *
     * Toggling halfway through typing and losing your place is the same frustration the control was
     * added to remove.
     */
    public function testTheCaretPositionIsPreserved(): void
    {
        // Act
        $html = PasswordToggle::render('password');

        // Assert
        $this->assertStringContainsString('selectionStart', $html);
        $this->assertStringContainsString('setSelectionRange', $html);
        $this->assertStringContainsString('field.focus()', $html, 'focus leaves the field');
    }

    // ── What it refuses ───────────────────────────────────────────────────────

    /**
     * An id that is not a usable DOM id is refused, not encoded.
     *
     * The value reaches an HTML attribute *and* a `getElementById` call. An id is written by a
     * developer as a constant, so anything outside that shape is a mistake — and refusing it loudly
     * beats emitting a control that silently addresses nothing.
     */
    public function testAnUnusableIdIsRefused(): void
    {
        // Act & Assert
        foreach (['', '"><script>', 'has space', '1leading-digit', 'quote\'d'] as $bad) {
            try {
                PasswordToggle::render($bad);
                $this->fail('accepted an unusable id: ' . var_export($bad, true));
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('password input', $exception->getMessage());
            }
        }
    }

    /** The ids a real form uses are accepted. */
    public function testOrdinaryIdsAreAccepted(): void
    {
        // Act & Assert
        foreach (['password', 'new_password', 'confirm-password', 'user.password', 'p1'] as $good) {
            $this->assertStringContainsString(
                'aria-controls="' . $good . '"',
                PasswordToggle::render($good),
                $good . ' was refused'
            );
        }
    }
}
