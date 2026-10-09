// exam_coding.js
// Coding questions on the exam page (TCS NQT style): problem statement on the left,
// code editor on the right with Compile & Run and Submit Code.
// Code is compiled and run in this browser by modules/coderun/runner.js (no online API).
// testState[i].code  = { lang: source }  the candidate's code per language (autosaved)
// testState[i].lang  = selected language
// testState[i].answer = { lang, code, passed, total } after "Submit Code" (this is what is marked)

(() => {
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const LANG_LABEL = { c: 'C', cpp: 'C++', python: 'Python 3', javascript: 'JavaScript' };
    let running = false;

    const block = (title, text) => text ? `<div class="cq-block"><div class="cq-h">${esc(title)}</div><div class="cq-text">${esc(text)}</div></div>` : '';

    const renderStatement = (q) => {
        const c = q.coding;
        const samples = c.tests.filter(t => t.sample);
        const img = q.imageText ? `<img src="${esc(typeof getSafeImageUrl === 'function' ? getSafeImageUrl(q.imageText) : q.imageText)}" class="max-w-full h-auto object-contain my-3" alt="">` : '';
        return `<div class="cq-statement">
            <div class="cq-title">${esc(c.title)}</div>
            ${block('Problem Statement', c.statement)}
            ${img}
            ${block('Input Format', c.inputFormat)}
            ${block('Output Format', c.outputFormat)}
            ${block('Constraints', c.constraints)}
            ${samples.map((t, i) => `
                <div class="cq-block">
                    <div class="cq-h">Sample Input ${samples.length > 1 ? i + 1 : ''}</div><pre class="cq-pre">${esc(t.input)}</pre>
                    <div class="cq-h">Sample Output ${samples.length > 1 ? i + 1 : ''}</div><pre class="cq-pre">${esc(t.output)}</pre>
                </div>`).join('')}
        </div>`;
    };

    const codeFor = (q, state, lang) => {
        state.code = state.code || {};
        if (typeof state.code[lang] !== 'string') state.code[lang] = q.coding.starter[lang] || '';
        return state.code[lang];
    };

    const updateGutter = () => {
        const ta = document.getElementById('cq-code');
        const g = document.getElementById('cq-gutter');
        if (!ta || !g) return;
        const n = ta.value.split('\n').length;
        if (g.childElementCount !== n) g.innerHTML = Array.from({ length: n }, (_, i) => `<div>${i + 1}</div>`).join('');
        g.scrollTop = ta.scrollTop;
    };

    const setOutput = (html) => { const o = document.getElementById('cq-output'); if (o) o.innerHTML = html; };
    const setBusy = (busy, text) => {
        running = busy;
        ['cq-run', 'cq-submit', 'cq-lang', 'cq-reset'].forEach(id => { const b = document.getElementById(id); if (b) b.disabled = busy; });
        if (busy) setOutput(`<div class="cq-status">${esc(text || 'Working...')}</div>`);
    };

    const submittedNote = (state) => {
        const a = state.answer;
        const el = document.getElementById('cq-submitted');
        if (!el) return;
        if (!a) { el.innerHTML = ''; return; }
        const changed = state.code && state.code[a.lang] !== a.code;
        el.innerHTML = `<span class="cq-badge ${a.passed === a.total ? 'ok' : 'part'}">Last submission: ${a.passed}/${a.total} test cases passed (${esc(LANG_LABEL[a.lang])})</span>` +
            (changed ? ` <span class="cq-warn">You changed the code after submitting. Click Submit Code again to use the new code.</span>` : '');
    };

    // Comparison table for visible cases; hidden cases show only pass/fail
    const resultRows = (tests, results, showDetails) => results.map((r, i) => {
        const t = tests[i];
        const pass = !r.error && CodeRunner.sameOutput(r.stdout, t.output);
        const label = t.sample ? `Sample test case ${i + 1}` : `Hidden test case ${i + 1}`;
        const why = r.error ? ` (${esc(r.error)})` : '';
        let detail = '';
        if (showDetails && t.sample) {
            detail = `<div class="cq-cmp">
                <div><div class="cq-sub">Input</div><pre class="cq-pre">${esc(t.input)}</pre></div>
                <div><div class="cq-sub">Expected output</div><pre class="cq-pre">${esc(t.output)}</pre></div>
                <div><div class="cq-sub">Your output</div><pre class="cq-pre">${esc(r.stdout)}${r.stderr ? `\n<span class="cq-err">${esc(r.stderr)}</span>` : ''}</pre></div>
            </div>`;
        }
        return `<div class="cq-case"><span class="cq-dot ${pass ? 'ok' : 'bad'}"></span><b>${label}:</b> ${pass ? 'Passed' : 'Failed' + why}${detail}</div>`;
    }).join('');

    const runCode = async (index, mode) => {
        if (running) return;
        const q = window.EXAM_CONFIG.questions[index];
        const state = testState[index];
        const lang = state.lang;
        const code = document.getElementById('cq-code').value;
        state.code[lang] = code;
        const custom = mode === 'run' && document.getElementById('cq-custom-on').checked;
        const tests = mode === 'submit' ? q.coding.tests
                    : custom ? [{ input: document.getElementById('cq-custom-in').value, output: null, sample: true }]
                    : q.coding.tests.filter(t => t.sample);
        if (!tests.length) { setOutput('<div class="cq-status">This question has no sample test cases. Use custom input or Submit Code.</div>'); return; }
        setBusy(true, mode === 'submit' ? 'Submitting: compiling...' : 'Compiling...');
        let r;
        try {
            r = await CodeRunner.run(lang, code, tests.map(t => t.input), {
                timeLimitMs: q.coding.timeLimitMs,
                onStatus: (s) => { if (currentQuestionIndex === index) setOutput(`<div class="cq-status">${esc(s)}</div>`); },
            });
        } catch (e) {
            setBusy(false);
            setOutput(`<div class="cq-compile-err">Could not run the code: ${esc(e.message)}</div>`);
            return;
        }
        setBusy(false);
        if (currentQuestionIndex !== index) return; // candidate moved on; nothing to show

        if (!r.compiled) {
            setOutput(`<div class="cq-h">Compilation Error</div><pre class="cq-pre cq-err">${esc(r.compileLog)}</pre>`);
            if (mode === 'submit') {
                state.answer = { lang, code, passed: 0, total: tests.length, compileError: true, compileLog: String(r.compileLog || '').slice(0, 2000) };
                if (typeof window.onCodingSubmitted === 'function') window.onCodingSubmitted(index);
                submittedNote(state);
            }
            return;
        }
        if (custom) {
            const res = r.results[0];
            setOutput(`<div class="cq-h">Output (custom input)${res.timeMs ? ` <span class="cq-sub">${res.timeMs} ms</span>` : ''}</div>
                <pre class="cq-pre">${esc(res.stdout)}${res.stderr ? `\n<span class="cq-err">${esc(res.stderr)}</span>` : ''}</pre>
                ${res.error ? `<div class="cq-err">${esc(res.error)}</div>` : ''}`);
            return;
        }
        const passed = r.results.filter((res, i) => !res.error && CodeRunner.sameOutput(res.stdout, tests[i].output)).length;
        const head = mode === 'submit'
            ? `<div class="cq-h">Code submitted: ${passed} / ${tests.length} test cases passed</div>`
            : `<div class="cq-h">Compiled successfully. ${passed} / ${tests.length} sample test cases passed</div>`;
        setOutput(head + (r.compileLog ? `<pre class="cq-pre cq-warnlog">${esc(r.compileLog)}</pre>` : '') + resultRows(tests, r.results, true));
        if (mode === 'submit') {
            state.answer = { lang, code, passed, total: tests.length,
                // per test case: passed?, run time, error; used by the downloadable report
                results: r.results.map((res, i) => ({ pass: !res.error && CodeRunner.sameOutput(res.stdout, tests[i].output), ms: res.timeMs || 0, error: res.error || '' })) };
            if (typeof window.onCodingSubmitted === 'function') window.onCodingSubmitted(index);
            submittedNote(state);
        }
    };

    const render = (q, state, index) => {
        const c = q.coding;
        if (!state.lang || !c.languages.includes(state.lang)) state.lang = (state.answer && c.languages.includes(state.answer.lang)) ? state.answer.lang : c.languages[0];
        const lang = state.lang;

        // Left: statement (in the passage pane)
        const pane = document.getElementById('passage-pane');
        const content = document.getElementById('passage-content');
        pane.style.display = '';
        pane.classList.add('cq-left');
        content.dataset.passageId = 'code-' + q.id;
        content.innerHTML = renderStatement(q);
        pane.scrollTop = 0;

        document.getElementById('question-text').innerHTML = '';
        const box = document.getElementById('options-container');
        box.className = 'relative z-20';
        box.innerHTML = `
            <div class="cq-editor-wrap">
                <div class="cq-toolbar">
                    <label>Language:
                        <select id="cq-lang">${c.languages.map(l => `<option value="${l}" ${l === lang ? 'selected' : ''}>${esc(LANG_LABEL[l])}</option>`).join('')}</select>
                    </label>
                    <button type="button" id="cq-reset" class="cq-btn-light">Reset code</button>
                </div>
                <div class="cq-editor">
                    <div id="cq-gutter" class="cq-gutter"></div>
                    <textarea id="cq-code" class="code-input" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off" wrap="off"></textarea>
                </div>
                <div class="cq-custom">
                    <label><input type="checkbox" id="cq-custom-on"> Test against custom input</label>
                    <textarea id="cq-custom-in" class="code-input" rows="3" spellcheck="false" style="display:none" placeholder="Type the input here"></textarea>
                </div>
                <div class="cq-actions">
                    <button type="button" id="cq-run" class="cq-btn">Compile &amp; Run</button>
                    <button type="button" id="cq-submit" class="cq-btn cq-btn-green">Submit Code</button>
                    <span id="cq-submitted"></span>
                </div>
                <div id="cq-output" class="cq-output"><div class="cq-status">Compile &amp; Run checks your code against the sample test cases. Submit Code runs all test cases; your last submission is marked.</div></div>
            </div>`;

        const ta = document.getElementById('cq-code');
        ta.value = codeFor(q, state, lang);
        updateGutter();
        submittedNote(state);
        if (running) setBusy(true, 'Still running the previous code...');

        ta.addEventListener('input', () => { state.code[state.lang] = ta.value; updateGutter(); submittedNote(state); });
        ta.addEventListener('scroll', updateGutter);
        ta.addEventListener('keydown', (e) => {
            if (e.key === 'Tab') {
                e.preventDefault();
                const s = ta.selectionStart, en = ta.selectionEnd;
                ta.setRangeText('    ', s, en, 'end');
                ta.dispatchEvent(new Event('input'));
            } else if (e.key === 'Enter') {
                // keep the indentation of the current line, one more level after { or :
                e.preventDefault();
                const s = ta.selectionStart;
                const lineStart = ta.value.lastIndexOf('\n', s - 1) + 1;
                const line = ta.value.slice(lineStart, s);
                let indent = (line.match(/^[ \t]*/) || [''])[0];
                if (/[{:(\[]\s*$/.test(line)) indent += '    ';
                ta.setRangeText('\n' + indent, s, ta.selectionEnd, 'end');
                ta.dispatchEvent(new Event('input'));
            }
        });
        document.getElementById('cq-lang').onchange = (e) => {
            state.code[state.lang] = ta.value;
            state.lang = e.target.value;
            ta.value = codeFor(q, state, state.lang);
            updateGutter(); submittedNote(state);
            CodeRunner.preload(state.lang);
        };
        // No confirm() dialog here: a browser dialog can count as leaving the exam window
        const resetBtn = document.getElementById('cq-reset');
        resetBtn.onclick = () => {
            if (!resetBtn.dataset.armed) {
                resetBtn.dataset.armed = '1';
                resetBtn.textContent = 'Click again to reset';
                setTimeout(() => { delete resetBtn.dataset.armed; resetBtn.textContent = 'Reset code'; }, 3000);
                return;
            }
            delete resetBtn.dataset.armed; resetBtn.textContent = 'Reset code';
            ta.value = q.coding.starter[state.lang] || '';
            ta.dispatchEvent(new Event('input'));
        };
        document.getElementById('cq-custom-on').onchange = (e) => {
            document.getElementById('cq-custom-in').style.display = e.target.checked ? '' : 'none';
        };
        document.getElementById('cq-run').onclick = () => runCode(index, 'run');
        document.getElementById('cq-submit').onclick = () => runCode(index, 'submit');
        CodeRunner.preload(lang);
    };

    // Called by renderQuestion for every question, so the left pane is reset for non-coding ones
    const leave = () => {
        const pane = document.getElementById('passage-pane');
        if (pane) pane.classList.remove('cq-left');
    };

    window.ExamCoding = { render, leave, isBusy: () => running };
})();
