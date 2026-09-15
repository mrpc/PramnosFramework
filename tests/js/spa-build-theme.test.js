/**
 * The SPA's palette build, driven as the file a project actually runs.
 *
 * `scripts/build-theme.mjs` is the **second** reader of `app/themes/theme.css`. The
 * first is `Pramnos\Theme\ThemeTokens`, which feeds the server-rendered themes. One
 * palette, two parsers, and the way two parsers stop agreeing is over what is not a
 * declaration.
 *
 * A comment was exactly that. The PHP side gained comment stripping; this side did not,
 * and the divergence was not cosmetic:
 *
 *   - a comment's own text became a declaration in the generated stylesheet, taking the
 *     token on the line after it with it;
 *   - a comment containing `}` ended the block regex early, and the `/* was: ;`
 *     fragment left behind opened a comment that never closed — so the rest of the
 *     generated file, the font rule included, was inside it. `npm run build` reported
 *     success.
 *
 * The stub is rendered and executed rather than imported, because it is a script that
 * writes a file on load, which is how a project runs it.
 *
 * Run:
 *   node --test tests/js/spa-build-theme.test.js
 */
'use strict';

const { test, describe, before, after } = require('node:test');
const assert  = require('node:assert/strict');
const fs      = require('node:fs');
const os      = require('node:os');
const path    = require('node:path');
const { execFileSync } = require('node:child_process');

const STUB = path.join(
    __dirname, '..', '..', 'scaffolding', 'templates', 'spa-build-theme.mjs.stub'
);

let workspace;

/** A rendered copy of the stub, in a throwaway project. */
before(() => {
    workspace = fs.mkdtempSync(path.join(os.tmpdir(), 'pramnos-theme-'));
    fs.mkdirSync(path.join(workspace, 'scripts'), { recursive: true });
    fs.mkdirSync(path.join(workspace, 'app', 'themes'), { recursive: true });
    fs.mkdirSync(path.join(workspace, 'www', 'assets', 'css'), { recursive: true });

    const rendered = fs.readFileSync(STUB, 'utf8')
        .replace(/\{\{ themePath \}\}/g, 'www/assets/css/style.css')
        .replace(/\{\{ palettePath \}\}/g, 'app/themes/theme.css')
        .replace(/\{\{ themeOutput \}\}/g, 'out.css')
        .replace(/\{\{ fallbackPrimary \}\}/g, '#2563eb')
        .replace(/\{\{ fontFamily \}\}/g, 'system-ui');

    fs.writeFileSync(path.join(workspace, 'scripts', 'build-theme.mjs'), rendered);
});

after(() => fs.rmSync(workspace, { recursive: true, force: true }));

/** Write a palette, run the build, return what it generated. */
function build(palette) {
    fs.writeFileSync(path.join(workspace, 'app', 'themes', 'theme.css'), palette);
    execFileSync(process.execPath, [path.join(workspace, 'scripts', 'build-theme.mjs')], {
        cwd: workspace,
        stdio: 'pipe',
    });

    return fs.readFileSync(path.join(workspace, 'out.css'), 'utf8');
}

describe('build-theme.mjs', () => {
    test('a comment inside a block is not read as a declaration', () => {
        // Arrange — a comment between two declarations, the shape a person writes.
        const css = build([
            '@plugin "daisyui/theme" {',
            '    name: "acme";',
            '    default: true;',
            '    /* The surfaces: white cards on a light grey page. */',
            '    --color-base-100: #ffffff;',
            '    --color-primary: #ff0000; /* the brand */',
            '}',
        ].join('\n'));

        // Assert — both declarations survived, and the prose did not.
        assert.match(css, /--color-base-100: #ffffff;/);
        assert.match(css, /--color-primary: #ff0000;/);
        assert.doesNotMatch(css, /The surfaces/);
    });

    test('a commented-out brace does not truncate the block', () => {
        // Arrange — the case that broke the whole file rather than one declaration.
        const css = build([
            '@plugin "daisyui/theme" {',
            '    name: "acme";',
            '    default: true;',
            '    /* was: } */',
            '    --color-primary: #ff0000;',
            '}',
        ].join('\n'));

        // Assert — the declaration below the comment is there…
        assert.match(css, /--color-primary: #ff0000;/);

        // …and every comment the file contains is closed. An unterminated one swallows
        // the rest of the stylesheet, which is why this is counted rather than matched:
        // the generated file legitimately opens with a doc comment of its own.
        const opened = (css.match(/\/\*/g) || []).length;
        const closed = (css.match(/\*\//g) || []).length;
        assert.equal(opened, closed, 'an unterminated comment hides everything after it');

        // The font rule is the last thing in the file, so its presence is the proof
        // that nothing after the palette was commented out.
        assert.match(css, /--font-sans: system-ui;/);
    });

    test('the theme names and the dark block come through', () => {
        // Arrange
        const css = build([
            '@plugin "daisyui/theme" {',
            '    name: "acme";',
            '    default: true;',
            '    --color-primary: #ff0000;',
            '}',
            '@plugin "daisyui/theme" {',
            '    name: "acme-dark";',
            '    prefersdark: true;',
            '    --color-primary: #00ff00;',
            '}',
        ].join('\n'));

        // Assert — the default lands on :root as well as its own attribute…
        assert.match(css, /:root:root,\n\[data-theme="acme"\]:root/);
        assert.match(css, /\[data-theme="acme-dark"\]:root/);
        // …and the OS preference is scoped so an explicit choice still wins.
        assert.match(css, /@media \(prefers-color-scheme: dark\)/);
        assert.match(css, /:root:root:not\(\[data-theme\]\)/);
    });
});
