<?php

declare(strict_types=1);

namespace Pramnos\Moderation;

/**
 * What a message may say: markup that would run as a page's own refused, words the application
 * lists masked or refused, links let through, masked, or kept to some sites.
 * The rules are `moderation.filter` in `app.php`, or given to {@see apply()}:
 *
 * ```php
 * 'filter' => [
 *     'words'  => ['βλάκας', 'idiot'],      // masked: *****
 *     'refuse' => ['κάποια φράση'],         // the whole message refused
 *     'links'  => 'allow',                  // 'allow' every link, 'deny' every one, or 'only' the sites below
 *     'sites'  => ['example.com'],          // with 'only': these and their subdomains
 * ],
 * ```
 *
 * Words compare whole and ignoring case. The page's own escaping is still what keeps a message
 * from running as code; refusing markup here keeps it out of every other place it is shown.
 */
final class Filter
{
    /** What is masked in place of a link. */
    public const MASK = '***';

    /**
     * Let a text through, changed, or refuse it.
     *
     * @param array<string, mixed>|null $rules The rules; `moderation.filter` when null
     * @return array{allowed: bool, text: string, reasons: list<string>} What to keep, and why it changed or was refused
     */
    public static function apply(string $text, ?array $rules = null): array
    {
        $rules   = $rules ?? Moderation::rule('filter');
        $reasons = [];
        if (self::dangerous($text)) {
            return ['allowed' => false, 'text' => $text, 'reasons' => ['markup']];
        }
        foreach ((array) ($rules['refuse'] ?? []) as $phrase) {
            if (trim((string) $phrase) !== '' && preg_match(self::whole((string) $phrase), $text)) {
                return ['allowed' => false, 'text' => $text, 'reasons' => ['refused']];
            }
        }
        foreach ((array) ($rules['words'] ?? []) as $word) {
            if (trim((string) $word) === '') {
                continue;
            }
            $masked = (string) preg_replace_callback(self::whole((string) $word), static fn (array $m): string => str_repeat('*', mb_strlen($m[0])), $text);
            if ($masked !== $text) {
                $text = $masked;
                $reasons[] = 'words';
            }
        }
        $links = (string) ($rules['links'] ?? 'allow');
        if ($links !== 'allow') {
            $sites  = array_map(static fn ($s): string => strtolower(trim((string) $s)), (array) ($rules['sites'] ?? []));
            $found  = false;
            $text   = (string) preg_replace_callback('~\b(?:https?://|www\.)[^\s<>"\']+~iu', static function (array $m) use ($links, $sites, &$found): string {
                $host = strtolower((string) parse_url(str_starts_with(strtolower($m[0]), 'www.') ? 'http://' . $m[0] : $m[0], PHP_URL_HOST));
                foreach ($links === 'only' ? $sites : [] as $site) {
                    if ($site !== '' && ($host === $site || str_ends_with($host, '.' . $site))) {
                        return $m[0];
                    }
                }
                $found = true;

                return self::MASK;
            }, $text);
            if ($found) {
                $reasons[] = 'links';
            }
        }
        return ['allowed' => true, 'text' => $text, 'reasons' => array_values(array_unique($reasons))];
    }

    /** Markup that would run where it is shown: a script, a frame, an event handler, a `javascript:` address. */
    public static function dangerous(string $text): bool
    {
        return (bool) preg_match('~<\s*/?\s*(script|style|iframe|object|embed|applet|meta|base|link|form|input|textarea|button|select)\b'
            . '|\bon(load|error|click|dblclick|mouse\w+|key\w+|focus|blur|change|submit|input|wheel|copy|paste|contextmenu|pointer\w+|animation\w+)\s*='
            . '|javascript\s*:|data\s*:\s*text/html~i', $text);
    }

    /** A word or phrase, whole, ignoring case. */
    private static function whole(string $phrase): string
    {
        return '/(?<![\p{L}\p{N}])' . preg_quote(trim($phrase), '/') . '(?![\p{L}\p{N}])/iu';
    }
}
