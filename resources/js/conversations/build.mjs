/**
 * Build `scaffolding/assets/js/pf-conversations.js`: the Svelte components and Svelte's runtime in
 * one file, for an application with no bundler of its own.
 *
 *   npm run build:conversations
 *
 * The first line of the output records a hash of the sources and the Svelte version it was built
 * from; tests/js/conversations-build.test.js fails when the sources changed and the file was not
 * rebuilt, so the shipped file cannot quietly be a different one from the source.
 */
import { build } from 'esbuild';
import sveltePlugin from 'esbuild-svelte';
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(here, '..', '..', '..');

/** What the shipped file is built from: every source here but this script, and the Svelte version. */
export function sourceStamp() {
    const hash = createHash('sha256');
    for (const name of readdirSync(here).filter((n) => /\.(js|svelte)$/.test(n)).sort()) {
        hash.update(name);
        hash.update(readFileSync(path.join(here, name)));
    }
    const svelte = JSON.parse(readFileSync(path.join(root, 'node_modules', 'svelte', 'package.json'), 'utf8')).version;

    return 'svelte ' + svelte + ' sources ' + hash.digest('hex').slice(0, 16);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    await build({
        entryPoints: [path.join(here, 'main.js')],
        outfile: path.join(root, 'scaffolding', 'assets', 'js', 'pf-conversations.js'),
        bundle: true,
        format: 'iife',
        minify: true,
        target: 'es2020',
        mainFields: ['svelte', 'browser', 'module', 'main'],
        conditions: ['svelte', 'browser'],
        legalComments: 'eof',
        // Styles live in the themes' components.css (`pf-conv-*`), so a theme draws them.
        plugins: [sveltePlugin({ compilerOptions: { css: 'external' } })],
        banner: { js: '/*! pf-conversations — ' + sourceStamp() + ' — built from resources/js/conversations by `npm run build:conversations`; Svelte is MIT licensed */' },
    });
}
