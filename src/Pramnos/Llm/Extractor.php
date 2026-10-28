<?php

declare(strict_types=1);

namespace Pramnos\Llm;

/**
 * Fields read out of a document — an invoice, an order, a form — by a model, and checked before
 * anybody uses them. It never writes: the application shows what was read for a person to confirm,
 * or hands it to an Ask proposal ({@see Chat\Proposals}) to approve.
 *
 * ```php
 * $fields = [
 *     'supplier' => ['type' => 'text', 'required' => true],
 *     'issued'   => ['type' => 'date', 'required' => true],
 *     'total'    => ['type' => 'number', 'required' => true, 'description' => 'Including VAT'],
 *     'currency' => ['type' => 'enum', 'options' => ['EUR', 'USD']],
 *     'vat'      => ['type' => 'text', 'pattern' => '/^EL\d{9}$/'],
 *     'paid'     => ['type' => 'boolean'],
 * ];
 * $read = (new Extractor())->fromFile('invoice.pdf', $bytes, $fields, 'a supplier invoice');
 * // ['ok' => true, 'data' => ['supplier' => 'ACME', 'issued' => '2026-10-02', 'total' => 1240.0, …],
 * //  'missing' => [], 'invalid' => [], 'says' => '']
 * ```
 *
 * The model is given one tool whose arguments are the fields — not prose to be parsed — told to
 * answer through it, and to leave out what the document does not say rather than guess; a model that
 * answers without it read nothing, and is reported so. Then each field
 * is checked: a required one is there; a number is a number (`1.240,50` and `1,240.50` both read);
 * a date is a real date, written `YYYY-MM-DD`; an `enum` is one of its options; a `pattern`
 * matches. What fails is reported by field, and kept out of `data`.
 *
 * The document goes to the provider the client is connected to: pass a {@see Client} of the
 * feature and connection that may receive it.
 */
final class Extractor
{
    /** The kinds of field. */
    public const TYPES = ['text', 'number', 'date', 'boolean', 'enum'];

    /** Characters of a document sent. */
    public const CHARS = 60000;

    public function __construct(private readonly ?Client $client = null)
    {
    }

    /**
     * Read the fields out of a file: its text, by {@see DocumentText} — and a PDF's, where
     * `pdftotext` is installed.
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array{ok: bool, data: array<string, mixed>, missing: list<string>, invalid: array<string, string>, says: string}
     */
    public function fromFile(string $name, string $bytes, array $fields, string $what = 'a document'): array
    {
        $text = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf' ? self::pdfText($bytes) : DocumentText::from($name, $bytes);
        if ($text === null || trim($text) === '') {
            return self::failed('The text of ' . basename($name) . ' could not be read'
                . (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf' ? ' — reading a PDF needs pdftotext (poppler-utils) on the server.' : '.'));
        }

        return $this->extract($text, $fields, $what);
    }

    /**
     * Read the fields out of a document's text.
     *
     * @param array<string, array<string, mixed>> $fields name => type, required, options (enum), pattern, description
     * @return array{ok: bool, data: array<string, mixed>, missing: list<string>, invalid: array<string, string>, says: string}
     */
    public function extract(string $text, array $fields, string $what = 'a document'): array
    {
        if ($fields === []) {
            return self::failed('Name the fields to read.');
        }
        $properties = [];
        foreach ($fields as $name => $field) {
            $type = (string) ($field['type'] ?? 'text');
            $properties[(string) $name] = array_filter([
                'type'        => match ($type) { 'number' => 'number', 'boolean' => 'boolean', default => 'string' },
                'enum'        => $type === 'enum' ? array_values(array_map('strval', (array) ($field['options'] ?? []))) : null,
                'description' => trim(((string) ($field['description'] ?? '')) . ($type === 'date' ? ' As YYYY-MM-DD.' : '')) ?: null,
            ], static fn ($v): bool => $v !== null);
        }
        $read  = null;
        $tools = ['record_fields' => [
            'description' => 'Record the fields read from the document. Leave out a field the document does not say; never guess one.',
            'parameters'  => ['type' => 'object', 'properties' => $properties],
            'run'         => static function (array $a) use (&$read): string {
                $read = $a;

                return 'Recorded.';
            },
        ]];
        $client = $this->client ?? new Client();
        $answer = $client->askWithTools(
            'You read ' . $what . ' and record its fields with record_fields, exactly as the document gives them. Do not invent what it does not say.',
            [['role' => 'user', 'content' => mb_substr($text, 0, self::CHARS)]],
            $tools,
            2
        );
        if (!($answer['ok'] ?? false)) {
            return self::failed((string) ($answer['says'] ?? 'The model did not answer.'));
        }
        if (!is_array($read)) {
            return self::failed('The model read nothing.');
        }

        return self::check($read, $fields);
    }

    /**
     * Check what was read against the fields: present when required, of its type, one of its
     * options, matching its pattern. Public so an application can check what a person corrected.
     *
     * @param array<string, mixed>                $read
     * @param array<string, array<string, mixed>> $fields
     * @return array{ok: bool, data: array<string, mixed>, missing: list<string>, invalid: array<string, string>, says: string}
     */
    public static function check(array $read, array $fields): array
    {
        $data = [];
        $missing = [];
        $invalid = [];
        foreach ($fields as $name => $field) {
            $value = $read[$name] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                if (!empty($field['required'])) {
                    $missing[] = (string) $name;
                }
                continue;
            }
            [$clean, $why] = self::value($value, $field);
            if ($why !== null) {
                $invalid[(string) $name] = $why;
                continue;
            }
            $data[(string) $name] = $clean;
        }
        $ok = $missing === [] && $invalid === [];

        return ['ok' => $ok, 'data' => $data, 'missing' => $missing, 'invalid' => $invalid,
            'says' => $ok ? '' : trim(($missing !== [] ? 'Not found: ' . implode(', ', $missing) . '. ' : '') . ($invalid !== [] ? 'Not valid: ' . implode(', ', array_keys($invalid)) . '.' : ''))];
    }

    /**
     * One value, made what its field says, or why it cannot be.
     *
     * @param array<string, mixed> $field
     * @return array{0: mixed, 1: ?string}
     */
    private static function value(mixed $value, array $field): array
    {
        $type = (string) ($field['type'] ?? 'text');
        switch ($type) {
            case 'number':
                $number = is_int($value) || is_float($value) ? $value : self::number((string) $value);

                return $number === null ? [null, 'not a number'] : [$number, null];
            case 'date':
                $text = trim((string) $value);
                $at   = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

                return $at === false || $at->format('Y-m-d') !== $text ? [null, 'not a date (YYYY-MM-DD)'] : [$text, null];
            case 'boolean':
                $bool = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                return $bool === null ? [null, 'not yes or no'] : [$bool, null];
            case 'enum':
                $options = array_map('strval', (array) ($field['options'] ?? []));

                return in_array((string) $value, $options, true) ? [(string) $value, null] : [null, 'not one of ' . implode(', ', $options)];
            default:
                $text = trim((string) $value);
                if (isset($field['pattern']) && preg_match((string) $field['pattern'], $text) !== 1) {
                    return [null, 'not in the expected form'];
                }

                return [$text, null];
        }
    }

    /** A number as people write it — `1.240,50`, `1,240.50`, `1240.5`, `€ 12` — or null. */
    private static function number(string $text): float|int|null
    {
        $text = (string) preg_replace('/[^\d.,\-]/u', '', $text);
        if ($text === '' || $text === '-') {
            return null;
        }
        $comma = strrpos($text, ',');
        $dot   = strrpos($text, '.');
        // The separator written last is the decimal one; the other groups thousands.
        if ($comma !== false && ($dot === false || $comma > $dot)) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        } else {
            $text = str_replace(',', '', $text);
        }
        if (!is_numeric($text)) {
            return null;
        }

        return str_contains($text, '.') ? (float) $text : (int) $text;
    }

    /** A PDF's text, where `pdftotext` is installed; null otherwise. */
    public static function pdfText(string $bytes): ?string
    {
        $binary = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
        if ($binary === '') {
            return null;
        }
        $process = proc_open([$binary, '-layout', '-q', '-', '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        fwrite($pipes[0], $bytes);
        fclose($pipes[0]);
        $text = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? $text : null;
    }

    /** @return array{ok: false, data: array{}, missing: list<string>, invalid: array<string, string>, says: string} */
    private static function failed(string $says): array
    {
        return ['ok' => false, 'data' => [], 'missing' => [], 'invalid' => [], 'says' => $says];
    }
}
