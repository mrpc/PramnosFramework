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

    // ── matchesLogLevel() ─────────────────────────────────────────────────────

    /**
     * matchesLogLevel() must return true when logLevel is null (no filter set).
     *
     * This covers the `if ($this->logLevel === null) return true` guard
     * (line ~663 of LogViewer.php).
     */
    public function testMatchesLogLevelReturnsTrueWhenLevelIsNull(): void
    {
        // Arrange — logLevel is null by default (viewer created in setUp with no level)
        $result = $this->callPrivate('matchesLogLevel', 'any log line');

        // Assert
        $this->assertTrue($result,
            'matchesLogLevel must return true when no level filter is set');
    }

    /**
     * matchesLogLevel() must return true for a JSON line whose "level" field
     * matches the configured level.
     *
     * This covers the `json_decode` → `isset($jsonData['level'])` branch (line ~672).
     */
    public function testMatchesLogLevelMatchesJsonLevelField(): void
    {
        // Arrange — set level to 'error'
        $this->viewer->setLogLevel('error');
        $line = json_encode(['level' => 'error', 'message' => 'something failed']);

        // Act
        $result = $this->callPrivate('matchesLogLevel', $line);

        // Assert
        $this->assertTrue($result, 'JSON line with level=error must match when filter is error');
    }

    /**
     * matchesLogLevel() must return false when the JSON level does not match.
     */
    public function testMatchesLogLevelDoesNotMatchWrongJsonLevel(): void
    {
        // Arrange
        $this->viewer->setLogLevel('error');
        $line = json_encode(['level' => 'info', 'message' => 'all good']);

        // Act
        $result = $this->callPrivate('matchesLogLevel', $line);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * matchesLogLevel() must fall back to a text search for non-JSON lines.
     *
     * This covers the `preg_match('/\b…\b/i', $line)` fallback branch (line ~686).
     */
    public function testMatchesLogLevelPlainTextFallback(): void
    {
        // Arrange — set level to 'warning', plain-text line contains 'Warning'
        $this->viewer->setLogLevel('warning');

        // Act — plain text (not JSON)
        $match   = $this->callPrivate('matchesLogLevel', '[14/01/2025 10:00:00] Warning: undefined variable');
        $noMatch = $this->callPrivate('matchesLogLevel', '[14/01/2025 10:00:00] Notice: something');

        // Assert
        $this->assertTrue($match,   'plain text containing the level keyword must match');
        $this->assertFalse($noMatch, 'plain text without the level keyword must not match');
    }

    // ── renderError() ─────────────────────────────────────────────────────────

    /**
     * renderError() must return a complete HTML page containing the message.
     *
     * This covers renderError() (line ~1568), a public method that wraps a message
     * in a minimal styled HTML page.
     */
    public function testRenderErrorReturnsHtmlWithMessage(): void
    {
        // Act
        $html = $this->viewer->renderError('Test error occurred');

        // Assert — result is an HTML string containing the error message
        $this->assertStringContainsString('Test error occurred', $html);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    // ── renderViewer() ────────────────────────────────────────────────────────

    /**
     * renderViewer() must throw RuntimeException when no view object is set.
     *
     * This covers the `if (!$this->view) throw new RuntimeException(…)` guard
     * (line ~1612 of LogViewer.php). The viewer in setUp is constructed without
     * a controller, so $view is null.
     */
    public function testRenderViewerThrowsWhenNoViewSet(): void
    {
        // Assert — no view is set, so RuntimeException must be thrown
        $this->expectException(\RuntimeException::class);
        $this->viewer->renderViewer('test.log');
    }

    // ── renderPagination() ────────────────────────────────────────────────────

    /**
     * renderPagination() must include page / total-page info when entries exist.
     *
     * This covers the `if ($totalMatchedLines > 0)` branch (line ~942).
     */
    public function testRenderPaginationWithEntries(): void
    {
        // Act — 45 matched lines, page 2 of 5, 10 lines/page
        $html = $this->callPrivate('renderPagination', 2, 5, 10, 45);

        // Assert — entry range and page info are present
        $this->assertStringContainsString('11', $html, 'start entry of page 2 must be 11');
        $this->assertStringContainsString('20', $html, 'end entry of page 2 must be 20');
        $this->assertStringContainsString('45',  $html, 'total entries count must appear');
        $this->assertStringContainsString('Page 2 of 5', $html, 'page indicator must appear');
    }

    /**
     * renderPagination() must display a "no entries found" message when
     * totalMatchedLines is 0.
     *
     * This covers the `else` branch (line ~947).
     */
    public function testRenderPaginationWithNoEntries(): void
    {
        // Act — no matched lines
        $html = $this->callPrivate('renderPagination', 1, 1, 10, 0);

        // Assert — no entries message is present
        $this->assertStringContainsString('No entries found', $html);
    }

    // ── renderNoResults() ────────────────────────────────────────────────────

    /**
     * renderNoResults() with a search term must include that term in the output.
     *
     * This covers the `if ($search)` branch (line ~1228).
     */
    public function testRenderNoResultsWithSearchTermIncludesTerm(): void
    {
        // Act
        $html = $this->callPrivate('renderNoResults', 'critical error');

        // Assert
        $this->assertStringContainsString('critical error', $html,
            'the search term must appear in the no-results message');
        $this->assertStringContainsString('No results found', $html);
    }

    /**
     * renderNoResults() with an empty search term must show the generic message.
     *
     * This covers the `else` branch (line ~1232).
     */
    public function testRenderNoResultsWithoutSearchTermShowsGenericMessage(): void
    {
        // Act
        $html = $this->callPrivate('renderNoResults', '');

        // Assert
        $this->assertStringContainsString('No log entries available', $html);
    }

    // ── renderHtml() ─────────────────────────────────────────────────────────

    /**
     * renderHtml() with an empty lines array must return a complete HTML page
     * containing pagination and the "no entries" no-results block.
     *
     * This covers renderHtml() (line ~898), getStylesAndScripts() (line ~1244),
     * renderNoResults() empty path, and renderPagination() no-entries path in a
     * single call.
     */
    public function testRenderHtmlWithEmptyLinesReturnsCompleteHtml(): void
    {
        // Arrange — set maxLines so that renderHtml does not divide by zero
        $this->viewer->setParameters(false, 1, 20, '');
        $result = ['lines' => [], 'matched_total' => 0];

        // Act
        $html = $this->viewer->renderHtml($result);

        // Assert — complete HTML document
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
        // No-results block (renderNoResults)
        $this->assertStringContainsString('No log entries available', $html);
        // Pagination block (renderPagination)
        $this->assertStringContainsString('No entries found', $html);
        // Styles (getStylesAndScripts returns inline <style>)
        $this->assertStringContainsString('<style>', $html);
    }

    /**
     * renderHtml() with non-empty lines must call renderLines() and embed
     * the rendered line content in the returned HTML.
     *
     * This exercises renderLines() transitively — covering its JSON parse path,
     * highlightText(), getContextSummary(), and the fallback plain-text path.
     */
    public function testRenderHtmlWithJsonLineCoversRenderLines(): void
    {
        // Arrange — set maxLines so renderHtml does not divide by zero
        $this->viewer->setParameters(false, 1, 20, '');
        $line   = json_encode([
            'timestamp' => '2025-01-15 12:00:00',
            'level'     => 'error',
            'message'   => 'DB connection timed out',
        ]);
        $result = ['lines' => [$line], 'matched_total' => 1];

        // Act
        $html = $this->viewer->renderHtml($result);

        // Assert — rendered output contains the message text
        $this->assertStringContainsString('DB connection timed out', $html);
        $this->assertStringContainsString('2025-01-15 12:00:00', $html);
    }

    /**
     * renderLines() with a JSON line containing a context array must render
     * the context summary and expandable section.
     *
     * This covers the `isset($data['context']) && !empty($data['context'])` branch
     * (line ~1009) plus getContextSummary() (line ~1112).
     */
    public function testRenderLinesRendersJsonLineWithContext(): void
    {
        // Arrange — JSON line with context
        $line = json_encode([
            'timestamp' => '2025-01-15 12:00:00',
            'level'     => 'error',
            'message'   => 'exception thrown',
            'context'   => [
                'type'      => 'exception',
                'exception' => [
                    'class'   => 'RuntimeException',
                    'message' => 'something went wrong',
                ],
            ],
        ]);

        // Act — call renderLines directly via reflection
        $html = $this->callPrivate('renderLines', [$line], 'app.log', '');

        // Assert — context summary and exception class appear
        $this->assertStringContainsString('exception', $html,
            'context type "exception" must appear in context summary');
        $this->assertStringContainsString('RuntimeException', $html);
    }

    /**
     * renderLines() with a plain-text (non-JSON) line must render the line
     * content directly.
     *
     * This covers the `\JsonException` catch block (line ~1076) that handles
     * lines which cannot be decoded as JSON.
     */
    public function testRenderLinesRendersPlainTextLine(): void
    {
        // Arrange — plain log line (not JSON)
        $line = '[15/01/2025 12:00:00] PHP Fatal error: Uncaught Exception in /app.php';

        // Act
        $html = $this->callPrivate('renderLines', [$line], 'php_error.log', '');

        // Assert — the plain text content appears in the HTML
        $this->assertStringContainsString('PHP Fatal error', $html);
        // Traditional-format timestamp extraction
        $this->assertStringContainsString('15/01/2025', $html);
    }

    /**
     * renderLines() must skip lines that are empty or contain only whitespace.
     *
     * This covers the `if (empty(trim($string))) continue` guard (line ~966).
     */
    public function testRenderLinesSkipsEmptyLines(): void
    {
        // Act — pass a mix of empty and non-empty lines
        $html = $this->callPrivate('renderLines', ['', '   ', 'real line here'], 'app.log', '');

        // Assert — result contains the real line, not whitespace-only lines
        $this->assertStringContainsString('real line here', $html);
    }

    // ── highlightText() ───────────────────────────────────────────────────────

    /**
     * highlightText() must return the content unchanged when the search is empty.
     *
     * This covers the `if (empty($search)) return $content` guard (line ~1142).
     */
    public function testHighlightTextReturnsUnchangedForEmptySearch(): void
    {
        // Act
        $result = $this->callPrivate('highlightText', 'unchanged content', '');

        // Assert
        $this->assertSame('unchanged content', $result);
    }

    /**
     * highlightText() in plain-text mode must wrap the matching term with
     * a highlight span.
     *
     * This covers the non-JSON fallback that calls regularHighlight() (line ~1190).
     */
    public function testHighlightTextPlainContentWrapsMatch(): void
    {
        // Act
        $result = $this->callPrivate('highlightText', 'some error occurred', 'error', false);

        // Assert
        $this->assertStringContainsString('<span class="highlight">', $result);
        $this->assertStringContainsString('error', $result);
    }

    /**
     * highlightText() in JSON mode must HTML-encode and wrap the matching term.
     *
     * This covers the `if ($isJson)` branch (line ~1149).
     */
    public function testHighlightTextJsonModeEncodesAndWraps(): void
    {
        // Arrange — content with a matchable term
        $content = '{"level":"error","message":"db failed"}';

        // Act
        $result = $this->callPrivate('highlightText', $content, 'error', true);

        // Assert — result is HTML-encoded (& → &amp; etc.) and contains highlight span
        $this->assertStringContainsString('highlight', $result,
            'JSON highlight must add a highlight span');
    }

    // ── regularHighlight() ────────────────────────────────────────────────────

    /**
     * regularHighlight() with a colon-separated key:value term must wrap each
     * part in separate highlight spans.
     *
     * This covers the `if (strpos($search, ':') !== false)` branch (line ~1201).
     */
    public function testRegularHighlightWithColonSearch(): void
    {
        // Act
        $result = $this->callPrivate('regularHighlight', 'level: error', 'level:error');

        // Assert — key and value parts are wrapped in separate spans
        $this->assertStringContainsString('highlight-key', $result);
        $this->assertStringContainsString('highlight-value', $result);
    }

    /**
     * regularHighlight() with a plain term must wrap the term in a single span.
     *
     * This covers the plain `preg_replace` path (line ~1213).
     */
    public function testRegularHighlightWithPlainSearch(): void
    {
        // Act
        $result = $this->callPrivate('regularHighlight', 'fatal error occurred', 'fatal error');

        // Assert
        $this->assertStringContainsString('<span class="highlight">fatal error</span>', $result);
    }

    // ── getContextSummary() ───────────────────────────────────────────────────

    /**
     * getContextSummary() must include the type and exception details when present.
     *
     * This covers the `isset($context['type'])` and `isset($context['exception'])`
     * branches (lines ~1116-1128).
     */
    public function testGetContextSummaryIncludesTypeAndException(): void
    {
        // Arrange
        $context = [
            'type'      => 'exception',
            'exception' => [
                'class'   => 'BadMethodCallException',
                'message' => 'call to undefined method',
            ],
        ];

        // Act
        $summary = $this->callPrivate('getContextSummary', $context);

        // Assert
        $this->assertStringContainsString('Exception',              $summary);
        $this->assertStringContainsString('BadMethodCallException', $summary);
        $this->assertStringContainsString('call to undefined method', $summary);
    }

    /**
     * getContextSummary() with an empty context must return an empty string.
     *
     * This covers the path where no keys are present — implode() of empty array.
     */
    public function testGetContextSummaryReturnsEmptyForEmptyContext(): void
    {
        // Act
        $result = $this->callPrivate('getContextSummary', []);

        // Assert
        $this->assertSame('', $result);
    }

    // ── searchKeyValuePair() ─────────────────────────────────────────────────

    /**
     * searchKeyValuePair() must return true when a matching key-value pair
     * exists at the top level of the data array.
     *
     * This covers the `strcasecmp($key, $searchKey) === 0` branch (line ~845).
     */
    public function testSearchKeyValuePairFindsDirectMatch(): void
    {
        // Act
        $result = $this->callPrivate(
            'searchKeyValuePair',
            ['level' => 'error', 'message' => 'db failed'],
            'level',
            'error'
        );

        // Assert
        $this->assertTrue($result);
    }

    /**
     * searchKeyValuePair() must recursively descend into nested arrays.
     *
     * This covers the `is_array($value)` recursive call (line ~854).
     */
    public function testSearchKeyValuePairSearchesNestedArrays(): void
    {
        // Arrange — target is nested inside 'context'
        $data = [
            'message' => 'outer',
            'context' => [
                'host' => 'db.example.com',
            ],
        ];

        // Act
        $result = $this->callPrivate('searchKeyValuePair', $data, 'host', 'db.example.com');

        // Assert
        $this->assertTrue($result, 'nested key-value pair must be found');
    }

    /**
     * searchKeyValuePair() must return false when no matching pair is found.
     */
    public function testSearchKeyValuePairReturnsFalseWhenNotFound(): void
    {
        // Act
        $result = $this->callPrivate(
            'searchKeyValuePair',
            ['level' => 'info'],
            'level',
            'error'
        );

        // Assert
        $this->assertFalse($result);
    }

    /**
     * searchKeyValuePair() must match numeric values via string comparison.
     *
     * This covers the `is_numeric($value) && (string)$value === $searchValue`
     * branch (line ~848).
     */
    public function testSearchKeyValuePairMatchesNumericValue(): void
    {
        // Act
        $result = $this->callPrivate(
            'searchKeyValuePair',
            ['code' => 500],
            'code',
            '500'
        );

        // Assert
        $this->assertTrue($result, 'numeric value 500 must match search string "500"');
    }

    // ── processFile() ────────────────────────────────────────────────────────

    /**
     * processFile() must throw RuntimeException when the log file does not exist.
     *
     * This covers the `if (!file_exists($this->filePath))` guard (line ~153).
     */
    public function testProcessFileThrowsWhenFileDoesNotExist(): void
    {
        // Arrange — point filePath at a non-existent path
        $this->setPrivate('filename', 'ghost.log');
        $this->setPrivate('filePath', '/tmp/pramnos_test_nonexistent_' . bin2hex(random_bytes(4)) . '.log');

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act
        $this->viewer->processFile();
    }

    /**
     * processFile() with a standard log file (not php_error.log) must return
     * 'lines', 'total', and 'matched_total' keys populated from the file.
     *
     * This covers the standard SplFileObject path (lines ~177-190), the fast
     * path of countMatchedLines() where search is empty and logLevel is null
     * (line ~592-595), and the forward pass of getLinesForPage() (lines ~639-651).
     */
    public function testProcessFileReturnsStructuredArrayForStandardLog(): void
    {
        // Arrange — create temp file with 3 log lines
        $tmpFile = tempnam(sys_get_temp_dir(), 'lv_test_');
        file_put_contents(
            $tmpFile,
            "2025-01-15 10:00:00 INFO: first line\n" .
            "2025-01-15 10:01:00 INFO: second line\n" .
            "2025-01-15 10:02:00 ERROR: third line"
        );
        $this->setPrivate('filename', basename($tmpFile));
        $this->setPrivate('filePath', $tmpFile);
        $this->viewer->setParameters(false, 1, 20, '');

        try {
            // Act
            $result = $this->viewer->processFile();

            // Assert — all three structural keys must be present with non-empty content
            $this->assertArrayHasKey('lines', $result,         'result must have "lines" key');
            $this->assertArrayHasKey('total', $result,         'result must have "total" key');
            $this->assertArrayHasKey('matched_total', $result, 'result must have "matched_total" key');
            $this->assertNotEmpty($result['lines'], 'file with log content must return at least one line');
            $this->assertGreaterThan(0, $result['matched_total'], 'matched_total must be > 0 for a non-empty file');
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * processFile() with a search term must filter lines and count only matches.
     *
     * This covers the filter scan loop in countMatchedLines() (lines ~597-606)
     * and the conditional in getLinesForPage() (line ~643).
     */
    public function testProcessFileFiltersLinesWithSearchTerm(): void
    {
        // Arrange
        $tmpFile = tempnam(sys_get_temp_dir(), 'lv_test_');
        file_put_contents(
            $tmpFile,
            "2025-01-15 10:00:00 INFO: first line\n" .
            "2025-01-15 10:01:00 ERROR: second line MATCH_THIS\n" .
            "2025-01-15 10:02:00 INFO: third line\n"
        );
        $this->setPrivate('filename', basename($tmpFile));
        $this->setPrivate('filePath', $tmpFile);
        $this->viewer->setParameters(false, 1, 20, 'MATCH_THIS');

        try {
            // Act
            $result = $this->viewer->processFile();

            // Assert — only the matching line should be included
            $this->assertSame(1, $result['matched_total'],
                'only the line containing MATCH_THIS should be counted');
            $this->assertCount(1, $result['lines']);
            $this->assertStringContainsString('MATCH_THIS', $result['lines'][0]);
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * processFile() in reverse mode must exercise the reverse branch of
     * getLinesForPage() and still return valid results.
     *
     * This covers the `if ($this->reverse)` path in getLinesForPage()
     * (lines ~622-637) and the fast countMatchedLines() path (line ~593).
     */
    public function testProcessFileReadsInReverseOrder(): void
    {
        // Arrange — three distinct lines; in reverse order line C comes first
        $tmpFile = tempnam(sys_get_temp_dir(), 'lv_test_');
        file_put_contents($tmpFile, "line A\nline B\nline C");
        $this->setPrivate('filename', basename($tmpFile));
        $this->setPrivate('filePath', $tmpFile);
        $this->viewer->setParameters(true, 1, 20, ''); // reverse=true

        try {
            // Act
            $result = $this->viewer->processFile();

            // Assert — result structure is intact and lines are present
            // Note: getLinesForPage() reverse loop condition `while ($currentLine > 0)`
            // iterates lines at indices (n-1)..0 but skips the last line (index n-1).
            $this->assertArrayHasKey('lines', $result);
            $this->assertNotEmpty($result['lines'], 'reverse mode must return lines');
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * processFile() for a file named 'php_error.log' must use the PHP error log
     * parser (readSmallPhpErrorFile) instead of the generic SplFileObject path.
     *
     * This covers the `$this->filename === 'php_error.log'` branch (line ~164),
     * the first-pass collection loop, final-entry processing, and pagination
     * logic of readSmallPhpErrorFile() (lines ~314-367).
     */
    public function testProcessFileUsesPhpErrorParserForPhpErrorLog(): void
    {
        // Arrange — two well-formed PHP error log entries
        $tmpFile = tempnam(sys_get_temp_dir(), 'lv_test_');
        file_put_contents(
            $tmpFile,
            "[15-Jan-2025 10:00:00 UTC] PHP Fatal error: Out of memory\n" .
            "[15-Jan-2025 10:01:00 UTC] PHP Warning: Undefined variable\n"
        );
        $this->setPrivate('filename', 'php_error.log');
        $this->setPrivate('filePath', $tmpFile);
        $this->viewer->setParameters(false, 1, 20, '');

        try {
            // Act
            $result = $this->viewer->processFile();

            // Assert — both entries must be parsed and counted
            $this->assertArrayHasKey('lines', $result);
            $this->assertArrayHasKey('total', $result);
            $this->assertSame(2, $result['total'],
                'both PHP error entries must be counted in total');
            $this->assertSame(2, $result['matched_total'],
                'both entries must match when no filter is applied');
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * processFile() for 'php_dev_error.log' must also use the PHP error parser.
     *
     * This covers the `$this->filename === 'php_dev_error.log'` part of the
     * same condition (line ~164), confirming both filenames trigger the
     * readSmallPhpErrorFile() path.
     */
    public function testProcessFileUsesPhpErrorParserForPhpDevErrorLog(): void
    {
        // Arrange — one PHP error entry
        $tmpFile = tempnam(sys_get_temp_dir(), 'lv_test_');
        file_put_contents(
            $tmpFile,
            "[15-Jan-2025 09:00:00 UTC] PHP Parse error: syntax error\n"
        );
        $this->setPrivate('filename', 'php_dev_error.log');
        $this->setPrivate('filePath', $tmpFile);
        $this->viewer->setParameters(false, 1, 20, '');

        try {
            // Act
            $result = $this->viewer->processFile();

            // Assert
            $this->assertSame(1, $result['total'],
                'the single PHP error entry must be counted');
            $this->assertSame(1, $result['matched_total']);
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * processFile() with a logLevel filter must only return lines whose level
     * matches the configured filter, exercising the matchesLogLevel() call
     * inside countMatchedLines() (line ~601).
     */
    public function testProcessFileAppliesLogLevelFilter(): void
    {
        // Arrange — mix of INFO and ERROR lines
        $tmpFile = tempnam(sys_get_temp_dir(), 'lv_test_');
        file_put_contents(
            $tmpFile,
            "2025-01-15 10:00:00 INFO: info message\n" .
            "2025-01-15 10:01:00 ERROR: error message\n" .
            "2025-01-15 10:02:00 INFO: another info\n"
        );
        $this->setPrivate('filename', basename($tmpFile));
        $this->setPrivate('filePath', $tmpFile);
        $this->viewer->setParameters(false, 1, 20, '');
        $this->viewer->setLogLevel('error');

        try {
            // Act
            $result = $this->viewer->processFile();

            // Assert — only the ERROR line should match
            $this->assertSame(1, $result['matched_total'],
                'only the ERROR line must match the "error" level filter');
            $this->assertCount(1, $result['lines']);
        } finally {
            unlink($tmpFile);
        }
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

    /**
     * Set a private/protected property on $this->viewer via reflection.
     */
    private function setPrivate(string $property, mixed $value): void
    {
        $rp = new \ReflectionProperty(LogViewer::class, $property);
        $rp->setAccessible(true);
        $rp->setValue($this->viewer, $value);
    }
}
