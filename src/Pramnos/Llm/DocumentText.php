<?php

declare(strict_types=1);

namespace Pramnos\Llm;

use Pramnos\Email\MailMessage;

/**
 * The words in a document somebody attached — a press release, a brief — so a conversation can
 * work from it: `Conversations::ask(…, attachment: ['name' => $name, 'text' => DocumentText::from($name, $bytes)])`.
 *
 * Word (.docx) and OpenDocument (.odt) are zip files with the text in one XML
 * part, so the standard library reads them: paragraphs become lines, headings
 * and list items included, and styles and comments are left behind. Their
 * pictures — a press release's own photos — are read apart, by
 * {@see pictures()}, and listed after the words under {@see PICTURES}. Plain text and Markdown are what they are. A saved
 * email (.eml) is its subject, sender and words, with the documents it carries
 * read too and its pictures taken ({@see MailMessage}). PDF is not read:
 * it would need a parser the framework does not carry.
 */
class DocumentText
{
    public const TYPES = ['docx', 'odt', 'txt', 'md', 'eml'];

    /** The most a document may weigh, before reading. */
    public const MAX_BYTES = 5_000_000;

    /** An email weighs its photos too: one mailed in may be larger than a document uploaded. */
    public const MAX_MAIL_BYTES = 25_000_000;

    /** The most pictures taken from one document. */
    public const MAX_PICTURES = 10;

    /** The line after a document's words that lists its pictures, one address a line below it. */
    public const PICTURES = 'Pictures in this document:';

    /**
     * The text, or null when this is not a document it can read.
     */
    public static function from(string $name, string $bytes): ?string
    {
        $type = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($bytes === '' || strlen($bytes) > ($type === 'eml' ? self::MAX_MAIL_BYTES : self::MAX_BYTES) || !in_array($type, self::TYPES, true)) {
            return null;
        }

        $text = match ($type) {
            'docx'  => self::fromZip($bytes, 'word/document.xml', 'p', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'),
            'odt'   => self::fromZip($bytes, 'content.xml', '*', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0'),
            'eml'   => self::fromMail($bytes),
            default => mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-7'),
        };

        if ($text === null) {
            return null;
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $text)));
    }

    /**
     * The pictures inside a .docx (`word/media/`) or .odt (`Pictures/`), in the
     * document's order, at most {@see MAX_PICTURES}; none for any other type.
     *
     * @return list<array{name: string, bytes: string}>
     */
    public static function pictures(string $name, string $bytes): array
    {
        // An email's own pictures, then those inside the documents it carries.
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'eml') {
            if (!MailMessage::looksLikeOne($bytes)) {
                return [];
            }

            $found = [];

            foreach (MailMessage::parse($bytes)['attachments'] as $file) {
                if (str_starts_with($file['type'], 'image/') || preg_match('/\.(jpe?g|png|gif|webp)$/i', $file['name'])) {
                    $found[] = ['name' => preg_match('/\.(jpe?g|png|gif|webp)$/i', $file['name']) ? $file['name'] : $file['name'] . '.' . (explode('/', $file['type'])[1] ?? 'jpg'), 'bytes' => $file['bytes']];
                } elseif (!str_ends_with(strtolower($file['name']), '.eml')) {
                    array_push($found, ...self::pictures($file['name'], $file['bytes']));
                }
            }

            return array_slice($found, 0, self::MAX_PICTURES);
        }

        // A picture on its own (pasted or attached in Ask AI) is its own one picture.
        if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $name) && $bytes !== '' && strlen($bytes) <= self::MAX_BYTES) {
            return [['name' => basename($name), 'bytes' => $bytes]];
        }

        $folder = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'docx'  => 'word/media/',
            'odt'   => 'Pictures/',
            default => null,
        };

        if ($folder === null || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return [];
        }

        $file = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($file, $bytes);
        $found = [];

        try {
            $zip = new \ZipArchive();

            if ($zip->open($file) !== true) {
                return [];
            }

            for ($i = 0; $i < $zip->numFiles && count($found) < self::MAX_PICTURES; $i++) {
                $entry = (string) $zip->getNameIndex($i);

                // A picture, by its name; what it really is is checked when it is stored.
                if (str_starts_with($entry, $folder) && preg_match('/\.(jpe?g|png|gif|webp)$/i', $entry)) {
                    $found[] = ['name' => basename($entry), 'bytes' => (string) $zip->getFromIndex($i)];
                }
            }

            $zip->close();
        } finally {
            @unlink($file);
        }

        return $found;
    }

    /** A saved email's subject, sender and words, and the words of the documents it carries; null if it is not one. */
    private static function fromMail(string $bytes): ?string
    {
        if (!MailMessage::looksLikeOne($bytes)) {
            return null;
        }

        $mail     = MailMessage::parse($bytes);
        // A forward is the forwarded email's: its subject and its first sender; who forwarded it is said apart.
        $original = $mail['original'];
        $subject  = $original['subject'] ?? MailMessage::cleanSubject($mail['subject']);
        $lines    = array_filter([
            $subject !== '' ? 'Subject: ' . $subject : '',
            ($original['from'] ?? $mail['from']) !== '' ? 'From: ' . ($original['from'] ?? $mail['from']) : '',
            $original !== null && $mail['from'] !== '' ? 'Forwarded by: ' . $mail['from'] : '',
            $mail['date'] !== '' ? 'Date: ' . $mail['date'] : '',
        ]);
        $text  = implode("\n", $lines) . "\n\n" . $mail['text'];

        foreach ($mail['attachments'] as $file) {
            $type = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            // A document it carries — the press release itself, often — read in; an email in an email is not read twice over.
            $words = in_array($type, ['docx', 'odt', 'txt', 'md'], true) ? self::from($file['name'], $file['bytes']) : null;

            if ($words !== null && $words !== '') {
                $text .= "\n\n--- " . $file['name'] . " ---\n" . $words;
            }
        }

        return $text;
    }

    /**
     * A document's pictures, kept where the application keeps media, and listed after its words
     * under {@see PICTURES} — so a conversation and a writer can use the document's own photos.
     *
     * `$store` keeps one picture and answers its address, or null when it will not; a picture
     * that cannot be kept is left out, and the words still go. Without one nothing is kept.
     *
     * @param callable(string $path, string $filename): ?string $store
     */
    public static function keepPictures(string $name, string $bytes, callable $store): string
    {
        $urls = [];

        foreach (self::pictures($name, $bytes) as $picture) {
            // Named as the picture is: a media library reads the extension.
            $base = (string) tempnam(sys_get_temp_dir(), 'pic');
            $path = $base . '.' . strtolower(pathinfo($picture['name'], PATHINFO_EXTENSION));
            file_put_contents($path, $picture['bytes']);

            try {
                $url = $store($path, $picture['name']);

                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            } catch (\Throwable) {
                continue;
            } finally {
                @unlink($path);
                @unlink($base);
            }
        }

        return $urls === [] ? '' : "\n\n" . self::PICTURES . "\n- " . implode("\n- ", $urls);
    }

    /**
     * The picture addresses listed after a document's words, in order.
     *
     * @return list<string>
     */
    public static function picturesIn(string $text): array
    {
        $at = mb_strrpos($text, self::PICTURES);

        if ($at === false) {
            return [];
        }

        preg_match_all('#^- (https?://\S+)$#m', mb_substr($text, $at), $found);

        return $found[1];
    }

    /**
     * One XML part's paragraphs, a line each. For .docx a paragraph is `w:p`;
     * for .odt it is `text:p` or `text:h`, which `*` with the namespace finds.
     */
    private static function fromZip(string $bytes, string $part, string $paragraph, string $namespace): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($file, $bytes);

        try {
            $zip = new \ZipArchive();

            if ($zip->open($file) !== true) {
                return null;
            }

            $xml = $zip->getFromName($part);
            $zip->close();
        } finally {
            @unlink($file);
        }

        $dom = new \DOMDocument();

        // LIBXML_NONET: a document's XML never reaches the network.
        if ($xml === false || !@$dom->loadXML($xml, LIBXML_NONET)) {
            return null;
        }

        $lines = [];

        foreach ($dom->getElementsByTagNameNS($namespace, $paragraph) as $node) {
            if ($paragraph === '*' && !in_array($node->localName, ['p', 'h'], true)) {
                continue;
            }

            $lines[] = trim($node->textContent);
        }

        return implode("\n", $lines);
    }
}
