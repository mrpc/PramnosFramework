<?php

declare(strict_types=1);

namespace Pramnos\Debug;

/**
 * The toolbar's JavaScript, and the two shapes it is delivered in.
 *
 * There were two renderers — PHP building HTML for server-rendered pages, and a
 * separate scaffolded module for SPA projects — drawing the same tables from the
 * same collector data. They drifted, and a bug then had to be fixed twice. This
 * class exists so there is one source and no second copy to forget.
 *
 * Two shapes, because the two contexts load code differently, not because the
 * code differs:
 *
 *   - `source()` — an IIFE that attaches `window.__pramnosDebugBar`. Inlined into
 *     a server-rendered page inside `<script>`, where an ESM `export` would be a
 *     syntax error.
 *   - `spaModule()` — the same source with an ESM re-export appended, written into
 *     a SPA project as `lib/debug.js`, where `lib/api.js` does
 *     `import { record } from './debug.js'`.
 */
final class DebugBarAsset
{
    /** Where the single source lives, relative to this file. */
    private const SOURCE = __DIR__ . '/assets/debugbar.js';

    /**
     * The renderer, as a browser-ready classic script.
     *
     * @throws \RuntimeException When the asset is missing — an install that
     *                           cannot draw the toolbar should say so rather than
     *                           emit an empty `<script>` and leave the reader
     *                           wondering why nothing appears.
     */
    public static function source(): string
    {
        $source = @file_get_contents(self::SOURCE);
        if ($source === false) {
            // the asset ships with the framework, so
            // @codeCoverageIgnoreStart
            // reaching this means a broken install, not a branch a test can set up
            // without deleting a file out of vendor/.
            throw new \RuntimeException(
                'The debug toolbar asset is missing: ' . self::SOURCE
            );
            // @codeCoverageIgnoreEnd
        }

        return $source;
    }

    /**
     * The renderer as an ES module, for a SPA project's `lib/debug.js`.
     *
     * The header is addressed to whoever opens the file expecting to edit it: it
     * is framework code that happens to live in the project, and a local edit is
     * lost the next time `project:resync --debug-panel` runs.
     *
     * @param string $appName Shown in the bar, so the panel names the application
     *                        it belongs to rather than the framework.
     */
    public static function spaModule(string $appName = ''): string
    {
        $name = $appName === '' ? 'Pramnos' : $appName;

        $header = "/**\n"
            . " * Debug panel for {$name} — FRAMEWORK-OWNED, do not edit.\n"
            . " *\n"
            . " * Generated from the framework's single toolbar source. Refresh it with\n"
            . " *   ./<cli> project:resync --debug-panel\n"
            . " * and add missing behaviour there rather than here: an edit made in this\n"
            . " * file is lost on the next refresh, and the same panel is what every other\n"
            . " * project (and every server-rendered page) uses.\n"
            . " *\n"
            . " * `record()` is called by lib/api.js for every response. It is inert unless\n"
            . " * a response carries debug data, which only happens in development — so in\n"
            . " * production there is no data, no DOM and no panel.\n"
            . " */\n";

        // The IIFE sets window.__pramnosDebugBar; the export forwards to it, so
        // the module has one implementation rather than a copy of it.
        $export = "\n"
            . "/**\n"
            . " * Note one API response, so the toolbar can show what it did.\n"
            . " *\n"
            . " * @param {string}      method\n"
            . " * @param {string}      path\n"
            . " * @param {number}      status\n"
            . " * @param {object|null} debug   The `_debug` payload, or null in production\n"
            . " * @param {object}      [extra] { ms, body }\n"
            . " */\n"
            . "export function record(method, path, status, debug, extra = {}) {\n"
            . "    const bar = typeof window !== 'undefined' ? window.__pramnosDebugBar : null;\n"
            . "    if (bar) {\n"
            . "        bar.record(method, path, status, debug, extra);\n"
            . "    }\n"
            . "}\n"
            . "\n"
            . "/**\n"
            . " * Hand the panel an error your code caught.\n"
            . " *\n"
            . " * The panel already listens for what nobody caught (`window.onerror`,\n"
            . " * `unhandledrejection`). This is for the opposite and more common case: an\n"
            . " * ApiError a screen handled, a `<svelte:boundary>` that swallowed a render\n"
            . " * failure, a `catch` that showed a message. Those never reach a global\n"
            . " * handler, and they are exactly what somebody is looking for when the screen\n"
            . " * says something went wrong and the network tab looks fine.\n"
            . " *\n"
            . " * Inert in production, like record(): with no debug data there is no panel.\n"
            . " *\n"
            . " * @param {Error|string} error\n"
            . " * @param {object}       [context] { kind, request }\n"
            . " */\n"
            . "export function reportError(error, context = {}) {\n"
            . "    const bar = typeof window !== 'undefined' ? window.__pramnosDebugBar : null;\n"
            . "    if (bar) {\n"
            . "        bar.reportError(error, context);\n"
            . "    }\n"
            . "}\n"
            . "\n"
            . "/**\n"
            . " * Tell the panel where the client-side router has arrived.\n"
            . " *\n"
            . " * The panel cannot work this out: the route table belongs to the application,\n"
            . " * and a router base that does not match the URL is exactly the failure the\n"
            . " * Client tab exists to make visible. lib/router.js calls this on every\n"
            . " * navigation; without it the tab still shows the URL and the injected\n"
            . " * configuration, which is most of the answer.\n"
            . " *\n"
            . " * Inert in production, like record().\n"
            . " *\n"
            . " * @param {string} name     The route the application resolved to\n"
            . " * @param {object} [detail] { base, params }\n"
            . " */\n"
            . "export function reportRoute(name, detail = {}) {\n"
            . "    const bar = typeof window !== 'undefined' ? window.__pramnosDebugBar : null;\n"
            . "    if (bar) {\n"
            . "        bar.reportRoute(name, detail);\n"
            . "    }\n"
            . "}\n";

        return $header . self::source() . $export;
    }

    /**
     * The brand name substituted into the bar, if the source carries a token.
     *
     * Kept separate from spaModule() so the server-rendered path can use the same
     * substitution without going through the module wrapper.
     */
    public static function withAppName(string $source, string $appName): string
    {
        return $appName === ''
            ? $source
            : str_replace('&#9881; Pramnos', '&#9881; ' . htmlspecialchars($appName, ENT_QUOTES), $source);
    }
}
