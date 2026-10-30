<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Llm\Extractor;

/**
 * Fields read out of a document by a model, and checked before they are used.
 *
 * What holds: the model answers through the one tool whose arguments are the fields; each field is checked — required, number (as people write it), real date, one of its options,
 * its pattern — and what fails is reported by field and kept out of the data; a file is read for
 * its text first, and a PDF without pdftotext says why; a provider that fails, or a model that
 * records nothing, is said.
 */
#[CoversClass(Extractor::class)]
#[CoversClass(\Pramnos\Llm\Client::class)]
class ExtractorTest extends LlmTestCase
{
    private const FIELDS = [
        'supplier' => ['type' => 'text', 'required' => true],
        'issued'   => ['type' => 'date', 'required' => true],
        'total'    => ['type' => 'number', 'required' => true, 'description' => 'Including VAT'],
        'currency' => ['type' => 'enum', 'options' => ['EUR', 'USD']],
        'vat'      => ['type' => 'text', 'pattern' => '/^EL\d{9}$/'],
        'paid'     => ['type' => 'boolean'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
    }

    /**
     * Read through the tool, where the provider has no structured answer of its own (DeepSeek),
     * checked, and the tool described from the fields — a number as the document writes it
     * passes the schema and is read by check().
     */
    public function testFieldsAreReadAndChecked(): void
    {
        // Arrange
        $this->deepseek();
        $this->answer([
            [200, self::calls([['record_fields', ['supplier' => ' ACME ', 'issued' => '2026-10-02', 'total' => '1.240,50', 'currency' => 'EUR', 'vat' => 'EL123456789', 'paid' => 'yes']]])],
            [200, self::says('Done.')],
        ]);

        // Act
        $read = (new Extractor())->extract('Invoice from ACME…', self::FIELDS, 'a supplier invoice');

        // Assert
        $this->assertTrue($read['ok'], $read['says']);
        $this->assertSame(['supplier' => 'ACME', 'issued' => '2026-10-02', 'total' => 1240.5, 'currency' => 'EUR', 'vat' => 'EL123456789', 'paid' => true], $read['data']);
        $tool = $this->sent[0]['body']['tools'][0]['function'];
        $this->assertSame('record_fields', $tool['name']);
        $this->assertSame(['EUR', 'USD'], $tool['parameters']['properties']['currency']['enum']);
        $this->assertSame('Including VAT', $tool['parameters']['properties']['total']['description']);
        $this->assertStringContainsString('YYYY-MM-DD', $tool['parameters']['properties']['issued']['description']);
        $this->assertStringContainsString('a supplier invoice', $this->sent[0]['body']['messages'][0]['content']);
        $this->assertSame(['number', 'string'], $tool['parameters']['properties']['total']['type']);
        $this->assertArrayNotHasKey('response_format', $this->sent[0]['body'], 'DeepSeek takes no json_schema');
    }

    /**
     * Where the provider takes `response_format: json_schema` (OpenAI), the fields are asked for
     * in that shape with no tool; an answer that does not fit is sent back once with what is
     * wrong, and a second that does not fit is a failure.
     */
    public function testAProvidersOwnStructuredAnswer(): void
    {
        // Arrange — a list where an object belongs, then the right one in a fence; then twice wrong
        $this->answer([
            [200, self::says('[1, 2]')],
            [200, self::says("```json\n{\"supplier\": \"ACME\", \"issued\": \"2026-10-02\", \"total\": 12}\n```")],
            [200, self::says('not JSON')],
        ]);
        $schema = ['type' => 'object', 'properties' => ['supplier' => ['type' => 'string']], 'required' => ['supplier']];

        // Act
        $read   = (new Extractor())->extract('Invoice', self::FIELDS);
        $client = new \Pramnos\Llm\Client();
        $direct = $client->askForJson('Read it', [['role' => 'user', 'content' => 'x']], $schema, 'invoice');
        $client->ask('Plain', [['role' => 'user', 'content' => 'y']]);

        // Assert
        $this->assertTrue($read['ok'], $read['says']);
        $this->assertSame(['supplier' => 'ACME', 'issued' => '2026-10-02', 'total' => 12], $read['data']);
        $first = $this->sent[0]['body'];
        $this->assertSame('json_schema', $first['response_format']['type']);
        $this->assertSame('record_fields', $first['response_format']['json_schema']['name']);
        $this->assertArrayNotHasKey('tools', $first, 'no tool when the provider answers in the shape itself');
        $this->assertStringContainsString('That does not fit the schema', end($this->sent[1]['body']['messages'])['content']);
        $this->assertFalse($direct['ok']);
        $this->assertNull($direct['data']);
        $this->assertStringStartsWith('The answer did not fit the shape asked for: the answer is not a JSON object', $direct['says']);
        $this->assertSame('json_schema', $this->sent[3]['body']['response_format']['type'], 'the second try is in the shape too');
        $this->assertArrayNotHasKey('response_format', end($this->sent)['body'], 'a plain question after it is not');
    }

    /** What fails is reported by field and left out; what is missing and required is named. */
    public function testWhatFailsIsReportedByField(): void
    {
        // Act
        $checked = Extractor::check(
            ['supplier' => '', 'issued' => '2026-02-30', 'total' => 'about a thousand', 'currency' => 'GBP', 'vat' => 'DE1', 'paid' => 'perhaps'],
            self::FIELDS
        );
        $numbers = array_map(static fn (string $n) => Extractor::check(['n' => $n], ['n' => ['type' => 'number']])['data']['n'] ?? null,
            ['1,240.50', '€ 12', '-3', '1240', '-']);

        // Assert
        $this->assertFalse($checked['ok']);
        $this->assertSame(['supplier'], $checked['missing']);
        $this->assertSame(['issued' => 'not a date (YYYY-MM-DD)', 'total' => 'not a number', 'currency' => 'not one of EUR, USD',
            'vat' => 'not in the expected form', 'paid' => 'not yes or no'], $checked['invalid']);
        $this->assertSame([], $checked['data']);
        $this->assertSame('Not found: supplier. Not valid: issued, total, currency, vat, paid.', $checked['says']);
        $this->assertSame([1240.5, 12, -3, 1240, null], $numbers);
        $this->assertSame(['ok' => true, 'data' => [], 'missing' => [], 'invalid' => [], 'says' => ''], Extractor::check([], ['note' => ['type' => 'text']]), 'an optional field may be absent');
    }

    /** A file is read for its text; a PDF without pdftotext, an unreadable file, no fields, a failing provider, a model that records nothing — each said. */
    public function testFilesAndFailures(): void
    {
        // Arrange
        $this->deepseek();
        $this->answer([
            [200, self::calls([['record_fields', ['supplier' => 'ACME', 'issued' => '2026-10-02', 'total' => 5]]])],
            [200, self::says('Done.')],
            [500, []],
            [200, self::says('I could not.')],
            [200, self::says('Still nothing.')],
        ]);
        $extractor = new Extractor();

        // Act
        $text    = $extractor->fromFile('invoice.txt', "Invoice\nACME", self::FIELDS);
        $pdf     = Extractor::pdfText('%PDF-1.4') === null ? $extractor->fromFile('invoice.pdf', '%PDF-1.4', self::FIELDS) : null;
        $odd     = $extractor->fromFile('invoice.exe', 'MZ', self::FIELDS);
        $none    = $extractor->extract('x', []);
        $failed  = $extractor->extract('x', self::FIELDS);
        $nothing = $extractor->extract('x', self::FIELDS);

        // Assert
        $this->assertTrue($text['ok'], $text['says']);
        $this->assertStringContainsString("Invoice\nACME", $this->sent[0]['body']['messages'][1]['content']);
        if ($pdf !== null) {
            $this->assertStringContainsString('pdftotext', $pdf['says'], 'a PDF without the tool says what it needs');
        }
        $this->assertSame('The text of invoice.exe could not be read.', $odd['says']);
        $this->assertSame('Name the fields to read.', $none['says']);
        $this->assertFalse($failed['ok']);
        $this->assertSame('The model gave no answer in the shape asked for.', $nothing['says']);
    }

    /** Answer on DeepSeek, which has no structured answer of its own: the tool is the way. */
    private function deepseek(): void
    {
        $this->setting('llm_deepseek_api_key', 'sk-deepseek');
        $this->setting('llm_provider', 'deepseek');
    }
}
