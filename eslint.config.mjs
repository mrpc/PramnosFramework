/**
 * ESLint configuration for the JavaScript this framework ships.
 *
 * ## Why this exists
 *
 * `debugbar.js` is 3744 lines, it is served to every page of every project that enables the
 * debug toolbar, and it had `var hasMvcPage` declared twice. A consuming project's linter found
 * it; the duplicate stopped **1,195 panel tests** from running there. Nothing in this repository
 * could have caught it — there was no linter, and a unit test for a duplicate `var` would be a
 * worse version of the rule below.
 *
 * That is the whole scope: mechanical mistakes in shipped JavaScript, caught here rather than in
 * somebody else's build.
 *
 * ## What is deliberately not configured
 *
 * No style rules — no quote style, no semicolon policy, no indentation. `debugbar.js` predates
 * this file by years and reformatting it would bury the next real change in noise, while a
 * `--fix` sweep across 3744 lines is exactly the diff nobody can review. Every rule enabled here
 * describes a **defect**, not a preference.
 */

export default [
    {
        // node_modules is ignored by default; these are the rest.
        ignores: [
            'vendor/**',
            'coverage/**',
            'site/**',
            'var/**',
            'www/assets/vendor/**',
        ],
    },

    {
        // The shipped browser assets.
        files: ['src/**/*.js', 'www/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'script',
            globals: {
                window: 'readonly',
                document: 'readonly',
                console: 'readonly',
                fetch: 'readonly',
                localStorage: 'readonly',
                sessionStorage: 'readonly',
                navigator: 'readonly',
                location: 'readonly',
                history: 'readonly',
                XMLHttpRequest: 'readonly',
                FormData: 'readonly',
                URL: 'readonly',
                URLSearchParams: 'readonly',
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                setInterval: 'readonly',
                clearInterval: 'readonly',
                requestAnimationFrame: 'readonly',
                MutationObserver: 'readonly',
                CustomEvent: 'readonly',
                Event: 'readonly',
                performance: 'readonly',
                alert: 'readonly',
                confirm: 'readonly',
                prompt: 'readonly',
                getComputedStyle: 'readonly',
                matchMedia: 'readonly',
                Node: 'readonly',
                Blob: 'readonly',
                HTMLElement: 'readonly',
                module: 'writable',
                globalThis: 'readonly',
            },
        },
        rules: {
            // The rule this file was created for.
            'no-redeclare': 'error',

            // Mistakes, not preferences.
            'no-undef': 'error',
            'no-dupe-keys': 'error',
            'no-dupe-args': 'error',
            'no-dupe-else-if': 'error',
            'no-duplicate-case': 'error',
            'no-unreachable': 'error',
            'no-fallthrough': 'error',
            'no-self-assign': 'error',
            'no-self-compare': 'error',
            'no-unsafe-negation': 'error',
            'no-cond-assign': ['error', 'except-parens'],
            'no-constant-condition': ['error', { checkLoops: false }],
            'no-empty': ['error', { allowEmptyCatch: true }],
            'no-sparse-arrays': 'error',
            'use-isnan': 'error',
            'valid-typeof': 'error',
            'no-func-assign': 'error',
            'no-import-assign': 'error',
            'no-obj-calls': 'error',
            'no-setter-return': 'error',
            'no-unsafe-finally': 'error',
            'no-unsafe-optional-chaining': 'error',
            'no-async-promise-executor': 'error',
            'no-compare-neg-zero': 'error',

            // Unused variables are reported, but an unused *argument* is often the shape of a
            // callback signature rather than a mistake.
            'no-unused-vars': ['error', {
                args: 'none',
                caughtErrors: 'none',
                varsIgnorePattern: '^_',
            }],
        },
    },

    {
        // The framework's own JS tests, which run under `node --test`.
        files: ['tests/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            // `require()`, not `import` — these run under `node --test` as CommonJS, and
            // declaring them modules makes every one of those calls an undefined global.
            sourceType: 'commonjs',
            globals: {
                require: 'readonly',
                module: 'writable',
                exports: 'writable',
                console: 'readonly',
                process: 'readonly',
                Buffer: 'readonly',
                __dirname: 'readonly',
                __filename: 'readonly',
                global: 'readonly',
                globalThis: 'readonly',
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                setInterval: 'readonly',
                clearInterval: 'readonly',
                URL: 'readonly',
                fetch: 'readonly',
                structuredClone: 'readonly',
                setImmediate: 'readonly',
            },
        },
        rules: {
            'no-redeclare': 'error',
            'no-undef': 'error',
            'no-dupe-keys': 'error',
            'no-unreachable': 'error',
            'no-unused-vars': ['error', {
                args: 'none',
                caughtErrors: 'none',
                varsIgnorePattern: '^_',
            }],
        },
    },
];
