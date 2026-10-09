# Compilers for coding questions

These files let candidates compile and run code **inside their own browser** during the exam.
Nothing is sent to an online compiler or any other API, and no code runs on the web server.

| Folder | What | Version | Licence |
|---|---|---|---|
| `clang/` | Clang/LLD compiler for C and C++, built for WebAssembly (YoWASP) | @yowasp/clang 22.0.0-git20542-10 | ISC (YoWASP), Apache-2.0 with LLVM exception (LLVM) |
| `wasi/` | Runs the compiled C/C++ program (standard input/output) | @bjorn3/browser_wasi_shim 0.4.2 | MIT or Apache-2.0 |
| `pyodide/` | Python 3 interpreter for WebAssembly | pyodide 314.0.7 | MPL-2.0 |

JavaScript uses the browser's own engine, so it needs no files here.

`clang/llvm.core.wasm` is stored as `llvm.core.wasm.part0` to `part3` (25 MB each) so no file is over
GitHub's 50 MB warning size; `modules/coderun/cc_worker.js` joins them in the browser.

Size: C/C++ is about 105 MB and Python about 13 MB. Each browser downloads a compiler once, the first time
a coding question in that language is opened, and keeps it in its cache. If the web server compresses files, C/C++ is about 30 MB to download.

To load the compilers from somewhere else (for example a CDN), change `CODE_COMPILERS_URL` in `config.php`.
