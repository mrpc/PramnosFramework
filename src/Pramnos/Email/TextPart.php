<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * The text part an application wrote ({@see Email::setTextBody()}), kept inside the stored body.
 *
 * Not a column of `mails`: bodies are kept out of the row — compressed in `mail_bodies`, or one
 * gzipped file per distinct body — and a text part in every row would put back exactly the
 * uncompressed, unshared bulk that was taken out. Kept with the HTML instead, it is compressed and
 * shared as the HTML is, in every store mode, with the unsubscribe link turned into the same
 * placeholder ({@see BodyStore::prepare()}).
 *
 * As `<script type="text/plain" data-pf-text>…</script>` at the end of the stored HTML: a browser
 * does not run it or show it, the text converter drops scripts, and a `--` signature line is fine
 * in it, as it would not be in a comment. Only the stored copy carries it — never a message sent.
 */
final class TextPart
{
    private const OPEN  = '<script type="text/plain" data-pf-text>';
    private const CLOSE = '</script>';

    /** The stored body: the HTML with the text part after it; the HTML alone without one. */
    public static function embed(string $html, ?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return $html;
        }

        // `</` cannot appear inside a script element; nothing else needs escaping.
        return $html . self::OPEN . str_replace('</', '<\/', $text) . self::CLOSE;
    }

    /** The text part kept in a stored body, or null where it was the HTML converted. */
    public static function of(string $stored): ?string
    {
        $at = strrpos($stored, self::OPEN);
        if ($at === false) {
            return null;
        }
        $end = strpos($stored, self::CLOSE, $at);

        return str_replace('<\/', '</', substr($stored, $at + strlen(self::OPEN), $end === false ? null : $end - $at - strlen(self::OPEN)));
    }

    /** The HTML of a stored body, without its text part — what is sent again. */
    public static function strip(string $stored): string
    {
        $at = strrpos($stored, self::OPEN);
        if ($at === false) {
            return $stored;
        }
        $end = strpos($stored, self::CLOSE, $at);

        return substr($stored, 0, $at) . ($end === false ? '' : substr($stored, $end + strlen(self::CLOSE)));
    }
}
