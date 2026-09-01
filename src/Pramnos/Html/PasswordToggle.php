<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * The «show password» control that sits beside a password field.
 *
 * The first thing <https://web.dev/articles/sign-in-form-best-practices> asks for, and the reason is
 * mobile: on a phone the most common cause of a failed sign-in is a typo in a field nobody can read.
 * A person who cannot see what they typed has no way to find it, so they try the same wrong thing
 * again and then reset a password that was never forgotten.
 *
 * ```php
 * <label for="password">Password</label>
 * <input type="password" name="password" id="password" autocomplete="current-password" required>
 * <?php echo \Pramnos\Html\PasswordToggle::render('password'); ?>
 * ```
 *
 * ## Hidden until JavaScript proves it works
 *
 * The button is rendered with `hidden` and unhidden by its own script. A control that cannot do
 * anything is worse than no control: without JavaScript a visible «show» button is a thing a person
 * presses twice and then distrusts the rest of the form. This way a no-JS visitor sees exactly the
 * form they saw before, and the field itself never depended on the script.
 *
 * ## One script, however many fields
 *
 * The listener is delegated from `document`, so the script is emitted **once per request** no matter
 * how many password fields a page has — and a field added later still works. The change-password
 * screen has three of them.
 *
 * It is inline rather than a file, and carries the CSP nonce when the application has one. That
 * follows {@see Input}'s rule: rendering a control must not quietly add to the page's asset list,
 * because then echoing a form changes what the document loads.
 *
 * ## What it deliberately does not touch
 *
 * Only the `type` attribute changes. `name`, `id` and `autocomplete` stay exactly as they were,
 * because those three are what a password manager matches on — a toggle that renamed the field would
 * stop it offering the saved password, which costs more than it gives. The caret position and focus
 * are preserved too: toggling mid-word and losing your place is the same frustration in a different
 * shape.
 */
class PasswordToggle
{
    /**
     * A DOM id this class will address.
     *
     * The value reaches an HTML attribute and a `getElementById` call, so it is checked rather than
     * escaped: an id is written by a developer as a constant, and anything outside this shape is a
     * mistake worth refusing loudly instead of encoding quietly.
     */
    private const ID_PATTERN = '/^[A-Za-z][A-Za-z0-9_:.-]*$/';

    /**
     * The eye, and the eye with a line through it.
     *
     * Inline SVG rather than a font or a sprite: this control ships with the markup and must work in
     * a project with no icon set, no build step and no network. `currentColor` and `1em` mean it
     * inherits whatever the field's text already is, so it fits a theme nobody told it about.
     *
     * `aria-hidden` on both — the button carries the name, and a screen reader announcing the shape
     * of a drawing after the label is noise.
     */
    private const EYE_OPEN = '<svg aria-hidden="true" focusable="false" width="1.15em"'
        . ' height="1.15em" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'
        . '<path d="M1.8 12S5.4 5.4 12 5.4 22.2 12 22.2 12 18.6 18.6 12 18.6 1.8 12 1.8 12Z"/>'
        . '<circle cx="12" cy="12" r="3.1"/></svg>';

    private const EYE_CLOSED = '<svg aria-hidden="true" focusable="false" width="1.15em"'
        . ' height="1.15em" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'
        . '<path d="M9.9 5.6A8 8 0 0 1 12 5.4c6.6 0 10.2 6.6 10.2 6.6a19 19 0 0 1-2.5 3.5"/>'
        . '<path d="M6.2 7.7A19 19 0 0 0 1.8 12S5.4 18.6 12 18.6a8 8 0 0 0 3.2-.6"/>'
        . '<path d="M10 10a2.8 2.8 0 0 0 4 4"/><path d="M3 3l18 18"/></svg>';

    /**
     * The toggle for one password field.
     *
     * @param  string $inputId    The `id` of the `<input type="password">` this controls
     * @param  string $showLabel  Text while the password is hidden; defaults to a translated «Show»
     * @param  string $hideLabel  Text while it is visible; defaults to a translated «Hide»
     * @param  string $class      CSS classes for the button — the theme's own, since this class has
     *                            no opinion about how a button looks
     * @return string Button markup, plus the shared script on the first call
     * @throws \InvalidArgumentException When the id is not a usable DOM id
     */
    public static function render(
        string $inputId,
        string $showLabel = '',
        string $hideLabel = '',
        string $class = ''
    ): string {
        if (preg_match(self::ID_PATTERN, $inputId) !== 1) {
            throw new \InvalidArgumentException(
                'PasswordToggle needs the id of a password input; got: ' . $inputId
            );
        }

        $show = $showLabel !== '' ? $showLabel : self::translate('Show password');
        $hide = $hideLabel !== '' ? $hideLabel : self::translate('Hide password');

        $attribute = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES);

        /*
         * An icon, and the words go to `aria-label`.
         *
         * The first version rendered the text as a button, and on a real screen that is a bold
         * control on its own line under the field — louder than the field it belongs to, for
         * something most people never press. An eye inside the right-hand edge of the box is the
         * convention, and it is quiet enough to ignore.
         *
         * The label is not lost: it becomes the accessible name, so a screen reader still hears
         * «Show password» and the swap on toggle still announces the new state.
         */
        $button = '<button type="button" hidden'
            . ($class !== '' ? ' class="' . $attribute($class) . '"' : '')
            . ' data-pramnos-password-toggle'
            . ' aria-controls="' . $attribute($inputId) . '"'
            . ' aria-pressed="false"'
            . ' aria-label="' . $attribute($show) . '"'
            . ' title="' . $attribute($show) . '"'
            . ' data-show-label="' . $attribute($show) . '"'
            . ' data-hide-label="' . $attribute($hide) . '"'
            . '>' . self::EYE_OPEN . '</button>';

        return $button . self::script();
    }

    /**
     * The shared script, emitted with every button and idempotent in the browser.
     *
     * Delegated from `document`, so one active copy covers every toggle on the page and any added
     * afterwards — and a `window` guard makes a second copy inert.
     *
     * **There is deliberately no «already emitted» flag in PHP.** The first version had one, and a
     * test caught what it costs: the flag is process state, not request state, so a process that
     * renders more than one response — an in-process test client, a long-running worker, anything
     * that produces two pages — gave the second page a button and no listener. A visible control
     * that does nothing is worse than no control, which is the same reason the button ships hidden.
     *
     * The cost is a repeated `<script>` on a form with several password fields. It is a few hundred
     * bytes against a class of bug that only appears outside a plain request, which is exactly where
     * nobody looks for it.
     */
    private static function script(): string
    {
        $nonce = '';
        $application = \Pramnos\Application\Application::currentInstance();
        if (is_object($application) && !empty($application->cspNonce)) {
            $nonce = ' nonce="' . htmlspecialchars((string) $application->cspNonce, ENT_QUOTES) . '"';
        }

        $eyeOpen = self::EYE_OPEN;
        $eyeClosed = self::EYE_CLOSED;

        return <<<HTML
<script{$nonce}>
(function () {
  // Idempotent: several fields on one page each carry this, and only the first one binds.
  if (window.__pramnosPasswordToggleBound) {
    return;
  }
  window.__pramnosPasswordToggleBound = true;

  var SELECTOR = '[data-pramnos-password-toggle]';
  var EYE_OPEN = '{$eyeOpen}';
  var EYE_CLOSED = '{$eyeClosed}';

  /**
   * Put the button inside the field's right-hand edge.
   *
   * Done here rather than in the markup so that no view has to change and no theme has to ship CSS
   * for it: the button is already rendered straight after its input, which is the correct **tab**
   * order, and absolute positioning moves it visually without moving it in the document.
   *
   * The padding on the field is what stops a long password running underneath the icon.
   */
  function place(button, field) {
    var wrapper = field.parentNode;

    if (!wrapper || wrapper.getAttribute('data-pramnos-password-wrap') === null) {
      wrapper = document.createElement('span');
      wrapper.setAttribute('data-pramnos-password-wrap', '');
      wrapper.style.position = 'relative';
      wrapper.style.display = 'block';
      field.parentNode.insertBefore(wrapper, field);
      wrapper.appendChild(field);
    }

    wrapper.appendChild(button);

    button.style.position = 'absolute';
    button.style.top = '50%';
    button.style.transform = 'translateY(-50%)';
    button.style.right = '0.55em';
    button.style.display = 'inline-flex';
    button.style.alignItems = 'center';
    button.style.justifyContent = 'center';
    button.style.padding = '0.2em';
    button.style.margin = '0';
    button.style.border = '0';
    button.style.background = 'transparent';
    button.style.color = 'inherit';
    button.style.opacity = '0.6';
    button.style.cursor = 'pointer';
    button.style.lineHeight = '1';

    // Room for the icon, kept in the field's own units so it survives a theme's font size.
    var padding = 'calc(1.15em + 1.1em)';
    if (getComputedStyle(field).direction === 'rtl') {
      button.style.right = 'auto';
      button.style.left = '0.55em';
      field.style.paddingLeft = padding;
    } else {
      field.style.paddingRight = padding;
    }
  }

  function reveal() {
    document.querySelectorAll(SELECTOR).forEach(function (button) {
      var field = document.getElementById(button.getAttribute('aria-controls'));

      if (field) {
        place(button, field);
      }

      button.hidden = false;
    });
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest(SELECTOR) : null;
    if (!button) {
      return;
    }

    var field = document.getElementById(button.getAttribute('aria-controls'));
    if (!field) {
      return;
    }

    // Where the caret was, so toggling mid-word does not send it to the end.
    var start = field.selectionStart;
    var end = field.selectionEnd;
    var reveal = field.type === 'password';

    field.type = reveal ? 'text' : 'password';
    button.setAttribute('aria-pressed', reveal ? 'true' : 'false');

    // The words are the accessible name, not the visible content: the icon carries the meaning on
    // screen and the label carries it to a screen reader, which announces the new state on toggle.
    var label = reveal
      ? button.getAttribute('data-hide-label')
      : button.getAttribute('data-show-label');
    button.setAttribute('aria-label', label);
    button.setAttribute('title', label);
    button.innerHTML = reveal ? EYE_CLOSED : EYE_OPEN;

    field.focus();
    if (start !== null && typeof field.setSelectionRange === 'function') {
      try {
        field.setSelectionRange(start, end);
      } catch (e) {
        // A field that refuses a selection range is still perfectly usable.
      }
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', reveal);
  } else {
    reveal();
  }
})();
</script>
HTML;
    }

    /** `t()` when the translator is loaded, the English otherwise. */
    private static function translate(string $text): string
    {
        return function_exists('t') ? (string) t($text) : $text;
    }
}
