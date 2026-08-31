<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\Logger;

/**
 * A type the caller declared survives, and the JSON sniff stops guessing over it.
 *
 * The guard before the sniff read `$content` — a variable that does not exist at that point. It
 * is assigned further down, inside the `startoffile` branch, and holds a file's contents as a
 * string. So `isset()` was false and the guard passed **on every call**. `isset()` never warns
 * about an undeclared variable, which is why it lasted: the line looks like a check and behaves
 * like nothing.
 *
 * The consequence that mattered is not the wasted `json_decode`. It is that a type the caller
 * supplied was overwritten with `\'json\'` whenever the message happened to parse as JSON — and a
 * bare number parses, and so do `true`, `null` and a quoted string. An error logged as `\'42\'`
 * was filed as JSON, so a reader filtering by type never saw it.
 *
 * Reported by an application migrating onto this framework, as FW-047.
 */
#[CoversClass(Logger::class)]
class LoggerRespectsADeclaredTypeTest extends TestCase
{
    private mixed $previousMode = null;

    protected function setUp(): void
    {
        /*
         * The output mode is a private static, so it is saved and put back by reflection.
         *
         * This test is about what lands in a file, and the suite may be in stream mode. Setting
         * it and then setting it to some *other* fixed value afterwards is what a first version
         * of this did — and `null` (meaning "resolve from the environment each time") is not a
         * value `setOutputMode()` accepts, so three unrelated tests that read their own log files
         * started failing. Restoring exactly what was there is the only version that does not
         * leak.
         */
        $property = new \ReflectionProperty(Logger::class, 'outputMode');
        $this->previousMode = $property->getValue();

        Logger::setOutputMode(Logger::OUTPUT_FILE);
    }

    protected function tearDown(): void
    {
        $property = new \ReflectionProperty(Logger::class, 'outputMode');
        $property->setValue(null, $this->previousMode);
    }

    private function entry(string $file): string
    {
        $path = Logger::logDirectory() . DIRECTORY_SEPARATOR . $file . '.log';

        $body = is_file($path) ? (string) file_get_contents($path) : '';
        @unlink($path);

        return $body;
    }

    /**
     * A message that parses as JSON no longer steals a declared type.
     *
     * `\'42\'` is valid JSON. Before this, logging it with `type => error` filed it as `json`.
     */
    public function testADeclaredTypeIsNotOverwrittenByAMessageThatParses(): void
    {
        // Arrange
        $file = 'fw047-declared-' . bin2hex(random_bytes(4));

        // Act
        Logger::log('42', $file, 'log', false, ['type' => 'error']);

        // Assert
        $entry = $this->entry($file);
        $this->assertNotSame('', $entry, 'the entry has to have been written');
        $this->assertStringNotContainsString('json', $entry,
            'a type the caller declared must survive the JSON sniff');
    }

    /**
     * And detection still runs when the caller said nothing.
     *
     * The other half: fixing the guard must not switch the feature off.
     */
    public function testDetectionStillRunsWhenNoTypeWasDeclared(): void
    {
        // Arrange
        $file = 'fw047-sniff-' . bin2hex(random_bytes(4));

        // Act
        Logger::log('{"a":1}', $file);

        // Assert
        $this->assertStringContainsString('json', $this->entry($file));
    }

    /**
     * A message that is not JSON gets no type invented for it.
     */
    public function testPlainTextIsNotCalledJson(): void
    {
        // Arrange
        $file = 'fw047-plain-' . bin2hex(random_bytes(4));

        // Act
        Logger::log('a plain sentence', $file);

        // Assert
        $this->assertStringNotContainsString('json', $this->entry($file));
    }
}
