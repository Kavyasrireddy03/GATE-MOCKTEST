// js_worker.js: runs JavaScript (Node.js style input/output) inside the browser.
// Input can be read with readline(), prompt(), require('fs').readFileSync(0, 'utf8'),
// require('readline').createInterface({ input: process.stdin }) or process.stdin.on('data').
// In:  { type: 'check', id, code } / { type: 'run', id, code, input, maxOutput }
// Out: { type: 'checked', id, ok, log } / { type: 'result', id, stdout, stderr, exitCode, error }

const fmt = (v) => {
    if (typeof v === 'string') return v;
    try { return typeof v === 'object' && v !== null ? JSON.stringify(v) : String(v); } catch (e) { return String(v); }
};

class Exit { constructor(code) { this.code = code; } }

onmessage = (ev) => {
    const m = ev.data;
    if (m.type === 'check') {
        try { new Function(m.code); postMessage({ type: 'checked', id: m.id, ok: true, log: '' }); }
        catch (e) { postMessage({ type: 'checked', id: m.id, ok: false, log: `${e.name}: ${e.message}` }); }
        return;
    }
    if (m.type !== 'run') return;

    const limit = m.maxOutput || 65536;
    const input = m.input || '';
    let stdout = '', stderr = '', truncated = false;
    const write = (s) => { if (stdout.length < limit) stdout += s; else truncated = true; };
    const writeErr = (s) => { if (stderr.length < limit) stderr += s; };

    const lines = input.split('\n');
    if (lines.length && lines[lines.length - 1] === '') lines.pop();
    let pos = 0;
    const readline = () => (pos < lines.length ? lines[pos++] : null);

    // Callbacks registered on stdin / readline run after the main code, like in Node
    const lineHandlers = [], closeHandlers = [], dataHandlers = [], endHandlers = [];
    const stdin = {
        on(evName, fn) {
            if (evName === 'data') dataHandlers.push(fn);
            else if (evName === 'end' || evName === 'close') endHandlers.push(fn);
            else if (evName === 'readable') dataHandlers.push(() => fn());
            return stdin;
        },
        setEncoding() { return stdin; }, resume() { return stdin; }, pause() { return stdin; },
        read() { const rest = lines.slice(pos).join('\n'); pos = lines.length; return rest || null; },
        fd: 0,
    };
    const rlInterface = {
        on(evName, fn) { if (evName === 'line') lineHandlers.push(fn); else if (evName === 'close') closeHandlers.push(fn); return rlInterface; },
        close() {}, question(q, fn) { write(String(q)); fn(readline() ?? ''); },
        [Symbol.asyncIterator]: async function* () { while (pos < lines.length) yield lines[pos++]; },
    };
    let exitCode = 0;
    const proc = {
        stdin, argv: ['node', 'main.js'], env: {}, platform: 'linux',
        stdout: { write: (s) => { write(String(s)); return true; } },
        stderr: { write: (s) => { writeErr(String(s)); return true; } },
        exit: (code) => { throw new Exit(code || 0); },
        nextTick: (fn, ...a) => Promise.resolve().then(() => fn(...a)),
        hrtime: Object.assign(() => [0, 0], { bigint: () => BigInt(Math.round(performance.now() * 1e6)) }),
        memoryUsage: () => ({ heapUsed: 0 }),
    };
    const req = (name) => {
        name = String(name).replace(/^node:/, '');
        if (name === 'fs') return { readFileSync: (f) => { if (f === 0 || f === '/dev/stdin') return input; throw new Error('File access is not available'); } };
        if (name === 'readline') return { createInterface: () => rlInterface };
        if (name === 'util') return { format: (...a) => a.map(fmt).join(' '), inspect: fmt };
        throw new Error(`Cannot find module '${name}'`);
    };
    const cons = {
        log: (...a) => write(a.map(fmt).join(' ') + '\n'),
        info: (...a) => write(a.map(fmt).join(' ') + '\n'),
        error: (...a) => writeErr(a.map(fmt).join(' ') + '\n'),
        warn: (...a) => writeErr(a.map(fmt).join(' ') + '\n'),
        table: (v) => write(fmt(v) + '\n'),
    };

    let error = '';
    const fail = (e) => {
        if (e instanceof Exit) { exitCode = e.code; return; }
        exitCode = 1;
        error = 'Runtime error';
        writeErr(`${e && e.name || 'Error'}: ${e && e.message || e}\n`);
    };
    const finish = () => {
        if (truncated) stderr += '\n[output too long, cut]';
        postMessage({ type: 'result', id: m.id, stdout, stderr, exitCode, error });
    };

    try {
        const fn = new Function('console', 'process', 'require', 'readline', 'prompt', 'module', 'exports', '"use strict";\n' + m.code);
        const module = { exports: {} };
        fn(cons, proc, req, readline, () => readline(), module, module.exports);
    } catch (e) { fail(e); finish(); return; }

    // Feed stdin to the registered callbacks, then let pending promises settle
    setTimeout(() => {
        try {
            if (dataHandlers.length) { const rest = lines.slice(pos).join('\n') + '\n'; pos = lines.length; dataHandlers.forEach(f => f(rest)); }
            while (lineHandlers.length && pos < lines.length) { const l = lines[pos++]; lineHandlers.forEach(f => f(l)); }
            endHandlers.forEach(f => f());
            closeHandlers.forEach(f => f());
        } catch (e) { fail(e); }
        setTimeout(finish, 0);
    }, 0);
    self.onunhandledrejection = (e) => { fail(e.reason); };
};
