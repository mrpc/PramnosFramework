<?php

namespace Pramnos\Application\Template;

/**
 * Compiles .tpl.php template source to plain PHP.
 *
 * Transforms Blade-inspired directives to PHP calls on the View object
 * ($this->layout(), $this->section(), etc.) and {{ }}/{!! !!} echo tags
 * to the appropriate PHP echo statements.
 *
 * This class is a pure string transformer — no I/O, no state, no side effects.
 * The same instance may be reused for multiple source strings.
 *
 * Directives supported:
 *   {{ expr }}             — escaped echo via e()
 *   {!! expr !!}           — raw (unescaped) echo
 *   {{-- comment --}}      — template comment (stripped from output)
 *   @extends('layout')     — set parent layout
 *   @section('name')       — start a named section
 *   @endsection / @stop    — end the current section
 *   @yield('name')         — output a section (in layout templates)
 *   @yield('name','def')   — output a section with a default value
 *   @include('tmpl', [...])— include a sub-template
 *   @if / @elseif / @else / @endif
 *   @foreach / @endforeach
 *   @for / @endfor
 *   @while / @endwhile
 *   @isset / @endisset
 *   @empty / @endempty
 *   @php / @endphp         — raw PHP block
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license    MIT
 */
class TemplateCompiler
{
    /**
     * Compile a template source string to executable PHP.
     *
     * @param string $source Raw .tpl.php template source.
     * @return string        Compiled PHP ready for include.
     */
    public function compile(string $source): string
    {
        // Order matters:
        // 1. Strip template comments ({{-- --}}) before echo tags see them
        // 2. Raw echos ({!! !!}) before escaped echos ({{ }}) to avoid double-match
        // 3. Escaped echos
        // 4. Directives last (some directive arguments contain {{ }} which are already replaced)
        $source = $this->compileComments($source);
        $source = $this->compileRawEchos($source);
        $source = $this->compileEscapedEchos($source);
        $source = $this->compileDirectives($source);
        return $source;
    }

    // =========================================================================
    // Echo tags
    // =========================================================================

    /**
     * {{-- comment --}} → stripped entirely.
     * Template comments are never emitted to the final HTML.
     */
    protected function compileComments(string $source): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    /**
     * {!! expr !!} → <?php echo expr; ?>
     * Raw output — the expression is not HTML-escaped.
     * Use only for trusted / pre-escaped content.
     */
    protected function compileRawEchos(string $source): string
    {
        return preg_replace('/\{!!\s*(.+?)\s*!!\}/s', '<?php echo $1; ?>', $source);
    }

    /**
     * {{ expr }} → <?php echo e(expr); ?>
     * Auto-escaped output via the global e() helper (htmlspecialchars wrapper).
     */
    protected function compileEscapedEchos(string $source): string
    {
        return preg_replace('/\{{\s*(.+?)\s*\}\}/s', '<?php echo e($1); ?>', $source);
    }

    // =========================================================================
    // Directives
    // =========================================================================

    protected function compileDirectives(string $source): string
    {
        // --- Template inheritance & slots ---
        // @extends('path')  → $this->layout('path')
        // @section('name')  → $this->section('name')
        // @yield('name')    → echo $this->yield('name')
        // @include('tmpl')  → $this->insert('tmpl')
        $source = $this->withArguments($source, 'extends', '<?php $this->layout', '; ?>');
        $source = $this->withArguments($source, 'section', '<?php $this->section', '; ?>');
        $source = $this->withArguments($source, 'yield', '<?php echo $this->yield', '; ?>');
        $source = $this->withArguments($source, 'include', '<?php $this->insert', '; ?>');

        // --- Control flow (PHP alternative syntax) ---
        $source = $this->withArguments($source, 'if', '<?php if', ': ?>');
        $source = $this->withArguments($source, 'elseif', '<?php elseif', ': ?>');
        $source = $this->withArguments($source, 'foreach', '<?php foreach', ': ?>');
        $source = $this->withArguments($source, 'for', '<?php for', ': ?>');
        $source = $this->withArguments($source, 'while', '<?php while', ': ?>');
        $source = $this->withArguments($source, 'isset', '<?php if(isset', '): ?>');
        $source = $this->withArguments($source, 'empty', '<?php if(empty', '): ?>');

        // --- Closing / simple directives ---
        // Process longer strings first to avoid partial matches
        // (e.g. @endsection before a hypothetical @end, @endforeach before @endfor)
        $source = str_replace('@endsection',  '<?php $this->endsection(); ?>', $source);
        $source = str_replace('@stop',        '<?php $this->endsection(); ?>', $source);
        $source = str_replace('@endforeach',  '<?php endforeach; ?>',          $source);
        $source = str_replace('@endisset',    '<?php endif; ?>',               $source);
        $source = str_replace('@endempty',    '<?php endif; ?>',               $source);
        $source = str_replace('@endwhile',    '<?php endwhile; ?>',            $source);
        $source = str_replace('@endfor',      '<?php endfor; ?>',              $source);
        $source = str_replace('@endif',       '<?php endif; ?>',               $source);
        $source = str_replace('@endphp',      ' ?>',                           $source);
        $source = str_replace('@php',         '<?php ',                        $source);

        // @else — word boundary prevents matching @elseif (already replaced above)
        $source = preg_replace('/@else\b/', '<?php else: ?>', $source);

        return $source;
    }

    /**
     * Replace each `@name(...)` with `$before(...)$after`, the parentheses matched at any depth
     * and those inside quoted strings ignored — a condition with a closure in it
     * (`@if (count(array_filter($rows, fn ($r) => $r['paid'])) > 1)`) is one argument, not
     * three cut short.
     */
    protected function withArguments(string $source, string $name, string $before, string $after): string
    {
        $out    = '';
        $offset = 0;
        while (preg_match('/@' . $name . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $at    = $m[0][1];
            $open  = $at + strlen($m[0][0]) - 1;
            $close = self::matchingParenthesis($source, $open);
            if ($close === null) {
                break;
            }
            $out   .= substr($source, $offset, $at - $offset) . $before . substr($source, $open, $close - $open + 1) . $after;
            $offset = $close + 1;
        }

        return $out . substr($source, $offset);
    }

    /** The position of the `)` closing the `(` at `$open`, or null when it is never closed. */
    private static function matchingParenthesis(string $source, int $open): ?int
    {
        $depth  = 0;
        $quote  = null;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            $c = $source[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }
}
