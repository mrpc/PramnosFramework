<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\LogViewer;

/**
 * Unit tests for Pramnos\Logs\LogViewer.
 *
 * LogViewer has two layers:
 *   (a) Public API (setFile, setParameters, setLogLevel, processFile, renderHtml)
 *       which requires real log files and a live document/application.
 *   (b) Private pure helpers (normalizeLineEndings, safeTrim, normalizeSearchTerm,
 *       searchInLine, searchInJson, searchKeyValuePair) plus the public static
 *       getErrorLevel() — all of which contain pure business logic.
 *
 * This test class covers the pure helper layer via reflection so that the
 * production logic is exercised without needing real log files or a booted app.
 * The static getErrorLevel() is tested directly as it is already public.
 */
#[CoversClass(LogViewer::class)]
class LogViewerTest extends TestCase
{
    private LogViewer $viewer;

    protected function setUp(): void
    {
        // Arrange – create viewer with no whitelist and no controller
        $this->viewer = new LogViewer();
    }

    // ── setFile() / setParameters() / setLogLevel() ───────────────────────────

    /**
     * setFile() must throw InvalidArgumentException when the filename is not
     * in the whitelist (when checkWhitelist = true and whitelist is non-empty).
     *
     * This covers the whitelist-check branch in setFile() (lines ~94-96).
     */
    public function testSetFileThrowsWhenFilenameNotInWhitelist(): void
    {
        // Arrange — viewer with a strict whitelist
        $restricted = new LogViewer(['allowed.log']);

        // Assert – non-whitelisted filename rejected
        $this->expectException(\InvalidArgumentException::class);
        $restricted->setFile('not_allowed.log');
    }

    /**
     * setFile() with checkWhitelist=false must store the filename without
     * consulting the whitelist at all.
     *
     * This covers the bypass path in setFile() (line ~98).
     */
    public function testSetFileBypassesWhitelistWhenCheckIsFalse(): void
    {
        // Arrange — restricted viewer
        $restricted = new LogViewer(['only.log']);

        // Act — bypass whitelist
        $result = $restricted->setFile('anything.log', false);

        // Assert – fluent return and filename stored
        $this->assertSame($restricted, $result,
            'setFile() must return $this for fluent chaining');
    }

    /**
     * setFile() with an empty whitelist always accepts any filename.
     *
     * This covers the `!empty($this->whitelist)` short-circuit in setFile() (line ~94).
     */
    public function testSetFileAcceptsAnyFilenameWithEmptyWhitelist(): void
    {
        // Arrange — viewer with empty whitelist (default)
        $result = $this->viewer->setFile('some.log');

        // Assert – no exception, fluent return
        $this->assertSame($this->viewer, $result);
    }

    /**
     * setParameters() must store the pagination and search options and return $this.
     *
     * This covers setParameters() (lines ~127-134): page/maxLines clamped to min 1,
     * search decoded and {space} replaced.
     */
    public function testSetParametersStoresOptionsAndReturnsFluentSelf(): void
    {
        // Act
        $result = $this->viewer->setParameters(true, 3, 50, 'hello%20world');

        // Assert – fluent return
        $this->assertSame($this->viewer, $result);

        // Assert – page and maxLines stored via reflection
        $ref = new \ReflectionClass(LogViewer::class);

        $page = $ref->getProperty('page');
        $page->setAccessible(true);
        $this->assertSame(3, $page->getValue($this->viewer));

        $maxLines = $ref->getProperty('maxLines');
        $maxLines->setAccessible(true);
        $this->assertSame(50, $maxLines->getValue($this->viewer));

        $search = $ref->getProperty('search');
        $search->setAccessible(true);
        $this->assertSame('hello world', $search->getValue($this->viewer),
            'URL-encoded search term must be decoded');
    }

    /**
     * setParameters() must clamp page and maxLines to at least 1.
     *
     * This covers the `max(1, …)` clamping on lines ~130-131.
     */
    public function testSetParametersClampsBelowMinimumValues(): void
    {
        // Act — zero/negative values
        $this->viewer->setParameters(false, 0, -5, '');

        // Assert
        $ref = new \ReflectionClass(LogViewer::class);

        $page = $ref->getProperty('page');
        $page->setAccessible(true);
        $this->assertSame(1, $page->getValue($this->viewer),
            'page must be clamped to 1');

        $maxLines = $ref->getProperty('maxLines');
        $maxLines->setAccessible(true);
        $this->assertSame(1, $maxLines->getValue($this->viewer),
            'maxLines must be clamped to 1');
    }

    /**
     * setLogLevel() must lowercase and store the level string, returning $this.
     *
     * This covers setLogLevel() (lines ~141-145).
     */
    public function testSetLogLevelLowercasesAndStoresLevel(): void
    {
        // Act
        $result = $this->viewer->setLogLevel('WARNING');

        // Assert – fluent return
        $this->assertSame($this->viewer, $result);

        // Assert – stored as lowercase
        $ref = new \ReflectionClass(LogViewer::class);
        $prop = $ref->getProperty('logLevel');
        $prop->setAccessible(true);
        $this->assertSame('warning', $prop->getValue($this->viewer));
    }

    // ── getErrorLevel() ───────────────────────────────────────────────────────

    /**
     * getErrorLevel() must return 'fatal' for messages containing "fatal error".
     *
     * This covers the first `strpos` branch in getErrorLevel() (line ~873).
     */
    public function testGetErrorLevelReturnsFatalForFatalError(): void
    {
        // Assert
        $this->assertSame('fatal', LogViewer::getErrorLevel('PHP Fatal error: ...'),
            '"fatal error" in message must return "fatal"');
    }

    /**
     * getErrorLevel() must return 'warning' for messages containing "warning".
     *
     * This covers line ~877.
     */
    public function testGetErrorLevelReturnsWarningForWarning(): void
    {
        $this->assertSame('warning', LogViewer::getErrorLevel('PHP Warning: Division by zero'));
    }

    /**
     * getErrorLevel() must return 'notice' for messages containing "notice".
     *
     * This covers line ~881.
     */
    public function testGetErrorLevelReturnsNoticeForNotice(): void
    {
        $this->assertSame('notice', LogViewer::getErrorLevel('PHP Notice: Undefined variable'));
    }

    /**
     * getErrorLevel() must return 'deprecated' for messages containing "deprecated".
     *
     * This covers line ~885.
     */
    public function testGetErrorLevelReturnsDeprecatedForDeprecated(): void
    {
        $this->assertSame('deprecated', LogViewer::getErrorLevel('Deprecated: Function ereg()'));
    }

    /**
     * getErrorLevel() must return 'strict' for "strict standards" messages.
     *
     * This covers line ~889.
     */
    public function testGetErrorLevelReturnsStrictForStrictStandards(): void
    {
        $this->assertSame('strict', LogViewer::getErrorLevel('Strict Standards: Only variables'));
    }

    /**
     * getErrorLevel() must return null for messages that do not match any
     * known level keyword.
     *
     * This covers the final `return null` path (line ~889).
     */
    public function testGetErrorLevelReturnsNullForUnknownLevel(): void
    {
        $this->assertNull(LogViewer::getErrorLevel('Some random info message'));
    }

    /**
     * getErrorLevel() must be case-insensitive (strtolower is applied first).
     *
     * This covers the `strtolower($message)` call at line ~871.
     */
    public function testGetErrorLevelIsCaseInsensitive(): void
    {
        // Assert – UPPER CASE message
        $this->assertSame('warning', LogViewer::getErrorLevel('PHP WARNING: something bad'));
        $this->assertSame('fatal', LogViewer::getErrorLevel('FATAL ERROR happened'));
    }

    // ── normalizeLineEndings() ────────────────────────────────────────────────

    /**
     * normalizeLineEndings() must convert CRLF to LF.
     *
     * This covers the first str_replace branch in normalizeLineEndings() (line ~701).
     */
    public function testNormalizeLineEndingsConvertsCrLfToLf(): void
    {
        // Act
        $result = $this->callPrivate('normalizeLineEndings', "line1\r\nline2\r\nline3");

        // Assert
        $this->assertSame("line1\nline2\nline3", $result,
            'CRLF must be converted to LF');
    }

    /**
     * normalizeLineEndings() must convert lone CR to LF.
     *
     * This covers the second str_replace in normalizeLineEndings() (line ~702).
     */
    public function testNormalizeLineEndingsConvertsCrToLf(): void
    {
        // Act
        $result = $this->callPrivate('normalizeLineEndings', "line1\rline2\rline3");

        // Assert
        $this->assertSame("line1\nline2\nline3", $result,
            'Lone CR must be converted to LF');
    }

    // ── safeTrim() ────────────────────────────────────────────────────────────

    /**
     * safeTrim() must remove trailing spaces and tabs but leave newlines intact.
     *
     * This covers safeTrim() (line ~713): rtrim with " \t".
     */
    public function testSafeTrimRemovesTrailingSpacesAndTabs(): void
    {
        // Act
        $result = $this->callPrivate('safeTrim', "hello world  \t  ");

        // Assert
        $this->assertSame('hello world', $result);
    }

    /**
     * safeTrim() must NOT remove leading whitespace.
     *
     * This confirms rtrim (not trim) is used — leading spaces are intentional
     * in log indentation.
     */
    public function testSafeTrimPreservesLeadingWhitespace(): void
    {
        // Act
        $result = $this->callPrivate('safeTrim', '  indented line  ');

        // Assert – leading spaces kept
        $this->assertStringStartsWith('  ', $result);
    }

    // ── normalizeSearchTerm() ─────────────────────────────────────────────────

    /**
     * normalizeSearchTerm() must return an empty string for an empty input.
     *
     * This covers the `if (empty($search)) return ''` branch (line ~723).
     */
    public function testNormalizeSearchTermReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame('', $this->callPrivate('normalizeSearchTerm', ''));
        $this->assertSame('', $this->callPrivate('normalizeSearchTerm', null));
    }

    /**
     * normalizeSearchTerm() must remove extra spaces around colons.
     *
     * This covers the first preg_replace in normalizeSearchTerm() (line ~728).
     */
    public function testNormalizeSearchTermRemovesSpacesAroundColons(): void
    {
        // Act
        $result = $this->callPrivate('normalizeSearchTerm', 'level : error');

        // Assert
        $this->assertSame('level:error', $result,
            'Spaces around colon must be removed');
    }

    // ── searchInLine() ────────────────────────────────────────────────────────

    /**
     * searchInLine() must return true when the search term is empty.
     *
     * This covers the `if (empty($search)) return true` guard (line ~758).
     */
    public function testSearchInLineReturnsTrueForEmptySearch(): void
    {
        // Assert – empty search always matches
        $this->assertTrue($this->callPrivate('searchInLine', 'any log line here', ''));
    }

    /**
     * searchInLine() must match a term inside a plain-text log line (case-insensitive).
     *
     * This covers the final `stripos($normalizedLine, $search)` path (line ~789)
     * after the JSON parse attempt fails on plain text.
     */
    public function testSearchInLineFindsCaseInsensitiveTermInPlainText(): void
    {
        // Act
        $result = $this->callPrivate(
            'searchInLine',
            '[2025-01-15 14:00:00] ERROR Something bad happened',
            'something bad'
        );

        // Assert
        $this->assertTrue($result,
            'Case-insensitive text search must find the term in a plain log line');
    }

    /**
     * searchInLine() must return false when the term is not found in a plain text line.
     */
    public function testSearchInLineReturnsFalseWhenTermNotFound(): void
    {
        // Act
        $result = $this->callPrivate('searchInLine', 'everything is fine', 'critical error');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * searchInLine() must search inside JSON log entries.
     *
     * When the line is valid JSON, the content is decoded and searched; a
     * matching value inside the JSON must produce true.
     *
     * This covers the `json_decode($line, true)` path (lines ~765-780).
     */
    public function testSearchInLineFindTermInsideJsonLine(): void
    {
        // Arrange — a JSON log line (typical framework JSON log format)
        $line = json_encode([
            'level'   => 'error',
            'message' => 'DB connection failed',
            'context' => ['host' => 'db.example.com'],
        ]);

        // Act
        $found    = $this->callPrivate('searchInLine', $line, 'DB connection');
        $notFound = $this->callPrivate('searchInLine', $line, 'nonexistent_term');

        // Assert
        $this->assertTrue($found,
            'searchInLine() must find terms inside JSON values');
        $this->assertFalse($notFound,
            'searchInLine() must return false when term is absent from JSON');
    }

    // ── searchInJson() ────────────────────────────────────────────────────────

    /**
     * searchInJson() with a key:value pattern must find exact matches.
     *
     * This covers the `strpos($search, ':')` branch in searchInJson() (line ~801).
     */
    public function testSearchInJsonFindsKeyValuePair(): void
    {
        // Arrange
        $data = ['level' => 'error', 'message' => 'disk full'];

        // Act
        $found    = $this->callPrivate('searchInJson', $data, 'level:error');
        $notFound = $this->callPrivate('searchInJson', $data, 'level:warning');

        // Assert
        $this->assertTrue($found, '"level:error" must match {"level":"error"}');
        $this->assertFalse($notFound, '"level:warning" must not match {"level":"error"}');
    }

    /**
     * searchInJson() must recursively search nested arrays.
     *
     * This covers the `is_array($value)` recursive branch (line ~818-820).
     */
    public function testSearchInJsonSearchesNestedArrays(): void
    {
        // Arrange
        $data = [
            'outer' => [
                'inner' => 'hidden-value',
            ],
        ];

        // Act
        $result = $this->callPrivate('searchInJson', $data, 'hidden-value');

        // Assert
        $this->assertTrue($result, 'Nested array values must be searchable');
    }

    /**
     * searchInJson() must match numeric values when the string form matches.
     *
     * This covers the `is_numeric($value) && (string)$value === $search` branch
     * (line ~826).
     */
    public function testSearchInJsonMatchesNumericValues(): void
    {
        // Arrange
        $data = ['retries' => 3, 'code' => 500];

        // Act
        $found = $this->callPrivate('searchInJson', $data, '500');

        // Assert
        $this->assertTrue($found, 'Numeric value 500 must match search term "500"');
    }

    // ── Private reflection helper ─────────────────────────────────────────────

    /**
     * Call a private method on $this->viewer via reflection.
     */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $rm = new \ReflectionMethod(LogViewer::class, $method);
        $rm->setAccessible(true);
        return $rm->invoke($this->viewer, ...$args);
    }
}
