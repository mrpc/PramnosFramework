<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\DevPanel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\DevPanel\DevPanelController;
use Pramnos\Mcp\McpServer;
use Pramnos\Mcp\McpToolInterface;

/**
 * The MCP tab — an interactive debugger for a server that speaks JSON-RPC on stdio.
 *
 * `mcp:serve` blocks on STDIN and, under a real client, does not own its own pipes. There
 * was no way to see what a tool returned. `mcp:call` answered that from a terminal; this is
 * the same thing for somebody already in the panel, and it adds the part a terminal cannot
 * show conveniently: the tool's schema rendered as a form, so its arguments are discovered
 * rather than guessed.
 *
 * What is asserted here is what appears on the page and the two ways it must not mislead: a
 * form that cannot express "omit this argument", and a tool that threw looking like output.
 */
#[CoversClass(DevPanelController::class)]
class DevPanelMcpTest extends TestCase
{
    private function controller(): DevPanelController
    {
        return (new \ReflectionClass(DevPanelController::class))
            ->newInstanceWithoutConstructor();
    }

    /** Call a private method on the controller. */
    private function call(DevPanelController $controller, string $method, ...$args): mixed
    {
        return (new \ReflectionMethod(DevPanelController::class, $method))
            ->invoke($controller, ...$args);
    }

    /** A tool with the schema shapes the renderer has to handle. */
    private function tool(string $name, string $description, array $schema): McpToolInterface
    {
        return new class ($name, $description, $schema) implements McpToolInterface {
            public function __construct(
                private string $toolName,
                private string $toolDescription,
                private array $toolSchema
            ) {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return $this->toolDescription;
            }

            public function inputSchema(): array
            {
                return $this->toolSchema;
            }

            public function execute(array $input): mixed
            {
                return $input;
            }
        };
    }

    // ── the tab itself ───────────────────────────────────────────────────────

    /**
     * Every tab in the strip is an action the controller will dispatch.
     *
     * The list of tabs and the list of dispatchable actions are separate on purpose —
     * `adminer` is a tab and not an action, `overview` is a tab whose action is `display`,
     * `logs` is an action with no tab — so they cannot be one array. This is the part worth
     * asserting: a tab whose action was never registered is a 404 in the navigation, and it
     * looks like the feature is broken rather than unrouted.
     */
    public function testEveryTabHasAnActionBehindIt(): void
    {
        // Arrange
        $tabs = array_keys(
            (array) (new \ReflectionMethod(DevPanelController::class, 'tabs'))->invoke(null)
        );

        $controller = new DevPanelController();
        $actions    = (array) (new \ReflectionProperty($controller, 'actions_auth'))
            ->getValue($controller);

        // Assert
        foreach ($tabs as $tab) {
            if ($tab === 'adminer') {
                // Its own route — a full application, not an action of this panel.
                continue;
            }

            $expected = $tab === 'overview' ? 'display' : $tab;

            $this->assertContains(
                $expected,
                $actions,
                'the ' . $tab . ' tab points at an action nothing dispatches'
            );
        }

        $this->assertContains('mcp', $tabs, 'the MCP tab is in the strip');
    }

    /**
     * The tab strip that other pages wear lists the same tabs as the panel's own.
     *
     * There were two copies of this list, and the comment on the second claimed to be the
     * single source while being the copy. Adminer wears `tabStrip()`, so a tab added to the
     * panel and forgotten there gives a page that wears a strip it does not appear in.
     */
    public function testTheBorrowedStripAndThePanelAgree(): void
    {
        // Act
        $strip = DevPanelController::tabStrip('mcp');
        $tabs  = (array) (new \ReflectionMethod(DevPanelController::class, 'tabs'))->invoke(null);

        // Assert
        foreach ($tabs as $label) {
            $this->assertStringContainsString(
                htmlspecialchars((string) $label),
                $strip,
                (string) $label . ' is missing from the strip other pages wear'
            );
        }

        $this->assertStringContainsString('class="active"', $strip);
    }

    // ── the form ─────────────────────────────────────────────────────────────

    /**
     * An enum becomes a select, and the list includes an explicit "omit".
     *
     * The entire reason to render a schema rather than a textarea is that the valid values
     * stop being something you look up. And omitting an argument has to be expressible: a
     * tool with a default gets to keep it, which is a different call from passing `""`.
     */
    public function testAnEnumBecomesASelectThatCanBeLeftOut(): void
    {
        // Arrange
        $tool = $this->tool('probe', 'A probe.', [
            'type' => 'object',
            'properties' => [
                'timespan' => ['type' => 'string', 'enum' => ['1h', '24h'], 'description' => 'How far back.'],
            ],
        ]);

        // Act
        $html = (string) $this->call($this->controller(), 'renderMcpTool', $tool);

        // Assert
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('— omit —', $html);
        $this->assertStringContainsString('<option>1h</option>', $html);
        $this->assertStringContainsString('<option>24h</option>', $html);
        $this->assertStringContainsString('How far back.', $html, 'the description is the hint');
    }

    /**
     * A boolean is a tri-state select, not a checkbox.
     *
     * An unchecked box and an absent argument are the same thing to a form and very
     * different things to a tool — `false` overrides a default that `true` was meant to
     * keep. A checkbox cannot say "leave it out".
     */
    public function testABooleanCanBeTrueFalseOrAbsent(): void
    {
        // Arrange
        $tool = $this->tool('probe', 'A probe.', [
            'type' => 'object',
            'properties' => ['loud' => ['type' => 'boolean']],
        ]);

        // Act
        $html = (string) $this->call($this->controller(), 'renderMcpTool', $tool);

        // Assert
        $this->assertStringNotContainsString('type="checkbox" id="mcp-probe-loud"', $html);
        $this->assertStringContainsString('value="true"', $html);
        $this->assertStringContainsString('value="false"', $html);
        $this->assertStringContainsString('— omit —', $html);
    }

    /**
     * The field carries its type, because the browser has to rebuild the JSON.
     *
     * `{"limit": "5"}` and `{"limit": 5}` are different calls and a schema wanting an
     * integer rejects the first. The type has to survive the trip into the DOM.
     */
    public function testEachFieldCarriesItsType(): void
    {
        // Arrange
        $tool = $this->tool('probe', 'A probe.', [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer'],
                'files' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['limit'],
        ]);

        // Act
        $html = (string) $this->call($this->controller(), 'renderMcpTool', $tool);

        // Assert
        $this->assertStringContainsString('data-type="integer"', $html);
        $this->assertStringContainsString('data-type="array"', $html);
        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringContainsString('comma separated', $html, 'an array needs telling how');
        $this->assertStringContainsString('required', $html);
    }

    /**
     * An array of enums lists the values instead of saying "comma separated".
     */
    public function testAnArrayOfEnumsNamesItsValues(): void
    {
        // Arrange
        $tool = $this->tool('probe', 'A probe.', [
            'type' => 'object',
            'properties' => [
                'levels' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['error', 'info']]],
            ],
        ]);

        // Act
        $html = (string) $this->call($this->controller(), 'renderMcpTool', $tool);

        // Assert
        $this->assertStringContainsString('error, info', $html);
    }

    /**
     * A tool with an empty schema says so rather than rendering as a truncated form.
     */
    public function testAToolWithNoArgumentsSaysSo(): void
    {
        // Arrange
        $tool = $this->tool('quiet', 'Takes nothing.', ['type' => 'object']);

        // Act
        $html = (string) $this->call($this->controller(), 'renderMcpTool', $tool);

        // Assert
        $this->assertStringContainsString('takes no arguments', $html);
        $this->assertStringContainsString('Call', $html, 'and it is still callable');
    }

    /**
     * A tool's name and description are escaped.
     *
     * Both come from a class a project wrote, and this page is rendered for the one visitor
     * who can reach every other tab as well.
     */
    public function testTheToolsOwnStringsAreEscaped(): void
    {
        // Arrange
        $tool = $this->tool('x"><script>', 'Also <script>alert(1)</script>', ['type' => 'object']);

        // Act
        $html = (string) $this->call($this->controller(), 'renderMcpTool', $tool);

        // Assert
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── the panel around it ──────────────────────────────────────────────────

    /**
     * The traffic log says how to switch it on when it is off.
     *
     * The panel cannot switch it on itself: the log belongs to the `mcp:serve` process the
     * *client* started, which is not this process. A disabled-looking button would be a lie,
     * so it is a sentence with the command in it.
     */
    public function testTheTrafficLogStatusExplainsHowToEnableIt(): void
    {
        // Act
        $html = (string) $this->call($this->controller(), 'mcpTrafficLogStatus');

        // Assert — either state has to name the file and point at the viewer
        $this->assertStringContainsString('mcp.log', $html);
        $this->assertStringContainsString('log viewer', $html);

        if (str_contains($html, 'off')) {
            $this->assertStringContainsString('mcp:serve --log', $html);
        }
    }

    /**
     * Resources are listed, and an installation with none renders nothing at all.
     *
     * An empty "Resources" heading reads as a section that failed to load.
     */
    public function testResourcesAreListedOnlyWhenThereAreSome(): void
    {
        // Arrange
        $empty = new McpServer('Test', '1.0.0');
        $full  = new McpServer('Test', '1.0.0');
        $file  = tempnam(sys_get_temp_dir(), 'mcp-res');
        file_put_contents((string) $file, 'x');
        $full->addResource(new \Pramnos\Mcp\McpResource('file://thing', 'A thing', (string) $file));

        try {
            // Act
            $none = (string) $this->call($this->controller(), 'renderMcpResources', $empty);
            $some = (string) $this->call($this->controller(), 'renderMcpResources', $full);

            // Assert
            $this->assertSame('', $none);
            $this->assertStringContainsString('file://thing', $some);
            $this->assertStringContainsString('A thing', $some);
        } finally {
            @unlink((string) $file);
        }
    }

    /**
     * The emitted script is valid JavaScript.
     *
     * It shipped broken. The script lives in a PHP **heredoc**, where `\n` is an escape PHP
     * itself consumes — so `'\n'` written in the source reached the browser as a real line
     * break *inside a JS string literal*:
     *
     * ```js
     * .join('
     * ');            // Uncaught SyntaxError: Invalid or unexpected token
     * ```
     *
     * Every other test on this panel passed: the markup was right, the token was right, and
     * `assertStringContainsString('// sent ')` matched happily. Nothing was asserting that the
     * result was a program. So this hands the script to `node --check`, which is the only
     * thing that actually knows.
     *
     * Skipped rather than failed where node is absent — a missing tool is not a broken page —
     * and node is in the container this suite runs in.
     */
    public function testTheEmittedScriptIsValidJavaScript(): void
    {
        // Arrange
        exec('node --version 2>/dev/null', $probe, $status);

        if ($status !== 0) {
            $this->markTestSkipped('node is not available, so the script cannot be parsed.');
        }

        $html = (string) $this->call($this->controller(), 'mcpScript', 'atoken123');

        // The script tag's contents, which is what a browser is handed
        $this->assertSame(
            1,
            preg_match('~<script[^>]*>(.*)</script>~s', $html, $matches),
            'there is exactly one script block to check'
        );

        $file = tempnam(sys_get_temp_dir(), 'mcp-panel-') . '.js';
        file_put_contents($file, $matches[1]);

        try {
            // Act
            exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $status);

            // Assert
            $this->assertSame(
                0,
                $status,
                "the panel emitted invalid JavaScript:\n" . implode("\n", $output)
            );
        } finally {
            @unlink($file);
        }
    }

    /**
     * A newline in the output is an escape, not an actual line break.
     *
     * The specific mistake, asserted directly as well — because `node --check` would also
     * pass on a script that had lost the escape in some *other* way that happened to stay
     * parseable, and joining log lines with a literal `\n` is the behaviour, not an
     * implementation detail.
     */
    public function testNewlinesInTheScriptSurviveAsEscapes(): void
    {
        // Act
        $html = (string) $this->call($this->controller(), 'mcpScript', 'atoken123');

        // Assert
        $this->assertStringContainsString("join('\\n')", $html);
        $this->assertStringNotContainsString("join('\n')", $html,
            'a real line break inside a JS string literal is a syntax error');
    }

    /**
     * Every link the panel renders is styled, wherever it is.
     *
     * This was fixed once and scoped wrong. The rule covered `table.data-table a`, because
     * that is where the first link went; the next one went into an info table and arrived as
     * the browser's default — a **visited** link, in a colour nobody can read on `#313244`,
     * beside a green badge. Fixing the instance rather than the class means fixing it again
     * every time a link is added.
     *
     * Asserted on the selector rather than by rendering, because CSS is the thing under test
     * and there is no browser here to ask.
     */
    public function testLinksAreStyledAcrossTheWholePanelNotJustTables(): void
    {
        // Act
        $css = (string) $this->call($this->controller(), 'panelCss');

        // Assert
        $this->assertStringContainsString('.panel-content a {', $css);
        $this->assertStringContainsString('.panel-content a:visited', $css,
            'a visited link is the one that actually goes unreadable');
        $this->assertStringNotContainsString('table.data-table a {', $css,
            'the table-scoped rule was the bug, not the fix');
    }

    /**
     * A row of chips wraps instead of running off the panel.
     *
     * `.range-bar` was written for a timespan selector — four fixed chips — and is reused for
     * the cache's namespace filter, which has one chip per namespace the installation happens to
     * have. On a real one that is twenty, and the row ran past the edge: the chips beyond it were
     * unreachable and the page scrolled sideways under everything else.
     */
    public function testAChipRowWrapsRatherThanOverflowing(): void
    {
        // Act
        $css = (string) $this->call($this->controller(), 'panelCss');

        // Assert
        $this->assertMatchesRegularExpression(
            '~\.range-bar\s*\{[^}]*flex-wrap:\s*wrap~',
            $css,
            'a list nobody chose the length of has to wrap'
        );
    }

    /**
     * The script sends the CSRF token it was given, and posts to this panel.
     *
     * A POST that *executes* whatever a project registered gets a token even behind the
     * panel's own gate — the other endpoints here read; this one runs.
     */
    public function testTheScriptCarriesTheTokenAndPostsToThePanel(): void
    {
        // Act
        $html = (string) $this->call($this->controller(), 'mcpScript', 'atoken123');

        // Assert
        $this->assertStringContainsString("body.set('csrf', 'atoken123')", $html);
        $this->assertStringContainsString("method: 'POST'", $html);
        $this->assertStringContainsString('/mcp', $html);
        $this->assertStringContainsString("credentials: 'same-origin'", $html);

        // The bit that stops a thrown tool reading as an answer
        $this->assertStringContainsString('isError', $html);

        // And what the form actually built, printed above the result
        $this->assertStringContainsString('// sent ', $html);
    }
}
