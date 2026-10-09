// py_worker.js (module worker): runs Python 3 inside the browser with Pyodide.
// In:  { type: 'init', base }
//      { type: 'check', id, code }               syntax check ("compile")
//      { type: 'run', id, code, input, maxOutput }
// Out: { type: 'progress', percent } / { type: 'ready' } / { type: 'error', message }
//      { type: 'checked', id, ok, log }
//      { type: 'result', id, stdout, stderr, exitCode, error }

let pyodide = null;
let loading = null;

const load = (base) => {
    if (!loading) {
        loading = (async () => {
            postMessage({ type: 'progress', percent: 10 });
            const { loadPyodide } = await import(base + 'pyodide/pyodide.mjs');
            pyodide = await loadPyodide({ indexURL: base + 'pyodide/', stdout: () => {}, stderr: () => {} });
            postMessage({ type: 'ready' });
        })();
    }
    return loading;
};

// Keep only the candidate's part of a traceback
const cleanError = (msg) => {
    const lines = String(msg).split('\n');
    const start = lines.findIndex(l => l.includes('File "main.py"'));
    const kept = (start >= 0 ? ['Traceback (most recent call last):', ...lines.slice(start)] : lines)
        .filter(l => !/File "\/lib\/python|_pyodide|pyodide\//.test(l));
    return kept.join('\n').trim();
};

const RUNNER = `
import sys, traceback
def __gate_run(src):
    g = {'__name__': '__main__', '__builtins__': __builtins__}
    try:
        exec(compile(src, 'main.py', 'exec'), g)
        return 0
    except SystemExit as e:
        return e.code if isinstance(e.code, int) else (0 if e.code is None else 1)
    except BaseException:
        et, ev, tb = sys.exc_info()
        sys.stderr.write(''.join(traceback.format_exception(et, ev, tb.tb_next)))
        return 1
    finally:
        sys.stdout.flush(); sys.stderr.flush()
`;

onmessage = async (ev) => {
    const m = ev.data;
    try {
        if (m.type === 'init') { await load(m.base); return; }
        await loading;
        if (m.type === 'check') {
            try {
                pyodide.globals.get('compile')(m.code, 'main.py', 'exec');
                postMessage({ type: 'checked', id: m.id, ok: true, log: '' });
            } catch (e) {
                postMessage({ type: 'checked', id: m.id, ok: false, log: cleanError(e.message) });
            }
            return;
        }
        if (m.type === 'run') {
            const limit = m.maxOutput || 65536;
            const dec = new TextDecoder();
            let stdout = '', stderr = '', truncated = false;
            let fed = false;
            pyodide.setStdin({ stdin: () => { if (fed) return undefined; fed = true; return m.input || ''; } });
            pyodide.setStdout({ write: (buf) => { if (stdout.length < limit) stdout += dec.decode(buf, { stream: true }); else truncated = true; return buf.length; } });
            pyodide.setStderr({ write: (buf) => { if (stderr.length < limit) stderr += dec.decode(buf, { stream: true }); return buf.length; } });
            pyodide.runPython(RUNNER);
            const code = pyodide.globals.get('__gate_run')(m.code);
            if (truncated) stderr += '\n[output too long, cut]';
            postMessage({ type: 'result', id: m.id, stdout, stderr: cleanError(stderr), exitCode: code, error: code ? 'Runtime error' : '' });
        }
    } catch (e) {
        postMessage({ type: m.type === 'check' ? 'checked' : 'result', id: m.id, ok: false, log: String(e.message || e),
                      stdout: '', stderr: String(e.message || e), exitCode: 1, error: 'Runtime error' });
    }
};
