/**
 * Build `scaffolding/assets/js/pf-rich-editor.js`: page.js and TipTap in one file, for a
 * server-rendered page that has no bundler of its own.
 *
 *   npm run build:editor
 *
 * The first line of the output records a hash of the sources and the TipTap version it was
 * built from; tests/js/rich-editor-build.test.js fails when the sources changed and the file was
 * not rebuilt, so the shipped editor cannot quietly be a different one from the source.
 */
import { build } from 'esbuild';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(here, '..', '..', '..');

/** What the shipped file is built from: the sources, and the TipTap they bundle. */
export function sourceStamp() {
    const hash = createHash('sha256');
    for (const name of ['core.js', 'extensions.js', 'page.js']) {
        hash.update(readFileSync(path.join(here, name)));
    }
    const tiptap = JSON.parse(readFileSync(path.join(root, 'node_modules', '@tiptap', 'core', 'package.json'), 'utf8')).version;

    return 'tiptap ' + tiptap + ' sources ' + hash.digest('hex').slice(0, 16);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    await build({
        entryPoints: [path.join(here, 'page.js')],
        outfile: path.join(root, 'scaffolding', 'assets', 'js', 'pf-rich-editor.js'),
        bundle: true,
        format: 'iife',
        minify: true,
        target: 'es2019',
        legalComments: 'eof',
        banner: { js: '/*! pf-rich-editor — ' + sourceStamp() + ' — built from resources/js/rich-editor by `npm run build:editor`; TipTap and ProseMirror are MIT licensed */' },
    });
    console.log('built scaffolding/assets/js/pf-rich-editor.js — ' + sourceStamp());
}
