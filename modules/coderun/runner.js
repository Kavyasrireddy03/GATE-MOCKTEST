// runner.js: compiles and runs candidate code entirely inside the browser.
// No code is sent to any server or online compiler API.
//   C / C++    -> Clang compiled to WebAssembly (vendor/compilers/clang), program runs in a worker
//   Python 3   -> Pyodide (vendor/compilers/pyodide)
//   JavaScript -> the browser's own engine, in a worker
// Every program runs in a Web Worker that is killed when the time limit passes.
//
// window.CodeRunner.run(lang, code, inputs, { timeLimitMs, onStatus })
//   -> { compiled: bool, compileLog, results: [{ stdout, stderr, error, timedOut, timeMs }] }

(() => {
    const scriptUrl = document.currentScript ? document.currentScript.src : location.href;
    const HERE = new URL('./', scriptUrl).href;
    const BASE = new URL((window.CODE_COMPILERS_URL || 'vendor/compilers').replace(/\/?$/, '/'), location.href).href;
    const MAX_OUTPUT = 65536;

    const LANGS = {
        c:          { label: 'C',          kind: 'cc' },
        cpp:        { label: 'C++',        kind: 'cc' },
        python:     { label: 'Python 3',   kind: 'py' },
        javascript: { label: 'JavaScript', kind: 'js' },
    };

    let seq = 0;
    const call = (worker, msg, replyType, transfer) => new Promise((resolve) => {
        const id = ++seq;
        const onMsg = (ev) => {
            if (ev.data && ev.data.type === replyType && ev.data.id === id) {
                worker.removeEventListener('message', onMsg);
                resolve(ev.data);
            }
        };
        worker.addEventListener('message', onMsg);
        worker.postMessage({ ...msg, id }, transfer || []);
    });

    // ---- long-lived workers that hold a loaded compiler ----
    const loaders = {};
    const startLoader = (kind, onStatus) => {
        const make = () => {
            const w = new Worker(HERE + (kind === 'cc' ? 'cc_worker.js' : 'py_worker.js'), { type: 'module' });
            const state = { worker: w, ready: null, percent: 0, listeners: new Set() };
            state.ready = new Promise((resolve, reject) => {
                w.addEventListener('message', (ev) => {
                    const d = ev.data || {};
                    if (d.type === 'progress') { state.percent = d.percent; state.listeners.forEach(f => f(d.percent)); }
                    else if (d.type === 'ready') resolve();
                    else if (d.type === 'error') reject(new Error(d.message));
                });
                w.addEventListener('error', (e) => reject(new Error(e.message || 'Compiler failed to load')));
            });
            w.postMessage({ type: 'init', base: BASE });
            return state;
        };
        if (!loaders[kind]) loaders[kind] = make();
        const st = loaders[kind];
        if (onStatus) {
            const f = (p) => onStatus(`Loading ${kind === 'cc' ? 'C/C++ compiler' : 'Python'} (first time only)... ${p}%`);
            st.listeners.add(f);
            st.ready.finally(() => st.listeners.delete(f));
        }
        st.ready.catch(() => { if (loaders[kind] === st) delete loaders[kind]; });
        return st;
    };
    const restartLoader = (kind) => { if (loaders[kind]) { loaders[kind].worker.terminate(); delete loaders[kind]; } };

    // Run one input in a throwaway worker, killing it on timeout
    const runInWorker = (makeWorker, msg, timeLimitMs, transfer) => new Promise((resolve) => {
        const w = makeWorker();
        const t0 = performance.now();
        let done = false;
        const timer = setTimeout(() => {
            if (done) return; done = true; w.terminate();
            resolve({ stdout: '', stderr: '', error: `Time limit exceeded (${(timeLimitMs / 1000).toFixed(1)} s)`, timedOut: true, timeMs: timeLimitMs });
        }, timeLimitMs);
        w.onmessage = (ev) => {
            if (done || !ev.data || ev.data.type !== 'result') return;
            done = true; clearTimeout(timer); w.terminate();
            resolve({ ...ev.data, timedOut: false, timeMs: Math.round(performance.now() - t0) });
        };
        w.onerror = (e) => {
            if (done) return; done = true; clearTimeout(timer); w.terminate();
            resolve({ stdout: '', stderr: String(e.message || ''), error: 'Runtime error', timedOut: false, timeMs: 0 });
        };
        w.postMessage(msg, transfer || []);
    });

    // Python keeps the interpreter loaded between runs; on timeout it is reloaded
    const runPython = async (code, input, timeLimitMs, onStatus, shownLimitMs) => {
        const st = startLoader('py', onStatus);
        await st.ready;
        return new Promise((resolve) => {
            const t0 = performance.now();
            let done = false;
            const timer = setTimeout(() => {
                if (done) return; done = true;
                restartLoader('py');
                resolve({ stdout: '', stderr: '', error: `Time limit exceeded (${(shownLimitMs / 1000).toFixed(1)} s)`, timedOut: true, timeMs: timeLimitMs });
            }, timeLimitMs);
            call(st.worker, { type: 'run', code, input, maxOutput: MAX_OUTPUT }, 'result').then((r) => {
                if (done) return; done = true; clearTimeout(timer);
                resolve({ ...r, timedOut: false, timeMs: Math.round(performance.now() - t0) });
            });
        });
    };

    const run = async (lang, code, inputs, opts = {}) => {
        const info = LANGS[lang];
        if (!info) throw new Error('Unsupported language: ' + lang);
        const timeLimitMs = Math.max(500, opts.timeLimitMs || 2000);
        const onStatus = opts.onStatus || (() => {});
        const results = [];

        if (info.kind === 'cc') {
            const st = startLoader('cc', onStatus);
            await st.ready;
            onStatus('Compiling...');
            const c = await call(st.worker, { type: 'compile', lang, code }, 'compiled');
            if (!c.ok) return { compiled: false, compileLog: c.log || 'Compilation failed', results };
            for (let i = 0; i < inputs.length; i++) {
                onStatus(`Running test case ${i + 1} of ${inputs.length}...`);
                const wasm = c.wasm.slice(0);
                results.push(await runInWorker(() => new Worker(HERE + 'wasm_worker.js', { type: 'module' }),
                    { type: 'run', base: BASE, wasm, input: inputs[i], maxOutput: MAX_OUTPUT }, timeLimitMs, [wasm.buffer]));
            }
            return { compiled: true, compileLog: c.log || '', results };
        }

        if (info.kind === 'py') {
            const st = startLoader('py', onStatus);
            await st.ready;
            onStatus('Checking syntax...');
            const c = await call(st.worker, { type: 'check', code }, 'checked');
            if (!c.ok) return { compiled: false, compileLog: c.log, results };
            for (let i = 0; i < inputs.length; i++) {
                onStatus(`Running test case ${i + 1} of ${inputs.length}...`);
                // Python's first run includes start-up time, so allow it a little extra
                results.push(await runPython(code, inputs[i], timeLimitMs + 1000, onStatus, timeLimitMs));
            }
            return { compiled: true, compileLog: '', results };
        }

        // JavaScript: a fresh worker per run so nothing leaks between test cases
        const syntax = await new Promise((resolve) => {
            const w = new Worker(HERE + 'js_worker.js');
            w.onmessage = (ev) => { w.terminate(); resolve(ev.data); };
            w.postMessage({ type: 'check', id: 0, code });
        });
        if (!syntax.ok) return { compiled: false, compileLog: syntax.log, results };
        for (let i = 0; i < inputs.length; i++) {
            onStatus(`Running test case ${i + 1} of ${inputs.length}...`);
            results.push(await runInWorker(() => new Worker(HERE + 'js_worker.js'),
                { type: 'run', id: 0, code, input: inputs[i], maxOutput: MAX_OUTPUT }, timeLimitMs));
        }
        return { compiled: true, compileLog: '', results };
    };

    // Same rule as most coding platforms: ignore trailing spaces on each line and trailing blank lines
    const normalize = (s) => String(s ?? '').replace(/\r\n?/g, '\n').split('\n').map(l => l.replace(/\s+$/, '')).join('\n').replace(/\n+$/, '');
    const sameOutput = (actual, expected) => normalize(actual) === normalize(expected);

    // Start downloading a compiler in the background (e.g. when a coding question opens)
    const preload = (lang, onStatus) => {
        const info = LANGS[lang];
        if (info && info.kind !== 'js') startLoader(info.kind, onStatus).ready.catch(() => {});
    };

    window.CodeRunner = { run, preload, sameOutput, LANGS };
})();
