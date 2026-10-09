// cc_worker.js (module worker): compiles C / C++ to WebAssembly inside the browser
// with Clang (YoWASP build). Nothing is sent to any server.
// Messages in:  { type: 'init', base }            base = URL of vendor/compilers/
//               { type: 'compile', id, lang, code }
// Messages out: { type: 'progress', percent } while the compiler downloads
//               { type: 'ready' }
//               { type: 'compiled', id, ok, wasm?, log }

let runClang = null;
let loading = null;
let base = '';
const realFetch = globalThis.fetch.bind(globalThis);

const CACHE_NAME = 'gate-compilers-v1';

// Fetch through the Cache API so the compiler downloads once per browser,
// and serve every .wasm with the right MIME type even if the web server does not.
const cachedFetch = async (url) => {
    let cache = null;
    try { cache = await caches.open(CACHE_NAME); } catch (e) { /* not available (http) */ }
    if (cache) {
        const hit = await cache.match(url);
        if (hit) return hit;
    }
    let resp;
    if (String(url).endsWith('/llvm.core.wasm')) {
        // Stored as parts so no file is over GitHub's 50 MB warning limit
        const parts = [];
        for (let i = 0; ; i++) {
            const r = await realFetch(`${url}.part${i}`);
            if (!r.ok) break;
            parts.push(await r.arrayBuffer());
        }
        if (!parts.length) throw new Error('C/C++ compiler files are missing on the server (vendor/compilers/clang).');
        resp = new Response(new Blob(parts), { headers: { 'Content-Type': 'application/wasm' } });
    } else {
        const r = await realFetch(url);
        if (!r.ok) throw new Error(`Could not load ${url} (${r.status})`);
        const buf = await r.arrayBuffer();
        resp = new Response(buf, { headers: { 'Content-Type': String(url).endsWith('.wasm') ? 'application/wasm' : 'application/octet-stream' } });
    }
    if (cache) { try { await cache.put(url, resp.clone()); } catch (e) { /* quota */ } }
    return resp;
};

const load = () => {
    if (!loading) {
        loading = (async () => {
            globalThis.fetch = (input, init) => {
                const url = String(input instanceof Request ? input.url : input);
                if (url.startsWith(base + 'clang/')) return cachedFetch(url);
                return realFetch(input, init);
            };
            const mod = await import(base + 'clang/bundle.js');
            runClang = mod.runClang;
            // First run downloads and compiles the toolchain; report progress
            await runClang(['clang', '--version'], {}, {
                stdout: null, stderr: null,
                fetchProgress: ({ totalLength, doneLength }) => {
                    if (totalLength) postMessage({ type: 'progress', percent: Math.min(99, Math.floor(doneLength * 100 / totalLength)) });
                },
            });
            postMessage({ type: 'ready' });
        })();
    }
    return loading;
};

// libc++ has no <bits/stdc++.h>; many candidates use it, so provide one
const STDCXX = ['algorithm', 'array', 'bitset', 'cassert', 'cctype', 'climits', 'cmath', 'cstdio', 'cstdlib', 'cstring',
    'ctime', 'deque', 'functional', 'iomanip', 'iostream', 'iterator', 'limits', 'list', 'map', 'numeric', 'queue',
    'set', 'sstream', 'stack', 'string', 'tuple', 'unordered_map', 'unordered_set', 'utility', 'vector', 'cfloat',
    'complex', 'fstream', 'istream', 'ostream', 'random', 'regex', 'stdexcept', 'memory', 'chrono', 'numbers']
    .map(h => `#include <${h}>`).join('\n') + '\n';

// The WebAssembly C++ library is built without exception support; a throw ends the program
const CXX_RT = `#include <cstdio>
#include <cstdlib>
extern "C" void* __cxa_allocate_exception(unsigned long n) { return std::malloc(n); }
extern "C" void __cxa_throw(void*, void*, void (*)(void*)) { std::fprintf(stderr, "terminate called after throwing an exception\\n"); std::abort(); }
`;

const compile = async (lang, code) => {
    await load();
    const dec = new TextDecoder();
    let log = '';
    const sink = (bytes) => { if (bytes) log += dec.decode(bytes); };
    const cpp = lang === 'cpp';
    const files = cpp
        ? { 'main.cpp': code, 'rt.cpp': CXX_RT, inc: { bits: { 'stdc++.h': STDCXX } } }
        : { 'main.c': code };
    const args = cpp
        ? ['clang++', '-O2', '-std=c++17', '-Iinc', '-Wno-everything', 'main.cpp', 'rt.cpp', '-o', 'a.wasm']
        : ['clang', '-O2', '-Wno-everything', 'main.c', '-o', 'a.wasm', '-lm'];
    try {
        const out = await runClang(args, files, { stdout: sink, stderr: sink });
        return { ok: true, wasm: out['a.wasm'], log: log.replace(/\/tmp\/[\w.-]+\.o/g, 'main') };
    } catch (e) {
        if (e && e.files && log) return { ok: false, log: log.replace(/\/tmp\/[\w.-]+\.o/g, 'main') };
        return { ok: false, log: log || String(e && e.message || e) };
    }
};

onmessage = async (ev) => {
    const m = ev.data;
    if (m.type === 'init') {
        base = m.base;
        load().catch(err => postMessage({ type: 'error', message: String(err && err.message || err) }));
    } else if (m.type === 'compile') {
        try {
            const r = await compile(m.lang, m.code);
            postMessage({ type: 'compiled', id: m.id, ...r }, r.wasm ? [r.wasm.buffer] : []);
        } catch (err) {
            postMessage({ type: 'compiled', id: m.id, ok: false, log: String(err && err.message || err) });
        }
    }
};
