<?php

namespace Pramnos\Html;

/**
 * The two pieces of `<head>` markup a page needs to be read correctly by a crawler.
 *
 * Both are here rather than on `Document` because a page does not have to be built
 * through a `Document` to need them: an application rendering a layout template
 * directly wants the same string, produced the same way, and a second implementation
 * of the encoding rules below is how the two drift.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Seo
{
    /**
     * JSON encoding flags for a `ld+json` block.
     *
     * Not a preference. Each one answers a specific way the block goes wrong:
     *
     * - **`JSON_HEX_TAG`** — the only injection this format has. A `</script>` inside
     *   any value would otherwise end the block early and everything after it would
     *   be parsed as markup. Structured data is assembled from record titles and
     *   descriptions, which is exactly where such a string arrives from.
     * - **`JSON_HEX_AMP`** — the same reasoning one step out, for consumers that
     *   re-parse the block out of an HTML string.
     * - **`JSON_UNESCAPED_SLASHES`** — without it every URL becomes `https:\/\/…`.
     *   Valid JSON, and it makes the block unreadable to the person checking it in
     *   view-source, which is the only way anybody ever checks it.
     * - **`JSON_UNESCAPED_UNICODE`** — without it non-Latin text becomes `\uXXXX`,
     *   with the same cost and no benefit.
     */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP;

    /**
     * A `ld+json` script block for structured data.
     *
     * ```php
     * echo Seo::jsonLd([
     *     '@context' => 'https://schema.org',
     *     '@type'    => 'RadioStation',
     *     'name'     => $station['name'],
     * ]);
     * ```
     *
     * **Absent is not empty.** Omit a key you have no value for rather than emitting
     * `"genre": ""` — an empty string is a claim that the field is blank, which is a
     * different statement from not making the claim, and consumers treat it as one.
     * This method does not do that for you: it cannot tell a deliberate empty string
     * from a missing lookup, and guessing would be worse than either.
     *
     * @param  array<string, mixed> $data Structured data
     * @return string Empty string when there is nothing to say
     */
    public static function jsonLd(array $data): string
    {
        if ($data === []) {
            return '';
        }

        $json = json_encode($data, self::JSON_FLAGS);
        if ($json === false) {
            // Unencodable data — a resource handle, invalid UTF-8, recursion. A page
            // without structured data is a smaller problem than a page with a broken
            // script block in its head, and the caller gets to keep rendering.
            return '';
        }

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * A canonical link element.
     *
     * @param  string $url Absolute URL this page should be indexed as
     * @return string Empty string when there is no URL
     */
    public static function canonicalLink(string $url): string
    {
        if (trim($url) === '') {
            return '';
        }

        return '<link rel="canonical" href="'
            . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false)
            . '">';
    }
}
