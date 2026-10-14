<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst;

/**
 * Personal data and secrets out of what a tool hands the model.
 *
 * The analyst's provider is a third party, and a log line or an email subject carries whatever
 * somebody typed. Every tool result passes through here before the model reads it: email
 * addresses are masked to their shape (`a***@d***.com`), and tokens, payment cards, phone numbers
 * and IP addresses are replaced by what they were.
 *
 * Conservative where a near-miss is common: a phone number written without a single separator
 * is indistinguishable from an order number or a Unix time, and is left alone; a card number
 * must pass the Luhn check.
 */
final class Redactor
{
    /**
     * @param list<string> $keep Exact strings left as they are — the one email the `user` tool was asked for
     */
    public static function clean(string $text, array $keep = []): string
    {
        $held = [];
        foreach (array_values(array_filter($keep, static fn (string $k): bool => $k !== '')) as $i => $exact) {
            $held["\x02{$i}\x02"] = $exact;
            $text = str_replace($exact, "\x02{$i}\x02", $text);
        }

        $text = (string) preg_replace('/\beyJ[\w-]+\.[\w-]+\.[\w-]+/', '[token]', $text);
        $text = (string) preg_replace('/\bBearer\s+[\w.~+\/-]+=*/i', 'Bearer [token]', $text);
        $text = (string) preg_replace('/\b(?:sk|pk|rk)[-_][\w-]{16,}/', '[token]', $text);
        $text = (string) preg_replace('/\b[a-f0-9]{32,}\b/i', '[token]', $text);

        $text = (string) preg_replace_callback(
            '/[A-Za-z0-9._%+-]+@((?:[A-Za-z0-9-]+\.)+)([A-Za-z]{2,})\b/',
            static fn (array $m): string => $m[0][0] . '***@' . $m[1][0] . '***.' . $m[2],
            $text
        );

        // Cards before phones: a card written in groups of four also looks like a phone number.
        // Not glued to a letter: a run of digits inside an id — the hex of an export's link,
        // `7-d71592725381644e` — is part of that id, and a Luhn pass there is chance.
        $text = (string) preg_replace_callback(
            '/(?<![\w-])(?:\d[ -]?){12,18}\d(?![\w-])/',
            static fn (array $m): string => self::luhn((string) preg_replace('/\D/', '', $m[0])) ? '[card]' : $m[0],
            $text
        );

        // International: a plus and eight to fifteen digits.
        $text = (string) preg_replace_callback(
            '/(?<![\w+])\+[1-9][\d\s().-]{6,18}\d(?!\d)/',
            static fn (array $m): string => self::digits($m[0], 8, 15) ? '[phone]' : $m[0],
            $text
        );
        // Local: groups with separators, nine to twelve digits — `210 123 4567`, `(210) 123-4567`.
        $text = (string) preg_replace_callback(
            '/(?<![\w.:-])\(?\d{2,4}\)?[\s.-]\d{3,4}[\s.-]?\d{3,4}(?![\w:-])/',
            static fn (array $m): string => self::digits($m[0], 9, 12) ? '[phone]' : $m[0],
            $text
        );

        // IPv6 needs a digit in it: `Feed::add` is a method, not an address.
        $text = (string) preg_replace_callback(
            '/(?<![\w:])(?:(?:[0-9a-f]{1,4}:){4,7}[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}:){0,6}:(?::?[0-9a-f]{1,4}){0,6})(?![\w:])/i',
            static fn (array $m): string => strlen($m[0]) >= 3 && preg_match('/\d/', $m[0]) === 1 && substr_count($m[0], ':') >= 2 ? '[ip]' : $m[0],
            $text
        );
        $text = (string) preg_replace_callback(
            '/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/',
            static fn (array $m): string => max(array_map('intval', explode('.', $m[0]))) <= 255 ? '[ip]' : $m[0],
            $text
        );

        return strtr($text, $held);
    }

    /** Whether a run of 13 to 19 digits passes the Luhn check a card number carries. */
    private static function luhn(string $digits): bool
    {
        $length = strlen($digits);
        $sum = 0;
        for ($i = 0; $i < $length; $i++) {
            $d = (int) $digits[$length - 1 - $i];
            if ($i % 2 === 1) {
                $d = $d * 2 > 9 ? $d * 2 - 9 : $d * 2;
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }

    private static function digits(string $text, int $min, int $max): bool
    {
        $count = strlen((string) preg_replace('/\D/', '', $text));

        return $count >= $min && $count <= $max;
    }
}
