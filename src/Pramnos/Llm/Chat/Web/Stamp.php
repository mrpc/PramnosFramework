<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

/**
 * A message's `created_at` as the conversation page writes it: an ISO-8601 instant in UTC, for
 * `<time datetime>`, which `pf-chat.js` shows in the reader's own zone.
 *
 * The column is written in UTC; a value read back without an offset is UTC.
 */
final class Stamp
{
    /** `2026-10-13T19:50:00Z`, or '' for a value that is not a time. */
    public static function iso(string $stamp): string
    {
        if (trim($stamp) === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($stamp, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return '';
        }
    }

    /** The same instant as text for a page read without JavaScript: `13 Oct 2026 19:50 UTC`. */
    public static function text(string $iso): string
    {
        return $iso === '' ? '' : gmdate('j M Y H:i', (int) strtotime($iso)) . ' UTC';
    }
}
