// wasm_worker.js (module worker): runs one compiled C / C++ program on one input.
// The page kills this worker when the time limit passes (infinite loops).
// In:  { type: 'run', base, wasm, input, maxOutput }
// Out: { type: 'result', stdout, stderr, exitCode, error }

onmessage = async (ev) => {
    const { base, wasm, input, maxOutput } = ev.data;
    const { WASI, File, OpenFile, ConsoleStdout, PreopenDirectory } = await import(base + 'wasi/index.js');
    const dec = new TextDecoder();
    let stdout = '', stderr = '', truncated = false;
    const limit = maxOutput || 65536;
    const out = new ConsoleStdout((buf) => {
        if (stdout.length < limit) stdout += dec.decode(buf, { stream: true });
        else truncated = true;
    });
    const err = new ConsoleStdout((buf) => { if (stderr.length < limit) stderr += dec.decode(buf, { stream: true }); });
    const fds = [
        new OpenFile(new File(new TextEncoder().encode(input || ''))),
        out, err,
        new PreopenDirectory('/', new Map()),
    ];
    const wasi = new WASI(['main'], [], fds);
    let exitCode = 0, error = '';
    try {
        const module = await WebAssembly.compile(wasm);
        const inst = await WebAssembly.instantiate(module, { wasi_snapshot_preview1: wasi.wasiImport });
        exitCode = wasi.start(inst);
    } catch (e) {
        if (e && typeof e.code === 'number') exitCode = e.code;
        else {
            const msg = String(e && e.message || e);
            error = /unreachable/.test(msg) ? 'Runtime error (program aborted)'
                  : /out of bounds/.test(msg) ? 'Runtime error (segmentation fault: invalid memory access)'
                  : /divide by zero|integer overflow/.test(msg) ? 'Runtime error (division by zero)'
                  : /call stack|recursion/i.test(msg) ? 'Runtime error (stack overflow)'
                  : 'Runtime error: ' + msg;
        }
    }
    if (truncated) stderr += '\n[output too long, cut]';
    postMessage({ type: 'result', stdout, stderr, exitCode, error });
};
