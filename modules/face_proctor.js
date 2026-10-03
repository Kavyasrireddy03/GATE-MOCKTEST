// face_proctor.js
// Face-cam proctoring for the GATE mock exam
//  1) Before the exam: candidate's photo is captured from the webcam (this becomes the reference)
//  2) During the exam: no face / multiple faces / different person than the starting photo
//  3) Every warning is logged to face_log.php (text only, no images).
//     The reference photo is kept in this browser's localStorage and is never uploaded.
// Requires: face-api.js (@vladmandic/face-api) loaded before this file.

(function () {
    'use strict';

    const CFG = Object.assign({
        modelUrl: window.FACE_API_MODEL_URL || 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/model',
        logUrl: 'face_log.php',
        matchThreshold: 0.55,      // euclidean distance; lower = stricter (0.45 strict, 0.6 lenient)
        detectIntervalMs: 2000,    // face-count check interval
        identityIntervalMs: 15000, // identity re-check interval during exam
        noFaceLimit: 5,            // consecutive "no face" checks before warning (~10s)
        multiFaceLimit: 2,         // consecutive "multiple faces" checks before warning (~4s)
        mismatchLimit: 2,          // consecutive failed identity checks before warning
        maxWarnings: 5,            // warnings before exam is terminated (0 = log only, never terminate)
        warningCooldownMs: 10000   // min gap between two warnings of the same type
    }, window.FACE_CONFIG || {});

    const S = {
        opts: {}, stream: null, video: null, ref: null,
        modelsReady: false, previewing: false, previewTimer: null,
        running: false, timer: null, warnings: 0,
        noFace: 0, multi: 0, mismatch: 0, recheck: false, lastIdentity: 0,
        lastWarnAt: {}, resolve: null, verifying: false, captured: null
    };

    const sleep = (ms) => new Promise(r => setTimeout(r, ms));
    const $ = (id) => document.getElementById(id);
    const key = (name) => `fp_${name}_${S.opts.subjectId}_${S.opts.setNo}`;

    // ───────────────────────── UI ─────────────────────────
    function injectStyles() {
        if ($('fp-style')) return;
        const st = document.createElement('style');
        st.id = 'fp-style';
        st.textContent = `
        #fp-overlay{position:fixed;inset:0;background:#e9eef2;z-index:99999;display:flex;align-items:center;justify-content:center;font-family:Arial,Helvetica,sans-serif}
        #fp-overlay .fp-card{background:#fff;width:760px;max-width:96vw;max-height:96vh;overflow:auto;border:1px solid #a3c8de;border-radius:6px;box-shadow:0 10px 30px rgba(0,0,0,.15)}
        #fp-overlay .fp-head{background:#287baf;color:#fff;font-weight:bold;font-size:17px;padding:12px 18px}
        #fp-overlay .fp-body{display:flex;gap:18px;padding:18px;flex-wrap:wrap;justify-content:center}
        #fp-overlay .fp-col{flex:1;min-width:240px;display:flex;flex-direction:column;align-items:center}
        #fp-overlay .fp-label{font-size:13px;font-weight:bold;color:#4F6887;margin-bottom:8px}
        #fp-overlay .fp-ref{width:210px;height:250px;border:1px solid #999;background:#f5f5f5;display:flex;align-items:center;justify-content:center;overflow:hidden}
        #fp-overlay .fp-ref img{width:100%;height:100%;object-fit:cover}
        #fp-overlay .fp-live{position:relative;width:100%;max-width:340px;border:2px solid #999;background:#000;line-height:0}
        #fp-overlay .fp-live video,#fp-overlay .fp-live canvas{width:100%;height:auto;transform:scaleX(-1)}
        #fp-overlay .fp-live canvas{position:absolute;inset:0;height:100%}
        #fp-overlay .fp-count{margin-top:6px;font-size:12px;font-weight:bold;color:#555}
        #fp-overlay .fp-rules{margin:0 18px;padding:10px 14px 10px 30px;background:#f7fafc;border:1px solid #dbe6ee;font-size:12.5px;color:#333;line-height:1.7}
        #fp-overlay .fp-status{margin:14px 18px 0;padding:10px 12px;font-size:13.5px;font-weight:bold;border-radius:3px;background:#eef5fb;color:#1F4E79}
        #fp-overlay .fp-status.error{background:#fdecec;color:#b91c1c}
        #fp-overlay .fp-status.ok{background:#e9f7e1;color:#2f6b12}
        #fp-overlay .fp-actions{display:flex;justify-content:center;gap:10px;padding:16px}
        #fp-overlay button{padding:9px 26px;font-size:14px;font-weight:bold;border:none;border-radius:2px;cursor:pointer;color:#fff;background:#287baf}
        #fp-overlay button:disabled{background:#88acc2;cursor:not-allowed}
        #fp-overlay button.fp-secondary{background:#fff;color:#333;border:1px solid #ccc}
        #fp-mini{position:fixed;left:12px;bottom:70px;width:140px;z-index:9998;background:#000;border:3px solid #6DB825;border-radius:4px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.35);line-height:0;cursor:move}
        #fp-mini.in-slot{position:relative;left:auto;bottom:auto;width:100%;height:100%;border-width:2px;border-radius:0;box-shadow:none;cursor:default}
        #fp-mini video{width:100%;height:auto;transform:scaleX(-1);display:block}
        #fp-mini.in-slot video{height:100%;object-fit:cover}
        #fp-mini .fp-badge{position:absolute;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);color:#fff;font:bold 9px Arial;line-height:13px;text-align:center;white-space:nowrap;overflow:hidden}
        #fp-mini.bad{border-color:#FF5252}
        #fp-mini.bad .fp-badge{background:rgba(185,28,28,.85)}
        #fp-banner{position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:99998;background:#b91c1c;color:#fff;font:bold 15px Arial;padding:12px 22px;border-radius:4px;box-shadow:0 6px 18px rgba(0,0,0,.3);display:none;max-width:90vw;text-align:center}
        #fp-banner.final{background:#000}
        `;
        document.head.appendChild(st);
    }

    function buildOverlay() {
        const wrap = document.createElement('div');
        wrap.id = 'fp-overlay';
        wrap.innerHTML = `
          <div class="fp-card">
            <div class="fp-head">Candidate Photo Capture</div>
            <div class="fp-body">
              <div class="fp-col">
                <div class="fp-label">Live Camera</div>
                <div class="fp-live" id="fp-live">
                  <video id="fp-video" autoplay muted playsinline></video>
                  <canvas id="fp-draw"></canvas>
                </div>
                <div class="fp-count" id="fp-count">Faces in frame: –</div>
              </div>
              <div class="fp-col">
                <div class="fp-label">Captured Photo</div>
                <div class="fp-ref"><img id="fp-ref-img" alt="" style="display:none"><span id="fp-ref-empty" style="font-size:12px;color:#888">Not captured yet</span></div>
              </div>
            </div>
            <ul class="fp-rules">
              <li>Sit in a well-lit place and look straight at the camera.</li>
              <li>Only you must be visible. Any other person in the frame is a violation.</li>
              <li>Remove mask, cap or dark glasses.</li>
              <li>This photo is used to verify you throughout the exam. Your photo stays in this browser and is not uploaded. After ${CFG.maxWarnings > 0 ? CFG.maxWarnings : 'repeated'} warnings the exam ends.</li>
            </ul>
            <div class="fp-status" id="fp-status">Preparing…</div>
            <div class="fp-actions">
              <button id="fp-retry-btn" class="fp-secondary" style="display:none">Retry</button>
              <button id="fp-retake-btn" class="fp-secondary" style="display:none">Retake</button>
              <button id="fp-capture-btn" disabled>Capture Photo</button>
              <button id="fp-start-btn" style="display:none">Confirm &amp; Start Exam</button>
            </div>
          </div>`;
        document.body.appendChild(wrap);
        S.video = $('fp-video');
        $('fp-capture-btn').onclick = doCapture;
        $('fp-retake-btn').onclick = retake;
        $('fp-start-btn').onclick = confirmStart;
        $('fp-retry-btn').onclick = () => { $('fp-retry-btn').style.display = 'none'; setup(); };
    }

    function setStatus(msg, type = '') {
        const el = $('fp-status');
        if (!el) return;
        el.textContent = msg;
        el.className = 'fp-status' + (type ? ' ' + type : '');
    }

    function showBanner(msg, final = false) {
        let b = $('fp-banner');
        if (!b) { b = document.createElement('div'); b.id = 'fp-banner'; document.body.appendChild(b); }
        b.textContent = msg;
        b.className = final ? 'final' : '';
        b.style.display = 'block';
        clearTimeout(b._t);
        if (!final) b._t = setTimeout(() => { b.style.display = 'none'; }, 6000);
    }

    function setBadge(text, bad) {
        const m = $('fp-mini');
        if (!m) return;
        m.classList.toggle('bad', !!bad);
        m.querySelector('.fp-badge').textContent = text;
    }

    // ───────────────────────── ENGINE ─────────────────────────
    const tinyOpts = () => new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 });
    const ssdOpts = (c = 0.5) => new faceapi.SsdMobilenetv1Options({ minConfidence: c });

    async function loadModels() {
        if (S.modelsReady) return;
        if (!window.faceapi) throw new Error('Face verification engine failed to load. Check your internet connection and click Retry.');
        try { if (!(await faceapi.tf.setBackend('webgl'))) await faceapi.tf.setBackend('cpu'); } catch (e) { }
        await faceapi.tf.ready();
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(CFG.modelUrl),
            faceapi.nets.ssdMobilenetv1.loadFromUri(CFG.modelUrl),
            faceapi.nets.faceLandmark68Net.loadFromUri(CFG.modelUrl),
            faceapi.nets.faceRecognitionNet.loadFromUri(CFG.modelUrl)
        ]);
        S.modelsReady = true;
    }

    async function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Camera not supported here. Open the exam over HTTPS in Chrome / Edge.');
        }
        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' }, audio: false
            });
        } catch (e) {
            const map = {
                NotAllowedError: 'Camera permission denied. Click the camera icon in the address bar, choose "Allow", then click Retry.',
                NotFoundError: 'No camera found. Connect a webcam and click Retry.',
                NotReadableError: 'Camera is being used by another application. Close it and click Retry.',
                OverconstrainedError: 'Camera does not support the required mode. Click Retry.'
            };
            throw new Error(map[e.name] || ('Unable to start camera: ' + e.message));
        }
        S.stream = stream;
        S.video.srcObject = stream;
        await S.video.play().catch(() => { });
        for (let i = 0; i < 50 && (S.video.readyState < 2 || !S.video.videoWidth); i++) await sleep(100);
        stream.getVideoTracks().forEach(t => t.addEventListener('ended', onCameraLost));
    }

    function log(event, data = {}) {
        const body = JSON.stringify(Object.assign({
            event, subject_id: S.opts.subjectId, set_no: S.opts.setNo
        }, data));
        return fetch(CFG.logUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body, keepalive: body.length < 60000
        }).catch(() => { });
    }

    // ───────────────────────── PHOTO CAPTURE ─────────────────────────
    // The reference photo never leaves the candidate's browser.
    function savePhotoLocally(photo) {
        try { localStorage.setItem(key('photo'), photo); } catch (e) { /* storage full or blocked */ }
    }

    function drawBoxes(dets) {
        const c = $('fp-draw');
        if (!c || !S.video.videoWidth) return;
        c.width = S.video.videoWidth; c.height = S.video.videoHeight;
        const ctx = c.getContext('2d');
        ctx.clearRect(0, 0, c.width, c.height);
        ctx.lineWidth = 3;
        ctx.strokeStyle = dets.length === 1 ? '#6DB825' : '#FF5252';
        dets.forEach(d => { const b = d.box || d.detection.box; ctx.strokeRect(b.x, b.y, b.width, b.height); });
    }

    async function previewLoop() {
        if (!S.previewing) return;
        try {
            if (S.video.readyState >= 2) {
                const dets = await faceapi.detectAllFaces(S.video, tinyOpts());
                if (!S.previewing) return;
                drawBoxes(dets);
                const n = dets.length;
                $('fp-count').textContent = 'Faces in frame: ' + n;
                $('fp-live').style.borderColor = n === 1 ? '#6DB825' : '#FF5252';
                if (!S.verifying && !S.captured) {
                    $('fp-capture-btn').disabled = n !== 1;
                    if (n === 0) setStatus('No face detected. Look straight at the camera.', 'error');
                    else if (n > 1) setStatus(`Multiple faces detected (${n}). Only the candidate may be in front of the camera.`, 'error');
                    else setStatus('Face detected. Click "Capture Photo".', 'ok');
                }
            }
        } catch (e) { console.warn('preview', e); }
        S.previewTimer = setTimeout(previewLoop, 400);
    }

    function stopPreview() {
        S.previewing = false;
        clearTimeout(S.previewTimer);
        const c = $('fp-draw');
        if (c) c.getContext('2d').clearRect(0, 0, c.width, c.height);
    }

    async function setup() {
        try {
            setStatus('Loading face engine and starting camera… allow camera access if prompted.');
            await Promise.all([loadModels(), S.stream ? null : startCamera()]);
            S.previewing = true;
            previewLoop();
        } catch (e) {
            console.error(e);
            setStatus(e.message || String(e), 'error');
            $('fp-retry-btn').style.display = '';
        }
    }

    function averageDescriptor(list) {
        const out = new Float32Array(list[0].length);
        list.forEach(d => { for (let i = 0; i < d.length; i++) out[i] += d[i] / list.length; });
        return out;
    }

    function fullSnapshot() {
        const v = S.video, c = document.createElement('canvas');
        c.width = v.videoWidth; c.height = v.videoHeight;
        const ctx = c.getContext('2d');
        ctx.translate(c.width, 0); ctx.scale(-1, 1);   // mirror, same as preview
        ctx.drawImage(v, 0, 0, c.width, c.height);
        return c.toDataURL('image/jpeg', 0.85);
    }

    async function doCapture() {
        if (S.verifying) return;
        S.verifying = true;
        $('fp-capture-btn').disabled = true;
        setStatus('Capturing… hold still and look at the camera.');

        const descs = [];
        let multiFrames = 0, maxFaces = 0, photo = null;
        for (let i = 0; i < 8 && descs.length < 3; i++) {
            const dets = await faceapi.detectAllFaces(S.video, ssdOpts()).withFaceLandmarks().withFaceDescriptors();
            maxFaces = Math.max(maxFaces, dets.length);
            if (dets.length > 1) { if (++multiFrames >= 2) break; }
            else if (dets.length === 1) {
                descs.push(dets[0].descriptor);
                if (!photo) photo = fullSnapshot();
            }
            await sleep(250);
        }
        S.verifying = false;

        if (multiFrames >= 2) {
            $('fp-capture-btn').disabled = false;
            return setStatus(`Multiple faces detected (${maxFaces}). Only the candidate may be in front of the camera.`, 'error');
        }
        if (descs.length < 3) {
            $('fp-capture-btn').disabled = false;
            return setStatus('Face not clearly visible. Improve lighting, face the camera and try again.', 'error');
        }
        // make sure the 3 frames are the same person (nobody swapped mid-capture)
        const spread = Math.max(...descs.slice(1).map(d => faceapi.euclideanDistance(d, descs[0])));
        if (spread > CFG.matchThreshold) {
            $('fp-capture-btn').disabled = false;
            return setStatus('Face changed during capture. Hold still and try again.', 'error');
        }

        S.captured = { descriptor: averageDescriptor(descs), photo };
        stopPreview();
        $('fp-ref-img').src = photo;
        $('fp-ref-img').style.display = '';
        $('fp-ref-empty').style.display = 'none';
        $('fp-capture-btn').style.display = 'none';
        $('fp-retake-btn').style.display = '';
        $('fp-start-btn').style.display = '';
        setStatus('Photo captured ✓ Check it and click "Confirm & Start Exam", or Retake.', 'ok');
    }

    function retake() {
        S.captured = null;
        $('fp-ref-img').style.display = 'none';
        $('fp-ref-empty').style.display = '';
        $('fp-capture-btn').style.display = '';
        $('fp-retake-btn').style.display = 'none';
        $('fp-start-btn').style.display = 'none';
        S.previewing = true;
        previewLoop();
    }

    async function confirmStart() {
        if (!S.captured) return;
        $('fp-start-btn').disabled = true;
        $('fp-retake-btn').disabled = true;
        S.ref = S.captured.descriptor;
        savePhotoLocally(S.captured.photo);
        log('start_photo', { face_count: 1, message: 'Reference photo captured at exam start (kept in browser)' });
        setStatus('Starting exam…', 'ok');
        await sleep(500);
        enterMonitoring();
    }

    // ───────────────────────── MONITORING ─────────────────────────
    function makeDraggable(el) {
        let sx, sy, ox, oy, drag = false;
        el.addEventListener('mousedown', (e) => {
            drag = true; sx = e.clientX; sy = e.clientY;
            const r = el.getBoundingClientRect(); ox = r.left; oy = r.top;
            e.preventDefault();
        });
        window.addEventListener('mousemove', (e) => {
            if (!drag) return;
            el.style.left = Math.max(0, Math.min(window.innerWidth - el.offsetWidth, ox + e.clientX - sx)) + 'px';
            el.style.top = Math.max(0, Math.min(window.innerHeight - el.offsetHeight, oy + e.clientY - sy)) + 'px';
            el.style.bottom = 'auto';
        });
        window.addEventListener('mouseup', () => { drag = false; });
    }

    async function enterMonitoring() {
        // Fresh <video> for the exam screen (moving the old element freezes the frame in Chrome)
        const v = document.createElement('video');
        v.muted = true; v.autoplay = true; v.playsInline = true;
        v.setAttribute('muted', ''); v.setAttribute('playsinline', '');
        v.srcObject = S.stream;

        const mini = document.createElement('div');
        mini.id = 'fp-mini';
        mini.title = 'Live proctoring camera';
        mini.innerHTML = '<div class="fp-badge">● LIVE</div>';
        mini.insertBefore(v, mini.firstChild);

        const slot = $('fp-cam-slot');
        if (slot) { mini.classList.add('in-slot'); slot.appendChild(mini); }
        else { document.body.appendChild(mini); makeDraggable(mini); }

        const old = S.video;
        S.video = v;
        await v.play().catch(() => { });
        for (let i = 0; i < 30 && (v.readyState < 2 || !v.videoWidth); i++) await sleep(100);
        if (old) old.srcObject = null;
        const ov = $('fp-overlay');
        if (ov) ov.remove();

        // Chrome can pause a video when the page is re-laid out; keep it playing
        v.addEventListener('pause', () => { if (S.running) v.play().catch(() => { }); });

        S.warnings = parseInt(sessionStorage.getItem(key('warnings')) || '0', 10);
        S.running = true;
        S.lastIdentity = Date.now();
        S.timer = setTimeout(tick, CFG.detectIntervalMs);
        if (S.resolve) { S.resolve(); S.resolve = null; }
    }

    async function tick() {
        if (!S.running) return;
        try {
            if (S.video.readyState >= 2) {
                const now = Date.now();
                const doIdentity = S.recheck || (now - S.lastIdentity >= CFG.identityIntervalMs);
                let dets;
                if (doIdentity) {
                    dets = await faceapi.detectAllFaces(S.video, ssdOpts()).withFaceLandmarks().withFaceDescriptors();
                    S.lastIdentity = now;
                } else {
                    dets = await faceapi.detectAllFaces(S.video, tinyOpts());
                }
                if (!S.running) return;
                const n = dets.length;

                if (n > 1) {
                    S.noFace = 0;
                    setBadge(`⚠ ${n} FACES`, true);
                    if (++S.multi >= CFG.multiFaceLimit) {
                        S.multi = 0;
                        warn('multiple_faces', `Multiple faces detected (${n}). Only the candidate may be visible.`, { face_count: n });
                    }
                } else if (n === 0) {
                    S.multi = 0;
                    setBadge('⚠ NO FACE', true);
                    if (++S.noFace >= CFG.noFaceLimit) {
                        S.noFace = 0;
                        warn('no_face', 'Face not visible. Stay in front of the camera.', { face_count: 0 });
                    }
                } else {
                    S.noFace = 0; S.multi = 0;
                    setBadge('● LIVE', false);
                    if (doIdentity) {
                        const d = faceapi.euclideanDistance(dets[0].descriptor, S.ref);
                        if (d > CFG.matchThreshold) {
                            S.recheck = true;
                            setBadge('⚠ CHECKING', true);
                            if (++S.mismatch >= CFG.mismatchLimit) {
                                S.mismatch = 0; S.recheck = false;
                                warn('identity_mismatch', 'Face does not match the registered candidate.', { face_count: 1, distance: d });
                            }
                        } else {
                            S.mismatch = 0; S.recheck = false;
                        }
                    }
                }
            }
        } catch (e) { console.warn('proctor tick', e); }
        if (S.running) S.timer = setTimeout(tick, S.recheck ? 3000 : CFG.detectIntervalMs);
    }

    function warn(type, msg, extra = {}) {
        if (!S.running) return;
        const now = Date.now();
        if (S.lastWarnAt[type] && now - S.lastWarnAt[type] < CFG.warningCooldownMs) return;
        S.lastWarnAt[type] = now;
        S.warnings++;
        sessionStorage.setItem(key('warnings'), String(S.warnings));

        const final = CFG.maxWarnings > 0 && S.warnings >= CFG.maxWarnings;
        const label = CFG.maxWarnings > 0 ? `Warning ${S.warnings}/${CFG.maxWarnings}: ` : 'Warning: ';
        showBanner(label + msg + (final ? ' — Exam is being terminated.' : ''), final);
        log(type, Object.assign({ warning_no: S.warnings, message: msg }, extra));

        if (final) terminate('Proctoring: ' + msg);
    }

    function terminate(reason) {
        S.running = false;
        clearTimeout(S.timer);
        log('violation', { message: reason, warning_no: S.warnings });
        setTimeout(() => {
            stop();
            if (typeof S.opts.onViolation === 'function') S.opts.onViolation(reason);
        }, 2500);
    }

    async function onCameraLost() {
        if (!S.running) return;
        warn('camera_off', 'Camera was turned off or disconnected.');
        if (!S.running) return;
        try {
            S.stream = null;
            await startCamera();
        } catch (e) {
            terminate('Camera unavailable: ' + e.message);
        }
    }

    function stop() {
        S.running = false;
        stopPreview();
        clearTimeout(S.timer);
        if (S.stream) S.stream.getTracks().forEach(t => { t.removeEventListener('ended', onCameraLost); t.stop(); });
        S.stream = null;
        const mini = $('fp-mini');
        if (mini) mini.remove();
    }

    // ───────────────────────── PUBLIC API ─────────────────────────
    window.FaceProctor = {
        /** Shows verification screen; resolves once identity is verified and monitoring has started. */
        start(opts = {}) {
            S.opts = opts;
            injectStyles();
            buildOverlay();
            setup();
            return new Promise(res => { S.resolve = res; });
        },
        stop,
        get warnings() { return S.warnings; },
        config: CFG
    };
})();
