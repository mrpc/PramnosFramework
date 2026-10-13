<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Llm\DocumentText;

/**
 * Files sent with a question, as the one attachment a conversation takes.
 *
 * Each document's words go under its name, and every picture — a document's own and those sent
 * on their own — is kept by the application's store and listed after them, so the model can use
 * them. At most {@see FILES} files of {@see BYTES} together.
 */
final class Attachments
{
    /** The most files sent with one question. */
    public const FILES = 10;

    /** The most bytes they may weigh together. */
    public const BYTES = 5_000_000;

    /**
     * @param list<array{name: string, bytes: string}>              $files
     * @param callable(string $path, string $filename): ?string     $store Keeps a picture, answers its address
     * @return array{name: string, text: string}|string|null The attachment, why not, or null for no files
     */
    public static function build(array $files, callable $store): array|string|null
    {
        $names = [];
        $words = [];
        $urls  = [];
        $total = 0;

        foreach (array_slice($files, 0, self::FILES) as $file) {
            $name  = basename((string) ($file['name'] ?? 'document'));
            $bytes = (string) ($file['bytes'] ?? '');

            if ($bytes === '') {
                continue;
            }

            if (($total += strlen($bytes)) > self::BYTES) {
                return 'The files are too large together: at most ' . (self::BYTES / 1_000_000) . ' MB.';
            }

            $picture = preg_match('/\.(jpe?g|png|gif|webp)$/i', $name) === 1;
            $text    = $picture ? '' : DocumentText::from($name, $bytes);
            $kept    = $picture || ($text !== null && $text !== '') ? DocumentText::picturesIn(DocumentText::keepPictures($name, $bytes, $store)) : [];

            if ($picture ? $kept === [] : ($text === null || $text === '')) {
                return '“' . $name . '” could not be read. Attach pictures, or Word (.docx), OpenDocument (.odt), text, Markdown or email (.eml) files.';
            }

            $names[] = $name;
            $urls    = [...$urls, ...$kept];

            if (!$picture) {
                $words[$name] = (string) $text;
            }
        }

        if ($names === []) {
            return null;
        }

        $text = count($words) === 1 ? (string) reset($words) : implode("\n\n", array_map(
            static fn (string $name, string $text): string => '--- ' . $name . " ---\n" . $text,
            array_keys($words),
            $words
        ));

        return [
            'name' => implode(', ', $names),
            'text' => trim(($text !== '' ? $text : 'Pictures the person attached.')
                . ($urls === [] ? '' : "\n\n" . DocumentText::PICTURES . "\n- " . implode("\n- ", array_slice($urls, 0, DocumentText::MAX_PICTURES)))),
        ];
    }

    /**
     * The files a form uploaded with a question, `files[]` — only those this request uploaded
     * without an error.
     *
     * @return list<array{name: string, bytes: string}>
     */
    public static function uploaded(): array
    {
        $files = $_FILES['files'] ?? null;

        if (!is_array($files) || !is_array($files['name'] ?? null)) {
            return [];
        }

        $out = [];

        foreach ($files['name'] as $i => $name) {
            $path = (string) ($files['tmp_name'][$i] ?? '');

            if ((int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($path)) {
                $out[] = ['name' => (string) $name, 'bytes' => (string) file_get_contents($path)];
            }
        }

        return $out;
    }
}
