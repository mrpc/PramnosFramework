<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * An email as it is saved (.eml) or received: its subject, sender, date, words and attachments.
 *
 * ```php
 * $mail = MailMessage::parse($raw);
 * $mail['subject']; $mail['from']; $mail['text']; $mail['attachments'];   // name, type, bytes each
 * $mail['original'];   // the email it forwards, when it forwards one: its first sender and subject
 * ```
 *
 * MIME is read with the standard library: multipart parts walked at any depth, base64 and
 * quoted-printable decoded, charsets turned to UTF-8, encoded-word headers (`=?UTF-8?B?…?=`)
 * read. The plain-text part is preferred; HTML is turned into its words when it is all there is.
 * Not a full RFC 5322 reader: no S/MIME, no TNEF (winmail.dat).
 */
class MailMessage
{
    /**
     * `original` is the email this one forwards, when it forwards one: who first
     * sent it and its subject, from an attached message or the block a mail
     * program writes above a forwarded one.
     *
     * @return array{subject: string, from: string, reply_to: string, message_id: string, references: list<string>, date: string, to: list<string>, text: string, attachments: list<array{name: string, type: string, bytes: string}>, original: array{from: string, subject: string, reply_to?: string, message_id?: string, references?: list<string>}|null}
     */
    public static function parse(string $raw): array
    {
        [$headers, $body] = self::split(str_replace("\r\n", "\n", $raw));
        $found            = ['plain' => '', 'html' => '', 'attachments' => [], 'original' => null];

        self::walk($headers, $body, $found, 0);

        $text = trim($found['plain']) !== '' ? $found['plain'] : self::words($found['html']);

        return [
            'subject'     => self::header($headers, 'subject'),
            'from'        => self::header($headers, 'from'),
            // To answer it in its own thread.
            'reply_to'    => self::header($headers, 'reply-to'),
            'message_id'  => self::ids(self::header($headers, 'message-id'))[0] ?? '',
            'references'  => self::ids(self::header($headers, 'references')),
            'date'        => self::header($headers, 'date'),
            // Every address it was sent to, the ones a mail server adds included: which inbox it was meant for.
            'to'          => self::addresses($headers),
            'text'        => trim((string) preg_replace("/\n{3,}/", "\n\n", $text)),
            'attachments' => $found['attachments'],
            // An attached email is the original only when this one says it forwards (Fwd:, FW:, ΠΡΘ:): attached
            // to a press release of its own, it is just more of what it carries.
            'original'    => ($found['original'] !== null && self::cleanSubject(self::header($headers, 'subject')) !== self::header($headers, 'subject')
                ? $found['original'] : null) ?? self::forwardedIn($text, self::header($headers, 'subject')),
        ];
    }

    /** @return list<string> The message ids in a header, without their angle brackets. */
    private static function ids(string $value): array
    {
        preg_match_all('/<([^<>\s]+)>/', $value, $found);

        return $found[1];
    }

    /**
     * The language an email is written in, as far as a reply needs to know:
     * Greek when a fair share of its letters are Greek, else English.
     */
    public static function language(string $text): string
    {
        $letters = (int) preg_match_all('/\pL/u', $text);
        $greek   = (int) preg_match_all('/\p{Greek}/u', $text);

        return $letters > 0 && $greek / $letters > 0.3 ? 'el' : 'en';
    }

    /** "Fwd: Fw: ΠΡΘ: Blue Remixes" as "Blue Remixes". */
    public static function cleanSubject(string $subject): string
    {
        return trim((string) preg_replace('/^(\s*(fwd?|fw|tr|wg|πρθ|προωθ\.?|προώθηση)\s*:\s*)+/iu', '', $subject));
    }

    /**
     * The forwarded email's sender and subject, from the block a mail program
     * writes above it: Gmail's "Forwarded message" (Προωθημένο μήνυμα), Apple
     * Mail's "Begin forwarded message", Outlook's From/Sent/Subject — in English
     * or Greek. Only where the email says it is a forward, by that block's
     * marker or by its own subject, so a quoted reply is not taken for one.
     *
     * @return array{from: string, subject: string}|null
     */
    private static function forwardedIn(string $text, string $subject): ?array
    {
        $marked = preg_match('/-{2,}\s*(forwarded message|προωθημένο μήνυμα)\s*-{2,}|begin forwarded message|αρχή προωθημένου μηνύματος/iu', $text, $marker, PREG_OFFSET_CAPTURE);

        if (!$marked && self::cleanSubject($subject) === trim($subject)) {
            return null;
        }

        $after = str_replace("\r", '', $marked ? substr($text, (int) $marker[0][1]) : $text);
        /*
         * A line the mail program wrapped is put back first: Gmail breaks a
         * sender's name and address over two lines, and the sender would then
         * be the one who forwarded it.
         */
        $after = (string) preg_replace('/^(\W*(?:from|από)\s*:[^@\n]*)\n([^\n:]*@[^\n]*)$/imu', '$1$2', $after);

        if (!preg_match('/^\W*(from|από)\s*:\s*(.+@.+)$/imu', $after, $from, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // The block's subject: within the few lines that follow its From.
        $block = implode("\n", array_slice(explode("\n", substr($after, (int) $from[0][1])), 0, 8));
        $title = preg_match('/^\W*(subject|θέμα)\s*:\s*(.+)$/imu', $block, $s) ? self::cleanSubject(trim($s[2])) : self::cleanSubject($subject);
        // A block subject the mail program wrapped is the start of the email's own: that one is whole.
        $title = $title !== '' && str_starts_with(self::cleanSubject($subject), $title) ? self::cleanSubject($subject) : $title;

        return ['from' => trim(str_replace(['*', 'mailto:'], '', $from[2][0])), 'subject' => $title];
    }

    /** Whether these bytes look like an email: headers, a From or Subject among them. */
    public static function looksLikeOne(string $raw): bool
    {
        $head = substr(str_replace("\r\n", "\n", $raw), 0, 8000);

        return preg_match('/^(from|subject|date|received|return-path|mime-version|message-id|to):/im', $head) === 1
            && preg_match('/^(from|subject):/im', $head) === 1;
    }

    /** @param array{plain: string, html: string, attachments: list<array{name: string, type: string, bytes: string}>} $found */
    private static function walk(array $headers, string $body, array &$found, int $depth): void
    {
        $type = self::contentType($headers);

        if (str_starts_with($type['type'], 'multipart/') && $type['boundary'] !== '' && $depth < 10) {
            $parts = explode('--' . $type['boundary'], $body);
            array_shift($parts);

            foreach ($parts as $part) {
                if (str_starts_with($part, '--')) {
                    break;
                }

                [$h, $b] = self::split(ltrim($part, "\n"));
                self::walk($h, $b, $found, $depth + 1);
            }

            return;
        }

        if ($type['type'] === 'message/rfc822' && $depth < 10) {
            // A forwarded email, attached whole: its words and files count as this one's, and its sender is the original.
            [$h, $b] = self::split(self::decode($headers, $body));
            $found['original'] ??= ['from' => self::header($h, 'from'), 'subject' => self::cleanSubject(self::header($h, 'subject')),
                // What a reply to its sender needs to join their thread.
                'reply_to' => self::header($h, 'reply-to'), 'message_id' => self::ids(self::header($h, 'message-id'))[0] ?? '', 'references' => self::ids(self::header($h, 'references'))];
            self::walk($h, $b, $found, $depth + 1);

            return;
        }

        $bytes       = self::decode($headers, $body);
        $disposition = strtolower(self::header($headers, 'content-disposition'));
        $name        = self::param(self::header($headers, 'content-disposition'), 'filename') ?: self::param(self::header($headers, 'content-type'), 'name');

        if ($name !== '' || str_starts_with($disposition, 'attachment') || !str_starts_with($type['type'], 'text/')) {
            $found['attachments'][] = ['name' => basename($name !== '' ? $name : 'attachment'), 'type' => $type['type'], 'bytes' => $bytes];

            return;
        }

        $text = self::utf8($bytes, $type['charset']);

        if ($type['type'] === 'text/html') {
            $found['html'] .= $text;
        } else {
            $found['plain'] .= ($found['plain'] === '' ? '' : "\n\n") . $text;
        }
    }

    /** @return list<string> The addresses in To, Cc and the delivery headers, lower-case, once each. */
    private static function addresses(array $headers): array
    {
        $found = [];

        foreach (['to', 'cc', 'delivered-to', 'x-original-to', 'envelope-to'] as $name) {
            preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', (string) ($headers[$name] ?? ''), $m);
            array_push($found, ...array_map('strtolower', $m[0]));
        }

        return array_values(array_unique($found));
    }

    /** @return array{0: array<string, string>, 1: string} Headers by lower-case name (unfolded, decoded), and the body. */
    private static function split(string $part): array
    {
        $at      = strpos($part, "\n\n");
        $head    = $at === false ? $part : substr($part, 0, $at);
        $body    = $at === false ? '' : substr($part, $at + 2);
        $headers = [];

        foreach (explode("\n", (string) preg_replace("/\n[ \t]+/", ' ', $head)) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                $headers[strtolower($m[1])] ??= $m[2];
            }
        }

        return [$headers, $body];
    }

    private static function header(array $headers, string $name): string
    {
        $value = (string) ($headers[$name] ?? '');

        if ($value === '') {
            return '';
        }

        return self::words8($value);
    }

    /**
     * A header's words: encoded-words (`=?UTF-8?B?…?=`) decoded; a header with
     * none, sent as plain UTF-8 as mail clients now do, kept as it is — the
     * decoder drops every character it does not expect there.
     */
    private static function words8(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return trim(self::utf8($value, 'UTF-8'));
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim($decoded === false ? $value : $decoded);
    }

    /** @return array{type: string, charset: string, boundary: string} */
    private static function contentType(array $headers): array
    {
        $raw = (string) ($headers['content-type'] ?? 'text/plain');

        return [
            'type'     => strtolower(trim(explode(';', $raw)[0])) ?: 'text/plain',
            'charset'  => self::param($raw, 'charset') ?: 'UTF-8',
            'boundary' => self::param($raw, 'boundary'),
        ];
    }

    /** A header parameter — `name="x"`, `filename*=UTF-8''…` — decoded. */
    private static function param(string $header, string $name): string
    {
        if (preg_match('/' . $name . '\*=([^\';]*)\'[^\']*\'([^;]+)/i', $header, $m)) {
            return self::utf8(rawurldecode(trim($m[2], " \"")), $m[1] ?: 'UTF-8');
        }

        if (!preg_match('/' . $name . '\s*=\s*("([^"]*)"|([^;\s]+))/i', $header, $m)) {
            return '';
        }

        return self::words8($m[2] !== '' ? $m[2] : ($m[3] ?? ''));
    }

    private static function decode(array $headers, string $body): string
    {
        return match (strtolower(trim((string) ($headers['content-transfer-encoding'] ?? '')))) {
            'base64'           => (string) base64_decode((string) preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    private static function utf8(string $bytes, string $charset): string
    {
        $charset = strtoupper(trim($charset, " \"'"));

        if ($charset === 'UTF-8' || $charset === 'US-ASCII' || $charset === '') {
            return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-7');
        }

        $converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);

        return $converted === false ? mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-7') : $converted;
    }

    /** HTML as its words: paragraphs and line breaks kept as lines, scripts and styles dropped. */
    private static function words(string $html): string
    {
        $html = (string) preg_replace(['#<(script|style)\b.*?</\1>#is', '#<br\s*/?>#i', '#</(p|div|li|h[1-6]|tr)>#i'], ['', "\n", "\n\n"], $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
