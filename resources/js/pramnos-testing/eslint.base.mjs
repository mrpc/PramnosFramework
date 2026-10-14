/**
 * The framework's ESLint rules, as a base an application extends.
 *
 * Rules that describe **defects** only — a redeclared variable, an undefined name, unreachable
 * code — never a style. `php bin/<app> lint:js` uses this when the application has no
 * `eslint.config.mjs` of its own; one that does extends it:
 *
 * ```js
 * // eslint.config.mjs
 * import { browser, tests } from './vendor/mrpc/pramnosframework/resources/js/pramnos-testing/eslint.base.mjs';
 *
 * export default [
 *     { ignores: ['www/assets/vendor/**'] },
 *     browser(['www/assets/js/**\/*.js'], { jQuery: 'readonly', $: 'readonly' }),
 *     tests(['tests/js/**\/*.js']),
 * ];
 * ```
 */

/** Mistakes, not preferences. */
export const rules = {
    'no-redeclare': 'error',
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
    // An unused *argument* is often the shape of a callback signature rather than a mistake.
    'no-unused-vars': ['error', { args: 'none', caughtErrors: 'none', varsIgnorePattern: '^_' }],
};

const BROWSER = [
    'window', 'document', 'console', 'fetch', 'localStorage', 'sessionStorage', 'navigator',
    'location', 'history', 'XMLHttpRequest', 'FormData', 'URL', 'URLSearchParams', 'setTimeout',
    'clearTimeout', 'setInterval', 'clearInterval', 'requestAnimationFrame', 'MutationObserver',
    'CustomEvent', 'Event', 'performance', 'alert', 'confirm', 'prompt', 'getComputedStyle',
    'matchMedia', 'Node', 'Blob', 'HTMLElement', 'globalThis', 'AbortController', 'structuredClone',
];

const NODE = [
    'require', 'console', 'process', 'Buffer', '__dirname', '__filename', 'global', 'globalThis',
    'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'setImmediate', 'URL',
    'URLSearchParams', 'fetch', 'structuredClone', 'FormData', 'Blob', 'AbortController',
];

const readonly = (names) => Object.fromEntries(names.map((name) => [name, 'readonly']));

/**
 * Classic browser scripts — what a page loads with `<script src>`.
 *
 * @param {string[]} files   Globs
 * @param {object}   globals More globals the scripts use (`{ jQuery: 'readonly' }`)
 */
export function browser(files, globals = {}) {
    return {
        files,
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'script',
            globals: { ...readonly(BROWSER), module: 'writable', ...globals },
        },
        rules,
    };
}

/**
 * Tests run under `node --test`, as CommonJS.
 *
 * @param {string[]} files   Globs
 * @param {object}   globals More globals
 */
export function tests(files, globals = {}) {
    return {
        files,
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'commonjs',
            globals: { ...readonly(NODE), module: 'writable', exports: 'writable', ...globals },
        },
        rules,
    };
}

/** The whole configuration, for an application with none of its own. */
export default [
    { ignores: ['vendor/**', 'node_modules/**', '**/vendor/**', 'var/**', 'coverage/**'] },
    browser(['www/assets/js/**/*.js', 'public/assets/js/**/*.js', 'src/**/*.js']),
    tests(['tests/js/**/*.js']),
];
