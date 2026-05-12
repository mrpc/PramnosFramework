<?php

namespace Pramnos\Tests\Unit\Application\Template;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Template\TemplateCompiler;

/**
 * Unit tests for TemplateCompiler.
 *
 * TemplateCompiler is a pure string transformer — every test follows the same
 * shape: compile(input) and assert on the output string. No I/O, no state.
 *
 * Coverage goals:
 *   - All echo tag variants (escaped, raw, comments)
 *   - All @directives (inheritance, control flow, raw PHP)
 *   - Edge cases: nested parens, multiline, adjacent directives, no directives
 */
#[\PHPUnit\Framework\Attributes\CoversClass(TemplateCompiler::class)]
class TemplateCompilerTest extends TestCase
{
    private TemplateCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new TemplateCompiler();
    }

    // =========================================================================
    // Template comments
    // =========================================================================

    /**
     * {{-- comment --}} is stripped entirely — not present in compiled output.
     * Template comments must not appear in the HTML sent to the browser.
     */
    public function testCommentIsStripped(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('before {{-- this is hidden --}} after');

        // Assert
        $this->assertSame('before  after', $result);
    }

    /**
     * Multi-line template comments are also stripped completely.
     */
    public function testMultilineCommentIsStripped(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile("{{--\n  multi-line comment\n--}}end");

        // Assert
        $this->assertStringNotContainsString('multi-line', $result);
        $this->assertStringContainsString('end', $result);
    }

    // =========================================================================
    // Echo tags
    // =========================================================================

    /**
     * {{ expr }} compiles to <?php echo e(expr); ?> — auto-escaped output.
     * This is the primary way to output user-supplied data safely.
     */
    public function testEscapedEchoTag(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('<h1>{{ $title }}</h1>');

        // Assert
        $this->assertSame('<h1><?php echo e($title); ?></h1>', $result);
    }

    /**
     * Whitespace inside {{ }} is trimmed so that {{ $var }} and {{$var}} both
     * produce the same output.
     */
    public function testEscapedEchoTrimsWhitespace(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('{{  $a  }}');

        // Assert — expression is trimmed
        $this->assertStringContainsString('e($a)', $result);
    }

    /**
     * {!! expr !!} compiles to <?php echo expr; ?> — raw (unescaped) output.
     * Used for trusted or pre-escaped HTML (e.g. rendered markdown, framework output).
     */
    public function testRawEchoTag(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('<div>{!! $html !!}</div>');

        // Assert — no e() wrapper, direct echo
        $this->assertSame('<div><?php echo $html; ?></div>', $result);
        $this->assertStringNotContainsString('e($html)', $result);
    }

    /**
     * {!! !!} is processed before {{ }} so that a {!! value !!} inside a
     * surrounding {{ }} context doesn't get double-processed.
     * (Practical case: they shouldn't be nested, but the order must be correct.)
     */
    public function testRawEchoProcessedBeforeEscapedEcho(): void
    {
        // Arrange — source has both tags
        $source = '{!! $raw !!} {{ $safe }}';

        // Act
        $result = $this->compiler->compile($source);

        // Assert — raw uses direct echo; escaped uses e()
        $this->assertStringContainsString('echo $raw', $result);
        $this->assertStringContainsString('echo e($safe)', $result);
    }

    // =========================================================================
    // Template inheritance
    // =========================================================================

    /**
     * @extends('path') compiles to $this->layout('path').
     * This is the Blade-inspired directive for child templates.
     */
    public function testExtendsDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile("@extends('layouts/main')");

        // Assert
        $this->assertStringContainsString("\$this->layout('layouts/main')", $result);
        $this->assertStringContainsString('<?php', $result);
    }

    /**
     * @section('name') compiles to $this->section('name').
     * Starts capturing output for the named slot.
     */
    public function testSectionDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile("@section('content')");

        // Assert
        $this->assertStringContainsString("\$this->section('content')", $result);
    }

    /**
     * @endsection compiles to $this->endsection().
     * Ends the currently-open section.
     */
    public function testEndsectionDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@endsection');

        // Assert
        $this->assertStringContainsString('$this->endsection()', $result);
    }

    /**
     * @stop is an alias for @endsection — both must produce the same output.
     */
    public function testStopIsAliasForEndsection(): void
    {
        // Arrange + Act
        $stop     = $this->compiler->compile('@stop');
        $endsection = $this->compiler->compile('@endsection');

        // Assert — identical PHP output
        $this->assertSame($stop, $endsection);
    }

    /**
     * @yield('name') compiles to echo $this->yield('name').
     * Used in layout templates to output child sections.
     */
    public function testYieldDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile("@yield('content')");

        // Assert
        $this->assertStringContainsString("echo \$this->yield('content')", $result);
    }

    /**
     * @yield('name', 'default') passes both arguments through correctly.
     */
    public function testYieldDirectiveWithDefault(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile("@yield('sidebar', '<aside>default</aside>')");

        // Assert
        $this->assertStringContainsString("yield('sidebar', '<aside>default</aside>')", $result);
    }

    /**
     * @include('partial') compiles to $this->insert('partial').
     */
    public function testIncludeDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile("@include('partials/card')");

        // Assert
        $this->assertStringContainsString("\$this->insert('partials/card')", $result);
    }

    /**
     * @include('partial', ['key' => $val]) passes data array through unchanged.
     */
    public function testIncludeDirectiveWithData(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile("@include('card', ['item' => \$item])");

        // Assert
        $this->assertStringContainsString("insert('card', ['item' => \$item])", $result);
    }

    // =========================================================================
    // Control flow directives
    // =========================================================================

    /**
     * @if(expr) compiles to PHP alternative if syntax.
     * Alternative syntax (if...: / endif;) is required for readable mixed HTML/PHP templates.
     */
    public function testIfDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@if($active)');

        // Assert
        $this->assertStringContainsString('<?php if($active): ?>', $result);
    }

    /**
     * @elseif(expr) compiles to PHP elseif alternative syntax.
     */
    public function testElseifDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@elseif($count > 0)');

        // Assert
        $this->assertStringContainsString('<?php elseif($count > 0): ?>', $result);
    }

    /**
     * @else compiles to PHP else alternative syntax.
     * The word-boundary regex must not match @elseif — verified by ordering.
     */
    public function testElseDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@else');

        // Assert
        $this->assertStringContainsString('<?php else: ?>', $result);
    }

    /**
     * In a template with both @elseif and @else, @else must not corrupt @elseif.
     * This verifies that the @else regex uses a word boundary (\b).
     */
    public function testElseDoesNotCorruptElseif(): void
    {
        // Arrange
        $source = "@if(\$a)\nfoo\n@elseif(\$b)\nbar\n@else\nbaz\n@endif";

        // Act
        $result = $this->compiler->compile($source);

        // Assert — @elseif is intact
        $this->assertStringContainsString('elseif($b):', $result);
        $this->assertStringContainsString('else:', $result);
        // @elseif must not have been double-processed into "else:" + "if"
        $this->assertStringNotContainsString('else: ?>' . "\n" . '<?php if', $result);
    }

    /**
     * @endif compiles to PHP endif; alternative syntax.
     */
    public function testEndifDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@endif');

        // Assert
        $this->assertStringContainsString('<?php endif; ?>', $result);
    }

    /**
     * @foreach compiles to PHP foreach alternative syntax.
     */
    public function testForeachDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@foreach($items as $item)');

        // Assert
        $this->assertStringContainsString('<?php foreach($items as $item): ?>', $result);
    }

    /**
     * @endforeach compiles to PHP endforeach.
     * Must not accidentally match @endfor — different strings, no conflict.
     */
    public function testEndforeachDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@endforeach');

        // Assert
        $this->assertStringContainsString('<?php endforeach; ?>', $result);
        $this->assertStringNotContainsString('endfor;', $result);
    }

    /**
     * @for compiles to PHP for alternative syntax.
     */
    public function testForDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@for($i = 0; $i < 10; $i++)');

        // Assert
        $this->assertStringContainsString('<?php for($i = 0; $i < 10; $i++): ?>', $result);
    }

    /**
     * @endfor compiles to PHP endfor.
     */
    public function testEndforDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@endfor');

        // Assert
        $this->assertStringContainsString('<?php endfor; ?>', $result);
    }

    /**
     * @while compiles to PHP while alternative syntax.
     */
    public function testWhileDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@while($running)');

        // Assert
        $this->assertStringContainsString('<?php while($running): ?>', $result);
    }

    /**
     * @endwhile compiles to PHP endwhile.
     */
    public function testEndwhileDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@endwhile');

        // Assert
        $this->assertStringContainsString('<?php endwhile; ?>', $result);
    }

    /**
     * @isset($var) compiles to if(isset($var)) using alternative syntax.
     * Convenience directive — avoids writing <?php if(isset(...)): ?> manually.
     */
    public function testIssetDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@isset($user)');

        // Assert
        $this->assertStringContainsString('<?php if(isset($user)): ?>', $result);
    }

    /**
     * @endisset compiles to endif (it closes the if opened by @isset).
     */
    public function testEndissetDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@endisset');

        // Assert
        $this->assertStringContainsString('<?php endif; ?>', $result);
    }

    /**
     * @empty($arr) compiles to if(empty($arr)) using alternative syntax.
     */
    public function testEmptyDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@empty($items)');

        // Assert
        $this->assertStringContainsString('<?php if(empty($items)): ?>', $result);
    }

    /**
     * @endempty compiles to endif.
     */
    public function testEndemptyDirective(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@endempty');

        // Assert
        $this->assertStringContainsString('<?php endif; ?>', $result);
    }

    // =========================================================================
    // Raw PHP blocks
    // =========================================================================

    /**
     * @php ... @endphp wraps a raw PHP block.
     * Used when a directive-style block needs arbitrary PHP logic.
     */
    public function testPhpBlock(): void
    {
        // Arrange + Act
        $result = $this->compiler->compile('@php $x = 1; @endphp');

        // Assert — @php compiles to opening PHP tag; @endphp compiles to closing PHP tag
        $this->assertStringContainsString('<?php ', $result);
        $this->assertStringContainsString('$x = 1;', $result);
        $this->assertStringContainsString(' ?>', $result);
    }

    // =========================================================================
    // Nested parentheses in expressions
    // =========================================================================

    /**
     * Directives whose expression contains nested parentheses (e.g. function
     * calls inside @if) must be compiled correctly. The paren-matching regex
     * handles up to 3 levels of nesting.
     */
    public function testIfWithNestedParens(): void
    {
        // Arrange — two levels of nesting: count() inside @if
        $result = $this->compiler->compile('@if(count($items) > 0)');

        // Assert — full expression preserved
        $this->assertStringContainsString('if(count($items) > 0):', $result);
    }

    /**
     * @foreach with array_filter (nested parens) is handled correctly.
     */
    public function testForeachWithNestedParens(): void
    {
        // Arrange
        $result = $this->compiler->compile('@foreach(array_filter($items) as $item)');

        // Assert
        $this->assertStringContainsString('foreach(array_filter($items) as $item):', $result);
    }

    // =========================================================================
    // Full template round-trip
    // =========================================================================

    /**
     * A complete child template (extends + section) compiles to valid PHP.
     * This integration-level test verifies that multiple directives interact
     * correctly in a realistic template.
     */
    public function testFullChildTemplate(): void
    {
        // Arrange
        $source = <<<'TPL'
@extends('layouts/main')

@section('content')
<h1>{{ $title }}</h1>
@foreach($items as $item)
<li>{{ $item }}</li>
@endforeach
@endsection
TPL;

        // Act
        $result = $this->compiler->compile($source);

        // Assert — key compiled tokens present
        $this->assertStringContainsString("\$this->layout('layouts/main')", $result);
        $this->assertStringContainsString("\$this->section('content')", $result);
        $this->assertStringContainsString('echo e($title)', $result);
        $this->assertStringContainsString('foreach($items as $item):', $result);
        $this->assertStringContainsString('echo e($item)', $result);
        $this->assertStringContainsString('endforeach', $result);
        $this->assertStringContainsString('$this->endsection()', $result);
    }

    /**
     * A template without any directives is returned unchanged.
     * This guarantees BC: existing .tpl.php files that happen to use no
     * directives are not mangled by the compiler.
     */
    public function testTemplateWithNoDirectivesIsUnchanged(): void
    {
        // Arrange
        $source = '<p>Hello, world.</p>';

        // Act + Assert
        $this->assertSame($source, $this->compiler->compile($source));
    }

    /**
     * Plain PHP (<?php echo ... ?>) inside a template is passed through
     * unchanged — the compiler only processes @-directives and {{ }}.
     */
    public function testExistingPhpIsPassedThrough(): void
    {
        // Arrange — plain PHP as used in .html.php templates
        $source = '<?php echo e($name); ?>';

        // Act + Assert — existing PHP is not double-processed
        $this->assertSame($source, $this->compiler->compile($source));
    }
}
