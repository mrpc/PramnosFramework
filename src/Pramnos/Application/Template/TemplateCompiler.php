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
        // Regex fragment matching balanced parentheses up to 3 levels deep.
        // Sufficient for real-world template expressions.
        $p = '(\((?:[^)(]*|\((?:[^)(]*|\([^)(]*\))*\))*\))';

        // --- Template inheritance & slots ---
        // @extends('path')  → $this->layout('path')
        // @section('name')  → $this->section('name')
        // @yield('name')    → echo $this->yield('name')
        // @include('tmpl')  → $this->insert('tmpl')
        $source = preg_replace('/@extends\s*' . $p . '/', '<?php $this->layout$1; ?>', $source);
        $source = preg_replace('/@section\s*'  . $p . '/', '<?php $this->section$1; ?>', $source);
        $source = preg_replace('/@yield\s*'    . $p . '/', '<?php echo $this->yield$1; ?>', $source);
        $source = preg_replace('/@include\s*'  . $p . '/', '<?php $this->insert$1; ?>', $source);

        // --- Control flow (PHP alternative syntax) ---
        $source = preg_replace('/@if\s*'      . $p . '/', '<?php if$1: ?>',         $source);
        $source = preg_replace('/@elseif\s*'  . $p . '/', '<?php elseif$1: ?>',     $source);
        $source = preg_replace('/@foreach\s*' . $p . '/', '<?php foreach$1: ?>',    $source);
        $source = preg_replace('/@for\s*'     . $p . '/', '<?php for$1: ?>',        $source);
        $source = preg_replace('/@while\s*'   . $p . '/', '<?php while$1: ?>',      $source);
        $source = preg_replace('/@isset\s*'   . $p . '/', '<?php if(isset$1): ?>',  $source);
        $source = preg_replace('/@empty\s*'   . $p . '/', '<?php if(empty$1): ?>', $source);

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
}
