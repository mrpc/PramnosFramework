// Pramnos\Llm\Sandbox\PyodideRunner's own half: one Python script, run in Pyodide (CPython
// compiled to WebAssembly — see docs/Pramnos_LLM_Analyst_Guide.md, "code_exec"), with whatever
// CSV the question attached read into /tmp/input.csv first.
//
// No network isolation is set up here — PHP wraps this process in a cgroup for memory/CPU and
// a wall-clock kill (PyodideRunner.php); a network namespace is still a documented follow-up,
// not because Pyodide's own socket shim is relied on (it was measured not to move real bytes
// under Node.js, but that is an implementation detail of a dependency, not a guarantee this
// takes credit for).
//
// Takes one argument: a work directory with code.py (required) and input.csv (optional).
// Prints one JSON line to stdout: {"ok": true, "result": "..."} or {"ok": false, "error": "..."}.
import { loadPyodide } from 'pyodide';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const workDir = process.argv[2];
if (!workDir) {
    console.log(JSON.stringify({ ok: false, error: 'No work directory given.' }));
    process.exit(1);
}

const here = dirname(fileURLToPath(import.meta.url));
const code = readFileSync(join(workDir, 'code.py'), 'utf8');
const inputPath = join(workDir, 'input.csv');

try {
    const pyodide = await loadPyodide({ indexURL: join(here, 'node_modules/pyodide/') });

    // Best effort: a script that does not touch pandas still runs without it, offline or not.
    // Silenced (messageCallback/errorCallback) — its own progress lines are not this call's
    // stdout, which must be exactly one JSON line for PHP to read back.
    try {
        await pyodide.loadPackage('pandas', { messageCallback: () => {}, errorCallback: () => {} });
    } catch (e) {
        process.stderr.write('code_exec: pandas not available (' + e.message + ')\n');
    }

    if (existsSync(inputPath)) {
        pyodide.FS.writeFile('/tmp/input.csv', readFileSync(inputPath, 'utf8'));
    }

    let out = '';
    pyodide.setStdout({ batched: (s) => (out += s + '\n') });
    pyodide.setStderr({ batched: (s) => (out += s + '\n') });
    await pyodide.runPythonAsync(code);

    console.log(JSON.stringify({ ok: true, result: out }));
} catch (e) {
    console.log(JSON.stringify({ ok: false, error: String(e && e.message ? e.message : e) }));
    process.exit(1);
}
