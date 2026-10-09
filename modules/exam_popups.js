// exam_popups.js
// "Instructions" and "Question Paper" popups on the exam page (TCS iON style).
// The exam timer keeps running while a popup is open.

(() => {
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // "Question Type: MCQ ; Marks for correct answer: 2 ; Negative Marks: 0.67", as in the real GATE paper
    const num = (n) => String(parseFloat((Number(n) || 0).toFixed(2)));
    const metaLine = (q) => {
        const marks = parseFloat(q.marks) || 0;
        const type = q.type === 'CODE' ? 'Coding' : (q.type || '');
        const neg = q.type === 'MCQ' ? marks / 3 : 0;
        return `<div class="xp-q-meta">Question Type: <b>${esc(type)}</b> ; Marks for correct answer: <b class="xp-pos">${num(marks)}</b> ; Negative Marks: <b class="xp-neg">${num(neg)}</b></div>`;
    };
    const imgUrl = (u) => (typeof getSafeImageUrl === 'function' ? getSafeImageUrl(u) : u);
    // Question Paper: questions of the current section only, without options (as in TCS iON).
    // A passage is printed once, before the first of its questions.
    const buildQuestionPaper = () => {
        const qs = window.EXAM_CONFIG.questions || [];
        const passages = window.EXAM_CONFIG.passages || {};
        const cur = (typeof sectionOf === 'function' && typeof currentQuestionIndex === 'number') ? sectionOf(currentQuestionIndex) : null;
        const from = cur ? cur.start : 0;
        const to = cur ? cur.end : qs.length - 1;
        const html = [`<div class="xp-section">${esc(cur ? cur.name : (window.EXAM_CONFIG.sectionName || 'Questions'))}</div>`];
        let lastPassage = null;
        for (let i = from; i <= to; i++) {
            const q = qs[i];
            if (q.passageId && q.passageId !== lastPassage && passages[q.passageId]) {
                const p = passages[q.passageId];
                html.push(`
                <div class="xp-passage">
                    <div class="xp-passage-head">Read the passage given below and answer the questions that follow:</div>
                    ${p.image ? `<img class="xp-q-img" src="${esc(imgUrl(p.image))}" loading="lazy" alt="Passage">` : ''}
                    ${p.text ? `<div class="xp-passage-text">${esc(p.text)}</div>` : ''}
                </div>`);
            }
            lastPassage = q.passageId;
            html.push(`
                <div class="xp-q">
                    <div class="xp-q-no">Q.${i + 1})</div>
                    <div class="xp-q-body">${q.type === 'CODE' && q.coding
                        ? `<div class="xp-code-title">${esc(q.coding.title)} (coding)</div><div class="xp-code-text">${esc(q.coding.statement)}</div>`
                        : `<img class="xp-q-img" src="${esc(imgUrl(q.imageText))}" loading="lazy" alt="Question ${i + 1}">`}
                        ${metaLine(q)}</div>
                </div>`);
        }
        document.getElementById('xp-paper-body').innerHTML = html.join('');
    };

    const open = (id) => {
        if (id === 'xp-paper') buildQuestionPaper(); // rebuilt each time: the section may have changed
        document.querySelectorAll('.xp-overlay').forEach(el => el.classList.add('hidden'));
        const el = document.getElementById(id);
        el.classList.remove('hidden');
        el.querySelector('.xp-body').scrollTop = 0;
    };
    const close = (id) => document.getElementById(id).classList.add('hidden');

    document.addEventListener('DOMContentLoaded', () => {
        const bind = (btnId, fn) => { const b = document.getElementById(btnId); if (b) b.onclick = fn; };
        bind('xp-instr-link', () => open('xp-instr'));
        bind('xp-paper-link', () => open('xp-paper'));
        document.querySelectorAll('.xp-overlay .xp-close').forEach(c => {
            c.onclick = () => close(c.closest('.xp-overlay').id);
        });
    });
})();
